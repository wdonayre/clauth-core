<?php

namespace App\Services;

use App\Models\AccessProfile;
use App\Models\Application;
use App\Models\AppRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Join this app" for people who already have a Clauth account (SPEC-provisioning, join step).
 *
 * Open sign-up (SigninPolicies registration: open) used to help only brand-new people: someone already signed in to
 * Clauth who opened an open app hit "You don't have access to this app". Now they are offered the same thing a new
 * account gets: membership of the app's organization plus the policy's sign-up access profile, after one click.
 *
 * Never offered to someone who is already in the app's organization (an admin there decided what they have, and
 * taking access away must stick), to a suspended or inactive account, or when the email domain or the people limit
 * says no.
 */
class AppJoin
{
    /** What joining would give, or null when this person can't join this app themselves. */
    public static function offer(User $user, Application $app): ?array
    {
        $org = $app->publisher;
        if (! $org || $user->status !== 'active' || $app->isSuspended() || $app->status === 'paused' || $org->isSuspended()) {
            return null;
        }
        $r = SigninPolicies::rules($app);
        if ($r['registration'] !== 'open' || ! $r['open_profile']) {
            return null;
        }
        if ($r['open_domains'] && ! in_array(Str::after(mb_strtolower($user->email), '@'), $r['open_domains'], true)) {
            return null;
        }
        if ($user->organizations()->where('organizations.id', $org->id)->exists()) {
            return null;
        }
        $profile = AccessProfile::where('organization_id', $org->id)->where('key', $r['open_profile'])->first();
        $roleId = $profile ? DB::table('access_profile_roles')->where('access_profile_id', $profile->id)->where('app_id', $app->id)->value('app_role_id') : null;
        $role = $roleId ? AppRole::find($roleId) : null;
        if (! $role || $role->tier === 'owner' || Limits::exceeded($org, 'people')) {
            return null;
        }

        return ['org' => $org, 'profile' => $profile, 'role' => $role];
    }

    /** Join: membership + the sign-up profile, then access is worked out as for anyone else. False if no longer offered. */
    public static function join(User $user, Application $app): bool
    {
        $offer = self::offer($user, $app);
        if (! $offer) {
            return false;
        }
        ['org' => $org, 'profile' => $profile] = $offer;
        DB::transaction(function () use ($user, $app, $org, $profile) {
            $user->organizations()->attach($org->id, ['joined_at' => now(), 'role' => 'member']);
            $user->accessProfiles()->syncWithoutDetaching([$profile->id => ['assigned_by' => null, 'assigned_at' => now()]]);
            Access::resolve($user, $org, 'joined '.$app->key, null);
            Audit::log('user.joined_app', 'admin', $user, after: ['app' => $app->key, 'profile' => $profile->key], appId: $app->id, orgId: $org->id, actorId: $user->id, actorType: 'user');
        });

        return true;
    }
}
