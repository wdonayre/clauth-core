<?php

namespace App\Http\Controllers;

use App\Api\Problem;
use App\Models\Application;
use App\Models\OAuthClient;
use App\Models\Organization;
use App\Models\User;
use App\Services\Claims;
use App\Services\SigningKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * AuthZEN 1.0 policy decision point (SPEC-platform §9.1): "can this person do this action in this app (and org)?"
 * Answers come from the same grants and permissions tokens carry. Callers: an app's own server client (scope
 * `authzen`, its own app only) or an API client (scope `authzen`, apps and people in its organization).
 *
 * subject  {type: "user", id: "usr_…" | email}
 * action   {name: "<permission key>" | "role:<role key>"}
 * resource {type: "app", id: "<app key>", properties: {org: "<slug>"}}   (org optional: any organization counts)
 */
class AuthzenController
{
    public function configuration()
    {
        $iss = config('clauth.issuer');

        return response()->json([
            'policy_decision_point' => $iss,
            'access_evaluation_endpoint' => "$iss/access/v1/evaluation",
            'access_evaluations_endpoint' => "$iss/access/v1/evaluations",
            'search_action_endpoint' => "$iss/access/v1/search/action",
        ])->header('Cache-Control', 'public, max-age=300');
    }

    public function evaluation(Request $request)
    {
        if (! is_array($caller = $this->caller($request))) {
            return $caller;
        }
        $body = $request->json()->all();

        return response()->json($this->decide($caller, $body['subject'] ?? null, $body['action'] ?? null, $body['resource'] ?? null), 200, ['Cache-Control' => 'no-store']);
    }

    /** Batch: top-level subject/action/resource are defaults each item may override. */
    public function evaluations(Request $request)
    {
        if (! is_array($caller = $this->caller($request))) {
            return $caller;
        }
        $body = $request->json()->all();
        $items = $body['evaluations'] ?? null;
        if (! is_array($items) || $items === []) {
            return response()->json($this->decide($caller, $body['subject'] ?? null, $body['action'] ?? null, $body['resource'] ?? null));
        }
        if (count($items) > 100) {
            return Problem::make(422, 'Too many evaluations', 'Send at most 100 evaluations per request.');
        }
        $semantic = $body['options']['evaluations_semantic'] ?? 'execute_all';
        $out = [];
        foreach ($items as $it) {
            $d = $this->decide($caller, $it['subject'] ?? $body['subject'] ?? null, $it['action'] ?? $body['action'] ?? null, $it['resource'] ?? $body['resource'] ?? null);
            $out[] = $d;
            if (($semantic === 'deny_on_first_deny' && ! $d['decision']) || ($semantic === 'permit_on_first_permit' && $d['decision'])) {
                break;
            }
        }

        return response()->json(['evaluations' => $out], 200, ['Cache-Control' => 'no-store']);
    }

    /** Every action (permission) the subject may take on the resource. */
    public function searchAction(Request $request)
    {
        if (! is_array($caller = $this->caller($request))) {
            return $caller;
        }
        $body = $request->json()->all();
        [$user, $app, $orgs, $err] = $this->resolve($caller, $body['subject'] ?? null, $body['resource'] ?? null);
        if ($err) {
            return Problem::make(400, 'Invalid request', $err);
        }
        $perms = collect($user && $app ? $this->granted($user, $app, $orgs) : [])->flatMap(fn ($c) => $c['permissions'])->unique()->sort()->values();

        return response()->json(['results' => $perms->map(fn ($p) => ['name' => $p])->values()], 200, ['Cache-Control' => 'no-store']);
    }

    /** ['decision' => bool, 'context' => [...]] */
    private function decide(array $caller, $subject, $action, $resource): array
    {
        $name = is_array($action) ? (string) ($action['name'] ?? '') : '';
        [$user, $app, $orgs, $err] = $this->resolve($caller, $subject, $resource);
        if ($err || $name === '') {
            return ['decision' => false, 'context' => ['reason_admin' => ['en' => $err ?? 'action.name is required.']]];
        }
        if (! $user || ! $app) {
            return ['decision' => false];
        }
        $hits = [];
        foreach ($this->granted($user, $app, $orgs) as $slug => $c) {
            $ok = str_starts_with($name, 'role:') ? in_array(substr($name, 5), $c['roles'], true) : in_array($name, $c['permissions'], true);
            if ($ok) {
                $hits[] = $slug;
            }
        }

        // An organization's outside policy engine must agree too (SPEC-platform §9.1). Unreachable = no.
        $hits = array_values(array_filter($hits, fn ($slug) => $this->pdpAgrees($slug, $app, $subject, $action, $resource)));

        return $hits ? ['decision' => true, 'context' => ['orgs' => $hits]] : ['decision' => false];
    }

    private function pdpAgrees(string $slug, Application $app, $subject, $action, $resource): bool
    {
        $org = Organization::where('slug', $slug)->first();
        $pdps = \App\Models\Connection::where('type', 'authzen-pdp')->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $org?->id))->get()
            ->filter(fn ($c) => ! $c->cfg('apps') || in_array($app->key, $c->cfg('apps'), true));
        foreach ($pdps as $c) {
            try {
                $r = \Illuminate\Support\Facades\Http::acceptJson()->timeout(3)->when($c->cfg('token'), fn ($h) => $h->withToken($c->cfg('token')))
                    ->post($c->cfg('url'), ['subject' => $subject, 'action' => $action,
                        'resource' => ['type' => 'app', 'id' => $app->key, 'properties' => ['org' => $slug] + (array) (($resource['properties'] ?? []))]]);
                $c->forceFill(['last_used_at' => now()])->save();
                if (! $r->successful() || $r->json('decision') !== true) {
                    return false;
                }
            } catch (\Throwable $e) {
                report($e);

                return false;
            }
        }

        return true;
    }

    /** The orgs claim the person's token for this app would carry, limited to $orgs (null = all). */
    private function granted(User|\App\Models\Agent $user, Application $app, ?array $orgs): array
    {
        if ($user instanceof \App\Models\Agent) {
            $all = \App\Services\Agents::claims($user, $app);
        } elseif (! Claims::canUse($user, $app)) {
            return [];
        } else {
            $all = Claims::orgs($user, $app);
        }

        return $orgs === null ? $all : array_intersect_key($all, array_flip($orgs));
    }

    /** [user|null, app|null, org slugs|null, error|null]. Unknown or out-of-reach subjects come back as null (a plain "no"). */
    private function resolve(array $caller, $subject, $resource): array
    {
        if (! is_array($subject) || ! in_array($subject['type'] ?? '', ['user', 'identity', 'agent'], true) || ! is_string($subject['id'] ?? null)) {
            return [null, null, null, 'subject must be {"type": "user", "id": "usr_…"} or {"type": "agent", "id": "agt_…"}.'];
        }
        $resource = is_array($resource) ? $resource : [];
        if (($resource['type'] ?? 'app') !== 'app' && ($resource['type'] ?? '') !== 'application') {
            return [null, null, null, 'resource.type must be "app".'];
        }
        $key = $resource['id'] ?? $caller['app']?->key;
        $app = is_string($key) ? Application::where('key', $key)->first() : null;
        if (! $app) {
            return [null, null, null, 'resource.id must be an app key.'];
        }
        if ($caller['app'] && $caller['app']->id !== $app->id) {
            return [null, null, null, 'An app can only ask about itself.'];
        }
        $orgs = $caller['orgSlugs'];
        if (is_string($org = $resource['properties']['org'] ?? null)) {
            $orgs = $orgs === null || in_array($org, $orgs, true) ? [$org] : [];
        }
        $id = $subject['id'];
        if ($subject['type'] === 'agent' || str_starts_with($id, 'agt_')) {
            $agent = \App\Models\Agent::with(['organization', 'owner'])->find(str_starts_with($id, 'agt_') ? substr($id, 4) : $id);
            $ok = $agent && ($caller['orgSlugs'] === null || in_array($agent->organization?->slug, $caller['orgSlugs'], true));

            return [$ok ? $agent : null, $app, $orgs, null];
        }
        $user = str_contains($id, '@') ? User::where('email', mb_strtolower($id))->first() : User::findBySub($id);
        if ($user && $caller['orgSlugs'] !== null && ! $user->organizations()->whereIn('slug', $caller['orgSlugs'])->exists()) {
            $user = null;
        }

        return [$user, $app, $orgs, null];
    }

    /** ['app' => Application|null (app clients), 'orgSlugs' => array|null (API clients: their org)] or an error response. */
    private function caller(Request $request)
    {
        $t = SigningKeys::verify((string) $request->bearerToken());
        $c = $t?->claims();
        if (! $t || DB::table('oauth_access_tokens')->where('id', $c->get('jti'))->where('revoked', false)->doesntExist()) {
            return Problem::make(401, 'Invalid token', 'Send a client-credentials token with the authzen scope.')->header('WWW-Authenticate', 'Bearer error="invalid_token"');
        }
        if (! in_array('authzen', explode(' ', (string) $c->get('scope')), true) || ! str_starts_with((string) $c->get('sub'), 'client_')) {
            return Problem::make(403, 'Missing scope', 'This call needs a client-credentials token with the authzen scope.');
        }
        $client = OAuthClient::with(['app', 'organization', 'creator'])->find($c->get('client_id'));
        if (! $client || $client->revoked || $client->app?->isSuspended()) {
            return Problem::make(401, 'Invalid token', 'The client is turned off.');
        }
        $key = 'authzen:'.$client->id;
        if (RateLimiter::tooManyAttempts($key, 1200)) {
            return Problem::make(429, 'Too many requests', 'Slow down.')->header('Retry-After', (string) RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);
        if ($client->isApi()) {
            $by = $client->creator;
            $ok = $by && $by->status === 'active' && ($client->isPlatformApi() ? $by->isOwner() : ($by->isOwner() || in_array($by->orgRole($client->organization_id), ['owner', 'admin'], true)));
            if (! $ok) {
                return Problem::make(403, 'Client owner lost access', 'The person who created this API client no longer runs its organization.');
            }

            return ['app' => null, 'orgSlugs' => $client->isPlatformApi() ? null : [Organization::whereKey($client->organization_id)->value('slug')]];
        }
        if ($client->app) {
            return ['app' => $client->app, 'orgSlugs' => null];
        }

        return Problem::make(403, 'Not allowed', 'Use an app\'s server client or an API client.');
    }
}
