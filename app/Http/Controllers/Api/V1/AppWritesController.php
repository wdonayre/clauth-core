<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\Etag;
use App\Api\Present;
use App\Http\Controllers\Admin\AppController;
use App\Models\AppDomain;
use App\Models\AppPermission;
use App\Models\Application;
use App\Models\AppRole;
use App\Models\Grant;
use App\Models\Organization;
use App\Services\Audit;
use App\Services\Domains;
use App\Services\Installations;
use App\Services\Limits;
use App\Services\Manifest;
use App\Services\Theme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * App changes over the API (SPEC-platform P1.1): create, settings, clients, roles, permissions, branding,
 * installations and the sign-in domain. Only the publishing organization changes an app — same rules as App detail.
 */
class AppWritesController
{
    use UsesApi;

    private function managed(string $key): Application
    {
        $a = $this->api()->app($key);
        abort_unless($this->api()->canManageApp($a), 403, 'Only the organization that publishes this app can change it.');
        abort_if($a->isSuspended() && ! $this->api()->isPlatform(), 403, 'This app is suspended.');

        return $a;
    }

    private function json(Application $a, int $status = 200)
    {
        return Etag::attach(response()->json(Present::app($a->fresh('publisher'), true), $status), $a->fresh());
    }

    /** {name, key, type: web|public|m2m, redirect_uri?, home_url?, color?, publisher?: slug, orgs?: [slug], privacy_url?, terms_url?, support_email?} */
    public function store(Request $request)
    {
        $api = $this->api();
        $owner = $api->actor->isOwner();
        $pubRef = $request->input('publisher') ?? ($api->isPersonal() || $api->isPlatform() ? null : $api->client->organization?->slug);
        abort_unless($pubRef, 422, 'Say which organization publishes the app (publisher).');
        $publisher = Organization::where('slug', $pubRef)->orWhere('id', $pubRef)->first();
        abort_unless($publisher && ($api->publisherOrgIds() === null || in_array($publisher->id, $api->publisherOrgIds(), true)), 404, 'Organization not found.');
        $reserved = AppController::reserved($owner);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', ...$reserved['name']],
            'key' => ['required', 'regex:/^[a-z][a-z0-9-]{1,40}$/', Rule::unique('apps', 'key'), ...$reserved['key']],
            'type' => 'required|in:web,public,m2m',
            'redirect_uri' => 'required_unless:type,m2m|nullable|url',
            'home_url' => 'nullable|url',
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'orgs' => 'array', 'orgs.*' => 'string',
        ] + AppController::policyRules($publisher->id));
        abort_if($publisher->needsTerms(), 422, 'The organization’s owner must accept the Terms and Data Processing Agreement first (console › Organizations).');
        if ($msg = Limits::exceeded($publisher, 'apps')) {
            abort(429, $msg);
        }
        $app = DB::transaction(function () use ($data, $publisher, $api) {
            $app = Application::create([
                'owner_organization_id' => $publisher->id, 'visibility' => 'private',
                'privacy_url' => $data['privacy_url'] ?? null, 'terms_url' => $data['terms_url'] ?? null, 'support_email' => $data['support_email'] ?? null,
                'name' => $data['name'], 'key' => $data['key'], 'home_url' => $data['home_url'] ?? null,
                'color' => $data['color'] ?? '#22D3EE', 'icon_letter' => mb_strtoupper(mb_substr($data['name'], 0, 1)),
                'status' => 'not_connected', 'manifest_url' => ! empty($data['home_url']) ? rtrim($data['home_url'], '/').'/.well-known/clauth.json' : null,
            ]);
            Installations::add($app, $publisher, $api->actor, true);
            AppRole::create(['app_id' => $app->id, 'key' => 'member', 'name' => 'Member', 'source' => 'manual']);

            return $app;
        });
        foreach ($data['orgs'] ?? [] as $slug) {
            $this->install($app, $slug);
        }
        [$client, $secret] = AppController::makeClient($app, $data['type'], $data['redirect_uri'] ?? null, $data['name'].' '.match ($data['type']) {
            'public' => 'mobile/SPA', 'm2m' => 'server-to-server', default => 'web'
        });
        Audit::log('app.registered', 'app', $app, after: ['key' => $app->key, 'type' => $data['type']], appId: $app->id);

        return response()->json(Present::app($app->fresh('publisher'), true) + ['client' => Present::client($client) + ['secret' => $secret]], 201);
    }

    /** PATCH {name?, home_url?, manifest_url?, status?, color?, visibility?, require_mfa_roles?: [role keys], privacy_url?, terms_url?, support_email?} */
    public function update(Request $request, string $app)
    {
        $a = $this->managed($app);
        Etag::check($a);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80', ...($request->input('name', $a->name) !== $a->name ? AppController::reserved($this->api()->actor->isOwner())['name'] : [])],
            'home_url' => 'sometimes|nullable|url', 'manifest_url' => 'sometimes|nullable|url',
            'status' => 'sometimes|in:live,paused,not_connected', 'color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'visibility' => 'sometimes|in:private,installable', 'require_mfa_roles' => 'sometimes|array', 'require_mfa_roles.*' => 'string',
        ] + collect(AppController::policyRules($a->owner_organization_id))->map(fn ($r) => array_merge(['sometimes'], $r))->all());
        if (isset($data['require_mfa_roles'])) {
            $known = $a->roles()->pluck('key')->all();
            abort_if(array_diff($data['require_mfa_roles'], $known), 422, 'require_mfa_roles lists roles this app does not have.');
            $data['require_mfa_roles'] = array_values($data['require_mfa_roles']);
        }
        $before = $a->only(array_keys($data));
        $a->update($data);
        Audit::log('app.updated', 'app', $a, $before, $a->only(array_keys($data)), $a->id);

        return $this->json($a);
    }

    /** DELETE {confirm: app key} — owners/admins of the publisher only (not developers). */
    public function destroy(Request $request, string $app)
    {
        $a = $this->managed($app);
        $me = $this->api()->actor;
        abort_unless($this->api()->isPlatform() || in_array($a->owner_organization_id, $me->adminOrgIds(), true), 403, 'Only an owner or admin of the app’s organization can delete it.');
        $request->validate(['confirm' => ['required', Rule::in([$a->key])]], ['confirm.in' => 'Send the app key as confirm.']);
        Etag::check($a);
        foreach ($a->clients as $c) {
            $c->update(['revoked' => true]);
            DB::table('oauth_access_tokens')->where('client_id', $c->id)->update(['revoked' => true]);
        }
        Grant::where('app_id', $a->id)->delete();
        $a->delete();
        Audit::log('app.updated', 'app', $a, after: ['deleted' => true], appId: $a->id);

        return response()->noContent();
    }

    /** {type: web|public|m2m, name, redirect_uri?} — the secret is in this response only. */
    public function addClient(Request $request, string $app)
    {
        $a = $this->managed($app);
        $data = $request->validate(['type' => 'required|in:web,public,m2m,device', 'redirect_uri' => 'required_if:type,web,public|nullable|string|max:500', 'name' => 'required|string|max:80']);
        [$client, $secret] = AppController::makeClient($a, $data['type'], $data['redirect_uri'] ?? null, $data['name']);
        Audit::log('app.client_added', 'app', $a, after: ['client' => $client->id, 'type' => $data['type']], appId: $a->id);

        return response()->json(Present::client($client) + ['secret' => $secret], 201);
    }

    /** {redirect_uris?: [url], post_logout_redirect_uris?: [url], name?} */
    public function updateClient(Request $request, string $app, string $client)
    {
        $a = $this->managed($app);
        $c = $a->clients()->findOrFail($client);
        $url = fn ($attr, $v, $fail) => filter_var($v, FILTER_VALIDATE_URL) || preg_match('#^[a-z][a-z0-9+.-]*://#i', (string) $v) ? null : $fail("Not a valid URL: $v");
        $data = $request->validate(['name' => 'sometimes|required|string|max:80', 'redirect_uris' => 'sometimes|array', 'redirect_uris.*' => ['string', 'max:500', $url],
            'post_logout_redirect_uris' => 'sometimes|array', 'post_logout_redirect_uris.*' => ['string', 'max:500', $url]]);
        $c->update(collect($data)->map(fn ($v) => is_array($v) ? array_values($v) : $v)->all());
        Audit::log('app.updated', 'app', $a, after: ['client' => $c->id] + $data, appId: $a->id);

        return response()->json(Present::client($c->fresh()));
    }

    /** {key, name, description?, tier?} */
    public function storeRole(Request $request, string $app)
    {
        $a = $this->managed($app);
        $data = $request->validate(['tier' => ['nullable', Rule::in(array_keys(AppRole::TIERS))], 'name' => 'required|string|max:60',
            'key' => ['required', 'regex:/^[a-z][a-z0-9_-]{0,40}$/', Rule::unique('app_roles')->where('app_id', $a->id)], 'description' => 'nullable|string|max:190']);
        $role = AppRole::create($data + ['app_id' => $a->id, 'source' => 'manual', 'edited' => true, 'position' => $a->roles()->count(),
            'tier_source' => ! empty($data['tier']) ? 'manual' : null]);
        Audit::log('app.role_changed', 'role', $role, after: ['created' => $role->key], appId: $a->id);

        return Etag::attach(response()->json(Present::role($role), 201), $role);
    }

    /** PATCH {name?, description?, tier?, aliases?: [old keys], assignable?} — aliases/assignable only for roles not from the manifest. */
    public function updateRole(Request $request, string $app, string $role)
    {
        $a = $this->managed($app);
        $r = $a->roles()->where('key', $role)->firstOrFail();
        Etag::check($r);
        $data = $request->validate(['name' => 'sometimes|required|string|max:60', 'description' => 'sometimes|nullable|string|max:190',
            'tier' => ['sometimes', 'nullable', Rule::in(array_keys(AppRole::TIERS))], 'aliases' => 'sometimes|array', 'aliases.*' => 'string|max:40', 'assignable' => 'sometimes|boolean']);
        abort_if($r->source === 'manifest' && (isset($data['aliases']) || isset($data['assignable']) || isset($data['name'])), 422,
            'This role comes from the app’s manifest: change its name, old keys and "can be assigned" there. Only the tier can be set here.');
        $before = $r->only('name', 'description', 'tier', 'aliases', 'assignable');
        $changes = array_intersect_key($data, array_flip(['name', 'description', 'assignable']));
        if (array_key_exists('tier', $data) && $data['tier'] !== $r->tier) {
            $changes += ['tier' => $data['tier'], 'tier_source' => $data['tier'] ? 'manual' : null];
        }
        if (isset($data['aliases'])) {
            $changes['aliases'] = AppRole::cleanAliases($data['aliases'], $r->key) ?: null;
        }
        $r->update($changes);
        if (array_key_exists('tier', $data) && ($diff = $a->manifest_diff)) {
            unset($diff[$r->key]['tier']);
            $a->update(['manifest_diff' => array_filter($diff) ?: null]);
        }
        Audit::log('app.role_changed', 'role', $r, $before, $r->only(array_keys($before)), $a->id);

        return Etag::attach(response()->json(Present::role($r->fresh())), $r->fresh());
    }

    public function destroyRole(string $app, string $role)
    {
        $a = $this->managed($app);
        $r = $a->roles()->where('key', $role)->firstOrFail();
        Etag::check($r);
        abort_if(Grant::where('app_role_id', $r->id)->exists(), 409, 'People still hold this role. Move them to another role first.');
        $r->delete();
        Audit::log('app.role_changed', 'role', $r, before: ['deleted' => $r->key], appId: $a->id);

        return response()->noContent();
    }

    /** PUT {permissions: [keys]} — the exact permission list of one role. */
    public function rolePermissions(Request $request, string $app, string $role)
    {
        $a = $this->managed($app);
        $r = $a->roles()->where('key', $role)->firstOrFail();
        Etag::check($r);
        $data = $request->validate(['permissions' => 'present|array', 'permissions.*' => 'string']);
        $perms = AppPermission::where('app_id', $a->id)->whereNull('deprecated_at')->whereIn('key', $data['permissions'])->pluck('id', 'key');
        abort_if($missing = array_diff($data['permissions'], $perms->keys()->all()), 422, 'Unknown or deprecated permissions: '.implode(', ', $missing));
        $have = $r->permissions()->whereNull('deprecated_at')->pluck('app_permissions.id')->sort()->values()->all();
        $want = $perms->values()->sort()->values()->all();
        if ($want !== $have) {
            $r->permissions()->sync($want);
            $r->update(['edited' => true]);
            Audit::log('app.role_changed', 'role', $r, ['permissions' => $have], ['permissions' => $want], $a->id);
        }

        return Etag::attach(response()->json(Present::role($r->fresh())), $r->fresh());
    }

    /** {key, description?} — a permission added by hand (manifest permissions come from the app). */
    public function storePermission(Request $request, string $app)
    {
        $a = $this->managed($app);
        $data = $request->validate(['key' => ['required', 'regex:/^[a-z][a-z0-9_.:-]{0,80}$/', Rule::unique('app_permissions')->where('app_id', $a->id)], 'description' => 'nullable|string|max:190']);
        $p = AppPermission::create($data + ['app_id' => $a->id, 'source' => 'manual']);
        Audit::log('app.role_changed', 'app', $a, after: ['permission_added' => $p->key], appId: $a->id);

        return response()->json(['key' => $p->key, 'description' => $p->description, 'source' => 'manual', 'deprecated' => false], 201);
    }

    /** PUT (JSON or multipart) {login_theme, accent_color?, logo?: png|svg file, logo_dark?: file, remove_logo?} */
    public function branding(Request $request, string $app)
    {
        $a = $this->managed($app);
        Etag::check($a);
        $data = $request->validate([
            'login_theme' => ['required', Rule::in(array_keys(Theme::NAMES))], 'accent_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => 'nullable|file|mimes:png,svg|max:512', 'logo_dark' => 'nullable|file|mimes:png,svg|max:512', 'remove_logo' => 'boolean',
        ]);
        abort_if(! empty($data['accent_color']) && ! Theme::contrastOk($data['accent_color']), 422, 'Neither black nor white text is readable on that accent color.');
        $before = $a->only('login_theme', 'accent_color', 'logo_path', 'logo_dark_path');
        $update = ['login_theme' => $data['login_theme'], 'accent_color' => $data['accent_color'] ?? null];
        foreach (['logo' => 'logo_path', 'logo_dark' => 'logo_dark_path'] as $field => $col) {
            if ($request->hasFile($field)) {
                $file = $request->file($field);
                abort_if($file->getClientOriginalExtension() === 'svg' && preg_match('/<script|on\w+=|javascript:/i', (string) file_get_contents($file->getRealPath())), 422, 'That SVG contains scripts.');
                $update[$col] = $file->storeAs('logos', $a->key.'-'.$field.'-'.Str::random(6).'.'.strtolower($file->getClientOriginalExtension()), 'local');
            }
        }
        if ($request->boolean('remove_logo')) {
            $update['logo_path'] = null;
            $update['logo_dark_path'] = null;
        }
        $a->update($update);
        Audit::log('app.branding_changed', 'app', $a, $before, $a->only(array_keys($before)), $a->id);

        return response()->json(['login_theme' => $a->login_theme, 'accent_color' => $a->accent_color,
            'logo_url' => $a->logoUrl(), 'logo_dark_url' => $a->logoUrl(true)]);
    }

    public function installations(string $app)
    {
        $a = $this->managed($app);

        return response()->json(['data' => DB::table('app_installations')->join('organizations', 'organizations.id', '=', 'app_installations.organization_id')
            ->where('app_id', $a->id)->orderBy('organizations.slug')->get(['organizations.slug as org', 'app_installations.status', 'app_installations.approved_at'])]);
    }

    /** POST {org: slug}: active at once in organizations this caller runs; otherwise a request their admins approve. */
    public function addInstallation(Request $request, string $app)
    {
        $a = $this->managed($app);
        $request->validate(['org' => 'required|string|max:64']);

        return response()->json(['org' => $request->input('org'), 'status' => $this->install($a, $request->input('org'))], 202);
    }

    public function removeInstallation(string $app, string $org)
    {
        $a = $this->managed($app);
        $o = $a->installations()->where('organizations.slug', $org)->first();
        abort_unless($o && $o->id !== $a->owner_organization_id, 404, 'Not installed there (an app always stays in its own organization).');
        Installations::remove($a, $o);

        return response()->noContent();
    }

    /** Same answer whether or not an organization outside the caller's reach exists. */
    private function install(Application $a, string $slug): string
    {
        $org = Organization::where('slug', strtolower(trim($slug)))->first();
        if (! $org) {
            return 'requested';
        }
        $may = $this->api()->canOrg($org->id) && Installations::canApprove($this->api()->actor, $org);
        $status = Installations::add($a, $org, $this->api()->actor, $may);

        return $this->api()->canOrg($org->id) ? $status : 'requested';
    }

    public function domain(string $app)
    {
        $a = $this->managed($app);
        $d = $a->signInDomain() ?? abort(404, 'This app has no sign-in domain.');

        return response()->json($this->domainJson($d));
    }

    /**
     * POST {host, share?: bool}. A new host starts verification; a host the publisher already verified is shared
     * (send share=true). Hosts claimed by another organization are refused here (the console handles stale claims).
     */
    public function storeDomain(Request $request, string $app)
    {
        $a = $this->managed($app);
        $raw = trim((string) $request->input('host'));
        $host = str_contains($raw, '://') ? (string) parse_url($raw, PHP_URL_HOST) : explode('/', $raw)[0];
        $request->merge(['host' => strtolower(trim($host, " .\t\n\r"))]);
        $data = $request->validate([
            'host' => ['required', 'max:190', 'regex:/^(?=.{4,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', Rule::notIn([Domains::mainHost(), ...Domains::mainAliases(), ...(array) config('clauth.previous_hosts')])],
            'share' => 'nullable|boolean',
        ]);
        abort_if($a->signInDomain(), 409, "{$a->name} already has a sign-in domain. Remove it first.");
        abort_unless($a->publisher, 422, 'The app has no owning organization.');
        $parent = collect(config('clauth.reserved_parent_domains'))->first(fn ($p) => $data['host'] === $p || str_ends_with($data['host'], '.'.$p));
        abort_if($parent && ! $a->publisher->is_platform, 422, "Addresses under {$parent} are reserved.");
        $existing = AppDomain::with('apps')->where('host', $data['host'])->first();
        if ($existing) {
            abort_if($existing->organization_id !== $a->owner_organization_id, 409, "{$data['host']} belongs to another organization (if their claim is stale, take it over in the console).");
            abort_unless($request->boolean('share'), 409, "{$data['host']} is already used by ".$existing->apps->pluck('key')->implode(', ').'. Send share=true to use it for this app too.');
            $existing->apps()->attach($a->id, ['created_by' => $this->api()->actor->id]);
            Domains::flush($existing->host);
            Audit::log('app.domain_shared', 'app', $existing, after: ['host' => $existing->host, 'app' => $a->key], appId: $a->id, orgId: $existing->organization_id);

            return response()->json($this->domainJson($existing->fresh()), 201);
        }
        if ($msg = Limits::exceeded($a->publisher, 'domains')) {
            abort(429, $msg);
        }
        $d = AppDomain::create(['host' => $data['host'], 'organization_id' => $a->owner_organization_id, 'trusted' => false,
            'verify_token' => Str::lower(Str::random(32)), 'status' => 'pending', 'created_by' => $this->api()->actor->id]);
        $d->apps()->attach($a->id, ['created_by' => $this->api()->actor->id]);
        Audit::log('app.domain_added', 'app', $d, after: ['host' => $d->host, 'organization_id' => $d->organization_id], appId: $a->id, orgId: $d->organization_id);
        rescue(fn () => Domains::check($d), report: false);

        return response()->json($this->domainJson($d->fresh()), 201);
    }

    public function checkDomain(string $app)
    {
        $a = $this->managed($app);
        $d = $a->signInDomain() ?? abort(404, 'This app has no sign-in domain.');
        foreach (range(1, 3) as $i) {
            $before = $d->status;
            $d = Domains::check($d);
            if ($d->status === $before || $d->status === 'active') {
                break;
            }
        }

        return response()->json($this->domainJson($d));
    }

    /** PATCH {display_name?, trusted? (Clauth owners only)} */
    public function updateDomain(Request $request, string $app)
    {
        $a = $this->managed($app);
        $d = $a->signInDomain() ?? abort(404, 'This app has no sign-in domain.');
        $data = $request->validate(['display_name' => 'sometimes|nullable|string|max:80', 'trusted' => 'sometimes|boolean']);
        abort_if(array_key_exists('trusted', $data) && ! $this->api()->actor->isOwner(), 403, 'Only Clauth owners can mark a domain trusted.');
        $before = $d->only(['display_name', 'trusted']);
        $d->fill($data)->save();
        Domains::flush($d->host);
        Audit::log('app.domain_updated', 'app', $d, $before, $d->only(['display_name', 'trusted']), $a->id, $d->organization_id);

        return response()->json($this->domainJson($d));
    }

    public function destroyDomain(string $app)
    {
        $a = $this->managed($app);
        $d = $a->signInDomain() ?? abort(404, 'This app has no sign-in domain.');
        $d->apps()->detach($a->id);
        $left = $d->apps()->count();
        if ($left === 0) {
            $d->delete();
        }
        Domains::flush($d->host);
        Audit::log($left ? 'app.domain_unshared' : 'app.domain_removed', 'app', $d, before: ['host' => $d->host, 'app' => $a->key], appId: $a->id, orgId: $d->organization_id);

        return response()->noContent();
    }

    private function domainJson(AppDomain $d): array
    {
        return ['host' => $d->host, 'status' => $d->status, 'label' => $d->label(), 'display_name' => $d->display_name, 'trusted' => (bool) $d->trusted,
            'apps' => $d->apps()->pluck('key'), 'dns' => [
                ['type' => 'TXT', 'name' => $d->txtName(), 'value' => $d->txtValue()],
                ['type' => 'CNAME', 'name' => $d->host, 'value' => Domains::mainHost()],
            ]];
    }
}
