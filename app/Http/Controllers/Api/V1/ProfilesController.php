<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiContext;
use App\Api\Etag;
use App\Api\Present;
use App\Models\AccessProfile;
use App\Models\AppRole;
use App\Models\Organization;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Limits;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** /api/v1/orgs/{org}/profiles — access profiles (SPEC-roles), the API's "groups" (SPEC-platform decision 1). */
class ProfilesController
{
    use UsesApi;

    public function index(Request $request, string $org)
    {
        $o = $this->api()->org($org);

        return Present::page(AccessProfile::with('organization')->where('organization_id', $o->id), fn ($p) => Present::profile($p, true), $request);
    }

    public function show(string $org, string $profile)
    {
        $o = $this->api()->org($org);

        $p = $this->api()->profile($o, $profile)->load('organization');

        return Etag::attach(response()->json(Present::profile($p, true)), $p);
    }

    /** {name, description?, key?, apps?: {app_key: role_key|"none"}} */
    public function store(Request $request, string $org)
    {
        $o = $this->api()->org($org);
        $data = $request->validate(['name' => 'required|string|max:80', 'description' => 'nullable|string|max:190',
            'key' => 'nullable|string|max:60|regex:/^[a-z0-9][a-z0-9-]*$/', 'apps' => 'array', 'apps.*' => 'nullable|string']);
        if ($msg = Limits::exceeded($o, 'profiles')) {
            abort(429, $msg);
        }
        $key = $data['key'] ?? null;
        abort_if($key && AccessProfile::where('organization_id', $o->id)->where('key', $key)->exists(), 409, "A profile with key {$key} already exists.");
        if (! $key) {
            $base = Str::slug($data['name']) ?: 'profile';
            $key = $base;
            for ($i = 2; AccessProfile::where('organization_id', $o->id)->where('key', $key)->exists(); $i++) {
                $key = "$base-$i";
            }
        }
        $p = AccessProfile::create(['organization_id' => $o->id, 'key' => $key, 'name' => $data['name'], 'description' => $data['description'] ?? null,
            'created_by' => $this->api()->actor->id, 'position' => AccessProfile::where('organization_id', $o->id)->count()]);
        Audit::log('profile.created', 'role', $p, after: $p->only('key', 'name'), orgId: $o->id);
        $this->map($o, $p, $data['apps'] ?? []);

        return response()->json(Present::profile($p->load('organization'), true), 201);
    }

    /** PATCH {name?, description?, apps?: {app_key: role_key|"none"|null}} — null clears the app (not set). */
    public function update(Request $request, string $org, string $profile)
    {
        $o = $this->api()->org($org);
        $p = $this->api()->profile($o, $profile);
        Etag::check($p);
        $data = $request->validate(['name' => 'sometimes|required|string|max:80', 'description' => 'sometimes|nullable|string|max:190', 'apps' => 'sometimes|array', 'apps.*' => 'nullable|string']);
        $p->update(array_intersect_key($data, array_flip(['name', 'description'])));
        $this->map($o, $p, $data['apps'] ?? []);

        $p->touch();

        return Etag::attach(response()->json(Present::profile($p->fresh('organization'), true)), $p->fresh());
    }

    public function destroy(string $org, string $profile)
    {
        $o = $this->api()->org($org);
        $p = $this->api()->profile($o, $profile);
        abort_if($p->hasOwnerTier() && ! Access::canTouchOwnerTier($this->api()->actor, $o->id), 403, "Only an owner of {$o->name} can delete a profile with an Owner-tier role.");
        Etag::check($p);
        $holders = $p->users()->get();
        $p->delete();
        foreach ($holders as $u) {
            Access::resolve($u, $o, "profile {$p->key} deleted", $this->api()->actor);
        }
        Audit::log('profile.deleted', 'role', $p, before: $p->only('key', 'name') + ['holders' => $holders->count()], orgId: $o->id);

        return response()->noContent();
    }

    public function addPerson(string $org, string $profile, string $user)
    {
        $o = $this->api()->org($org);
        $p = $this->api()->profile($o, $profile);
        $u = $this->api()->user($user);
        abort_unless($u->organizations()->where('organizations.id', $o->id)->exists(), 404, 'Not a member of this organization.');
        Access::setProfiles($u, $o, $u->accessProfiles()->where('organization_id', $o->id)->pluck('access_profiles.id')->push($p->id)->all(), $this->api()->actor);

        return response()->json(Present::profile($p->fresh('organization'), true));
    }

    public function removePerson(string $org, string $profile, string $user)
    {
        $o = $this->api()->org($org);
        $p = $this->api()->profile($o, $profile);
        $u = $this->api()->user($user);
        Access::setProfiles($u, $o, $u->accessProfiles()->where('organization_id', $o->id)->pluck('access_profiles.id')->reject(fn ($id) => $id === $p->id)->all(), $this->api()->actor);

        return response()->noContent();
    }

    /** Apply {app_key: role_key|"none"|null} through Access::mapApp, then re-resolve holders once. */
    private function map(Organization $o, AccessProfile $p, array $apps): void
    {
        $changes = [];
        foreach ($apps as $appKey => $roleKey) {
            $app = $o->apps()->with('roles')->where('apps.key', $appKey)->first();
            abort_unless($app, 422, "{$appKey} is not installed in {$o->slug}.");
            $choice = match (true) {
                $roleKey === null || $roleKey === '' => '',
                $roleKey === 'none' => 'none',
                default => (string) (AppRole::where('app_id', $app->id)->where('key', $roleKey)->value('id') ?? abort(422, "{$roleKey} is not a role of {$appKey}.")),
            };
            if ($c = Access::mapApp($p, $app, $choice, $this->api()->actor)) {
                $changes[$app->key] = $c;
            }
        }
        if ($changes) {
            Audit::log('profile.mapping_changed', 'role', $p, null, $changes, orgId: $o->id);
            Access::resolveHolders($p, "profile {$p->key} changed", $this->api()->actor);
        }
    }
}
