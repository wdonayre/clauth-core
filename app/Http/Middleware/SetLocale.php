<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Language of the sign-in pages (SPEC-platform §8.5): ?lang= (remembered in a cookie and on the account), else the
 * person's saved language, else the cookie, else the browser's Accept-Language (Tagalog counts as Filipino).
 */
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $supported = array_keys(config('clauth.locales'));
        $pick = null;
        if (in_array($q = (string) $request->query('lang'), $supported, true)) {
            $pick = $q;
            Cookie::queue(Cookie::make('clauth_lang', $q, 60 * 24 * 365, null, null, null, true, false, 'lax'));
            if (($u = $request->user()) && $u->locale !== $q) {
                $u->forceFill(['locale' => $q])->save();
            }
        }
        $pick ??= in_array($request->user()?->locale, $supported, true) ? $request->user()->locale : null;
        $pick ??= in_array($c = (string) $request->cookie('clauth_lang'), $supported, true) ? $c : null;
        if (! $pick) {
            foreach ($request->getLanguages() as $l) {
                $l = strtolower(str_replace('_', '-', $l));
                $base = explode('-', $l)[0];
                $pick = in_array($base, ['fil', 'tl'], true) ? 'fil' : (in_array($base, $supported, true) ? $base : null);
                if ($pick) {
                    break;
                }
            }
        }
        app()->setLocale($pick ?: 'en');

        return $next($request);
    }
}
