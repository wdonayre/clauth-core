<?php

namespace App\Http\Controllers\Admin;

use App\Models\Application;
use App\Models\AppPermission;
use App\Models\AppRole;
use App\Models\Grant;
use App\Models\Invite;
use App\Models\OAuthClient;
use App\Models\Organization;
use App\Services\Audit;
use App\Services\Manifest;
use App\Services\Theme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rule;

/** Owner-only: register apps, clients, permissions × roles, sign-in page styling. */
class AppController
{
    public function index()
    {
        return view('admin.apps.index', [
            'apps' => Application::withCount('grants')->with(['organizations', 'clients'])->orderBy('name')->get(),
            'orgs' => Organization::orderBy('name')->get(),
            'created' => session('created_client'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'key' => ['required', 'regex:/^[a-z][a-z0-9-]{1,40}$/', Rule::unique('apps', 'key')],
            'type' => 'required|in:web,public,m2m',
            'redirect_uri' => 'required_unless:type,m2m|nullable|url',
            'home_url' => 'nullable|url',
            'orgs' => 'array',
            'orgs.*' => 'exists:organizations,id',
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], ['key.regex' => 'Use lowercase letters, numbers and dashes, starting with a letter.']);

        $app = DB::transaction(function () use ($data) {
            $app = Application::create([
                'name' => $data['name'], 'key' => $data['key'], 'home_url' => $data['home_url'] ?? null,
                'color' => $data['color'] ?? '#22D3EE', 'icon_letter' => mb_strtoupper(mb_substr($data['name'], 0, 1)),
                'status' => 'not_connected', 'manifest_url' => ! empty($data['home_url']) ? rtrim($data['home_url'], '/').'/.well-known/clauth.json' : null,
            ]);
            $app->organizations()->sync($data['orgs'] ?? []);
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
        $app->load(['roles.permissions', 'organizations', 'clients']);

        return view('admin.apps.show', [
            'app' => $app, 'tab' => $tab,
            'permissions' => AppPermission::where('app_id', $app->id)->orderByRaw('deprecated_at is not null')->orderBy('key')->get(),
            'orgs' => Organization::orderBy('name')->get(),
            'grants' => $tab === 'users' ? Grant::with(['user', 'organization', 'role'])->where('app_id', $app->id)->get()->sortBy('user.name') : collect(),
            'created' => session('created_client'),
            'theme' => Theme::for($app),
            'themes' => Theme::NAMES,
        ]);
    }

    public function update(Request $request, Application $app)
    {
        $before = $app->only('name', 'home_url', 'manifest_url', 'status', 'require_mfa_roles', 'color');
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'home_url' => 'nullable|url',
            'manifest_url' => 'nullable|url',
            'status' => 'required|in:live,paused,not_connected',
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'orgs' => 'array',
            'orgs.*' => 'exists:organizations,id',
            'require_mfa_roles' => 'array',
        ]);
        $app->update(collect($data)->except('orgs')->all() + ['require_mfa_roles' => array_values($data['require_mfa_roles'] ?? [])]);
        $app->organizations()->sync($data['orgs'] ?? []);
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
        $app->update(['manifest_diff' => null]);

        return back()->with('status', 'Roles saved. Tokens issued from now on carry the new permissions.');
    }

    public function storeRole(Request $request, Application $app)
    {
        $data = $request->validate(['name' => 'required|string|max:60', 'key' => ['required', 'regex:/^[a-z][a-z0-9_-]{0,40}$/', Rule::unique('app_roles')->where('app_id', $app->id)], 'description' => 'nullable|string|max:190']);
        $role = AppRole::create($data + ['app_id' => $app->id, 'source' => 'manual', 'edited' => true, 'position' => $app->roles()->count()]);
        Audit::log('app.role_changed', 'role', $role, after: ['created' => $role->key], appId: $app->id);

        return back()->with('status', "Role “{$role->name}” added.");
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
