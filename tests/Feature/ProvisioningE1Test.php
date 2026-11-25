<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\WebhooksController;
use App\Models\Grant;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\TwoFactor;
use App\Services\Webhooks;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** SPEC-provisioning E1: webhooks, events from grants, retries, catch-up list. */
class ProvisioningE1Test extends ClauthTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser('owner@cakesbygrace.ph', ['name' => 'Grace']);
        $this->admin->organizations()->attach($this->cbg->id, ['role' => 'owner', 'joined_at' => now()]);
        $this->admin->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
    }

    private function apiToken(array $scopes, ?string $orgId = null): string
    {
        $c = new OAuthClient;
        $c->forceFill(['type' => 'api', 'name' => 'script', 'secret' => 'api-secret-api-secret', 'organization_id' => $orgId ?? $this->cbg->id,
            'created_by' => $this->admin->id, 'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'scopes' => $scopes, 'revoked' => false])->save();

        return $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $c->id, 'client_secret' => 'api-secret-api-secret',
            'scope' => implode(' ', $scopes)])->assertOk()->json('access_token');
    }

    private function bearer(string $t)
    {
        return $this->flushHeaders()->withHeaders(['Authorization' => 'Bearer '.$t, 'Accept' => 'application/json']);
    }

    private function sent(): array
    {
        $out = [];
        foreach (Http::recorded() as [$req]) {
            $out[] = json_decode($req->body(), true);
        }

        return $out;
    }

    public function test_api_manages_endpoints_and_signs_a_ping(): void
    {
        Http::fake(['hooks.xpenses.test/*' => Http::response('ok', 200)]);
        $t = $this->apiToken(['apps:read', 'apps:write']);
        $this->bearer($t)->postJson('/api/v1/apps/xpenses/webhooks', ['url' => 'http://hooks.xpenses.test/in'])->assertStatus(422); // https only
        $this->bearer($t)->postJson('/api/v1/apps/xpenses/webhooks', ['url' => 'https://hooks.xpenses.test/in', 'events' => ['nope']])->assertStatus(422);
        $r = $this->bearer($t)->postJson('/api/v1/apps/xpenses/webhooks', ['url' => 'https://hooks.xpenses.test/in'])->assertCreated();
        $id = $r->json('id');
        $secret = $r->json('secret');
        $this->assertStringStartsWith('whsec_', $secret);
        $this->bearer($t)->getJson('/api/v1/apps/xpenses/webhooks')->assertOk()->assertJsonMissingPath('data.0.secret')->assertJsonPath('data.0.id', $id);

        $this->bearer($t)->postJson("/api/v1/apps/xpenses/webhooks/$id/test")->assertOk()->assertJsonPath('status', 'delivered')->assertJsonPath('last_status', 200);
        Http::assertSent(function (HttpRequest $req) use ($secret) {
            parse_str(str_replace(',', '&', $req->header('Clauth-Signature')[0]), $sig);

            return $req->header('Clauth-Event')[0] === 'ping'
                && hash_equals(hash_hmac('sha256', $sig['t'].'.'.$req->body(), $secret), $sig['v1'])
                && abs(time() - (int) $sig['t']) < 60 && json_decode($req->body(), true)['app'] === 'xpenses';
        });

        // Another organization's client can't see or change them.
        $other = $this->apiToken(['apps:read', 'apps:write'], $this->alab->id);
        $this->alab->update(['is_platform' => false]);
        $this->bearer($other)->getJson('/api/v1/apps/xpenses/webhooks')->assertStatus(403);

        $new = $this->bearer($t)->patchJson("/api/v1/apps/xpenses/webhooks/$id", ['rotate_secret' => true, 'events' => ['access.granted']])->assertOk()->json('secret');
        $this->assertNotSame($secret, $new);
        $this->bearer($t)->deleteJson("/api/v1/apps/xpenses/webhooks/$id")->assertNoContent();
        $this->assertSame(0, DB::table('webhook_endpoints')->count());
    }

    public function test_grant_changes_become_events_in_order_with_filter(): void
    {
        Http::fake(['*' => Http::response('', 204)]);
        [$all] = WebhooksController::create($this->xp, 'https://a.test/h', null, null);
        [$only] = WebhooksController::create($this->xp, 'https://b.test/h', ['access.removed'], null);
        $maria = $this->makeUser('maria@cakesbygrace.ph', ['name' => 'Maria']);

        $this->grant($maria, $this->cbg, 'member');
        $this->grant($maria, $this->cbg, 'finance');
        $maria->update(['name' => 'Maria C.']);
        Grant::where('user_id', $maria->id)->first()->delete();
        Webhooks::sendDue();

        $a = DB::table('webhook_deliveries')->where('webhook_endpoint_id', $all)->orderBy('sequence')->pluck('event')->all();
        $this->assertSame(['access.granted', 'access.changed', 'user.updated', 'access.removed'], $a);
        $this->assertSame(['access.removed'], DB::table('webhook_deliveries')->where('webhook_endpoint_id', $only)->pluck('event')->all());
        $this->assertSame(0, DB::table('webhook_deliveries')->where('status', '!=', 'delivered')->count());

        $byType = collect($this->sent())->keyBy('type');
        $this->assertSame(['finance'], $byType['access.changed']['data']['roles']);
        $this->assertSame('member', $byType['access.changed']['data']['from']);
        $this->assertContains('expense.approve', $byType['access.changed']['data']['permissions']);
        $this->assertSame($maria->sub(), $byType['access.granted']['data']['user']['id']);
        $this->assertSame('cakesbygrace', $byType['access.removed']['data']['org']);
        $this->assertSame([], $byType['access.removed']['data']['still_in']);
        $seq = collect($this->sent())->where('app', 'xpenses')->pluck('sequence')->all();
        $this->assertSame($seq, collect($seq)->sort()->values()->all());

        // Someone with no access to the app causes nothing.
        $before = DB::table('webhook_deliveries')->count();
        $this->makeUser('stranger@x.test')->update(['name' => 'Who']);
        $this->assertSame($before, DB::table('webhook_deliveries')->count());
    }

    public function test_retries_back_off_then_fail_and_endpoint_switches_off(): void
    {
        $up = false;
        Http::fake(function () use (&$up) {
            return $up ? Http::response('', 200) : Http::response('nope', 500);
        });
        [$id] = WebhooksController::create($this->xp, 'https://down.test/h', null, null);
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        Webhooks::sendDue();
        $d = DB::table('webhook_deliveries')->first();
        $this->assertSame('pending', $d->status);
        $this->assertSame(1, (int) $d->attempts);
        $this->assertSame(500, (int) $d->last_status);
        $this->assertTrue(now()->addSeconds(50)->lt($d->next_attempt_at));

        // Not due yet: nothing is sent.
        $calls = count(Http::recorded());
        Webhooks::sendDue();
        $this->assertCount($calls, Http::recorded());

        for ($i = 0; $i < 6; $i++) {
            DB::table('webhook_deliveries')->update(['next_attempt_at' => now()->subSecond()]);
            Webhooks::sendDue();
        }
        $this->assertSame('failed', DB::table('webhook_deliveries')->value('status'));

        // 50 failures in a row switch it off; turning it on again resends the failed ones.
        DB::table('webhook_endpoints')->where('id', $id)->update(['consecutive_failures' => 49]);
        $this->grant($this->makeUser('ana@cakesbygrace.ph'), $this->cbg, 'member');
        Webhooks::sendDue();
        $this->assertSame('disabled', DB::table('webhook_endpoints')->value('status'));
        $this->grant($this->makeUser('jo@cakesbygrace.ph'), $this->cbg, 'member');
        $this->assertSame(2, DB::table('webhook_deliveries')->count()); // nothing queued for a switched-off endpoint

        $up = true;
        $t = $this->apiToken(['apps:read', 'apps:write']);
        $this->bearer($t)->patchJson("/api/v1/apps/xpenses/webhooks/$id", ['status' => 'active'])->assertOk()->assertJsonPath('consecutive_failures', 0);
        Webhooks::sendDue();
        $this->assertSame(['delivered'], DB::table('webhook_deliveries')->distinct()->pluck('status')->all());
        $this->bearer($t)->getJson("/api/v1/apps/xpenses/webhooks/$id/deliveries")->assertOk()->assertJsonCount(2, 'data');
        $did = DB::table('webhook_deliveries')->value('id');
        $this->bearer($t)->postJson("/api/v1/apps/xpenses/deliveries/$did/redeliver")->assertOk()->assertJsonPath('status', 'delivered');
    }

    public function test_disabled_user_and_redirects_not_followed(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://evil.test'])]);
        WebhooksController::create($this->xp, 'https://a.test/h', ['user.disabled', 'user.enabled'], null);
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        $maria->update(['status' => 'disabled']);
        Webhooks::sendDue();
        $this->assertSame('user.disabled', DB::table('webhook_deliveries')->value('event'));
        $this->assertSame('pending', DB::table('webhook_deliveries')->value('status')); // a redirect is not success
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'evil'));
    }

    public function test_people_list_for_the_apps_own_server_client_and_api_clients(): void
    {
        $maria = $this->makeUser('maria@cakesbygrace.ph');
        $this->grant($maria, $this->cbg, 'finance');
        $ana = $this->makeUser('ana@alab.test');
        $this->grant($ana, $this->alab, 'member');
        $this->makeUser('nobody@x.test');

        $m2m = new OAuthClient;
        $m2m->forceFill(['app_id' => $this->xp->id, 'type' => 'm2m', 'name' => 'svc', 'secret' => 's3cret-s3cret', 'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'revoked' => false])->save();
        $tok = $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $m2m->id, 'client_secret' => 's3cret-s3cret', 'scope' => 'users:read'])->json('access_token');

        $r = $this->bearer($tok)->getJson('/api/v1/apps/xpenses/people?limit=1')->assertOk()->assertJsonCount(1, 'data');
        $next = $r->json('next_cursor');
        $this->assertNotNull($next);
        $r2 = $this->bearer($tok)->getJson('/api/v1/apps/xpenses/people?limit=1&cursor='.$next)->assertOk();
        $emails = [$r->json('data.0.user.email'), $r2->json('data.0.user.email')];
        sort($emails);
        $this->assertSame(['ana@alab.test', 'maria@cakesbygrace.ph'], $emails);
        $this->bearer($tok)->getJson('/api/v1/apps/xpenses/people')->assertJsonPath('data.0.orgs.cakesbygrace.roles.0', 'finance');

        // ?since= only people changed since then.
        $this->travel(5)->minutes();
        $since = now()->toIso8601String();
        $this->travel(1)->minutes();
        $this->grant($ana, $this->alab, 'finance');
        $this->bearer($tok)->getJson('/api/v1/apps/xpenses/people?since='.urlencode($since))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.email', 'ana@alab.test');

        // Not for another app's client, nor without users:read, nor with no token.
        $this->flushHeaders()->getJson('/api/v1/apps/xpenses/people')->assertStatus(401);
        $other = \App\Models\Application::create(['key' => 'studio', 'name' => 'Studio', 'status' => 'live', 'home_url' => 'https://s.test', 'owner_organization_id' => $this->cbg->id]);
        $this->bearer($tok)->getJson('/api/v1/apps/studio/people')->assertStatus(401);

        // An API client of the publisher, with apps:read.
        $this->bearer($this->apiToken(['apps:read']))->getJson('/api/v1/apps/xpenses/people')->assertOk()->assertJsonCount(2, 'data');
        $this->bearer($this->apiToken(['users:read']))->getJson('/api/v1/apps/xpenses/people')->assertStatus(403);
    }

    public function test_console_webhooks_tab(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $as = fn () => $this->actingAs($this->admin)->withSession(['auth.mfa' => true]);
        $as()->get('/admin/apps/xpenses?tab=webhooks')->assertOk()->assertSee('No webhooks yet')->assertSee('/api/v1/apps/xpenses/people');
        $as()->post('/admin/apps/xpenses/webhooks', ['url' => 'https://hooks.xpenses.test/clauth/webhook', 'events' => ['access.granted', 'access.removed']])
            ->assertRedirect('/admin/apps/xpenses?tab=webhooks')->assertSessionHas('webhook_secret');
        $id = DB::table('webhook_endpoints')->value('id');
        $as()->post("/admin/apps/xpenses/webhooks/$id/test")->assertSessionHas('status', 'Test delivered (HTTP 200).');
        $as()->get('/admin/apps/xpenses?tab=webhooks')->assertOk()->assertSee('hooks.xpenses.test')->assertSee('Access given, Access removed')->assertSee('Delivered');
        $as()->patch("/admin/apps/xpenses/webhooks/$id", ['status' => 'disabled'])->assertRedirect();
        $this->assertSame('disabled', DB::table('webhook_endpoints')->value('status'));
        $as()->patch("/admin/apps/xpenses/webhooks/$id", ['rotate' => 1])->assertSessionHas('webhook_secret');
        $as()->delete("/admin/apps/xpenses/webhooks/$id")->assertRedirect();
        $this->assertSame(0, DB::table('webhook_endpoints')->count());

        // A member of another organization can't reach it (the app is hidden from them).
        $x = $this->makeUser('dev@alab.test');
        $x->organizations()->attach($this->alab->id, ['role' => 'developer', 'joined_at' => now()]);
        $this->actingAs($x)->withSession(['auth.mfa' => true])->post('/admin/apps/xpenses/webhooks', ['url' => 'https://evil.test/h'])->assertNotFound();
    }
}
