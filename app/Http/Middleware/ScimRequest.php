<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Marks /scim/v2 requests so errors come back in SCIM's format (App\Api\Problem::scim). */
class ScimRequest
{
    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set('clauth_scim', true);
        $request->attributes->set('clauth_api', true);

        return $next($request);
    }
}
