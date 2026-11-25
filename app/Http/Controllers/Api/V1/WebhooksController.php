<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Middleware\ApiClient;
use App\Models\Application;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\Audit;
use App\Services\Claims;
use App\Services\SigningKeys;
use App\Services\Webhooks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Webhook endpoints of an app and the people catch-up list (SPEC-provisioning E1). */
class WebhooksController
{
    use UsesApi;

    private function managed(string $key): Application
    {
        $a = $this->api()->app($key);
        abort_unless($this->api()->canManageApp($a), 403, 'Only the organization that publishes this app can change its webhooks.');

        return $a;
    }

    public static function json(object $e): array
    {
        return ['id' => $e->id, 'url' => $e->url, 'events' => $e->events ? json_decode($e->events, true) : null, 'status' => $e->status,
            'consecutive_failures' => (int) $e->consecutive_failures, 'last_success_at' => $e->last_success_at, 'last_failure_at' => $e->last_failure_at];
    }

    public function index(string $app)
    {
        $a = $this->managed($app);

        return response()->json(['data' => DB::table('webhook_endpoints')->where('app_id', $a->id)->orderBy('id')->get()->map(fn ($e) => self::json($e))->values()]);
    }

    public static function rules(): array
    {
        return ['url' => 'required|url|starts_with:https://|max:500', 'events' => 'nullable|array', 'events.*' => 'in:'.implode(',', Webhooks::EVENTS)];
    }

    /** {url, events?: [..] | null} — the signing secret is in this response only. */
    public function store(Request $request, string $app)
    {
        $a = $this->managed($app);
        $data = $request->validate(self::rules());
        abort_if(DB::table('webhook_endpoints')->where('app_id', $a->id)->count() >= 5, 422, 'An app can have up to 5 webhook endpoints.');
        [$id, $secret] = self::create($a, $data['url'], $data['events'] ?? null, $this->api()->actor->id);

        return response()->json(self::json(DB::table('webhook_endpoints')->find($id)) + ['secret' => $secret], 201);
    }

    public static function create(Application $a, string $url, ?array $events, ?string $by): array
    {
        $secret = 'whsec_'.Str::random(40);
        $id = DB::table('webhook_endpoints')->insertGetId(['app_id' => $a->id, 'url' => $url, 'secret' => Crypt::encryptString($secret),
            'events' => $events ? json_encode(array_values($events)) : null, 'status' => 'active', 'created_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
        Audit::log('app.webhook_added', 'app', $a, after: ['url' => $url], appId: $a->id);

        return [$id, $secret];
    }

    /** PATCH {url?, events?, status?: active|disabled, rotate_secret?: true}. Turning it back on resends failed deliveries. */
    public function update(Request $request, string $app, int $id)
    {
        $a = $this->managed($app);
        $e = DB::table('webhook_endpoints')->where('app_id', $a->id)->find($id) ?? abort(404, 'Webhook not found.');
        $data = $request->validate(array_map(fn ($r) => 'sometimes|'.(is_array($r) ? implode('|', $r) : $r), self::rules()) + ['status' => 'sometimes|in:active,disabled', 'rotate_secret' => 'sometimes|boolean']);
        $set = array_intersect_key($data, array_flip(['url', 'status']));
        if (array_key_exists('events', $data)) {
            $set['events'] = $data['events'] ? json_encode(array_values($data['events'])) : null;
        }
        $secret = null;
        if (! empty($data['rotate_secret'])) {
            $secret = 'whsec_'.Str::random(40);
            $set['secret'] = Crypt::encryptString($secret);
        }
        if (($data['status'] ?? null) === 'active' && $e->status !== 'active') {
            $set['consecutive_failures'] = 0;
            DB::table('webhook_deliveries')->where('webhook_endpoint_id', $e->id)->where('status', 'failed')->where('created_at', '>', now()->subDays(3))
                ->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now()]);
            DB::table('webhook_deliveries')->where('webhook_endpoint_id', $e->id)->where('status', 'pending')->update(['next_attempt_at' => now()]);
        }
        DB::table('webhook_endpoints')->where('id', $e->id)->update($set + ['updated_at' => now()]);
        Audit::log('app.webhook_updated', 'app', $a, after: array_diff_key($set, ['secret' => 1]) + ($secret ? ['secret' => 'rotated'] : []), appId: $a->id);

        return response()->json(self::json(DB::table('webhook_endpoints')->find($e->id)) + array_filter(['secret' => $secret]));
    }

    public function destroy(string $app, int $id)
    {
        $a = $this->managed($app);
        abort_unless(DB::table('webhook_endpoints')->where('app_id', $a->id)->where('id', $id)->delete(), 404, 'Webhook not found.');
        Audit::log('app.webhook_removed', 'app', $a, before: ['id' => $id], appId: $a->id);

        return response()->noContent();
    }

    /** POST …/test — a ping, sent now; answers with what the endpoint said. */
    public function test(string $app, int $id)
    {
        $a = $this->managed($app);
        $e = DB::table('webhook_endpoints')->where('app_id', $a->id)->find($id) ?? abort(404, 'Webhook not found.');
        abort_if($e->status !== 'active', 422, 'Turn the webhook on first.');
        $ids = Webhooks::emit($a, 'ping', ['message' => 'Hello from Clauth']);
        $mine = DB::table('webhook_deliveries')->whereIn('id', $ids)->where('webhook_endpoint_id', $e->id)->value('id');
        Webhooks::send([$mine]);

        return response()->json(self::delivery(DB::table('webhook_deliveries')->find($mine)));
    }

    public static function delivery(object $d): array
    {
        return ['id' => $d->id, 'event' => $d->event, 'sequence' => (int) $d->sequence, 'status' => $d->status, 'attempts' => (int) $d->attempts,
            'last_status' => $d->last_status, 'last_error' => $d->last_error, 'next_attempt_at' => $d->next_attempt_at, 'delivered_at' => $d->delivered_at,
            'created_at' => $d->created_at];
    }

    public function deliveries(Request $request, string $app, int $id)
    {
        $a = $this->managed($app);
        abort_unless(DB::table('webhook_endpoints')->where('app_id', $a->id)->where('id', $id)->exists(), 404, 'Webhook not found.');
        $rows = DB::table('webhook_deliveries')->where('webhook_endpoint_id', $id)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->orderByDesc('created_at')->limit(min(200, (int) $request->query('limit', 50)))->get();

        return response()->json(['data' => $rows->map(fn ($d) => self::delivery($d))->values()]);
    }

    public function redeliver(string $app, string $delivery)
    {
        $a = $this->managed($app);
        $d = DB::table('webhook_deliveries')->join('webhook_endpoints', 'webhook_endpoints.id', '=', 'webhook_deliveries.webhook_endpoint_id')
            ->where('webhook_endpoints.app_id', $a->id)->where('webhook_deliveries.id', $delivery)->select('webhook_deliveries.*')->first() ?? abort(404, 'Delivery not found.');
        DB::table('webhook_deliveries')->where('id', $d->id)->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now()]);
        Webhooks::send([$d->id]);

        return response()->json(self::delivery(DB::table('webhook_deliveries')->find($d->id)));
    }

    /**
     * GET /apps/{app}/people — everyone with access to the app, with the same `orgs` the token carries. For the app's
     * own server client (scope users:read, its own app) or an API client (apps:read). ?since= for changes only.
     */
    public function people(Request $request, string $app)
    {
        $t = SigningKeys::verify((string) $request->bearerToken());
        $client = $t ? OAuthClient::find($t->claims()->get('client_id')) : null;
        if ($client?->isApi()) {
            return app(ApiClient::class)->handle($request, fn () => $this->peopleFor($request, $this->api()->app($app)), 'apps:read');
        }
        $a = Application::where('key', $app)->first();
        $ok = $t && $client && ! $client->revoked && $a && $client->app_id === $a->id
            && in_array('users:read', explode(' ', (string) $t->claims()->get('scope')), true)
            && DB::table('oauth_access_tokens')->where('id', $t->claims()->get('jti'))->where('revoked', false)->exists();
        if (! $ok) {
            return response()->json(['error' => 'invalid_token', 'error_description' => 'Use this app’s server client with the users:read scope.'], 401);
        }

        return $this->peopleFor($request, $a);
    }

    private function peopleFor(Request $request, Application $a)
    {
        $since = $request->query('since') ? \Carbon\Carbon::parse($request->query('since')) : null;
        $limit = max(1, min(500, (int) $request->query('limit', 200)));
        $q = User::query()->whereIn('id', DB::table('grants')->where('app_id', $a->id)->select('user_id'))
            ->when($since, fn ($q) => $q->where(fn ($w) => $w->where('updated_at', '>=', $since)
                ->orWhereIn('id', DB::table('grants')->where('app_id', $a->id)->where('changed_at', '>=', $since)->select('user_id'))))
            ->orderBy('id');
        $p = $q->cursorPaginate($limit);

        return response()->json(['app' => $a->key, 'data' => collect($p->items())->map(fn (User $u) => ['user' => Webhooks::user($u),
            'orgs' => (object) Claims::orgs($u, $a), 'updated_at' => $u->updated_at?->toIso8601String()])->values(), 'next_cursor' => $p->nextCursor()?->encode()]);
    }
}
