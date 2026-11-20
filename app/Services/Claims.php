<?php

namespace App\Services;

use App\Models\AppRole;
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
            // The role's key plus any old keys it replaced, so an app still checking an old name keeps working.
            $out[$slug]['roles'] = array_values(array_unique(array_merge($out[$slug]['roles'] ?? [], $grant->role->tokenKeys())));
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
        if (Settings::get('require_mfa_admin_tiers') && self::holdsMfaTier($user, $app)) {
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

    /** Does the user hold an owner- or admin-tier role (in this app, or in any app)? (SPEC-roles §10.2) */
    public static function holdsMfaTier(User $user, ?Application $app = null): bool
    {
        return Grant::where('user_id', $user->id)
            ->when($app, fn ($q) => $q->where('app_id', $app->id))
            ->whereHas('role', fn ($q) => $q->whereIn('tier', AppRole::MFA_TIERS))
            ->exists();
    }

    /**
     * People who would newly have to use 2-step if the tier rule were turned on: they hold an owner/admin-tier role,
     * no current rule requires 2-step for them yet, and they are not exempt. Used for the preview in Settings.
     */
    public static function tierRulePreview()
    {
        return User::where('status', '!=', 'disabled')->where('mfa_exempt', false)
            ->whereHas('grants', fn ($g) => $g->whereHas('role', fn ($q) => $q->whereIn('tier', AppRole::MFA_TIERS)))
            ->with(['grants.role', 'grants.app'])->orderBy('name')->get()
            ->filter(fn (User $u) => ! self::policyWithoutTierRule($u))
            ->values();
    }

    private static function policyWithoutTierRule(User $user): bool
    {
        if ($user->isAdmin() || Settings::get('require_mfa_everyone')) {
            return true;
        }
        foreach (Application::whereNotNull('require_mfa_roles')->get() as $a) {
            $roles = $a->require_mfa_roles ?? [];
            if ($roles && Grant::where('user_id', $user->id)->where('app_id', $a->id)
                ->whereHas('role', fn ($q) => $q->whereIn('key', $roles))->exists()) {
                return true;
            }
        }

        return false;
    }
}
