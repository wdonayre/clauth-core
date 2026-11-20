<?php

namespace App\Services;

use App\Models\AccessProfile;
use App\Models\AppRole;
use App\Models\Grant;
use App\Models\GrantOverride;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Access profiles (SPEC-roles §6): works out each person's effective role per app from their profiles and overrides,
 * and writes it to `grants` — the only table tokens read. Nothing else should write grants.
 */
class Access
{
    /** Highest tier first; on equal tiers the role the publisher lists first. */
    public static function rank(Collection $roles): Collection
    {
        return $roles->unique('id')->sort(fn (AppRole $a, AppRole $b) => [$a->tierRank(), $a->position, $a->id] <=> [$b->tierRank(), $b->position, $b->id])->values();
    }

    /**
     * What this person gets in each app installed in $org, and why:
     * [app_id => ['app', 'role' (AppRole|null), 'source' (override|profile|null), 'override' (GrantOverride|null),
     *             'profileRole', 'via' (profile names giving profileRole), 'candidates', 'conflict' (bool), 'unmapped' (profile names)]]
     */
    public static function explain(User $user, Organization $org): array
    {
        $apps = $org->apps()->with('roles')->orderBy('name')->get();
        $profiles = $user->accessProfiles()->where('organization_id', $org->id)->orderBy('name')->get();
        $maps = DB::table('access_profile_roles')->whereIn('access_profile_id', $profiles->pluck('id'))->get();
        $overrides = GrantOverride::where('user_id', $user->id)->where('organization_id', $org->id)->get()->keyBy('app_id');

        $out = [];
        foreach ($apps as $app) {
            $roles = $app->roles->keyBy('id');
            $from = [];
            $unmapped = [];
            foreach ($profiles as $p) {
                $row = $maps->first(fn ($m) => $m->access_profile_id === $p->id && $m->app_id === $app->id);
                if (! $row) {
                    $unmapped[] = $p->name;
                } elseif ($row->app_role_id && $roles->has($row->app_role_id)) {
                    $from[] = ['profile' => $p->name, 'role' => $roles[$row->app_role_id]];
                }
            }
            $candidates = self::rank(collect($from)->pluck('role'));
            $profileRole = $candidates->first();
            $o = $overrides->get($app->id);
            $role = $o ? ($o->app_role_id ? $roles->get($o->app_role_id) : null) : $profileRole;
            $out[$app->id] = [
                'app' => $app, 'role' => $role, 'override' => $o,
                'source' => $o ? 'override' : ($role ? 'profile' : null),
                'profileRole' => $profileRole, 'candidates' => $candidates, 'conflict' => $candidates->count() > 1,
                'via' => collect($from)->filter(fn ($f) => $profileRole && $f['role']->id === $profileRole->id)->pluck('profile')->all(),
                'unmapped' => $unmapped,
            ];
        }

        return $out;
    }

    /** Bring one person's grants in $org in line with their profiles + overrides. Returns the changes made. */
    public static function resolve(User $user, Organization $org, string $cause, ?User $actor = null): array
    {
        $member = $user->organizations()->where('organizations.id', $org->id)->exists();
        $changes = [];
        foreach (self::explain($user, $org) as $appId => $e) {
            $want = $member ? $e['role'] : null;
            $g = Grant::with('role')->where(['user_id' => $user->id, 'app_id' => $appId, 'organization_id' => $org->id])->first();
            if (! $want) {
                if ($g) {
                    $g->delete();
                    Sessions::revokeApp($user, $appId);
                    $changes[] = ['app' => $e['app'], 'from' => $g->role?->key, 'to' => null];
                }

                continue;
            }
            if ($g && (int) $g->app_role_id === $want->id) {
                if ($g->source !== $e['source']) {
                    $g->update(['source' => $e['source'], 'resolved_at' => now()]);
                }

                continue;
            }
            Grant::updateOrCreate(['user_id' => $user->id, 'app_id' => $appId, 'organization_id' => $org->id], [
                'app_role_id' => $want->id, 'source' => $e['source'], 'resolved_at' => now(),
                'granted_by' => $actor?->id ?? $g?->granted_by, 'granted_at' => now(),
            ]);
            $changes[] = ['app' => $e['app'], 'from' => $g?->role?->key, 'to' => $want->key];
        }
        foreach ($changes as $c) {
            Audit::log('grant.resolved', 'role', $user, $c['from'] ? ['app' => $c['app']->key, 'role' => $c['from']] : null,
                ['app' => $c['app']->key, 'role' => $c['to'], 'cause' => $cause], $c['app']->id, $org->id, $actor?->id);
        }

        return $changes;
    }

    /** Re-resolve everyone holding $profile (after its mapping changed or before it is deleted). */
    public static function resolveHolders(AccessProfile $profile, string $cause, ?User $actor = null, ?Collection $users = null): int
    {
        $n = 0;
        foreach ($users ?? $profile->users()->get() as $u) {
            $n += count(self::resolve($u, $profile->organization, $cause, $actor));
        }

        return $n;
    }

    /** May $actor create/change anything involving an owner-tier role in $orgId? (SPEC-roles §10.1) */
    public static function canTouchOwnerTier(User $actor, string $orgId): bool
    {
        return $actor->isOwner() || in_array($orgId, $actor->ownedOrgIds(), true);
    }

    /**
     * Set the person's profiles in $org to exactly $profileIds (other orgs' profiles untouched), then resolve.
     * Adding or removing a profile with an owner-tier mapping needs an org owner.
     */
    public static function setProfiles(User $user, Organization $org, array $profileIds, User $actor): void
    {
        $valid = AccessProfile::where('organization_id', $org->id)->get()->keyBy('id');
        $want = collect($profileIds)->map(fn ($id) => (int) $id)->filter(fn ($id) => $valid->has($id))->unique()->values();
        $have = $user->accessProfiles()->where('organization_id', $org->id)->pluck('access_profiles.id');
        $added = $want->diff($have);
        $removed = $have->diff($want);
        foreach ($added->merge($removed) as $id) {
            abort_if($valid[$id]->hasOwnerTier() && ! self::canTouchOwnerTier($actor, $org->id), 403,
                "Only an owner of {$org->name} can give or take away the {$valid[$id]->name} profile (it includes an Owner-tier role).");
        }
        foreach ($added as $id) {
            $user->accessProfiles()->attach($id, ['assigned_by' => $actor->id, 'assigned_at' => now()]);
            Audit::log('profile.assigned', 'role', $user, after: ['profile' => $valid[$id]->key], orgId: $org->id);
        }
        foreach ($removed as $id) {
            $user->accessProfiles()->detach($id);
            Audit::log('profile.unassigned', 'role', $user, before: ['profile' => $valid[$id]->key], orgId: $org->id);
        }
        if ($added->isNotEmpty() || $removed->isNotEmpty()) {
            self::resolve($user, $org, 'profiles changed', $actor);
        }
    }

    /**
     * $choice: 'profile' (clear the override and follow profiles), 'none' (override: No access) or an app_role_id.
     * Returns true when something changed. Does not resolve (call resolve() once after all apps).
     */
    public static function setOverride(User $user, Organization $org, string $appId, string $choice, ?string $reason, User $actor): bool
    {
        $o = GrantOverride::with('role')->where(['user_id' => $user->id, 'organization_id' => $org->id, 'app_id' => $appId])->first();
        $roleId = ctype_digit($choice) ? (int) $choice : null;
        $role = $roleId ? AppRole::where('app_id', $appId)->findOrFail($roleId) : null;
        $now = $choice === 'profile' ? 'profile' : ($role?->id ?? 'none');
        $was = $o ? ($o->app_role_id ?? 'none') : 'profile';
        if ($now === $was) {
            return false;
        }
        $touchesOwner = $role?->tier === 'owner' || $o?->role?->tier === 'owner';
        abort_if($touchesOwner && ! self::canTouchOwnerTier($actor, $org->id), 403, "Only an owner of {$org->name} can give or take away an Owner-tier role.");
        if ($role && ! $role->assignable && $o?->app_role_id !== $role->id) {
            abort(422, "The {$role->name} role can no longer be given to people.");
        }
        if ($choice === 'profile') {
            $o->delete();
            Audit::log('grant.override_cleared', 'role', $user, ['app_id' => $appId, 'role' => $o->role?->key ?? 'none'], null, $appId, $org->id);
        } else {
            GrantOverride::updateOrCreate(['user_id' => $user->id, 'organization_id' => $org->id, 'app_id' => $appId],
                ['app_role_id' => $role?->id, 'reason' => $reason ?: null, 'set_by' => $actor->id, 'set_at' => now()]);
            Audit::log('grant.override_set', 'role', $user, $o ? ['role' => $o->role?->key ?? 'none'] : null,
                ['role' => $role?->key ?? 'none', 'reason' => $reason], $appId, $org->id);
        }

        return true;
    }

    /** Someone left $org: their profiles and overrides there go, then their grants (SPEC-roles §10.5). */
    public static function forgetMember(User $user, Organization $org): void
    {
        $user->accessProfiles()->detach(AccessProfile::where('organization_id', $org->id)->pluck('id'));
        GrantOverride::where('user_id', $user->id)->where('organization_id', $org->id)->delete();
        foreach (Grant::where(['user_id' => $user->id, 'organization_id' => $org->id])->get() as $g) {
            $g->delete();
            Sessions::revokeApp($user, $g->app_id);
        }
    }
}
