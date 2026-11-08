<?php

namespace App\Http\Controllers\Api;

use App\Models\Application;
use App\Models\Grant;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\SigningKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/bar — everything the Clauth bar shows, for the person behind an app's access token:
 * who they are, this app, their orgs + role in this app, and every app they can open.
 * Called by the app's server (kit route /auth/clauth/bar), never by the browser directly.
 */
class BarController
{
    public function __invoke(Request $request)
    {
        $t = SigningKeys::verify((string) $request->bearerToken());
        $c = $t?->claims();
        if (! $t || ! str_starts_with((string) $c->get('sub'), 'usr_')
            || DB::table('oauth_access_tokens')->where('id', $c->get('jti'))->where('revoked', false)->doesntExist()) {
            return response()->json(['error' => 'invalid_token'], 401);
        }
        $user = User::findBySub((string) $c->get('sub'));
        $client = OAuthClient::with('app')->find($c->get('client_id'));
        $app = $client?->app;
        if (! $user || $user->status !== 'active' || ! $app) {
            return response()->json(['error' => 'invalid_token'], 401);
        }
        Application::whereKey($app->id)->update(['bar_seen_at' => now()]);

        $grants = Grant::with(['app.organizations', 'organization', 'role'])->where('user_id', $user->id)->get()
            ->filter(fn ($g) => $g->app && $g->app->status !== 'paused' && $g->organization?->status === 'active'
                && $g->app->organizations->contains('id', $g->organization_id));

        $iss = config('clauth.issuer');
        $present = fn (Application $a) => [
            'key' => $a->key, 'name' => $a->name, 'letter' => $a->letter(), 'color' => $a->color,
            'icon' => $a->logoUrl(true), // dark-background variant: the bar is dark
            'url' => $a->home_url,
        ];

        return response()->json([
            'v' => 1,
            'user' => ['name' => $user->name, 'email' => $user->email, 'initials' => $user->initials()],
            'app' => $present($app),
            'orgs' => $grants->where('app_id', $app->id)->sortBy('organization.name')->values()
                ->map(fn ($g) => ['slug' => $g->organization->slug, 'name' => $g->organization->name, 'role' => $g->role->name])->all(),
            'apps' => $grants->unique('app_id')->sortBy('app.name')->values()
                ->filter(fn ($g) => $g->app->home_url)->map(fn ($g) => $present($g->app) + ['current' => $g->app_id === $app->id])->values()->all(),
            'links' => [
                'account' => $iss.'/account',
                'signout_everywhere' => $iss.'/signout-everywhere',
                'privacy' => $iss.'/privacy',
                'terms' => $iss.'/terms',
            ],
        ])->header('Cache-Control', 'private, max-age=60');
    }
}
