<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Agent;
use App\Services\Agents;
use App\Services\Audit;
use Illuminate\Http\Request;

/**
 * Agents (SPEC-platform §9.3). /orgs/{org}/agents + /agents/{id}: an organization's agents (agents:read|write).
 * /me/agents: a person's own agents, acting for them (personal token, me:write). Credentials: client credentials with
 * audience=<app key> at /oauth/token.
 */
class AgentsController
{
    use UsesApi;

    private function find(string $id, bool $mine = false): Agent
    {
        $a = Agent::with(['organization', 'owner'])->find(str_starts_with($id, 'agt_') ? substr($id, 4) : $id);
        $ok = $a && ($mine ? $a->owner_user_id === $this->api()->actor->id : $this->api()->canOrg($a->organization_id));
        abort_unless($ok, 404, 'Agent not found.');

        return $a;
    }

    public function index(string $org)
    {
        $o = $this->api()->org($org);

        return response()->json(['data' => Agent::with(['organization', 'owner'])->where('organization_id', $o->id)->orderBy('name')->get()->map(fn ($a) => Agents::json($a))->values()]);
    }

    /** {name, description?, apps?: {app_key: role_key}} — returns the agent with client_id and client_secret (shown once). */
    public function store(Request $request, string $org)
    {
        $o = $this->api()->org($org);
        $data = $request->validate(['name' => 'required|string|max:80', 'description' => 'nullable|string|max:190', 'apps' => 'sometimes|array']);
        abort_if(Agent::where('organization_id', $o->id)->count() >= 100, 422, 'An organization can have up to 100 agents.');
        $a = Agents::create($o, null, $data['name'], $data['description'] ?? null, $this->api()->actor);
        if (! empty($data['apps'])) {
            Agents::setAccess($a, $data['apps'], $this->api()->actor);
        }
        [$id, $secret] = Agents::rotate($a, $this->api()->actor);

        return response()->json(Agents::json($a->fresh(['organization', 'owner'])) + ['client_secret' => $secret], 201);
    }

    public function show(string $agent)
    {
        return response()->json(Agents::json($this->find($agent)));
    }

    public function update(Request $request, string $agent)
    {
        $a = $this->find($agent);
        $data = $request->validate(['name' => 'sometimes|required|string|max:80', 'description' => 'sometimes|nullable|string|max:190', 'status' => 'sometimes|in:active,suspended']);
        $a->update($data);
        Audit::log('agent.updated', 'admin', $a, after: $data, orgId: $a->organization_id);

        return response()->json(Agents::json($a->fresh(['organization', 'owner'])));
    }

    /** PUT {apps: {app_key: role_key | null}} — an organization agent's roles. */
    public function access(Request $request, string $agent)
    {
        $a = $this->find($agent);
        $data = $request->validate(['apps' => 'required|array']);
        Agents::setAccess($a, $data['apps'], $this->api()->actor);

        return response()->json(Agents::json($a));
    }

    public function rotate(string $agent)
    {
        $a = $this->find($agent);
        [$id, $secret] = Agents::rotate($a, $this->api()->actor);

        return response()->json(Agents::json($a) + ['client_secret' => $secret]);
    }

    public function destroy(string $agent)
    {
        $a = $this->find($agent);
        $this->remove($a);

        return response()->noContent();
    }

    // ---- a person's own agents (/me/agents) ----

    public function mine()
    {
        return response()->json(['data' => Agent::with(['organization', 'owner'])->where('owner_user_id', $this->api()->actor->id)->orderBy('name')->get()
            ->map(fn ($a) => Agents::json($a))->values()]);
    }

    /** {name, org: slug (one of your organizations), description?} */
    public function storeMine(Request $request)
    {
        $me = $this->api()->actor;
        $data = $request->validate(['name' => 'required|string|max:80', 'org' => 'required|string', 'description' => 'nullable|string|max:190']);
        $o = $me->organizations()->where('slug', $data['org'])->first() ?? abort(404, 'You are not in that organization.');
        abort_if(Agent::where('owner_user_id', $me->id)->count() >= 20, 422, 'You can have up to 20 agents.');
        $a = Agents::create($o, $me, $data['name'], $data['description'] ?? null, $me);
        [, $secret] = Agents::rotate($a, $me);

        return response()->json(Agents::json($a->fresh(['organization', 'owner'])) + ['client_secret' => $secret], 201);
    }

    public function rotateMine(string $agent)
    {
        $a = $this->find($agent, true);
        [, $secret] = Agents::rotate($a, $this->api()->actor);

        return response()->json(Agents::json($a) + ['client_secret' => $secret]);
    }

    public function updateMine(Request $request, string $agent)
    {
        $a = $this->find($agent, true);
        $data = $request->validate(['name' => 'sometimes|required|string|max:80', 'description' => 'sometimes|nullable|string|max:190', 'status' => 'sometimes|in:active,suspended']);
        $a->update($data);

        return response()->json(Agents::json($a->fresh(['organization', 'owner'])));
    }

    public function destroyMine(string $agent)
    {
        $this->remove($this->find($agent, true));

        return response()->noContent();
    }

    private function remove(Agent $a): void
    {
        foreach (\App\Models\OAuthClient::where('type', 'agent')->where('agent_id', $a->id)->get() as $c) {
            \Illuminate\Support\Facades\DB::table('oauth_access_tokens')->where('client_id', $c->id)->update(['revoked' => true]);
            $c->forceFill(['revoked' => true])->save();
        }
        $a->delete();
        Audit::log('agent.deleted', 'admin', $a, before: ['name' => $a->name], orgId: $a->organization_id);
    }
}
