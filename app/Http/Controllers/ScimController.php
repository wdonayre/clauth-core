<?php

namespace App\Http\Controllers;

use App\Api\ApiContext;
use App\Api\Problem;
use App\Models\AccessProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Invites;
use App\Services\Limits;
use App\Services\OrgData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * SCIM 2.0 (RFC 7643/7644) for one organization (SPEC-platform §9.2): HR systems, Google Workspace, Entra ID and Okta
 * create, update, switch off and remove the organization's people, and manage Groups = access profiles.
 * Auth: a SCIM token (Organization › API clients › SCIM provisioning), i.e. an org API client with scope `scim`.
 *
 * - A person who already has a Clauth account (another organization) is linked, never duplicated; their email and
 *   name can only be changed through SCIM when this is their only organization.
 * - active=false keeps the membership but removes all access in this organization (Access::resolve).
 * - DELETE removes the person from this organization only.
 */
class ScimController
{
    private const USER = 'urn:ietf:params:scim:schemas:core:2.0:User';

    private const GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    private const EXT = 'urn:ietf:params:scim:schemas:extension:clauth:2.0:User';

    private const LIST = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';

    private const PATCH = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

    private function api(): ApiContext
    {
        return app(ApiContext::class);
    }

    private function org(): Organization
    {
        $org = $this->api()->client->organization;
        abort_unless($org && ! $this->api()->isPersonal(), 403, 'SCIM tokens belong to one organization: make one under Organizations › API clients.');

        return $org;
    }

    private function out(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, ['Content-Type' => 'application/scim+json'], JSON_UNESCAPED_SLASHES);
    }

    private function base(): string
    {
        return rtrim(config('clauth.issuer'), '/').'/scim/v2';
    }

    // ---------------------------------------------------------------- discovery

    public function serviceProviderConfig()
    {
        return $this->out([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'documentationUri' => 'https://helloclauth.com/docs/api/#scim',
            'patch' => ['supported' => true], 'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => true, 'maxResults' => 200], 'changePassword' => ['supported' => false],
            'sort' => ['supported' => false], 'etag' => ['supported' => false],
            'authenticationSchemes' => [['type' => 'oauthbearertoken', 'name' => 'Bearer token', 'description' => 'SCIM token from Organizations › API clients', 'primary' => true]],
            'meta' => ['resourceType' => 'ServiceProviderConfig', 'location' => $this->base().'/ServiceProviderConfig'],
        ]);
    }

    public function resourceTypes()
    {
        $types = [
            ['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'], 'id' => 'User', 'name' => 'User', 'endpoint' => '/Users',
                'schema' => self::USER, 'schemaExtensions' => [['schema' => self::EXT, 'required' => false]], 'meta' => ['resourceType' => 'ResourceType', 'location' => $this->base().'/ResourceTypes/User']],
            ['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'], 'id' => 'Group', 'name' => 'Group', 'endpoint' => '/Groups',
                'description' => 'Access profiles', 'schema' => self::GROUP, 'meta' => ['resourceType' => 'ResourceType', 'location' => $this->base().'/ResourceTypes/Group']],
        ];

        return $this->out(['schemas' => [self::LIST], 'totalResults' => 2, 'itemsPerPage' => 2, 'startIndex' => 1, 'Resources' => $types]);
    }

    public function schemas()
    {
        $a = fn ($name, $type = 'string', $extra = []) => $extra + ['name' => $name, 'type' => $type, 'multiValued' => false, 'required' => false, 'mutability' => 'readWrite', 'returned' => 'default', 'uniqueness' => 'none'];
        $list = [
            ['id' => self::USER, 'name' => 'User', 'attributes' => [
                $a('userName', 'string', ['required' => true, 'uniqueness' => 'server']), $a('externalId'), $a('displayName'), $a('active', 'boolean'),
                $a('name', 'complex', ['subAttributes' => [$a('formatted'), $a('givenName'), $a('familyName')]]),
                $a('emails', 'complex', ['multiValued' => true, 'subAttributes' => [$a('value'), $a('type'), $a('primary', 'boolean')]]),
                $a('phoneNumbers', 'complex', ['multiValued' => true, 'subAttributes' => [$a('value'), $a('type')]]),
                $a('groups', 'complex', ['multiValued' => true, 'mutability' => 'readOnly', 'subAttributes' => [$a('value'), $a('display')]]),
            ]],
            ['id' => self::GROUP, 'name' => 'Group', 'description' => 'An access profile', 'attributes' => [
                $a('displayName', 'string', ['required' => true]),
                $a('members', 'complex', ['multiValued' => true, 'subAttributes' => [$a('value'), $a('display')]]),
            ]],
            ['id' => self::EXT, 'name' => 'ClauthUser', 'description' => 'The organization\'s teams and profile fields', 'attributes' => [
                $a('teams', 'string', ['multiValued' => true]), $a('fields', 'complex'),
            ]],
        ];
        foreach ($list as &$s) {
            $s['schemas'] = ['urn:ietf:params:scim:schemas:core:2.0:Schema'];
            $s['meta'] = ['resourceType' => 'Schema', 'location' => $this->base().'/Schemas/'.$s['id']];
        }

        return $this->out(['schemas' => [self::LIST], 'totalResults' => count($list), 'itemsPerPage' => count($list), 'startIndex' => 1, 'Resources' => $list]);
    }

    // ---------------------------------------------------------------- users

    private function members(Organization $org)
    {
        return User::query()->whereHas('organizations', fn ($o) => $o->where('organizations.id', $org->id))
            ->with(['organizations' => fn ($o) => $o->where('organizations.id', $org->id)]);
    }

    private function findUser(Organization $org, string $id): User
    {
        $u = str_starts_with($id, 'usr_') ? $this->members($org)->whereKey(substr($id, 4))->first() : null;
        abort_unless($u, 404, 'User not found.');

        return $u;
    }

    private function userJson(User $u, Organization $org): array
    {
        $pivot = $u->organizations->firstWhere('id', $org->id)?->pivot;
        $parts = preg_split('/\s+/', trim($u->name), 2) ?: [];
        $out = [
            'schemas' => [self::USER, self::EXT],
            'id' => $u->sub(), 'externalId' => $pivot?->external_id, 'userName' => $u->email, 'displayName' => $u->name,
            'name' => ['formatted' => $u->name, 'givenName' => $parts[0] ?? null, 'familyName' => $parts[1] ?? null],
            'emails' => [['value' => $u->email, 'type' => 'work', 'primary' => true]],
            'active' => ! $pivot?->deactivated_at,
            'groups' => $u->accessProfiles()->where('organization_id', $org->id)->get()->map(fn ($p) => ['value' => 'prf_'.$p->id, 'display' => $p->name])->values()->all(),
            self::EXT => ['teams' => OrgData::teamKeys($u, $org), 'fields' => (object) OrgData::values($u, $org)],
            'meta' => ['resourceType' => 'User', 'created' => $u->created_at?->toIso8601String(), 'lastModified' => $u->updated_at?->toIso8601String(),
                'location' => $this->base().'/Users/'.$u->sub()],
        ];
        if ($u->phone) {
            $out['phoneNumbers'] = [['value' => $u->phone, 'type' => 'work']];
        }

        return array_filter($out, fn ($v) => $v !== null);
    }

    public function users(Request $request)
    {
        $org = $this->org();
        $q = $this->members($org);
        if ($f = $request->query('filter')) {
            [$attr, $value] = $this->filter($f, ['username', 'externalid', 'emails.value', 'emails', 'id', 'displayname']);
            match ($attr) {
                'username', 'emails.value', 'emails' => $q->where('email', mb_strtolower($value)),
                'externalid' => $q->whereHas('organizations', fn ($o) => $o->where('organizations.id', $org->id)->where('organization_user.external_id', $value)),
                'id' => $q->whereKey(str_starts_with($value, 'usr_') ? substr($value, 4) : '-'),
                'displayname' => $q->where('name', $value),
            };
        }

        return $this->page($request, $q->orderBy('created_at')->orderBy('id'), fn (User $u) => $this->userJson($u, $org));
    }

    public function user(string $id)
    {
        $org = $this->org();

        return $this->out($this->userJson($this->findUser($org, $id), $org));
    }

    public function createUser(Request $request)
    {
        $org = $this->org();
        $r = $this->userInput($request->json()->all());
        abort_unless($r['email'], 422, 'userName (an email address) is required.');
        $user = User::where('email', $r['email'])->first();
        if ($user && $user->organizations()->where('organizations.id', $org->id)->exists()) {
            abort(409, "{$r['email']} is already in {$org->name}.");
        }
        if ($msg = Limits::exceeded($org, 'people')) {
            abort(429, $msg);
        }
        $new = ! $user;
        if ($new) {
            $user = User::create(['email' => $r['email'], 'name' => $r['name'] ?: Str::before($r['email'], '@'), 'phone' => $r['phone'], 'status' => 'invited']);
        }
        $user->organizations()->attach($org->id, ['joined_at' => now(), 'role' => 'member', 'external_id' => $r['externalId'],
            'deactivated_at' => $r['active'] === false ? now() : null]);
        Audit::log('scim.user_provisioned', 'admin', $user, after: ['email' => $user->email, 'new_account' => $new], orgId: $org->id);
        $this->applyExtension($user, $org, $r);
        Access::resolve($user, $org, 'provisioned (SCIM)', $this->api()->actor);
        if ($user->status === 'invited' && $r['active'] !== false) {
            // The person sets a password (or uses Google) from the invite email.
            [$invite, $token] = Invites::create(['email' => $user->email, 'name' => $user->name, 'organization_id' => $org->id], $this->api()->actor);
            rescue(fn () => Invites::send($invite, $token), report: true);
        }

        return $this->out($this->userJson($user->fresh(), $org), 201)->header('Location', $this->base().'/Users/'.$user->sub());
    }

    public function replaceUser(Request $request, string $id)
    {
        $org = $this->org();
        $user = $this->findUser($org, $id);
        $this->applyUser($user, $org, $this->userInput($request->json()->all()), true);

        return $this->out($this->userJson($user->fresh(), $org));
    }

    public function patchUser(Request $request, string $id)
    {
        $org = $this->org();
        $user = $this->findUser($org, $id);
        $doc = $this->userJson($user, $org);
        foreach ($this->operations($request) as [$op, $path, $value]) {
            $doc = $this->applyOp($doc, $op, $path, $value);
        }
        $this->applyUser($user, $org, $this->userInput($doc), false);

        return $this->out($this->userJson($user->fresh(), $org));
    }

    public function deleteUser(string $id)
    {
        $org = $this->org();
        $user = $this->findUser($org, $id);
        abort_if($user->id === $this->api()->actor->id, 400, 'SCIM cannot remove the person who owns this SCIM token.');
        abort_if($user->orgRole($org->id) === 'owner', 400, 'Owners of the organization are not removed by SCIM. Change their role in Clauth first.');
        Access::forgetMember($user, $org);
        $user->organizations()->detach($org->id);
        Audit::log('scim.user_removed', 'admin', $user, before: ['email' => $user->email], orgId: $org->id);

        return response()->noContent();
    }

    /** Normalised input: email, name, phone, externalId, active (bool|null = unchanged), teams, fields. */
    private function userInput(array $in): array
    {
        $in = array_change_key_case($in, CASE_LOWER);
        $emails = collect($in['emails'] ?? []);
        $email = $in['username'] ?? ($emails->firstWhere('primary', true)['value'] ?? $emails->first()['value'] ?? null);
        $name = $in['name'] ?? [];
        $name = is_array($name) ? array_change_key_case($name, CASE_LOWER) : [];
        $full = $name['formatted'] ?? trim(($name['givenname'] ?? '').' '.($name['familyname'] ?? '')) ?: ($in['displayname'] ?? null);
        $ext = $in[strtolower(self::EXT)] ?? null;

        return [
            'email' => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_strtolower(trim($email)) : null,
            'name' => is_string($full) && trim($full) !== '' ? Str::limit(trim($full), 120, '') : null,
            'phone' => collect($in['phonenumbers'] ?? [])->first()['value'] ?? null,
            'externalId' => isset($in['externalid']) ? (string) $in['externalid'] : null,
            'active' => array_key_exists('active', $in) ? filter_var($in['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null,
            'teams' => is_array($ext) && array_key_exists('teams', $ext) ? (array) $ext['teams'] : null,
            'fields' => is_array($ext) && isset($ext['fields']) ? (array) $ext['fields'] : null,
        ];
    }

    private function applyUser(User $user, Organization $org, array $r, bool $full): void
    {
        $onlyHere = $user->organizations()->count() === 1 && ! $user->isOwner();
        $changes = [];
        if ($r['email'] && $r['email'] !== $user->email) {
            abort_unless($onlyHere, 400, 'userName can only change for people who belong to this organization alone.');
            abort_if(User::where('email', $r['email'])->exists(), 409, "{$r['email']} is already used by another account.");
            $changes['email'] = $r['email'];
        }
        if ($r['name'] && $r['name'] !== $user->name && $onlyHere) {
            $changes['name'] = $r['name'];
        }
        if ($r['phone'] !== null && $onlyHere) {
            $changes['phone'] = $r['phone'];
        }
        if ($changes) {
            $before = $user->only(array_keys($changes));
            $user->update($changes);
            Audit::log('user.updated', 'admin', $user, $before, $changes, orgId: $org->id);
        }
        $pivot = [];
        if ($r['externalId'] !== null || $full) {
            $pivot['external_id'] = $r['externalId'];
        }
        $was = $user->organizations()->where('organizations.id', $org->id)->first()?->pivot->deactivated_at;
        if ($r['active'] === false && ! $was) {
            $pivot['deactivated_at'] = now();
        } elseif ($r['active'] === true && $was) {
            $pivot['deactivated_at'] = null;
        }
        if ($pivot) {
            $user->organizations()->updateExistingPivot($org->id, $pivot);
        }
        if (array_key_exists('deactivated_at', $pivot)) {
            Audit::log($pivot['deactivated_at'] ? 'scim.user_deactivated' : 'scim.user_reactivated', 'admin', $user, orgId: $org->id);
        }
        $this->applyExtension($user, $org, $r);
        Access::resolve($user, $org, 'updated (SCIM)', $this->api()->actor);
    }

    private function applyExtension(User $user, Organization $org, array $r): void
    {
        if ($r['teams'] !== null) {
            OrgData::setTeams($user, $org, array_values(array_filter($r['teams'], 'is_string')), $this->api()->actor);
        }
        if ($r['fields']) {
            OrgData::setValues($user, $org, $r['fields'], $this->api()->actor);
        }
    }

    // ---------------------------------------------------------------- groups (= access profiles)

    private function findGroup(Organization $org, string $id): AccessProfile
    {
        $p = preg_match('/^prf_(\d+)$/', $id, $m) ? AccessProfile::where('organization_id', $org->id)->find((int) $m[1]) : null;
        abort_unless($p, 404, 'Group not found.');

        return $p;
    }

    private function groupJson(AccessProfile $p, bool $members = true): array
    {
        $out = ['schemas' => [self::GROUP], 'id' => 'prf_'.$p->id, 'displayName' => $p->name,
            'meta' => ['resourceType' => 'Group', 'created' => $p->created_at?->toIso8601String(), 'lastModified' => $p->updated_at?->toIso8601String(),
                'location' => $this->base().'/Groups/prf_'.$p->id]];
        if ($members) {
            $out['members'] = $p->users()->orderBy('name')->get()->map(fn ($u) => ['value' => $u->sub(), 'display' => $u->name,
                '$ref' => $this->base().'/Users/'.$u->sub()])->values()->all();
        }

        return $out;
    }

    public function groups(Request $request)
    {
        $org = $this->org();
        $q = AccessProfile::where('organization_id', $org->id);
        if ($f = $request->query('filter')) {
            [$attr, $value] = $this->filter($f, ['displayname', 'id', 'externalid']);
            match ($attr) {
                'displayname' => $q->where('name', $value),
                'id' => $q->where('id', preg_match('/^prf_(\d+)$/', $value, $m) ? (int) $m[1] : 0),
                'externalid' => $q->whereRaw('1 = 0'),
            };
        }
        $members = ! str_contains(strtolower((string) $request->query('excludedAttributes')), 'members');

        return $this->page($request, $q->orderBy('id'), fn ($p) => $this->groupJson($p, $members));
    }

    public function group(string $id)
    {
        return $this->out($this->groupJson($this->findGroup($this->org(), $id)));
    }

    public function createGroup(Request $request)
    {
        $org = $this->org();
        $in = array_change_key_case($request->json()->all(), CASE_LOWER);
        $name = trim((string) ($in['displayname'] ?? ''));
        abort_if($name === '', 422, 'displayName is required.');
        abort_if(AccessProfile::where('organization_id', $org->id)->where('name', $name)->exists(), 409, "{$name} already exists.");
        if ($msg = Limits::exceeded($org, 'profiles')) {
            abort(429, $msg);
        }
        $base = Str::slug($name) ?: 'group';
        $key = $base;
        for ($i = 2; AccessProfile::where('organization_id', $org->id)->where('key', $key)->exists(); $i++) {
            $key = "$base-$i";
        }
        $p = AccessProfile::create(['organization_id' => $org->id, 'key' => $key, 'name' => Str::limit($name, 80, ''), 'created_by' => $this->api()->actor->id,
            'description' => 'Created by SCIM. Pick a role per app under Access profiles.', 'position' => AccessProfile::where('organization_id', $org->id)->count()]);
        Audit::log('profile.created', 'role', $p, after: $p->only('key', 'name') + ['via' => 'scim'], orgId: $org->id);
        $this->setMembers($org, $p, $this->memberIds($in['members'] ?? []));

        return $this->out($this->groupJson($p->fresh()), 201)->header('Location', $this->base().'/Groups/prf_'.$p->id);
    }

    public function replaceGroup(Request $request, string $id)
    {
        $org = $this->org();
        $p = $this->findGroup($org, $id);
        $in = array_change_key_case($request->json()->all(), CASE_LOWER);
        if (is_string($in['displayname'] ?? null) && trim($in['displayname']) !== '') {
            $p->update(['name' => Str::limit(trim($in['displayname']), 80, '')]);
        }
        $this->setMembers($org, $p, $this->memberIds($in['members'] ?? []));

        return $this->out($this->groupJson($p->fresh()));
    }

    public function patchGroup(Request $request, string $id)
    {
        $org = $this->org();
        $p = $this->findGroup($org, $id);
        $have = $p->users()->pluck('users.id');
        foreach ($this->operations($request) as [$op, $path, $value]) {
            $lp = strtolower((string) $path);
            if ($lp === '' && is_array($value)) {
                $value = array_change_key_case($value, CASE_LOWER);
                if (isset($value['displayname'])) {
                    $p->update(['name' => Str::limit(trim((string) $value['displayname']), 80, '')]);
                }
                if (isset($value['members'])) {
                    $ids = $this->memberIds($value['members']);
                    $have = $op === 'add' ? $have->merge($ids)->unique() : ($op === 'remove' ? $have->diff($ids) : collect($ids));
                }
            } elseif ($lp === 'displayname') {
                $p->update(['name' => Str::limit(trim((string) $value), 80, '')]);
            } elseif ($lp === 'members') {
                $ids = $this->memberIds($value ?? []);
                $have = match ($op) {
                    'add' => $have->merge($ids)->unique(),
                    'remove' => $value === null ? collect() : $have->diff($ids),
                    default => collect($ids),
                };
            } elseif (preg_match('/^members\[value eq "([^"]+)"\]$/i', (string) $path, $m) && $op === 'remove') {
                $have = $have->diff($this->memberIds([['value' => $m[1]]]));
            } else {
                abort(422, "Unsupported path: {$path}");
            }
        }
        $this->setMembers($org, $p, $have->values()->all());

        return $this->out($this->groupJson($p->fresh()));
    }

    public function deleteGroup(string $id)
    {
        $org = $this->org();
        $p = $this->findGroup($org, $id);
        abort_if($p->hasOwnerTier() && ! Access::canTouchOwnerTier($this->api()->actor, $org->id), 403, 'Only an owner can delete a profile with an Owner-tier role.');
        $holders = $p->users()->get();
        $p->delete();
        foreach ($holders as $u) {
            Access::resolve($u, $org, "profile {$p->key} deleted (SCIM)", $this->api()->actor);
        }
        Audit::log('profile.deleted', 'role', $p, before: $p->only('key', 'name') + ['via' => 'scim'], orgId: $org->id);

        return response()->noContent();
    }

    /** User ids (members of this organization) from [{value: usr_…}]; anyone else is a 400. */
    private function memberIds(mixed $members): array
    {
        $org = $this->org();
        $ids = [];
        foreach ((array) $members as $m) {
            $v = is_array($m) ? ($m['value'] ?? null) : $m;
            $u = is_string($v) && str_starts_with($v, 'usr_') ? $this->members($org)->whereKey(substr($v, 4))->first() : null;
            abort_unless($u, 422, 'Group members must be people provisioned in this organization first ('.(is_string($v) ? $v : '?').').');
            $ids[] = $u->id;
        }

        return array_values(array_unique($ids));
    }

    /** Make the profile's holders exactly $userIds (through Access::setProfiles, so grants follow). */
    private function setMembers(Organization $org, AccessProfile $p, array $userIds): void
    {
        $current = $p->users()->pluck('users.id')->all();
        foreach (User::whereIn('id', array_unique(array_merge($current, $userIds)))->get() as $u) {
            $held = $u->accessProfiles()->where('organization_id', $org->id)->pluck('access_profiles.id');
            $want = in_array($u->id, $userIds, true) ? $held->concat([$p->id])->unique() : $held->reject(fn ($id) => $id === $p->id); // concat: push() would change $held too
            if ($want->sort()->values()->all() !== $held->sort()->values()->all()) {
                Access::setProfiles($u, $org, $want->values()->all(), $this->api()->actor);
            }
        }
    }

    // ---------------------------------------------------------------- helpers

    /** [[op, path|null, value]] from a PatchOp body; op names are case-insensitive (Entra sends "Replace"). */
    private function operations(Request $request): array
    {
        $ops = $request->json('Operations') ?? $request->json('operations');
        abort_unless(is_array($ops) && $ops, 422, 'Send a PatchOp with Operations.');

        return array_map(function ($o) {
            $op = strtolower((string) ($o['op'] ?? ''));
            abort_unless(in_array($op, ['add', 'replace', 'remove'], true), 422, 'op must be add, replace or remove.');

            return [$op, $o['path'] ?? null, $o['value'] ?? null];
        }, $ops);
    }

    /** Apply one PATCH operation to the user document (paths the common providers send). */
    private function applyOp(array $doc, string $op, ?string $path, mixed $value): array
    {
        if ($path === null || $path === '') {
            abort_unless(is_array($value), 422, 'A PATCH without path needs an object value.');
            foreach ($value as $k => $v) {
                $doc = $this->applyOp($doc, $op, (string) $k, $v);
            }

            return $doc;
        }
        $p = strtolower($path);
        $ext = strtolower(self::EXT).':';
        if (str_starts_with($p, $ext)) {
            $sub = substr($p, strlen($ext));
            $doc[self::EXT][$sub === 'teams' ? 'teams' : 'fields'] = $op === 'remove' ? ($sub === 'teams' ? [] : null) : $value;

            return $doc;
        }

        return match (true) {
            $p === 'active' => ['active' => $op === 'remove' ? true : $value] + $doc,
            $p === 'externalid' => ['externalId' => $op === 'remove' ? '' : $value] + $doc,
            $p === 'username' => ['userName' => $value] + $doc,
            $p === 'displayname' => ['displayName' => $value, 'name' => ['formatted' => $value]] + $doc,
            in_array($p, ['name.formatted', 'name.givenname', 'name.familyname'], true) => $this->setName($doc, substr($p, 5), $value),
            $p === 'name' && is_array($value) => ['name' => $value] + $doc,
            str_starts_with($p, 'emails') => ['emails' => [['value' => is_array($value) ? ($value[0]['value'] ?? $value['value'] ?? null) : $value, 'primary' => true]]] + $doc,
            str_starts_with($p, 'phonenumbers') => ['phoneNumbers' => [['value' => is_array($value) ? ($value[0]['value'] ?? $value['value'] ?? null) : $value]]] + $doc,
            $p === strtolower(self::EXT) && is_array($value) => [self::EXT => array_merge($doc[self::EXT] ?? [], $value)] + $doc,
            default => $doc, // attributes Clauth doesn't keep (title, addresses…) are accepted and ignored
        };
    }

    private function setName(array $doc, string $part, mixed $value): array
    {
        $n = $doc['name'] ?? [];
        $n[$part === 'givenname' ? 'givenName' : ($part === 'familyname' ? 'familyName' : 'formatted')] = $value;
        if ($part !== 'formatted') {
            $n['formatted'] = trim(($n['givenName'] ?? '').' '.($n['familyName'] ?? ''));
        }
        $doc['name'] = $n;

        return $doc;
    }

    /** `attr eq "value"` only — what Entra, Okta and Google Workspace send. */
    private function filter(string $f, array $allowed): array
    {
        if (! preg_match('/^\s*([\w.]+)\s+eq\s+"((?:[^"\\\\]|\\\\.)*)"\s*$/i', $f, $m) || ! in_array(strtolower($m[1]), $allowed, true)) {
            abort(400, 'Only filters like userName eq "a@b.c" are supported.');
        }

        return [strtolower($m[1]), stripslashes($m[2])];
    }

    private function page(Request $request, $q, callable $map): JsonResponse
    {
        $start = max(1, (int) $request->query('startIndex', 1));
        $count = max(0, min(200, (int) $request->query('count', 100)));
        $total = (clone $q)->count();
        $items = $count ? $q->skip($start - 1)->take($count)->get()->map($map)->values()->all() : [];

        return $this->out(['schemas' => [self::LIST], 'totalResults' => $total, 'itemsPerPage' => count($items), 'startIndex' => $start, 'Resources' => $items]);
    }
}
