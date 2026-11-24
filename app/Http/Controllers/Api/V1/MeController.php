<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\Present;
use App\Models\Application;
use App\Models\ClientSession;
use App\Models\Consent;
use App\Models\Grant;
use App\Services\Audit;
use App\Services\Consents;
use App\Services\Sessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** /api/v1/me — the person behind a personal API token (SPEC-platform §6.1, P1.1). Mirrors My account. */
class MeController
{
    use UsesApi;

    public function show()
    {
        $u = $this->api()->actor->load('organizations', 'socialIdentities');

        return response()->json(Present::user($u, null, true));
    }

    public function update(Request $request)
    {
        $u = $this->api()->actor;
        $data = $request->validate(['name' => 'sometimes|required|string|max:120', 'phone' => 'sometimes|nullable|string|max:40']);
        $before = $u->only(array_keys($data));
        $u->update($data);
        Audit::log('user.updated', 'security', $u, $before, $u->only(array_keys($data)), actorId: $u->id);

        return $this->show();
    }

    /** Apps the person can open, with their role per organization. */
    public function apps()
    {
        $u = $this->api()->actor;
        $grants = Grant::with(['app', 'organization', 'role'])->where('user_id', $u->id)->get()
            ->filter(fn ($g) => $g->app && $g->app->status !== 'paused' && ! $g->app->isSuspended());

        return response()->json(['data' => $grants->groupBy('app_id')->map(fn ($gs) => [
            'app' => $gs->first()->app->key, 'name' => $gs->first()->app->name, 'home_url' => $gs->first()->app->home_url,
            'roles' => $gs->map(fn ($g) => ['org' => $g->organization?->slug, 'role' => $g->role?->key])->values(),
        ])->values()]);
    }

    /** Browsers signed in to Clauth and apps holding a session. */
    public function sessions()
    {
        $u = $this->api()->actor;

        return response()->json([
            'browsers' => DB::table('sessions')->where('user_id', $u->id)->orderByDesc('last_activity')->get()
                ->map(fn ($s) => ['id' => 'web_'.$s->id, 'ip' => $s->ip_address, 'user_agent' => $s->user_agent, 'last_active_at' => date(DATE_ATOM, (int) $s->last_activity)])->values(),
            'apps' => ClientSession::with('app')->where('user_id', $u->id)->orderByDesc('last_used_at')->get()
                ->map(fn ($s) => ['id' => 'app_'.$s->id, 'app' => $s->app?->key, 'ip' => $s->ip, 'user_agent' => $s->user_agent,
                    'signed_in_at' => $s->auth_time?->toIso8601String(), 'last_used_at' => $s->last_used_at?->toIso8601String()])->values(),
        ]);
    }

    /** DELETE /me/sessions/{id} — web_… signs a browser out of Clauth; app_… signs out of that app. */
    public function endSession(string $id)
    {
        $u = $this->api()->actor;
        if (str_starts_with($id, 'web_')) {
            abort_unless(DB::table('sessions')->where('user_id', $u->id)->where('id', substr($id, 4))->delete(), 404, 'Session not found.');
        } elseif (str_starts_with($id, 'app_') && ctype_digit(substr($id, 4))) {
            $cs = ClientSession::where('user_id', $u->id)->find((int) substr($id, 4)) ?? abort(404, 'Session not found.');
            Sessions::revokeClient($u, $cs->client_id);
        } else {
            abort(404, 'Session not found.');
        }
        Audit::log('security.session_ended', 'security', $u, after: ['session' => $id], actorId: $u->id);

        return response()->noContent();
    }

    /** Outside apps the person allowed to receive their details. */
    public function consents()
    {
        $u = $this->api()->actor;

        return response()->json(['data' => Consent::with('app.publisher')->where('user_id', $u->id)->whereNull('revoked_at')->get()->filter(fn ($c) => $c->app)
            ->map(fn ($c) => ['app' => $c->app->key, 'name' => $c->app->name, 'publisher' => $c->app->publisher?->slug, 'scopes' => $c->scopes,
                'granted_at' => $c->granted_at?->toIso8601String()])->values()]);
    }

    public function revokeConsent(string $app)
    {
        $u = $this->api()->actor;
        $a = Application::where('key', $app)->first();
        abort_unless($a && Consent::where('user_id', $u->id)->where('app_id', $a->id)->whereNull('revoked_at')->exists(), 404, 'Consent not found.');
        Consents::revoke($u, $a);

        return response()->noContent();
    }
}
