<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\Etag;
use App\Models\Connection;
use App\Services\Audit;
use App\Services\Connections;
use Illuminate\Http\Request;

/** /api/v1/connections (SPEC-platform §7): sign-in providers, SMS gateway and email senders. Secrets are write-only. */
class ConnectionsController
{
    use UsesApi;

    private function find(string $key): Connection
    {
        $c = Connection::with('organization')->where('key', $key)->first();
        abort_unless($c && ($c->organization_id ? $this->api()->canOrg($c->organization_id) : $this->api()->isPlatform()), 404, 'Connection not found.');

        return $c;
    }

    public function index(Request $request)
    {
        $ids = $this->api()->orgIds();
        $q = Connection::with('organization')
            ->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))
            ->when($request->query('org'), fn ($q, $o) => $q->where('organization_id', $this->api()->org($o)->id))
            ->when($request->query('org') === null && $ids === null && $request->query('platform'), fn ($q) => $q->whereNull('organization_id'))
            ->orderBy('type')->orderBy('name');

        return response()->json(['data' => $q->get()->map(fn ($c) => Connections::json($c))->values()]);
    }

    public function show(string $key)
    {
        $c = $this->find($key);

        return Etag::attach(response()->json(Connections::json($c)), $c);
    }

    /** {type, name, org?: slug (omit for a platform connection; platform clients only), config: {...}} */
    public function store(Request $request)
    {
        $org = $request->filled('org') ? $this->api()->org((string) $request->input('org')) : null;
        abort_if(! $org && ! $this->api()->isPlatform(), 422, 'Say which organization the connection is for (org).');
        $request->validate(['type' => Connections::typeRule((bool) $org), 'name' => 'required|string|max:80', 'config' => 'required|array']);
        $type = $request->input('type');
        abort_if($type === 'sms' && Connection::where('type', 'sms')->whereNull('organization_id')->exists(), 409, 'There is already an SMS gateway.');
        abort_if($type === 'email-smtp' && Connection::where('type', 'email-smtp')->where('organization_id', $org?->id)->exists(), 409, 'This organization already has an email sender.');
        $c = Connection::create(['organization_id' => $org?->id, 'type' => $type, 'key' => Connections::newKey($request->input('name'), $org),
            'name' => $request->input('name'), 'config' => Connections::config($type, (bool) $org, $request->input('config')),
            'status' => 'active', 'created_by' => $this->api()->actor->id]);
        Audit::log('connection.created', 'admin', $c, after: ['type' => $type, 'key' => $c->key], orgId: $org?->id);

        return response()->json(Connections::json($c->load('organization')), 201);
    }

    /** PATCH {name?, status?: active|disabled, config?: {...}} — config fields left out (or empty secrets) keep their value. */
    public function update(Request $request, string $key)
    {
        $c = $this->find($key);
        Etag::check($c);
        $data = $request->validate(['name' => 'sometimes|required|string|max:80', 'status' => 'sometimes|in:active,disabled', 'config' => 'sometimes|array']);
        $changes = array_intersect_key($data, array_flip(['name', 'status']));
        if (isset($data['config'])) {
            $changes['config'] = Connections::config($c->type, (bool) $c->organization_id, $data['config'] + $c->config, $c);
        }
        $before = ['name' => $c->name, 'status' => $c->status, 'config' => Connections::masked($c)];
        $c->update($changes);
        Audit::log('connection.updated', 'admin', $c, $before, ['name' => $c->name, 'status' => $c->status, 'config' => Connections::masked($c)], orgId: $c->organization_id);

        return Etag::attach(response()->json(Connections::json($c->fresh('organization'))), $c->fresh());
    }

    public function destroy(string $key)
    {
        $c = $this->find($key);
        Etag::check($c);
        $c->delete();
        Audit::log('connection.deleted', 'admin', $c, before: ['type' => $c->type, 'key' => $c->key], orgId: $c->organization_id);

        return response()->noContent();
    }
}
