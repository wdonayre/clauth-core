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

    /** New organizations start with an Owner and a Member profile (SPEC-roles §9.1). Returns [owner, member]. */
    public static function starterProfiles(Organization $org, ?User $actor = null): array
    {
        $out = [];
        foreach (['owner' => ['Owner', 'Runs the organization: the top role in every app.'], 'member' => ['Member', 'Everyday access in every app.']] as $key => [$name, $desc]) {
            $p = AccessProfile::firstOrCreate(['organization_id' => $org->id, 'key' => $key],
                ['name' => $name, 'description' => $desc, 'position' => count($out), 'created_by' => $actor?->id]);
            foreach ($org->apps()->with('roles')->get() as $app) {
                if (! array_key_exists($app->id, $p->mapping()) && ($role = self::suggestFor($p, $app))) {
                    DB::table('access_profile_roles')->insert(['access_profile_id' => $p->id, 'app_id' => $app->id, 'app_role_id' => $role->id, 'updated_at' => now()]);
                }
            }
            $out[] = $p;
        }

        return $out;
    }

    /**
     * Suggested role in $app for $profile (SPEC-roles §9.2): the app's first assignable role at the profile's highest
     * tier so far (or the tier its key names, e.g. the starter "owner"); if the app has none at that tier, its best
     * role below it. Null = no suggestion.
     */
    public static function suggestFor(AccessProfile $profile, \App\Models\Application $app): ?AppRole
    {
        $ranks = array_keys(AppRole::TIERS);
        $mapped = AppRole::whereIn('id', array_filter($profile->mapping()))->whereNotNull('tier')->get();
        $tier = $mapped->isNotEmpty() ? self::rank($mapped)->first()->tier : (in_array($profile->key, $ranks, true) ? $profile->key : null);
        if (! $tier) {
            return null;
        }
        $want = array_search($tier, $ranks, true);

        return self::rank($app->roles->filter(fn (AppRole $r) => $r->assignable && $r->tier && $r->tierRank() >= $want))->first();
    }

    /**
     * Set one profile × app cell. $choice: '' = not set, 'none' = No access, or an app_role_id.
     * Returns ['from' => ..., 'to' => ...] when it changed, else null. Does not resolve holders.
     */
    public static function mapApp(AccessProfile $p, \App\Models\Application $app, string $choice, User $actor): ?array
    {
        $org = $p->organization;
        $before = $p->mapping();
        $had = array_key_exists($app->id, $before) ? ($before[$app->id] ?? 'none') : '';
        $role = ctype_digit($choice) ? $app->roles->firstWhere('id', (int) $choice) : null;
        abort_if(ctype_digit($choice) && ! $role, 422, 'That role is not one of the app’s roles.');
        $now = $role?->id ?? ($choice === 'none' ? 'none' : '');
        if ((string) $now === (string) $had) {
            return null;
        }
        $oldRole = is_numeric($had) ? $app->roles->firstWhere('id', (int) $had) : null;
        abort_if(($role?->tier === 'owner' || $oldRole?->tier === 'owner') && ! self::canTouchOwnerTier($actor, $org->id), 403, "Only an owner of {$org->name} can map an Owner-tier role.");
        if ($role) {
            abort_if(! $role->tier, 422, "Set a tier on {$app->name}’s {$role->name} role first (App › Roles).");
            abort_if(! $role->assignable, 422, "{$app->name}’s {$role->name} role can no longer be given to people.");
        }
        if ($now === '') {
            DB::table('access_profile_roles')->where('access_profile_id', $p->id)->where('app_id', $app->id)->delete();
        } else {
            DB::table('access_profile_roles')->updateOrInsert(['access_profile_id' => $p->id, 'app_id' => $app->id],
                ['app_role_id' => $role?->id, 'updated_by' => $actor->id, 'updated_at' => now()]);
        }

        return ['from' => $oldRole?->key ?? ($had === 'none' ? 'none' : null), 'to' => $role?->key ?? ($now === 'none' ? 'none' : null)];
    }

    /** Installed apps that at least one of the org's profiles hasn't set yet: [app_id => [app, profiles (not set)]]. */
    public static function unmappedApps(Organization $org): array
    {
        $profiles = $org->accessProfiles()->get();
        $rows = DB::table('access_profile_roles')->whereIn('access_profile_id', $profiles->pluck('id'))->get();
        $out = [];
        foreach ($org->apps()->orderBy('name')->get() as $app) {
            $missing = $profiles->reject(fn ($p) => $rows->contains(fn ($r) => $r->access_profile_id === $p->id && $r->app_id === $app->id))->values();
            if ($missing->isNotEmpty()) {
                $out[$app->id] = ['app' => $app, 'profiles' => $missing];
            }
        }

        return $out;
    }

    /**
     * Suggest profiles from today's access (SPEC-roles §11 step 3): people in $org with no profile yet, grouped by
     * identical app → role sets. Each: ['key' (signature hash), 'name', 'map' [app_id => role_id|null], 'roles', 'people',
     * 'blocked' (why it can't be accepted yet, or null)]. Biggest groups first.
     */
    public static function suggestions(Organization $org, User $actor): array
    {
        $apps = $org->apps()->with('roles')->orderBy('name')->get();
        $withProfiles = DB::table('access_profile_user')->join('access_profiles', 'access_profiles.id', '=', 'access_profile_user.access_profile_id')
            ->where('access_profiles.organization_id', $org->id)->pluck('user_id')->unique();
        $people = $org->users()->where('status', '!=', 'disabled')->whereNotIn('users.id', $withProfiles)->orderBy('name')->get();
        $grants = Grant::where('organization_id', $org->id)->whereIn('user_id', $people->pluck('id'))->get()->groupBy('user_id');
        $groups = [];
        foreach ($people as $u) {
            $map = [];
            foreach ($apps as $app) {
                $map[$app->id] = optional($grants->get($u->id)?->firstWhere('app_id', $app->id))->app_role_id;
            }
            if (! array_filter($map)) {
                continue; // no access anywhere: nothing to suggest
            }
            $sig = sha1(json_encode($map));
            $groups[$sig] ??= ['key' => $sig, 'map' => $map, 'people' => collect()];
            $groups[$sig]['people']->push($u);
        }
        $out = [];
        foreach ($groups as $g) {
            $roles = collect();
            foreach ($apps as $app) {
                if ($id = $g['map'][$app->id]) {
                    $r = $app->roles->firstWhere('id', $id);
                    $r?->setRelation('app', $app);
                    $roles->push($r);
                }
            }
            $roles = self::rank($roles->filter());
            $top = $roles->filter(fn ($r) => $r->tier === $roles->first()->tier)->take(2);
            $blocked = $roles->first(fn ($r) => ! $r->tier) ? 'Set a tier on '.$roles->first(fn ($r) => ! $r->tier)->app->name.'’s '.$roles->first(fn ($r) => ! $r->tier)->name.' role first.'
                : ($roles->contains(fn ($r) => $r->tier === 'owner') && ! self::canTouchOwnerTier($actor, $org->id) ? 'Includes an Owner-tier role: only an owner of '.$org->name.' can accept it.' : null);
            $out[] = $g + ['roles' => $roles, 'blocked' => $blocked,
                'name' => $top->map(fn ($r) => $r->app->name.' '.$r->name)->implode(' + ')];
        }
        usort($out, fn ($a, $b) => [$b['people']->count(), $a['name']] <=> [$a['people']->count(), $b['name']]);

        return $out;
    }

    /**
     * Accept a suggestion: create the profile with that mapping (other apps: No access), give it to the group, and drop
     * each person's exceptions that the profile now covers. Nobody's access changes. Returns the profile.
     */
    public static function acceptSuggestion(Organization $org, string $key, string $name, User $actor): AccessProfile
    {
        $s = collect(self::suggestions($org, $actor))->firstWhere('key', $key);
        abort_unless($s, 404, 'That suggestion no longer applies — someone’s access changed. Refresh the page.');
        abort_if($s['blocked'], 422, $s['blocked']);
        $base = \Illuminate\Support\Str::slug($name) ?: 'profile';
        $pkey = $base;
        for ($i = 2; AccessProfile::where('organization_id', $org->id)->where('key', $pkey)->exists(); $i++) {
            $pkey = "$base-$i";
        }

        return DB::transaction(function () use ($org, $s, $name, $pkey, $actor) {
            $p = AccessProfile::create(['organization_id' => $org->id, 'key' => $pkey, 'name' => $name, 'created_by' => $actor->id,
                'description' => 'Suggested from current access', 'position' => AccessProfile::where('organization_id', $org->id)->count()]);
            foreach ($s['map'] as $appId => $roleId) {
                DB::table('access_profile_roles')->insert(['access_profile_id' => $p->id, 'app_id' => $appId, 'app_role_id' => $roleId, 'updated_by' => $actor->id, 'updated_at' => now()]);
            }
            Audit::log('profile.created', 'role', $p, after: ['key' => $p->key, 'name' => $p->name, 'from_suggestion' => true, 'people' => $s['people']->count()], orgId: $org->id);
            foreach ($s['people'] as $u) {
                $u->accessProfiles()->attach($p->id, ['assigned_by' => $actor->id, 'assigned_at' => now()]);
                foreach ($s['map'] as $appId => $roleId) {
                    GrantOverride::where(['user_id' => $u->id, 'organization_id' => $org->id, 'app_id' => $appId])
                        ->where(fn ($q) => $roleId ? $q->where('app_role_id', $roleId) : $q->whereNull('app_role_id'))->delete();
                }
                Audit::log('profile.assigned', 'role', $u, after: ['profile' => $p->key], orgId: $org->id);
                self::resolve($u, $org, 'suggested profile accepted', $actor);
            }

            return $p;
        });
    }
}
