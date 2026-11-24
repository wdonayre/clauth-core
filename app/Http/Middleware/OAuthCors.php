<?php

namespace App\Http\Middleware;

use App\Models\OAuthClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * CORS for browser apps (SPA, @clauth/js) on the OAuth endpoints they call directly: token, revoke, userinfo,
 * discovery and JWKS. Discovery and JWKS are public; the others answer only origins of a registered redirect URL
 * (web or public clients), never with credentials. SPEC-platform §10.
 */
class OAuthCors
{
    private const PUBLIC = ['.well-known/openid-configuration', '.well-known/jwks.json', '.well-known/oauth-authorization-server'];

    private const CLIENT = ['oauth/token', 'oauth/revoke', 'userinfo'];

    public function handle(Request $request, Closure $next)
    {
        $path = ltrim($request->path(), '/');
        $origin = (string) $request->headers->get('Origin');
        $public = in_array($path, self::PUBLIC, true);
        if (! $origin || (! $public && ! in_array($path, self::CLIENT, true))) {
            return $next($request);
        }
        $allowed = $public || in_array(strtolower(rtrim($origin, '/')), self::origins(), true);
        if ($request->isMethod('OPTIONS')) {
            $r = response('', 204);
        } else {
            $r = $next($request);
        }
        if ($allowed) {
            $r->headers->set('Access-Control-Allow-Origin', $public ? '*' : $origin);
            $r->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $r->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type');
            $r->headers->set('Access-Control-Max-Age', '600');
            if (! $public) {
                $r->headers->set('Vary', 'Origin');
            }
        }

        return $r;
    }

    /** Origins of every registered redirect URL of web and public clients (cached 5 minutes). */
    public static function origins(): array
    {
        return Cache::remember('oauth:cors-origins', 300, fn () => OAuthClient::whereIn('type', ['web', 'public'])->where('revoked', false)->get()
            ->flatMap(fn ($c) => array_merge($c->redirect_uris ?? [], $c->post_logout_redirect_uris ?? []))
            ->map(fn ($u) => preg_match('#^(https?://[^/]+)#i', (string) $u, $m) ? strtolower($m[1]) : null)->filter()->unique()->values()->all());
    }
}
