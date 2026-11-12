<?php

namespace App\Http\Middleware;

use App\Models\AppDomain;
use App\Services\Domains;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Clauth answers on its main domain and on apps' own sign-in domains (e.g. auth.cebpos.com).
 * On an app domain only the person-facing pages are served, styled for that app; links and emails point back
 * at that domain. Everything else (admin, My account, token/JWKS/API) lives on the main domain only.
 * Unknown hosts get a 404, so a forged Host header can never mint links to someone else's domain.
 */
class ServeCustomDomain
{
    /** Paths an app's domain may serve. */
    private const ALLOWED = '#^(login(/.*)?|logout|forgot-password|reset-password(/.*)?|invite/[^/]+|oauth/authorize|oauth/logout|signout-everywhere|privacy|terms|help|sso/accept|apps/[^/]+/logo|up)$#';

    public function handle(Request $request, Closure $next)
    {
        $host = strtolower($request->getHost());
        URL::forceRootUrl($request->getSchemeAndHttpHost());
        $request->attributes->remove('clauth.domain');

        if (Domains::isMain($host) || in_array($host, ['localhost', '127.0.0.1'], true)) {
            return $next($request);
        }

        // Ownership proof for the verification check (served before the domain is live).
        if (preg_match('#^\.well-known/clauth-domain/([a-z0-9]{32})$#', $request->path(), $m)) {
            $d = AppDomain::where('host', $host)->where('verify_token', $m[1])->first();

            return $d ? response($d->proofBody(), 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store'])
                : response('Not found', 404, ['Content-Type' => 'text/plain']);
        }

        $domain = Domains::activeFor($host);
        if (! $domain) {
            return response('This sign-in address isn’t set up.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $path = trim($request->path(), '/');
        if ($path === '') {
            return redirect('/login');
        }
        if (! preg_match(self::ALLOWED, $path)) {
            // My account, admin, API… live on the main domain.
            return $request->isMethod('GET') && ! str_starts_with($path, 'api/')
                ? redirect()->away(Domains::mainUrl($request->getRequestUri()))
                : response('Not found', 404, ['Content-Type' => 'text/plain']);
        }
        $request->attributes->set('clauth.domain', $domain);

        return $next($request);
    }
}
