<?php

namespace App\Http\Controllers\Admin;

use App\Mail\DomainTakenOverMail;
use App\Models\AppDomain;
use App\Models\Application;
use App\Models\AppPermission;
use App\Models\AppRole;
use App\Models\Grant;
use App\Models\Invite;
use App\Models\OAuthClient;
use App\Models\Organization;
use App\Services\Audit;
use App\Services\Domains;
use App\Services\Installations;
use App\Services\Limits;
use App\Services\Manifest;
use App\Services\Suspensions;
use App\Services\Theme;
use App\Support\Dns;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rule;

/** Owner-only: register apps, clients, permissions × roles, sign-in page styling. */
class AppController
{
    public function index()
    {
        $me = auth()->user();

        return view('admin.apps.index', [
            'apps' => Application::withCount('grants')->with(['organizations', 'clients', 'publisher'])->orderBy('name')
                ->when(! $me->isOwner(), fn ($q) => $q->whereIn('owner_organization_id', $me->devOrgIds()))->get(),
            'publishers' => $this->publisherOrgs(),
            'orgs' => $this->installableOrgs(),
            'created' => session('created_client'),
        ]);
    }

    /** Organizations the signed-in person can publish apps for. */
    private function publisherOrgs()
    {
        $me = auth()->user();

        return Organization::orderBy('name')->when(! $me->isOwner(), fn ($q) => $q->whereIn('id', $me->devOrgIds()))->get();
    }

    /** Organizations where the signed-in person can turn an app on directly (they approve for that organization). */
    private function installableOrgs()
    {
        $me = auth()->user();

        return Organization::orderBy('name')->when(! $me->isOwner(), fn ($q) => $q->whereIn('id', $me->adminOrgIds()))->get();
    }

    public function store(Request $request)
    {
        $reserved = $this->reservedRules($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', ...$reserved['name']],
            'key' => ['required', 'regex:/^[a-z][a-z0-9-]{1,40}$/', Rule::unique('apps', 'key'), ...$reserved['key']],
            'type' => 'required|in:web,public,m2m',
            'redirect_uri' => 'required_unless:type,m2m|nullable|url',
            'home_url' => 'nullable|url',
            'publisher' => ['required', Rule::in($this->publisherOrgs()->pluck('id')->all())],
            'orgs' => 'array',
            'orgs.*' => 'exists:organizations,id',
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ] + $this->policyRules($request->input('publisher')), ['key.regex' => 'Use lowercase letters, numbers and dashes, starting with a letter.', 'publisher.in' => 'Pick the organization that owns this app.',
            'privacy_url.required' => 'Apps of organizations outside Clauth’s own need a privacy policy link (shown on the consent screen).',
            'terms_url.required' => 'Add a link to the app’s terms.', 'support_email.required' => 'Add a support email people can contact.']);

        if (Organization::find($data['publisher'])?->needsTerms()) {
            return back()->withInput()->withErrors(['publisher' => 'The organization’s owner must accept the Terms and Data Processing Agreement first (Organizations page).']);
        }
        if ($msg = Limits::exceeded(Organization::find($data['publisher']), 'apps')) {
            return back()->withInput()->withErrors(['name' => $msg]);
        }

        $app = DB::transaction(function () use ($data, $request) {
            $app = Application::create([
                'owner_organization_id' => $data['publisher'], 'visibility' => 'private',
                'privacy_url' => $data['privacy_url'] ?? null, 'terms_url' => $data['terms_url'] ?? null, 'support_email' => $data['support_email'] ?? null,
                'name' => $data['name'], 'key' => $data['key'], 'home_url' => $data['home_url'] ?? null,
                'color' => $data['color'] ?? '#22D3EE', 'icon_letter' => mb_strtoupper(mb_substr($data['name'], 0, 1)),
                'status' => 'not_connected', 'manifest_url' => ! empty($data['home_url']) ? rtrim($data['home_url'], '/').'/.well-known/clauth.json' : null,
            ]);
            // Usable in its own organization, plus any others picked that this person runs.
            Installations::syncFor($app, array_values(array_unique(array_merge([$data['publisher']], $data['orgs'] ?? []))), $request->user());
            // Every app starts with a basic role so access can be granted before a manifest exists.
            AppRole::create(['app_id' => $app->id, 'key' => 'member', 'name' => 'Member', 'source' => 'manual']);

            return $app;
        });
        [$client, $secret] = $this->makeClient($app, $data['type'], $data['redirect_uri'] ?? null, $data['name'].' '.match ($data['type']) {
            'public' => 'mobile/SPA', 'm2m' => 'server-to-server', default => 'web'
        });
        Audit::log('app.registered', 'app', $app, after: ['key' => $app->key, 'type' => $data['type']], appId: $app->id);

        return redirect()->route('admin.apps.show', [$app->key, 'tab' => 'integration'])
            ->with('created_client', ['id' => $client->id, 'secret' => $secret, 'name' => $client->name]);
    }

    public function show(Request $request, Application $app)
    {
        $tab = $request->query('tab', 'roles');
        $app->load(['roles.permissions', 'organizations', 'clients', 'publisher', 'installations']);

        return view('admin.apps.show', [
            'app' => $app, 'tab' => $tab,
            'permissions' => AppPermission::where('app_id', $app->id)->orderByRaw('deprecated_at is not null')->orderBy('key')->get(),
            'orgs' => $this->installableOrgs(),
            // People are only shown for organizations the viewer belongs to as staff: a publisher never sees who
            // uses its app in another organization (SPEC-organizations §2.3).
            'grants' => $tab === 'users' ? Grant::with(['user', 'organization', 'role'])->where('app_id', $app->id)
                ->when(! $request->user()->isOwner(), fn ($q) => $q->whereIn('organization_id', $request->user()->devOrgIds()))->get()->sortBy('user.name') : collect(),
            'created' => session('created_client'),
            'theme' => Theme::for($app),
            'themes' => Theme::NAMES,
        ]);
    }

    public function update(Request $request, Application $app)
    {
        $before = $app->only('name', 'home_url', 'manifest_url', 'status', 'require_mfa_roles', 'color', 'visibility', 'owner_organization_id', 'privacy_url', 'terms_url', 'support_email');
        $publisher = $request->user()->isOwner() && $request->filled('publisher') ? $request->input('publisher') : $app->owner_organization_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', ...($request->input('name') !== $app->name ? $this->reservedRules($request)['name'] : [])],
            'home_url' => 'nullable|url',
            'manifest_url' => 'nullable|url',
            'status' => 'required|in:live,paused,not_connected',
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'visibility' => 'nullable|in:private,installable',
            'publisher' => ['nullable', Rule::in($request->user()->isOwner() ? Organization::pluck('id')->all() : [$app->owner_organization_id])],
            'orgs' => 'array',
            'orgs.*' => 'exists:organizations,id',
            'require_mfa_roles' => 'array',
        ] + $this->policyRules($publisher), ['privacy_url.required' => 'Apps of organizations outside Clauth’s own need a privacy policy link (shown on the consent screen).',
            'terms_url.required' => 'Add a link to the app’s terms.', 'support_email.required' => 'Add a support email people can contact.']);
        $app->update(collect($data)->except('orgs', 'visibility', 'publisher')->all() + ['require_mfa_roles' => array_values($data['require_mfa_roles'] ?? []),
            'visibility' => $data['visibility'] ?? $app->visibility,
            'owner_organization_id' => $request->user()->isOwner() && ! empty($data['publisher']) ? $data['publisher'] : $app->owner_organization_id]);
        // Only organizations this person runs are touched; installations elsewhere stay as they are.
        Installations::syncFor($app, $data['orgs'] ?? [], $request->user());
        Audit::log('app.updated', 'app', $app, $before, $app->only(array_keys($before)) + ['orgs' => $data['orgs'] ?? []], $app->id);

        return back()->with('status', 'App settings saved.');
    }

    public function updateClient(Request $request, Application $app, string $client)
    {
        $c = $app->clients()->findOrFail($client);
        $data = $request->validate(['redirect_uris' => 'nullable|string', 'post_logout_redirect_uris' => 'nullable|string']);
        $lines = fn ($s) => collect(preg_split('/\s+/', (string) $s))->filter()->each(function ($u) {
            abort_unless(filter_var($u, FILTER_VALIDATE_URL) || preg_match('#^[a-z][a-z0-9+.-]*://#i', $u), 422, "Not a valid URL: $u");
        })->values()->all();
        $c->update(['redirect_uris' => $lines($data['redirect_uris']), 'post_logout_redirect_uris' => $lines($data['post_logout_redirect_uris'])]);
        Audit::log('app.updated', 'app', $app, after: ['client' => $c->id, 'redirect_uris' => $c->redirect_uris], appId: $app->id);

        return back()->with('status', 'Redirect URLs saved.');
    }

    public function addClient(Request $request, Application $app)
    {
        $data = $request->validate(['type' => 'required|in:web,public,m2m', 'redirect_uri' => 'required_unless:type,m2m|nullable|string', 'name' => 'required|string|max:80']);
        [$client, $secret] = $this->makeClient($app, $data['type'], $data['redirect_uri'] ?? null, $data['name']);
        Audit::log('app.client_added', 'app', $app, after: ['client' => $client->id, 'type' => $data['type']], appId: $app->id);

        return redirect()->route('admin.apps.show', [$app->key, 'tab' => 'settings'])
            ->with('created_client', ['id' => $client->id, 'secret' => $secret, 'name' => $client->name]);
    }

    public function rotateSecret(Application $app, string $client)
    {
        $c = $app->clients()->findOrFail($client);
        abort_if($c->type === 'public', 422, 'Public clients have no secret.');
        $secret = Str::random(48);
        $c->forceFill(['secret' => $secret, 'secret_expires_at' => now()->addDays(config('clauth.client_secret_days'))])->save();
        Audit::log('app.secret_rotated', 'app', $app, after: ['client' => $c->id], appId: $app->id);

        return redirect()->route('admin.apps.show', [$app->key, 'tab' => 'settings'])
            ->with('created_client', ['id' => $c->id, 'secret' => $secret, 'name' => $c->name]);
    }

    public function revokeClient(Application $app, string $client)
    {
        $c = $app->clients()->findOrFail($client);
        $c->update(['revoked' => true]);
        DB::table('oauth_access_tokens')->where('client_id', $c->id)->update(['revoked' => true]);
        Audit::log('app.updated', 'app', $app, after: ['client_revoked' => $c->id], appId: $app->id);

        return back()->with('status', 'Client turned off. Its tokens stop working within 15 minutes.');
    }

    public function sync(Application $app)
    {
        try {
            $s = Manifest::apply($app, Manifest::fetch($app));
        } catch (\Throwable $e) {
            return back()->withErrors(['manifest' => 'Sync failed: '.$e->getMessage()]);
        }

        return back()->with('status', sprintf('Manifest synced: %d new permission(s), %d deprecated, %d new role(s)%s.',
            count($s['added']), count($s['deprecated']), count($s['roles_created']), $s['diffs'] ? ', '.count($s['diffs']).' role(s) differ from the manifest' : ''));
    }

    public function importManifest(Request $request, Application $app)
    {
        $request->validate(['manifest' => 'required|json']);
        try {
            Manifest::apply($app, json_decode($request->input('manifest'), true));
        } catch (\Throwable $e) {
            return back()->withErrors(['manifest' => $e->getMessage()]);
        }

        return back()->with('status', 'Manifest imported.');
    }

    public function matrix(Request $request, Application $app)
    {
        $active = AppPermission::where('app_id', $app->id)->whereNull('deprecated_at')->pluck('id')->all();
        foreach ($app->roles as $role) {
            $want = array_values(array_intersect(array_map('intval', $request->input("matrix.{$role->id}", [])), $active));
            $have = $role->permissions()->whereNull('deprecated_at')->pluck('app_permissions.id')->sort()->values()->all();
            sort($want);
            if ($want !== $have) {
                $role->permissions()->sync($want);
                $role->update(['edited' => true]);
                Audit::log('app.role_changed', 'role', $role, ['permissions' => $have], ['permissions' => $want], $app->id);
            }
        }
        // Saving the matrix settles permission differences; tier differences stay until a tier is saved.
        $tierDiffs = collect($app->manifest_diff ?? [])->map(fn ($d) => array_intersect_key($d, ['tier' => 1]))->filter()->all();
        $app->update(['manifest_diff' => $tierDiffs ?: null]);

        return back()->with('status', 'Roles saved. Tokens issued from now on carry the new permissions.');
    }

    public function storeRole(Request $request, Application $app)
    {
        $data = $request->validate(['tier' => ['nullable', Rule::in(array_keys(AppRole::TIERS))], 'name' => 'required|string|max:60', 'key' => ['required', 'regex:/^[a-z][a-z0-9_-]{0,40}$/', Rule::unique('app_roles')->where('app_id', $app->id)], 'description' => 'nullable|string|max:190']);
        $role = AppRole::create($data + ['app_id' => $app->id, 'source' => 'manual', 'edited' => true, 'position' => $app->roles()->count(),
            'tier_source' => ! empty($data['tier']) ? 'manual' : null]);
        Audit::log('app.role_changed', 'role', $role, after: ['created' => $role->key], appId: $app->id);

        return back()->with('status', "Role “{$role->name}” added.");
    }

    /** Tier for any role; old keys and "can be assigned" only for roles not owned by a manifest (SPEC-roles §7, §9.6). */
    public function updateRole(Request $request, Application $app, int $role)
    {
        $r = $app->roles()->findOrFail($role);
        $data = $request->validate(['tier' => ['nullable', Rule::in(array_keys(AppRole::TIERS))], 'aliases' => 'nullable|string|max:300', 'assignable' => 'boolean']);
        $before = $r->only('tier', 'aliases', 'assignable');
        $changes = [];
        if (($data['tier'] ?? null) !== $r->tier) {
            $changes += ['tier' => $data['tier'] ?? null, 'tier_source' => ($data['tier'] ?? null) ? 'manual' : null];
        }
        if ($r->source !== 'manifest') {
            $changes += ['aliases' => AppRole::cleanAliases($data['aliases'] ?? '', $r->key) ?: null, 'assignable' => $request->boolean('assignable')];
        }
        $r->update($changes);
        // Saving a tier settles a tier difference the manifest reported for this role.
        if ($diff = $app->manifest_diff) {
            unset($diff[$r->key]['tier']);
            $diff = array_filter($diff);
            $app->update(['manifest_diff' => $diff ?: null]);
        }
        Audit::log('app.role_changed', 'role', $r, $before, $r->only('tier', 'aliases', 'assignable'), $app->id);

        return back()->with('status', "Role “{$r->name}” saved.");
    }

    /** For an app with no roles of its own: one "Access" role so people can simply be given access (SPEC-roles §7). */
    public function accessRole(Application $app)
    {
        abort_if($app->roles()->exists(), 422, 'This app already has roles.');
        $role = Manifest::ensureAccessRole($app);
        Audit::log('app.role_changed', 'role', $role, after: ['created' => 'access'], appId: $app->id);

        return back()->with('status', 'Access role added.');
    }

    public function destroyRole(Application $app, int $role)
    {
        $r = $app->roles()->findOrFail($role);
        abort_if(Grant::where('app_role_id', $r->id)->exists(), 422, 'People still hold this role. Move them to another role first.');
        $r->delete();
        Audit::log('app.role_changed', 'role', $r, before: ['deleted' => $r->key], appId: $app->id);

        return back()->with('status', 'Role deleted.');
    }

    public function storePermission(Request $request, Application $app)
    {
        $data = $request->validate(['key' => ['required', 'regex:/^[a-z][a-z0-9_.:-]{0,80}$/', Rule::unique('app_permissions')->where('app_id', $app->id)], 'description' => 'nullable|string|max:190']);
        AppPermission::create($data + ['app_id' => $app->id, 'source' => 'manual']);
        Audit::log('app.role_changed', 'app', $app, after: ['permission_added' => $data['key']], appId: $app->id);

        return back()->with('status', "Permission {$data['key']} added.");
    }

    public function branding(Request $request, Application $app)
    {
        $data = $request->validate([
            'login_theme' => ['required', Rule::in(array_keys(Theme::NAMES))],
            'accent_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => 'nullable|file|mimes:png,svg|max:512',
            'logo_dark' => 'nullable|file|mimes:png,svg|max:512',
            'remove_logo' => 'boolean',
        ]);
        if (! empty($data['accent_color']) && ! Theme::contrastOk($data['accent_color'])) {
            return back()->withErrors(['accent_color' => 'Neither black nor white text is readable on that color. Pick a lighter or darker one.']);
        }
        $before = $app->only('login_theme', 'accent_color', 'logo_path', 'logo_dark_path');
        $update = ['login_theme' => $data['login_theme'], 'accent_color' => $data['accent_color'] ?? null];
        foreach (['logo' => 'logo_path', 'logo_dark' => 'logo_dark_path'] as $field => $col) {
            if ($request->hasFile($field)) {
                $file = $request->file($field);
                if ($file->getClientOriginalExtension() === 'svg' && preg_match('/<script|on\w+=|javascript:/i', (string) file_get_contents($file->getRealPath()))) {
                    return back()->withErrors([$field => 'That SVG contains scripts. Upload a clean SVG or a PNG.']);
                }
                $update[$col] = $file->storeAs('logos', $app->key.'-'.$field.'-'.Str::random(6).'.'.strtolower($file->getClientOriginalExtension()), 'local');
            }
        }
        if ($request->boolean('remove_logo')) {
            $update['logo_path'] = null;
            $update['logo_dark_path'] = null;
        }
        $app->update($update);
        Audit::log('app.branding_changed', 'app', $app, $before, $app->only(array_keys($before)), $app->id);

        return back()->with('status', 'Sign-in page saved.');
    }

    public function destroy(Request $request, Application $app)
    {
        $me = $request->user();
        abort_unless($me->isOwner() || in_array($app->owner_organization_id, $me->adminOrgIds(), true), 403, 'Only an owner or admin of the app’s organization can delete it.');
        $request->validate(['confirm' => ['required', Rule::in([$app->key])]], ['confirm.in' => 'Type the app key to confirm.']);
        foreach ($app->clients as $c) {
            $c->update(['revoked' => true]);
            DB::table('oauth_access_tokens')->where('client_id', $c->id)->update(['revoked' => true]);
        }
        Grant::where('app_id', $app->id)->delete();
        $app->delete();
        Audit::log('app.updated', 'app', $app, after: ['deleted' => true], appId: $app->id);

        return redirect()->route('admin.apps.index')->with('status', "{$app->name} was deleted.");
    }

    /** Live preview of the themed sign-in / account-setup page (framed by App detail › Sign-in page). */
    public function preview(Request $request, Application $app)
    {
        $draft = $app->replicate();
        $draft->key = $app->key;
        if (isset(Theme::NAMES[$request->query('theme')])) {
            $draft->login_theme = $request->query('theme');
        }
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $request->query('accent'))) {
            $draft->accent_color = $request->query('accent');
        }
        $theme = Theme::for($draft);
        view()->share('errors', new ViewErrorBag);
        if ($request->query('mode') === 'register') {
            $invite = new Invite(['email' => 'maria.santos@example.com', 'name' => 'Maria Santos', 'grants' => []]);
            $invite->setRelation('inviter', auth()->user());
            $invite->setRelation('organization', $app->organizations->first());

            return view('auth.invite', ['theme' => $theme, 'invite' => $invite, 'app' => $draft, 'role' => $app->roles->first(), 'token' => 'preview', 'preview' => true]);
        }

        return view('auth.login', ['theme' => $theme, 'app' => $draft, 'preview' => true]);
    }

    /** Clauth owners: suspend an app (sign-ins and tokens stop at once) or restore it. */
    public function suspend(Request $request, Application $app)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        Suspensions::suspendApp($app, $data['reason']);

        return back()->with('status', "{$app->name} is suspended.");
    }

    public function restore(Application $app)
    {
        Suspensions::restoreApp($app);

        return back()->with('status', "{$app->name} is active again.");
    }

    /** Keys/names only Clauth owners may use (William's apps, Clauth, trusted brands). */
    private function reservedRules(Request $request): array
    {
        if ($request->user()->isOwner()) {
            return ['key' => [], 'name' => []];
        }
        $key = function (string $attr, $value, $fail) {
            if (in_array($value, config('clauth.reserved_app_keys'), true) || collect(config('clauth.reserved_key_prefixes'))->contains(fn ($p) => str_starts_with($value, $p))) {
                $fail('That app key is reserved. Pick another one.');
            }
        };
        $name = function (string $attr, $value, $fail) {
            $n = mb_strtolower((string) $value);
            if (collect(config('clauth.reserved_name_words'))->contains(fn ($w) => str_contains($n, $w))) {
                $fail('That name uses a reserved word (another company’s or Clauth’s). Pick another one.');
            }
        };

        return ['key' => [$key], 'name' => [$name]];
    }

    /** Outside organizations' apps must publish where their policies live (shown on the consent screen). */
    private function policyRules(?string $publisherId): array
    {
        $outside = $publisherId && ! Organization::whereKey($publisherId)->value('is_platform');
        $req = $outside ? 'required' : 'nullable';

        return [
            'privacy_url' => [$req, 'nullable', 'url', 'starts_with:https://', 'max:500'],
            'terms_url' => [$req, 'nullable', 'url', 'starts_with:https://', 'max:500'],
            'support_email' => [$req, 'nullable', 'email', 'max:190'],
        ];
    }

    /**
     * Offer the app to another organization by its slug (apps marked installable). The other organization's admin
     * approves it on their Organizations page. When you run that organization too, it's on straight away.
     */
    public function requestInstall(Request $request, Application $app)
    {
        $data = $request->validate(['slug' => 'required|string|max:64']);
        $org = Organization::where('slug', strtolower(trim($data['slug'])))->first();
        $msg = 'If that organization exists, its admins will see the request on their Organizations page.';
        if (! $org) {
            return back()->with('status', $msg); // same answer whether or not the slug exists
        }
        $status = Installations::add($app, $org, $request->user());

        return back()->with('status', $status === 'active' ? "{$app->name} can now be used in {$org->name}." : $msg);
    }

    /** Stop offering the app to an organization (or withdraw a request). Access given through it is removed. */
    public function removeInstall(Application $app, string $org)
    {
        $o = $app->installations()->where('organizations.id', $org)->firstOrFail();
        Installations::remove($app, $o);

        return back()->with('status', "{$app->name} removed from {$o->name}.");
    }

    /**
     * Add a sign-in domain to an app. A new host starts verification for one of the app's organizations. A host
     * that organization already verified is shared after a confirmation (no new DNS records). A host verified by
     * another organization is refused, unless that claim is stale (never verified for a day, or its TXT is gone).
     */
    public function storeDomain(Request $request, Application $app)
    {
        // Accept a pasted URL: keep just the host.
        $raw = trim((string) $request->input('host'));
        $host = str_contains($raw, '://') ? (string) parse_url($raw, PHP_URL_HOST) : explode('/', $raw)[0];
        $request->merge(['host' => strtolower(trim($host, " .\t\n\r"))]);
        $data = $request->validate([
            'host' => ['required', 'max:190', 'regex:/^(?=.{4,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', Rule::notIn([Domains::mainHost(), ...Domains::mainAliases(), ...(array) config('clauth.previous_hosts')])],
            'organization_id' => ['nullable', 'string'],
            'share' => ['nullable', 'boolean'],
        ], ['host.regex' => 'Enter a domain like auth.example.com (no https://, no path).', 'host.not_in' => 'That is Clauth’s main address.']);
        if ($app->signInDomain()) {
            return back()->withErrors(['host' => "{$app->name} already has a sign-in domain. Remove it first."]);
        }
        // A sign-in domain belongs to the organization that publishes the app (SPEC-organizations §5.2).
        $orgs = $app->publisher ? collect([$app->publisher]) : collect();
        // Hosts under William's own domains are for his organizations only (SPEC-organizations §7.2).
        $parent = collect(config('clauth.reserved_parent_domains'))->first(fn ($p) => $data['host'] === $p || str_ends_with($data['host'], '.'.$p));
        if ($parent && ! $app->publisher?->is_platform) {
            return back()->withErrors(['host' => "Addresses under {$parent} are reserved. Use a domain your organization owns."]);
        }
        if ($orgs->isEmpty()) {
            return back()->withErrors(['host' => "{$app->name} has no owning organization yet. Set one first: a sign-in domain belongs to an organization."]);
        }

        $existing = AppDomain::with(['apps', 'organization'])->where('host', $data['host'])->first();
        if ($existing && ! $orgs->contains('id', $existing->organization_id)) {
            if (! $this->claimIsStale($existing)) {
                return back()->withErrors(['host' => "{$data['host']} is verified by another organization. It can only be used by that organization’s apps."]);
            }
            $prevOrg = $existing->organization;
            Audit::log('app.domain_taken_over', 'app', $existing, before: ['host' => $existing->host, 'organization' => $prevOrg?->slug, 'status' => $existing->status], after: ['organization' => $app->publisher?->slug], appId: $app->id, orgId: $app->owner_organization_id);
            if ($prevOrg) {
                Audit::log('app.domain_lost', 'app', $existing, before: ['host' => $existing->host], after: ['taken_by' => $app->publisher?->slug], orgId: $prevOrg->id);
                $to = $prevOrg->users()->wherePivot('role', 'owner')->pluck('email');
                $to = $to->isEmpty() ? $prevOrg->users()->wherePivot('role', 'admin')->pluck('email') : $to;
                foreach ($to as $email) {
                    rescue(fn () => Mail::to($email)->send(new DomainTakenOverMail($existing->host, $prevOrg->name, (string) $app->publisher?->name)), report: true);
                }
            }
            $existing->delete();
            Domains::flush($data['host']);
            $existing = null;
        }

        if ($existing) {
            if (! $request->boolean('share')) {
                return back()->withInput()->with('domain_share', [
                    'host' => $existing->host, 'apps' => $existing->apps->pluck('name')->all(), 'status' => $existing->label(), 'org' => $existing->organization?->name,
                ]);
            }
            $existing->apps()->attach($app->id, ['created_by' => auth()->id()]);
            Domains::flush($existing->host);
            Audit::log('app.domain_shared', 'app', $existing, after: ['host' => $existing->host, 'app' => $app->key], appId: $app->id, orgId: $existing->organization_id);

            return back()->with('status', "{$app->name} now signs in at {$existing->host} too ({$existing->label()}). No DNS changes needed.");
        }

        $orgId = $orgs->first()->id;
        if ($msg = Limits::exceeded($orgs->first(), 'domains')) {
            return back()->withErrors(['host' => $msg]);
        }
        $d = AppDomain::create(['host' => $data['host'], 'organization_id' => $orgId, 'trusted' => false,
            'verify_token' => Str::lower(Str::random(32)), 'status' => 'pending', 'created_by' => auth()->id()]);
        $d->apps()->attach($app->id, ['created_by' => auth()->id()]);
        Audit::log('app.domain_added', 'app', $d, after: ['host' => $d->host, 'organization_id' => $orgId], appId: $app->id, orgId: $orgId);
        rescue(fn () => Domains::check($d), report: false);

        return back()->with('status', "Added {$d->host}. Add the two DNS records below; Clauth checks every 5 minutes.");
    }

    /** Another organization's claim can be replaced when it was never verified (a day old) or its TXT record is gone. */
    private function claimIsStale(AppDomain $d): bool
    {
        if ($d->status === 'pending') {
            return $d->created_at->lt(now()->subDay());
        }
        if ($d->status === 'problem') {
            return ! in_array($d->txtValue(), array_map('trim', app(Dns::class)->txt($d->txtName())), true);
        }

        return false;
    }

    public function checkDomain(Application $app)
    {
        $d = $app->signInDomain() ?? abort(404);
        // Up to three steps at once (DNS → reachable → active) when everything is already in place.
        foreach (range(1, 3) as $i) {
            $before = $d->status;
            $d = Domains::check($d);
            if ($d->status === $before || $d->status === 'active') {
                break;
            }
        }

        return back()->with('status', "{$d->host}: {$d->label()}.");
    }

    /** Display name (all admins) and trusted (Clauth owners only). Applies to every app sharing the domain. */
    public function updateDomain(Request $request, Application $app)
    {
        $d = $app->signInDomain() ?? abort(404);
        $data = $request->validate(['display_name' => ['nullable', 'string', 'max:80'], 'trusted' => ['nullable', 'boolean']]);
        $before = $d->only(['display_name', 'trusted']);
        $d->display_name = $data['display_name'] ?? null;
        if ($request->has('trusted')) {
            abort_unless($request->user()?->isOwner(), 403);
            $d->trusted = $request->boolean('trusted');
        }
        $d->save();
        Domains::flush($d->host);
        Audit::log('app.domain_updated', 'app', $d, $before, $d->only(['display_name', 'trusted']), $app->id, $d->organization_id);

        return back()->with('status', "Saved {$d->host}.");
    }

    /** Stop this app using its domain. The last app out deletes the domain (the server job drops it from nginx and the certificate). */
    public function destroyDomain(Application $app)
    {
        $d = $app->signInDomain() ?? abort(404);
        $d->apps()->detach($app->id);
        $left = $d->apps()->count();
        if ($left === 0) {
            $d->delete();
        }
        Domains::flush($d->host);
        Audit::log($left ? 'app.domain_unshared' : 'app.domain_removed', 'app', $d, before: ['host' => $d->host, 'app' => $app->key], appId: $app->id, orgId: $d->organization_id);

        return back()->with('status', "{$app->name} no longer uses {$d->host}; it signs in at ".Domains::mainHost().' again.'.($left ? " The domain stays for its {$left} other app(s)." : ''));
    }

    /** Serves an uploaded logo from Clauth's own domain (no hot-linking). */
    public function logo(Request $request, Application $app)
    {
        $file = $app->logoFile($request->boolean('dark'));
        abort_unless($file, 404);

        return response()->file($file, ['Cache-Control' => 'public, max-age=86400', 'Content-Type' => str_ends_with($file, '.svg') ? 'image/svg+xml' : 'image/png',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'"]);
    }

    private function makeClient(Application $app, string $type, ?string $redirect, string $name): array
    {
        $secret = $type === 'public' ? null : Str::random(48);
        $client = new OAuthClient;
        $client->forceFill([
            'app_id' => $app->id, 'type' => $type, 'name' => $name, 'secret' => $secret,
            'redirect_uris' => $redirect ? [$redirect] : [],
            'post_logout_redirect_uris' => $redirect ? [rtrim(preg_replace('#^(https?://[^/]+).*$#', '$1', $redirect), '/').'/'] : [],
            'grant_types' => $type === 'm2m' ? ['client_credentials'] : ['authorization_code', 'refresh_token'],
            'revoked' => false,
            'secret_expires_at' => $secret ? now()->addDays(config('clauth.client_secret_days')) : null,
        ])->save();

        return [$client, $secret];
    }
}
