<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\Etag;
use App\Models\Organization;
use App\Models\OrgAttribute;
use App\Models\Team;
use App\Services\Audit;
use App\Services\OrgData;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Teams and profile fields of an organization (SPEC-platform §6.3, §6.4) — /api/v1/orgs/{org}/teams|fields. */
class TeamsController
{
    use UsesApi;

    private function team(Organization $o, string $key): Team
    {
        return Team::where('organization_id', $o->id)->where('key', $key)->first() ?? abort(404, 'Team not found.');
    }

    private function teamJson(Team $t, bool $members = false): array
    {
        $out = ['key' => $t->key, 'name' => $t->name, 'description' => $t->description, 'members' => $t->users()->count()];
        if ($members) {
            $out['people'] = $t->users()->orderBy('name')->get()->map(fn ($u) => $u->sub())->values();
        }

        return $out;
    }

    public function index(string $org)
    {
        $o = $this->api()->org($org);

        return response()->json(['data' => $o->teams()->get()->map(fn ($t) => $this->teamJson($t))->values()]);
    }

    public function show(string $org, string $team)
    {
        $t = $this->team($this->api()->org($org), $team);

        return Etag::attach(response()->json($this->teamJson($t, true)), $t);
    }

    public static function teamRules(?Team $t, Organization $o): array
    {
        return ['name' => [$t ? 'sometimes' : 'required', 'string', 'max:80'], 'description' => 'sometimes|nullable|string|max:190',
            'key' => [$t ? 'prohibited' : 'nullable', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('teams', 'key')->where('organization_id', $o->id)]];
    }

    public function store(Request $request, string $org)
    {
        $o = $this->api()->org($org);
        $data = $request->validate(self::teamRules(null, $o), ['key.prohibited' => 'A team key cannot change (apps rely on it).']);
        $t = self::create($o, $data, $this->api()->actor->id);

        return response()->json($this->teamJson($t), 201);
    }

    public static function create(Organization $o, array $data, ?string $by): Team
    {
        $key = $data['key'] ?? null;
        if (! $key) {
            $base = Str::slug($data['name']) ?: 'team';
            $key = $base;
            for ($i = 2; Team::where('organization_id', $o->id)->where('key', $key)->exists(); $i++) {
                $key = "$base-$i";
            }
        }
        $t = Team::create(['organization_id' => $o->id, 'key' => $key, 'name' => $data['name'], 'description' => $data['description'] ?? null, 'created_by' => $by]);
        Audit::log('team.created', 'admin', $t, after: $t->only('key', 'name'), orgId: $o->id);

        return $t;
    }

    public function update(Request $request, string $org, string $team)
    {
        $o = $this->api()->org($org);
        $t = $this->team($o, $team);
        Etag::check($t);
        $data = $request->validate(self::teamRules($t, $o), ['key.prohibited' => 'A team key cannot change (apps rely on it).']);
        $before = $t->only(array_keys($data));
        $t->update($data);
        Audit::log('team.updated', 'admin', $t, $before, $t->only(array_keys($data)), orgId: $o->id);

        return Etag::attach(response()->json($this->teamJson($t->fresh())), $t->fresh());
    }

    public function destroy(string $org, string $team)
    {
        $o = $this->api()->org($org);
        $t = $this->team($o, $team);
        Etag::check($t);
        $t->delete();
        Audit::log('team.deleted', 'admin', $t, before: $t->only('key', 'name'), orgId: $o->id);

        return response()->noContent();
    }

    public function addMember(string $org, string $team, string $user)
    {
        $o = $this->api()->org($org);
        $t = $this->team($o, $team);
        $u = $this->member($o, $user);
        OrgData::setTeams($u, $o, array_values(array_unique([...OrgData::teamKeys($u, $o), $t->key])), $this->api()->actor);

        return response()->json($this->teamJson($t, true));
    }

    public function removeMember(string $org, string $team, string $user)
    {
        $o = $this->api()->org($org);
        $t = $this->team($o, $team);
        $u = $this->member($o, $user);
        OrgData::setTeams($u, $o, array_values(array_diff(OrgData::teamKeys($u, $o), [$t->key])), $this->api()->actor);

        return response()->noContent();
    }

    // ---- profile fields ----

    private function fieldJson(OrgAttribute $a): array
    {
        return ['key' => $a->key, 'label' => $a->label, 'type' => $a->type, 'options' => $a->options, 'required' => $a->required,
            'editable_by' => $a->editable_by, 'in_token' => $a->in_token];
    }

    public static function fieldRules(?OrgAttribute $a, Organization $o): array
    {
        $s = $a ? 'sometimes' : 'required';

        return [
            'label' => [$s, 'string', 'max:80'],
            'key' => [$a ? 'prohibited' : 'required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('org_attributes', 'key')->where('organization_id', $o->id)],
            'type' => [$a ? 'prohibited' : 'required', Rule::in(array_keys(OrgAttribute::TYPES))],
            'options' => ['required_if:type,enum', 'nullable', 'array', 'max:50'], 'options.*' => 'string|max:80',
            'required' => 'sometimes|boolean', 'editable_by' => 'sometimes|in:admin,self', 'in_token' => 'sometimes|boolean',
        ];
    }

    public function fields(string $org)
    {
        $o = $this->api()->org($org);

        return response()->json(['data' => $o->attributeDefs()->get()->map(fn ($a) => $this->fieldJson($a))->values()]);
    }

    public function storeField(Request $request, string $org)
    {
        $o = $this->api()->org($org);
        $data = $request->validate(self::fieldRules(null, $o), ['key.prohibited' => 'A field key cannot change.', 'type.prohibited' => 'A field type cannot change.']);
        $a = self::createField($o, $data);

        return response()->json($this->fieldJson($a), 201);
    }

    public static function createField(Organization $o, array $data): OrgAttribute
    {
        abort_if(OrgAttribute::where('organization_id', $o->id)->count() >= 50, 422, 'An organization can have up to 50 profile fields.');
        $a = OrgAttribute::create($data + ['organization_id' => $o->id, 'position' => OrgAttribute::where('organization_id', $o->id)->count()]);
        Audit::log('field.created', 'admin', $a, after: $a->only('key', 'type', 'in_token'), orgId: $o->id);

        return $a;
    }

    public function updateField(Request $request, string $org, string $field)
    {
        $o = $this->api()->org($org);
        $a = OrgAttribute::where('organization_id', $o->id)->where('key', $field)->first() ?? abort(404, 'Field not found.');
        Etag::check($a);
        $data = $request->validate(self::fieldRules($a, $o), ['key.prohibited' => 'A field key cannot change.', 'type.prohibited' => 'A field type cannot change.']);
        abort_if(isset($data['options']) && $a->type !== 'enum', 422, 'Only choice fields have options.');
        $before = $a->only(array_keys($data));
        $a->update($data);
        Audit::log('field.updated', 'admin', $a, $before, $a->only(array_keys($data)), orgId: $o->id);

        return Etag::attach(response()->json($this->fieldJson($a->fresh())), $a->fresh());
    }

    public function destroyField(string $org, string $field)
    {
        $o = $this->api()->org($org);
        $a = OrgAttribute::where('organization_id', $o->id)->where('key', $field)->first() ?? abort(404, 'Field not found.');
        Etag::check($a);
        \Illuminate\Support\Facades\DB::table('user_attributes')->where('organization_id', $o->id)->where('key', $a->key)->delete();
        $a->delete();
        Audit::log('field.deleted', 'admin', $a, before: $a->only('key', 'type'), orgId: $o->id);

        return response()->noContent();
    }

    // ---- one member ----

    /** GET /orgs/{org}/members/{user}/profile — teams and field values. */
    public function memberProfile(string $org, string $user)
    {
        $o = $this->api()->org($org);
        $u = $this->member($o, $user);

        return response()->json(['user' => $u->sub(), 'org' => $o->slug, 'teams' => OrgData::teamKeys($u, $o),
            'fields' => (object) OrgData::values($u, $o), 'missing_fields' => OrgData::missing($u, $o)]);
    }

    /** PATCH {teams?: [keys] (exact list), fields?: {key: value|null}} */
    public function updateMemberProfile(Request $request, string $org, string $user)
    {
        $o = $this->api()->org($org);
        $u = $this->member($o, $user);
        $data = $request->validate(['teams' => 'sometimes|array', 'teams.*' => 'string', 'fields' => 'sometimes|array']);
        if (array_key_exists('teams', $data)) {
            OrgData::setTeams($u, $o, $data['teams'], $this->api()->actor);
        }
        if (! empty($data['fields'])) {
            OrgData::setValues($u, $o, $data['fields'], $this->api()->actor);
        }

        return $this->memberProfile($org, $user);
    }

    private function member(Organization $o, string $user): \App\Models\User
    {
        $u = $this->api()->user($user);
        abort_unless($u->organizations()->where('organizations.id', $o->id)->exists(), 404, 'Not a member of this organization.');

        return $u;
    }
}
