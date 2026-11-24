<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\TeamsController;
use App\Models\Organization;
use App\Models\OrgAttribute;
use App\Models\Team;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Organization › Teams & fields (SPEC-platform §6.3, §6.4). Org owners and admins. */
class OrgDataController
{
    use Scope;

    private function org(string $org): Organization
    {
        abort_unless($this->canManageOrg($org), 404);

        return Organization::findOrFail($org);
    }

    public function index(string $org)
    {
        $o = $this->org($org);

        $teams = $o->teams()->withCount('users')->with(['users' => fn ($q) => $q->orderBy('name')->limit(3)])->get();

        return view('admin.org-data', ['org' => $o, 'teams' => $teams,
            'fields' => $o->attributeDefs()->get(), 'types' => OrgAttribute::TYPES]);
    }

    public function storeTeam(Request $request, string $org)
    {
        $o = $this->org($org);
        $data = $request->validate(TeamsController::teamRules(null, $o));
        $t = TeamsController::create($o, $data, $request->user()->id);

        return back()->with('status', "Team “{$t->name}” added (key {$t->key}).");
    }

    public function destroyTeam(string $org, int $team)
    {
        $o = $this->org($org);
        $t = Team::where('organization_id', $o->id)->findOrFail($team);
        $t->delete();
        Audit::log('team.deleted', 'admin', $t, before: $t->only('key', 'name'), orgId: $o->id);

        return back()->with('status', "Team “{$t->name}” deleted.");
    }

    public function storeField(Request $request, string $org)
    {
        $o = $this->org($org);
        $request->merge(['options' => $this->options($request), 'required' => $request->boolean('required'), 'in_token' => $request->boolean('in_token')]);
        $data = $request->validate(TeamsController::fieldRules(null, $o));
        if ($data['type'] !== 'enum') {
            $data['options'] = null;
        }
        $a = TeamsController::createField($o, $data);

        return back()->with('status', "Field “{$a->label}” added.");
    }

    public function updateField(Request $request, string $org, int $field)
    {
        $o = $this->org($org);
        $a = OrgAttribute::where('organization_id', $o->id)->findOrFail($field);
        $request->merge(['required' => $request->boolean('required'), 'in_token' => $request->boolean('in_token')]
            + ($a->type === 'enum' ? ['options' => $this->options($request)] : []));
        $data = $request->validate(collect(TeamsController::fieldRules($a, $o))->except(['key', 'type'])->all());
        $before = $a->only(array_keys($data));
        $a->update($data);
        Audit::log('field.updated', 'admin', $a, $before, $a->only(array_keys($data)), orgId: $o->id);

        return back()->with('status', "Field “{$a->label}” saved.");
    }

    public function destroyField(string $org, int $field)
    {
        $o = $this->org($org);
        $a = OrgAttribute::where('organization_id', $o->id)->findOrFail($field);
        DB::table('user_attributes')->where('organization_id', $o->id)->where('key', $a->key)->delete();
        $a->delete();
        Audit::log('field.deleted', 'admin', $a, before: $a->only('key', 'type'), orgId: $o->id);

        return back()->with('status', "Field “{$a->label}” and everyone's values for it deleted.");
    }

    /** Choices typed one per line or comma-separated. */
    private function options(Request $request): ?array
    {
        $raw = $request->input('options');
        if (is_array($raw)) {
            return $raw;
        }
        $list = collect(preg_split('/[\n,]+/', (string) $raw))->map(fn ($s) => trim($s))->filter()->unique()->values()->all();

        return $list ?: null;
    }
}
