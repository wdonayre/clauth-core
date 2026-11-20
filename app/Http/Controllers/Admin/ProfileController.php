<?php

namespace App\Http\Controllers\Admin;

use App\Models\AccessProfile;
use App\Models\AppRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Organization › Access profiles (SPEC-roles §9.1). Org owners and admins; owner-tier roles need an org owner. */
class ProfileController
{
    use Scope;

    private function org(string $org): Organization
    {
        abort_unless($this->canManageOrg($org), 404);

        return Organization::findOrFail($org);
    }

    private function profile(Organization $org, string $profile): AccessProfile
    {
        abort_unless(ctype_digit($profile), 404);

        return AccessProfile::where('organization_id', $org->id)->findOrFail((int) $profile);
    }

    public function index(string $org)
    {
        $org = $this->org($org);
        $apps = $org->apps()->get();
        $profiles = $org->accessProfiles()->withCount('users')->get();
        $mapped = DB::table('access_profile_roles')->whereIn('access_profile_id', $profiles->pluck('id'))
            ->select('access_profile_id', DB::raw('count(*) as n'))->groupBy('access_profile_id')->pluck('n', 'access_profile_id');

        return view('admin.profiles.index', ['org' => $org, 'profiles' => $profiles, 'apps' => $apps, 'mapped' => $mapped]);
    }

    public function store(Request $request, string $org)
    {
        $org = $this->org($org);
        $data = $request->validate(['name' => 'required|string|max:80', 'description' => 'nullable|string|max:190']);
        $base = Str::slug($data['name']) ?: 'profile';
        $key = $base;
        for ($i = 2; AccessProfile::where('organization_id', $org->id)->where('key', $key)->exists(); $i++) {
            $key = "$base-$i";
        }
        $p = AccessProfile::create($data + ['organization_id' => $org->id, 'key' => $key, 'created_by' => $request->user()->id,
            'position' => AccessProfile::where('organization_id', $org->id)->count()]);
        Audit::log('profile.created', 'role', $p, after: $p->only('key', 'name'), orgId: $org->id);

        return redirect()->route('admin.profiles.show', [$org->id, $p->id])->with('status', "Profile “{$p->name}” created. Pick a role for each app.");
    }

    public function show(string $org, string $profile)
    {
        $org = $this->org($org);
        $p = $this->profile($org, $profile);
        $me = auth()->user();
        $holders = $p->users()->orderBy('name')->get();

        return view('admin.profiles.show', [
            'org' => $org, 'profile' => $p, 'mapping' => $p->mapping(),
            'apps' => $org->apps()->with(['roles' => fn ($q) => $q->orderBy('position')])->orderBy('name')->get(),
            'holders' => $holders,
            'members' => $org->users()->whereNotIn('users.id', $holders->pluck('id'))->where('status', '!=', 'disabled')->orderBy('name')->get(),
            'canOwner' => Access::canTouchOwnerTier($me, $org->id),
            'tiers' => AppRole::TIERS,
        ]);
    }

    /** Name, description and the role per app. A select that isn't sent (disabled owner-tier one) stays as it is. */
    public function update(Request $request, string $org, string $profile)
    {
        $org = $this->org($org);
        $p = $this->profile($org, $profile);
        $data = $request->validate(['name' => 'required|string|max:80', 'description' => 'nullable|string|max:190', 'map' => 'array']);
        $me = $request->user();
        $canOwner = Access::canTouchOwnerTier($me, $org->id);
        $before = $p->mapping();
        $changes = [];
        foreach ($org->apps()->with('roles')->get() as $app) {
            if (! array_key_exists($app->id, $data['map'] ?? [])) {
                continue;
            }
            $choice = (string) $data['map'][$app->id];   // '' = not mapped, 'none' = No access, else a role id
            $had = array_key_exists($app->id, $before) ? ($before[$app->id] ?? 'none') : '';
            $role = ctype_digit($choice) ? $app->roles->firstWhere('id', (int) $choice) : null;
            abort_if(ctype_digit($choice) && ! $role, 422, 'That role is not one of the app’s roles.');
            $now = $role?->id ?? ($choice === 'none' ? 'none' : '');
            if ((string) $now === (string) $had) {
                continue;
            }
            $oldRole = is_numeric($had) ? $app->roles->firstWhere('id', (int) $had) : null;
            abort_if(($role?->tier === 'owner' || $oldRole?->tier === 'owner') && ! $canOwner, 403, "Only an owner of {$org->name} can map an Owner-tier role.");
            if ($role) {
                abort_if(! $role->tier, 422, "Set a tier on {$app->name}’s {$role->name} role first (App › Roles).");
                abort_if(! $role->assignable, 422, "{$app->name}’s {$role->name} role can no longer be given to people.");
            }
            if ($now === '') {
                DB::table('access_profile_roles')->where('access_profile_id', $p->id)->where('app_id', $app->id)->delete();
            } else {
                DB::table('access_profile_roles')->updateOrInsert(['access_profile_id' => $p->id, 'app_id' => $app->id],
                    ['app_role_id' => $role?->id, 'updated_by' => $me->id, 'updated_at' => now()]);
            }
            $changes[$app->key] = ['from' => $oldRole?->key ?? ($had === 'none' ? 'none' : null), 'to' => $role?->key ?? ($now === 'none' ? 'none' : null)];
        }
        $p->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);
        if ($changes) {
            Audit::log('profile.mapping_changed', 'role', $p, null, $changes, orgId: $org->id);
            $n = Access::resolveHolders($p, "profile {$p->key} changed", $me);
        }

        return back()->with('status', 'Profile saved.'.($changes ? ' '.($n ?? 0).' access change(s) for the people who hold it; apps pick them up within 15 minutes.' : ''));
    }

    public function destroy(Request $request, string $org, string $profile)
    {
        $org = $this->org($org);
        $p = $this->profile($org, $profile);
        abort_if($p->hasOwnerTier() && ! Access::canTouchOwnerTier($request->user(), $org->id), 403, "Only an owner of {$org->name} can delete a profile with an Owner-tier role.");
        $holders = $p->users()->get();
        $p->delete();
        foreach ($holders as $u) {
            Access::resolve($u, $org, "profile {$p->key} deleted", $request->user());
        }
        Audit::log('profile.deleted', 'role', $p, before: $p->only('key', 'name') + ['holders' => $holders->count()], orgId: $org->id);

        return redirect()->route('admin.profiles.index', $org->id)->with('status', "Profile “{$p->name}” deleted.");
    }

    public function addPerson(Request $request, string $org, string $profile)
    {
        $org = $this->org($org);
        $p = $this->profile($org, $profile);
        $user = $org->users()->where('users.id', $request->input('user_id'))->firstOrFail();
        $ids = $user->accessProfiles()->where('organization_id', $org->id)->pluck('access_profiles.id')->push($p->id)->all();
        Access::setProfiles($user, $org, $ids, $request->user());

        return back()->with('status', "{$user->name} now has the {$p->name} profile.");
    }

    public function removePerson(Request $request, string $org, string $profile, string $user)
    {
        $org = $this->org($org);
        $p = $this->profile($org, $profile);
        $u = User::findOrFail($user);
        $ids = $u->accessProfiles()->where('organization_id', $org->id)->pluck('access_profiles.id')->reject(fn ($id) => $id === $p->id)->all();
        Access::setProfiles($u, $org, $ids, $request->user());

        return back()->with('status', "{$u->name} no longer has the {$p->name} profile.");
    }
}
