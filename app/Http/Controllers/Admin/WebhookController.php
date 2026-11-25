<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\WebhooksController as Api;
use App\Models\Application;
use App\Services\Audit;
use App\Services\Webhooks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** App › Webhooks (SPEC-provisioning E1). Same rules as the API; the routes sit in the admin:apps group. */
class WebhookController
{
    private function endpoint(Application $app, int $id): object
    {
        return DB::table('webhook_endpoints')->where('app_id', $app->id)->find($id) ?? abort(404);
    }

    private function back(Application $app)
    {
        return redirect()->route('admin.apps.show', [$app->key, 'tab' => 'webhooks']);
    }

    public function store(Request $request, Application $app)
    {
        $data = $request->validate(Api::rules());
        if (DB::table('webhook_endpoints')->where('app_id', $app->id)->count() >= 5) {
            return $this->back($app)->withErrors(['url' => 'An app can have up to 5 webhook endpoints.']);
        }
        [, $secret] = Api::create($app, $data['url'], $data['events'] ?? null, $request->user()->id);

        return $this->back($app)->with('webhook_secret', $secret);
    }

    /** Switch on/off (on = resend recent failures) or make a new secret. */
    public function update(Request $request, Application $app, int $id)
    {
        $e = $this->endpoint($app, $id);
        if ($request->boolean('rotate')) {
            $secret = 'whsec_'.Str::random(40);
            DB::table('webhook_endpoints')->where('id', $e->id)->update(['secret' => Crypt::encryptString($secret), 'updated_at' => now()]);
            Audit::log('app.webhook_updated', 'app', $app, after: ['secret' => 'rotated'], appId: $app->id);

            return $this->back($app)->with('webhook_secret', $secret);
        }
        $on = $request->input('status') === 'active';
        DB::table('webhook_endpoints')->where('id', $e->id)->update(['status' => $on ? 'active' : 'disabled', 'updated_at' => now()] + ($on ? ['consecutive_failures' => 0] : []));
        if ($on && $e->status !== 'active') {
            DB::table('webhook_deliveries')->where('webhook_endpoint_id', $e->id)->where('status', 'failed')->where('created_at', '>', now()->subDays(3))
                ->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now()]);
            DB::table('webhook_deliveries')->where('webhook_endpoint_id', $e->id)->where('status', 'pending')->update(['next_attempt_at' => now()]);
        }
        Audit::log('app.webhook_updated', 'app', $app, after: ['status' => $on ? 'active' : 'disabled'], appId: $app->id);

        return $this->back($app)->with('status', $on ? 'Webhook on. Recent failed deliveries will be sent again within a minute.' : 'Webhook off. Nothing is sent to it until you turn it on.');
    }

    public function destroy(Application $app, int $id)
    {
        $this->endpoint($app, $id);
        DB::table('webhook_endpoints')->where('id', $id)->delete();
        Audit::log('app.webhook_removed', 'app', $app, before: ['id' => $id], appId: $app->id);

        return $this->back($app)->with('status', 'Webhook removed.');
    }

    public function test(Application $app, int $id)
    {
        $e = $this->endpoint($app, $id);
        abort_if($e->status !== 'active', 422, 'Turn the webhook on first.');
        $ids = Webhooks::emit($app, 'ping', ['message' => 'Hello from Clauth']);
        $mine = DB::table('webhook_deliveries')->whereIn('id', $ids)->where('webhook_endpoint_id', $e->id)->value('id');
        Webhooks::send([$mine]);
        $d = DB::table('webhook_deliveries')->find($mine);

        return $this->back($app)->with('status', $d->status === 'delivered' ? "Test delivered (HTTP {$d->last_status})." : 'Test not delivered: '.($d->last_status ? "HTTP {$d->last_status}" : $d->last_error).'. It will be retried.');
    }

    public function redeliver(Application $app, string $delivery)
    {
        $d = DB::table('webhook_deliveries')->join('webhook_endpoints', 'webhook_endpoints.id', '=', 'webhook_deliveries.webhook_endpoint_id')
            ->where('webhook_endpoints.app_id', $app->id)->where('webhook_deliveries.id', $delivery)->select('webhook_deliveries.*')->first() ?? abort(404);
        DB::table('webhook_deliveries')->where('id', $d->id)->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now()]);
        Webhooks::send([$d->id]);

        return $this->back($app)->with('status', DB::table('webhook_deliveries')->where('id', $d->id)->value('status') === 'delivered' ? 'Delivered.' : 'Still not delivered; it will be retried.');
    }
}
