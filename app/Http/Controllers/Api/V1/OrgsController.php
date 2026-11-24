<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiContext;
use App\Api\Present;
use App\Models\AccessProfile;
use App\Models\AppRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Limits;
use Illuminate\Http\Request;

/** /api/v1/orgs — organizations, members and each member's access (SPEC-platform §6.1). */
class OrgsController
{
    use UsesApi;

    public function index(Request $request)
    {
        $q = Organization::query()->when($this->api()->orgIds() !== null, fn ($q) => $q->whereIn('id', $this->api()->orgIds()));

        return Present::page($q, fn (Organization $o) => Present::org($o), $request);
    }

    public function show(string $org)
    {
        $o = $this->api()->org($org);

        return response()->json(Present::org($o) + [
            'members' => $o->users()->count(),
            'apps' => $o->apps()->orderBy('key')->pluck('key'),
            'profiles' => $o->accessProfiles()->pluck('key'),
        ]);
    }

    public function members(Request $request, string $org)
    {
        $o = $this->api()->org($org);
        $q = User::query()->with('organizations')->whereHas('organizations', fn ($w) => $w->where('organizations.id', $o->id));

        return Present::page($q, fn (User $u) => Present::user($u, [$o->id]), $request);
    }

    /** PUT /orgs/{org}/members/{user} {role?} — add an existing person (by email or id) or change their org role. */
    public function putMember(Request $request, string $org, string $user)
    {
        $o = $this->api()->org($org);
        $data = $request->validate(['role' => 'nullable|in:member,developer,admin,owner']);
        $u = str_contains($user, '@') ? User::where('email', mb_strtolower($user))->first() : User::findBySub($user);
        abort_unless($u, 404, 'User not found. Invite new people with POST /invites.');
        $member = $u->organizations()->where('organizations.id', $o->id)->first();
        if (! $member) {
            if ($msg = Limits::exceeded($o, 'people')) {
                abort(429, $msg);
            }
            $u->organizations()->attach($o->id, ['joined_at' => now(), 'role' => 'member']);
            Audit::log('user.updated', 'admin', $u, after: ['joined_org' => $o->id], orgId: $o->id);
            Access::resolve($u, $o, 'joined organization', $this->api()->actor);
        }
        $want = $data['role'] ?? null;
        $was = $member?->pivot->role ?? 'member';
        if ($want && $want !== $was) {
            abort_unless(Access::canTouchOwnerTier($this->api()->actor, $o->id), 403, 'Only an owner of the organization can change org roles.');
            $u->organizations()->updateExistingPivot($o->id, ['role' => $want]);
            Audit::log('org.role_changed', 'admin', $u, ['role' => $was], ['role' => $want, 'org' => $o->slug], orgId: $o->id);
        }

        return response()->json(Present::user($u->fresh('organizations'), [$o->id]), $member ? 200 : 201);
    }

    public function removeMember(string $org, string $user)
    {
        $o = $this->api()->org($org);
        $u = $this->member($o, $user);
        abort_if($u->id === $this->api()->actor->id, 422, 'A client cannot remove the person who created it.');
        abort_if($u->orgRole($o->id) === 'owner' && ! Access::canTouchOwnerTier($this->api()->actor, $o->id), 403, 'Only an owner can remove an owner.');
        Access::forgetMember($u, $o);
        $u->organizations()->detach($o->id);
        Audit::log('user.updated', 'admin', $u, after: ['left_org' => $o->id], orgId: $o->id);

        return response()->noContent();
    }

    /** GET /orgs/{org}/members/{user}/access — what the person gets in each app of the org, and why. */
    public function access(string $org, string $user)
    {
        $o = $this->api()->org($org);
        $u = $this->member($o, $user);

        return response()->json($this->explain($u, $o));
    }

    /**
     * PUT /orgs/{org}/members/{user}/access {profiles?: [key], apps?: {app_key: "profile"|"none"|role_key}, reason?}
     * profiles = the exact set of profiles in this org; apps = per-app exceptions. Then the grants are worked out.
     */
    public function putAccess(Request $request, string $org, string $user)
    {
        $o = $this->api()->org($org);
        $u = $this->member($o, $user);
        $data = $request->validate(['profiles' => 'sometimes|array', 'profiles.*' => 'string', 'apps' => 'sometimes|array', 'apps.*' => 'string', 'reason' => 'nullable|string|max:190']);
        $me = $this->api()->actor;
        if (array_key_exists('profiles', $data)) {
            $ids = collect($data['profiles'])->map(fn ($k) => $this->api()->profile($o, $k)->id)->all();
            Access::setProfiles($u, $o, $ids, $me);
        }
        $changed = false;
        foreach ($data['apps'] ?? [] as $appKey => $choice) {
            $app = $o->apps()->where('apps.key', $appKey)->first();
            abort_unless($app, 422, "{$appKey} is not installed in {$o->slug}.");
            if (! in_array($choice, ['profile', 'none'], true)) {
                $role = AppRole::where('app_id', $app->id)->where('key', $choice)->first();
                abort_unless($role, 422, "{$choice} is not a role of {$appKey}.");
                $choice = (string) $role->id;
            }
            $changed = Access::setOverride($u, $o, $app->id, $choice, $data['reason'] ?? null, $me) || $changed;
        }
        if ($changed) {
            Access::resolve($u, $o, 'exceptions changed', $me);
        }

        return response()->json($this->explain($u, $o));
    }

    /** GET /orgs/{org}/alignment — people × apps with where each role comes from (Who has what). */
    public function alignment(Request $request, string $org)
    {
        $o = $this->api()->org($org);
        $apps = $o->apps()->orderBy('key')->get();
        $rows = $o->users()->where('status', '!=', 'disabled')->orderBy('name')->get()->map(fn (User $u) => [
            'user' => $u->sub(), 'email' => $u->email, 'name' => $u->name,
            'profiles' => $u->accessProfiles()->where('organization_id', $o->id)->pluck('key'),
            'apps' => collect(Access::explain($u, $o))->mapWithKeys(fn ($e) => [$e['app']->key => [
                'role' => $e['role']?->key, 'source' => $e['source'], 'conflict' => $e['conflict'], 'not_set_in' => $e['unmapped']]]),
        ]);
        if ($request->query('format') === 'csv' || str_contains((string) $request->header('Accept'), 'text/csv')) {
            $out = fopen('php://temp', 'r+');
            fputcsv($out, array_merge(['user', 'email', 'name', 'profiles'], $apps->pluck('key')->all()));
            foreach ($rows as $r) {
                fputcsv($out, array_merge([$r['user'], $r['email'], $r['name'], $r['profiles']->implode(' ')],
                    $apps->map(fn ($a) => ($r['apps'][$a->key]['role'] ?? '').(($r['apps'][$a->key]['source'] ?? null) === 'override' ? ' (exception)' : ''))->all()));
            }
            rewind($out);

            return response(stream_get_contents($out), 200, ['Content-Type' => 'text/csv']);
        }

        return response()->json(['org' => $o->slug, 'apps' => $apps->pluck('key'), 'data' => $rows]);
    }

    private function member(Organization $o, string $user): User
    {
        $u = $this->api()->user($user);
        abort_unless($u->organizations()->where('organizations.id', $o->id)->exists(), 404, 'Not a member of this organization.');

        return $u;
    }

    private function explain(User $u, Organization $o): array
    {
        return [
            'user' => $u->sub(), 'org' => $o->slug, 'org_role' => $u->orgRole($o->id),
            'profiles' => $u->accessProfiles()->where('organization_id', $o->id)->pluck('key'),
            'apps' => collect(Access::explain($u, $o))->mapWithKeys(fn ($e) => [$e['app']->key => [
                'role' => $e['role']?->key, 'source' => $e['source'], 'from_profiles' => $e['via'],
                'exception' => $e['override'] ? ['role' => $e['override']->role?->key ?? 'none', 'reason' => $e['override']->reason] : null,
                'conflict' => $e['conflict'], 'not_set_in' => $e['unmapped'],
            ]]),
        ];
    }
}
