<?php

namespace App\Http\Controllers\Api;

use App\Models\Application;
use App\Models\Grant;
use App\Models\OAuthClient;
use App\Models\ReleaseNote;
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
        [$user, $app] = $this->resolve($request);
        if (! $user) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        return $this->payload($user, $app);
    }

    /** POST /api/v1/bar/read — {ids: [..]} or {all: true}: marks this app's release notes read for the person. */
    public function read(Request $request)
    {
        [$user, $app] = $this->resolve($request);
        if (! $user) {
            return response()->json(['error' => 'invalid_token'], 401);
        }
        $visible = ReleaseNote::forApp($app)->pluck('id')->all();
        $ids = $request->boolean('all') ? $visible : array_values(array_intersect($visible, array_map('intval', (array) $request->input('ids', []))));
        ReleaseNote::markRead($user, $ids);

        return response()->json(['read' => count($ids)]);
    }

    /** @return array{0: ?User, 1: ?Application} */
    private function resolve(Request $request): array
    {
        $t = SigningKeys::verify((string) $request->bearerToken());
        $c = $t?->claims();
        if (! $t || ! str_starts_with((string) $c->get('sub'), 'usr_')
            || DB::table('oauth_access_tokens')->where('id', $c->get('jti'))->where('revoked', false)->doesntExist()) {
            return [null, null];
        }
        $user = User::findBySub((string) $c->get('sub'));
        $app = OAuthClient::with('app')->find($c->get('client_id'))?->app;

        return $user && $user->status === 'active' && $app ? [$user, $app] : [null, null];
    }

    private function payload(User $user, Application $app)
    {
        Application::whereKey($app->id)->update(['bar_seen_at' => now()]);

        $grants = Grant::with(['app.organizations', 'organization', 'role'])->where('user_id', $user->id)->get()
            ->filter(fn ($g) => $g->app && $g->app->status !== 'paused' && ! $g->app->isSuspended() && $g->organization?->status === 'active' && ! $g->organization?->suspended_at
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
            'updates' => $this->updates($user, $app),
            'links' => [
                'account' => $iss.'/account',
                'signout_everywhere' => $iss.'/signout-everywhere',
                'privacy' => $iss.'/privacy',
                'terms' => $iss.'/terms',
            ],
        ])->header('Cache-Control', 'private, max-age=60');
    }

    private function updates(User $user, Application $app): array
    {
        $notes = ReleaseNote::with('app')->forApp($app)->limit(10)->get();
        $read = ReleaseNote::readIds($user, $notes->pluck('id')->all());
        $items = $notes->map(fn (ReleaseNote $n) => [
            'id' => $n->id,
            'app' => $n->app?->name ?? 'Every app',
            'version' => $n->version,
            'title' => $n->title,
            'body' => $n->body,
            'link' => $n->link,
            'at' => $n->published_at->toIso8601String(),
            'unread' => ! in_array($n->id, $read, true),
        ])->values()->all();

        return ['unread' => collect($items)->where('unread', true)->count(), 'items' => $items];
    }
}
