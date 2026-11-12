<?php

namespace App\Http\Middleware;

use App\Models\Application;
use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;

/**
 * Admin console, always after 2-step verification (SPEC §10.9). Levels (SPEC-organizations §4):
 *   (none)  anyone with a console role: Clauth owner, org owner/admin/developer
 *   people  Clauth owner or org owner/admin (users, organizations, audit)
 *   apps    Clauth owner or org owner/admin/developer; on /apps/{app} routes the app must be published by one of
 *           their organizations
 *   owner   Clauth owner only (settings, keys, updates, creating organizations)
 * Anything outside the person's scope answers 404.
 */
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
        match ($level) {
            'owner' => abort_unless($user->isOwner(), 404),
            'people' => abort_unless($user->isOwner() || $user->adminOrgIds() !== [], 404),
            'apps' => abort_unless($user->isOwner() || $user->devOrgIds() !== [], 404),
            default => null,
        };
        if ($level === 'apps' && ($app = $request->route('app')) instanceof Application) {
            abort_unless($user->canManageApp($app), 404);
        }
        // Suspended (SPEC-organizations §10.4): the console is read-only for the organization's staff.
        if (! $user->isOwner() && ! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            $app = $request->route('app');
            $org = $request->route('org');
            $suspended = ($app instanceof Application && $app->isSuspended()) || ($org instanceof Organization && $org->isSuspended())
                || Organization::whereIn('id', $user->devOrgIds())->whereNull('suspended_at')->doesntExist();
            abort_if($suspended, 403, 'This organization or app is suspended. The console is read-only until Clauth restores it.');
        }

        return $next($request);
    }
}
