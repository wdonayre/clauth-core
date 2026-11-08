<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Admin console: owners and org admins, always after 2-step verification (SPEC §10.9). */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next, ?string $level = null)
    {
        $user = $request->user();
        abort_unless($user && $user->isAdmin(), 404);
        if (! $request->session()->get('auth.mfa')) {
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('login.mfa');
        }
        if ($level === 'owner') {
            abort_unless($user->isOwner(), 404);
        }

        return $next($request);
    }
}
