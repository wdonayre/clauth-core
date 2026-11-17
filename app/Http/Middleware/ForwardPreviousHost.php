<?php

namespace App\Http\Middleware;

use App\Services\Domains;
use Closure;
use Illuminate\Http\Request;

/** Clauth's former main address (config clauth.previous_hosts): every request moves to the same path on the current one. 308 keeps POSTs intact. */
class ForwardPreviousHost
{
    public function handle(Request $request, Closure $next)
    {
        $host = strtolower($request->getHost());
        if (Domains::isPrevious($host) && ! Domains::isMain($host)) {
            return redirect()->away(Domains::mainUrl(ltrim($request->getRequestUri(), '/')), 308);
        }

        return $next($request);
    }
}
