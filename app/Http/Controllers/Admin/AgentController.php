<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use App\Models\Organization;
use App\Services\Agents;
use App\Services\Audit;
use Illuminate\Http\Request;

/** Organizations › Agents (SPEC-platform §9.3): the organization's AI assistants, bots and scripts. */
class AgentController
{
    use Scope;

    private function org(string $org): Organization
    {
        abort_unless($this->canManageOrg($org), 404);

        return Organization::findOrFail($org);
    }

    private function find(Organization $o, string $id): Agent
    {
        return Agent::with(['organization', 'owner'])->where('organization_id', $o->id)->findOrFail($id);
    }

    public function index(string $org)
    {
        $o = $this->org($org);

        return view('admin.agents', ['org' => $o, 'agents' => Agent::with('owner')->where('organization_id', $o->id)->orderByRaw('owner_user_id is not null')->orderBy('name')->get(),
            'apps' => $o->apps()->with(['roles' => fn ($q) => $q->where('assignable', true)->orderBy('position')])->orderBy('name')->get()]);
    }

    public function store(Request $request, string $org)
    {
        $o = $this->org($org);
        $data = $request->validate(['name' => 'required|string|max:80', 'description' => 'nullable|string|max:190']);
        $a = Agents::create($o, null, $data['name'], $data['description'] ?? null, $request->user());
        [$id, $secret] = Agents::rotate($a, $request->user());

        return back()->with('agent_secret', ['name' => $a->name, 'client_id' => $id, 'secret' => $secret]);
    }

    public function access(Request $request, string $org, string $agent)
    {
        $o = $this->org($org);
        $a = $this->find($o, $agent);
        $apps = collect((array) $request->input('apps', []))->map(fn ($v) => $v === '' ? null : $v)->all();
        Agents::setAccess($a, $apps, $request->user());

        return back()->with('status', "{$a->name}’s access saved.");
    }

    public function rotate(Request $request, string $org, string $agent)
    {
        $o = $this->org($org);
        $a = $this->find($o, $agent);
        [$id, $secret] = Agents::rotate($a, $request->user());

        return back()->with('agent_secret', ['name' => $a->name, 'client_id' => $id, 'secret' => $secret]);
    }

    public function status(Request $request, string $org, string $agent)
    {
        $o = $this->org($org);
        $a = $this->find($o, $agent);
        $a->update(['status' => $a->status === 'active' ? 'suspended' : 'active']);
        Audit::log('agent.updated', 'admin', $a, after: ['status' => $a->status], orgId: $o->id);

        return back()->with('status', "{$a->name} is ".($a->status === 'active' ? 'active again.' : 'suspended.'));
    }

    public function destroy(Request $request, string $org, string $agent)
    {
        $o = $this->org($org);
        $a = $this->find($o, $agent);
        foreach (\App\Models\OAuthClient::where('type', 'agent')->where('agent_id', $a->id)->get() as $c) {
            \Illuminate\Support\Facades\DB::table('oauth_access_tokens')->where('client_id', $c->id)->update(['revoked' => true]);
            $c->forceFill(['revoked' => true])->save();
        }
        $a->delete();
        Audit::log('agent.deleted', 'admin', $a, before: ['name' => $a->name], orgId: $o->id);

        return back()->with('status', "{$a->name} deleted.");
    }
}
