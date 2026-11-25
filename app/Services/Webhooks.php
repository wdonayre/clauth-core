<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Webhooks to apps (SPEC-provisioning E1). Events are recorded as deliveries when something changes (mostly from the
 * Grant model, the one table tokens read), sent right after the request that caused them, and retried by the scheduler
 * (`clauth:webhooks`, every minute): 1 min, 5 min, 30 min, 2 h, 6 h, 24 h, then failed. Signed like Stripe:
 * Clauth-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<body>")>.
 */
class Webhooks
{
    public const EVENTS = ['access.granted', 'access.changed', 'access.removed', 'user.updated', 'user.disabled', 'user.enabled', 'invite.accepted', 'ping'];

    private const BACKOFF = [60, 300, 1800, 7200, 21600, 86400];

    private const DISABLE_AFTER = 50;

    /** Delivery ids recorded in this request, sent after the response. */
    private static array $queued = [];

    private static bool $hooked = false;

    /** Record an event for every matching endpoint of $app. Returns the delivery ids. */
    public static function emit(Application $app, string $type, array $data): array
    {
        $endpoints = DB::table('webhook_endpoints')->where('app_id', $app->id)->where('status', 'active')->get()
            ->filter(fn ($e) => $type === 'ping' || ! $e->events || in_array($type, json_decode($e->events, true) ?: [], true));
        if ($endpoints->isEmpty()) {
            return [];
        }
        DB::table('apps')->where('id', $app->id)->increment('webhook_sequence');
        $seq = (int) DB::table('apps')->where('id', $app->id)->value('webhook_sequence');
        $ids = [];
        foreach ($endpoints as $e) {
            $id = (string) Str::ulid();
            $payload = ['id' => $id, 'type' => $type, 'created_at' => now()->toIso8601String(), 'app' => $app->key, 'sequence' => $seq, 'data' => $data];
            DB::table('webhook_deliveries')->insert(['id' => $id, 'webhook_endpoint_id' => $e->id, 'event' => $type, 'sequence' => $seq,
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'status' => 'pending',
                'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $ids[] = $id;
        }
        self::$queued = array_merge(self::$queued, $ids);
        if (! self::$hooked && ! app()->runningInConsole()) {
            self::$hooked = true;
            app()->terminating(function () {
                $ids = self::$queued;
                self::$queued = [];
                self::$hooked = false;
                self::send($ids);
            });
        }

        return $ids;
    }

    /** Send these deliveries now (used after the response, by the scheduler and by "Redeliver"). */
    public static function send(array $ids): void
    {
        foreach (DB::table('webhook_deliveries')->whereIn('id', $ids)->where('status', '!=', 'delivered')->orderBy('created_at')->get() as $d) {
            self::attempt($d);
        }
    }

    /** Scheduler: everything due. */
    public static function sendDue(int $limit = 200): int
    {
        $due = DB::table('webhook_deliveries')->where('status', 'pending')->where('next_attempt_at', '<=', now())->orderBy('created_at')->limit($limit)->pluck('id')->all();
        self::send($due);

        return count($due);
    }

    private static function attempt(object $d): void
    {
        $e = DB::table('webhook_endpoints')->find($d->webhook_endpoint_id);
        if (! $e || $e->status !== 'active') {
            return;
        }
        $t = time();
        $sig = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$d->payload, Crypt::decryptString($e->secret));
        $status = null;
        $error = null;
        try {
            $r = Http::withBody($d->payload, 'application/json')->timeout(10)->withOptions(['allow_redirects' => false])
                ->withHeaders(['Clauth-Event' => $d->event, 'Clauth-Delivery' => $d->id, 'Clauth-Signature' => $sig, 'User-Agent' => 'Clauth-Webhooks/1'])
                ->post($e->url);
            $status = $r->status();
            $ok = $r->successful();
            $error = $ok ? null : Str::limit(trim(strip_tags((string) $r->body())) ?: 'HTTP '.$status, 290);
        } catch (\Throwable $ex) {
            $ok = false;
            $error = Str::limit($ex->getMessage(), 290);
        }
        $attempts = $d->attempts + 1;
        if ($ok) {
            DB::table('webhook_deliveries')->where('id', $d->id)->update(['status' => 'delivered', 'attempts' => $attempts, 'last_status' => $status,
                'last_error' => null, 'delivered_at' => now(), 'next_attempt_at' => null, 'updated_at' => now()]);
            DB::table('webhook_endpoints')->where('id', $e->id)->update(['consecutive_failures' => 0, 'last_success_at' => now()]);

            return;
        }
        $wait = self::BACKOFF[$attempts - 1] ?? null;
        DB::table('webhook_deliveries')->where('id', $d->id)->update(['status' => $wait ? 'pending' : 'failed', 'attempts' => $attempts, 'last_status' => $status,
            'last_error' => $error, 'next_attempt_at' => $wait ? now()->addSeconds($wait) : null, 'updated_at' => now()]);
        DB::table('webhook_endpoints')->where('id', $e->id)->increment('consecutive_failures', 1, ['last_failure_at' => now()]);
        if ($e->consecutive_failures + 1 >= self::DISABLE_AFTER) {
            self::disable($e);
        }
    }

    private static function disable(object $e): void
    {
        DB::table('webhook_endpoints')->where('id', $e->id)->update(['status' => 'disabled', 'updated_at' => now()]);
        $app = Application::with('publisher')->find($e->app_id);
        Audit::log('app.webhook_disabled', 'app', $app, after: ['url' => $e->url, 'failures' => self::DISABLE_AFTER], appId: $e->app_id, actorType: 'system');
        $to = $app?->publisher?->users()->wherePivotIn('role', ['owner', 'admin'])->pluck('email') ?? collect();
        foreach ($to as $email) {
            rescue(fn () => Mail::to($email)->send(new \App\Mail\TemplateMail("Webhook turned off for {$app->name}",
                "Clauth couldn’t deliver to {$e->url} ".self::DISABLE_AFTER." times in a row, so it stopped trying.\n\nFix the endpoint, then turn it back on under App › Webhooks; the missed events are sent again.",
                route('admin.apps.show', $app->key))), report: true);
        }
    }

    // ---- event payloads ----

    public static function user(User $u): array
    {
        return ['id' => $u->sub(), 'email' => $u->email, 'name' => $u->name, 'status' => $u->status];
    }

    /** From the Grant model's events. $was = the role before (changed / removed). */
    public static function grantChanged(Grant $g, string $kind, ?int $oldRoleId = null): void
    {
        $app = Application::find($g->app_id);
        $user = User::withTrashed()->find($g->user_id);
        $org = Organization::find($g->organization_id);
        if (! $app || ! $user || ! $org || ! DB::table('webhook_endpoints')->where('app_id', $app->id)->where('status', 'active')->exists()) {
            return;
        }
        $role = \App\Models\AppRole::with('permissions')->find($kind === 'removed' ? ($oldRoleId ?? $g->app_role_id) : $g->app_role_id);
        $data = ['user' => self::user($user), 'org' => $org->slug, 'role' => $role?->key];
        if ($kind !== 'removed' && $role) {
            $perms = $role->permissionKeys();
            sort($perms);
            $data += ['roles' => $role->tokenKeys(), 'permissions' => $perms];
        }
        if ($kind === 'changed') {
            $data['from'] = \App\Models\AppRole::find($oldRoleId)?->key;
        }
        if ($kind === 'removed') {
            // Where they can still use the app; empty = no access left anywhere, so the app can close the account.
            $data['still_in'] = Organization::whereIn('id', Grant::where('user_id', $user->id)->where('app_id', $app->id)
                ->where('id', '!=', $g->id)->pluck('organization_id'))->orderBy('slug')->pluck('slug')->all();
        }
        self::emit($app, 'access.'.$kind, $data);
    }

    /** To every app the person can use. */
    public static function forUser(User $u, string $type, array $extra = []): void
    {
        $appIds = Grant::where('user_id', $u->id)->distinct()->pluck('app_id');
        foreach (Application::whereIn('id', $appIds)->get() as $app) {
            self::emit($app, $type, ['user' => self::user($u)] + $extra);
        }
    }
}
