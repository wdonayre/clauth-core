<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Grant;
use App\Models\User;

/** Builds the per-app `orgs` claim (SPEC §6.2) and decides whether a user may use an app. */
class Claims
{
    /** Grants for this app in orgs that the app is enabled for. */
    public static function grants(User $user, Application $app)
    {
        $allowedOrgs = $app->organizations()->where('organizations.status', 'active')->whereNull('organizations.suspended_at')->pluck('organizations.id');

        return Grant::with(['organization', 'role.permissions'])
            ->where('user_id', $user->id)
            ->where('app_id', $app->id)
            ->whereIn('organization_id', $allowedOrgs)
            ->get();
    }

    public static function canUse(User $user, Application $app): bool
    {
        return $user->status === 'active' && $app->status !== 'paused' && ! $app->isSuspended() && self::grants($user, $app)->isNotEmpty();
    }

    /** ['cakesbygrace' => ['roles' => ['finance'], 'permissions' => [...]], ...] */
    public static function orgs(User $user, Application $app): array
    {
        $out = [];
        foreach (self::grants($user, $app) as $grant) {
            $slug = $grant->organization->slug;
            $out[$slug]['roles'][] = $grant->role->key;
            $out[$slug]['permissions'] = array_values(array_unique(array_merge(
                $out[$slug]['permissions'] ?? [],
                $grant->role->permissionKeys(),
            )));
            sort($out[$slug]['permissions']);
        }
        ksort($out);

        return $out;
    }

    /** Does this user have to pass 2-step verification (SPEC §6.3)? */
    public static function mfaRequired(User $user, ?Application $app = null): bool
    {
        return $user->hasTwoFactor() || self::policyRequiresMfa($user, $app);
    }

    /** Policy alone (ignores whether the user already enrolled). */
    public static function policyRequiresMfa(User $user, ?Application $app = null): bool
    {
        if ($user->isAdmin()) {
            return true; // the admin console always needs 2-step; admins can't be exempted
        }
        if ($user->mfa_exempt) {
            return false; // an admin turned 2-step off for this user (overrides every policy below)
        }
        if (Settings::get('require_mfa_everyone')) {
            return true;
        }
        if (session('invite_require_mfa')) {
            return true;
        }
        $apps = $app ? collect([$app]) : Application::whereNotNull('require_mfa_roles')->get();
        foreach ($apps as $a) {
            $roles = $a->require_mfa_roles ?? [];
            if ($roles && Grant::where('user_id', $user->id)->where('app_id', $a->id)
                ->whereHas('role', fn ($q) => $q->whereIn('key', $roles))->exists()) {
                return true;
            }
        }

        return false;
    }
}
