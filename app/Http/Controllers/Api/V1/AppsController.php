<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiContext;
use App\Api\Present;
use App\Models\Application;
use App\Models\Grant;
use App\Services\Audit;
use App\Services\Manifest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** /api/v1/apps, /api/v1/grants (SPEC-platform §6.1). Apps change only through their publisher. */
class AppsController
{
    use UsesApi;

    public function index(Request $request)
    {
        return Present::page($this->api()->scopeApps(Application::query()->with('publisher')), fn (Application $a) => Present::app($a), $request);
    }

    public function show(string $app)
    {
        $a = $this->api()->app($app)->load('publisher');
        $out = Present::app($a, true);
        if (! $this->api()->canManageApp($a)) {
            unset($out['clients']); // an organization that only uses the app doesn't see its clients
        }

        return \App\Api\Etag::attach(response()->json($out), $a);
    }

    public function syncManifest(string $app)
    {
        $a = $this->managed($app);
        try {
            $s = Manifest::apply($a, Manifest::fetch($a));
        } catch (\Throwable $e) {
            abort(422, 'Sync failed: '.$e->getMessage());
        }

        return response()->json(['added' => $s['added'], 'deprecated' => $s['deprecated'], 'roles_created' => $s['roles_created'], 'role_differences' => $s['diffs']]);
    }

    /** New secret, shown once in this response. */
    public function rotateClient(string $app, string $client)
    {
        $a = $this->managed($app);
        $c = $a->clients()->findOrFail($client);
        abort_if($c->type === 'public', 422, 'Public clients have no secret.');
        $secret = Str::random(48);
        $c->forceFill(['secret' => $secret, 'secret_expires_at' => now()->addDays(config('clauth.client_secret_days'))])->save();
        Audit::log('app.secret_rotated', 'app', $a, after: ['client' => $c->id], appId: $a->id);

        return response()->json(Present::client($c) + ['secret' => $secret]);
    }

    public function revokeClient(string $app, string $client)
    {
        $a = $this->managed($app);
        $c = $a->clients()->findOrFail($client);
        $c->update(['revoked' => true]);
        DB::table('oauth_access_tokens')->where('client_id', $c->id)->update(['revoked' => true]);
        Audit::log('app.updated', 'app', $a, after: ['client_revoked' => $c->id], appId: $a->id);

        return response()->json(Present::client($c->fresh()));
    }

    /** GET /grants?org=&app=&user= — the effective grants tokens are built from (read-only; change access via members/{user}/access). */
    public function grants(Request $request)
    {
        $q = Grant::with(['user', 'app', 'role', 'organization'])
            ->when($this->api()->orgIds() !== null, fn ($q) => $q->whereIn('organization_id', $this->api()->orgIds()))
            ->when($request->query('org'), fn ($q, $o) => $q->where('organization_id', $this->api()->org($o)->id))
            ->when($request->query('app'), fn ($q, $a) => $q->where('app_id', $this->api()->app($a)->id))
            ->when($request->query('user'), fn ($q, $u) => $q->where('user_id', $this->api()->user($u)->id));

        return Present::page($q, fn (Grant $g) => Present::grant($g), $request);
    }

    private function managed(string $key): Application
    {
        $a = $this->api()->app($key);
        abort_unless($this->api()->canManageApp($a), 403, 'Only the organization that publishes this app can change it.');
        abort_if($a->isSuspended() && ! $this->api()->isPlatform(), 403, 'This app is suspended.');

        return $a;
    }
}
