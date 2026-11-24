<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Connection;
use App\Models\OAuthClient;
use App\Models\Team;
use App\Models\User;
use App\Services\ClientMetadata;
use App\Services\ConfigTransfer;
use App\Services\PersonalTokens;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/** P5a: agents, CIBA, dynamic client registration + metadata documents, config export/import (SPEC-platform §8.3, §9.3, §9.4). */
class PlatformP5aTest extends ClauthTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->role('member')->update(['tier' => 'member']);
        $this->role('finance')->update(['tier' => 'admin']);
        $this->admin = $this->makeUser('owner@cakesbygrace.ph', ['name' => 'Grace']);
        $this->admin->organizations()->attach($this->cbg->id, ['role' => 'owner', 'joined_at' => now()]);
        $this->admin->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
    }

    private function api(array $scopes): \Closure
    {
        $c = new OAuthClient;
        $c->forceFill(['type' => 'api', 'name' => 's', 'secret' => 'api-secret-api-secret', 'organization_id' => $this->cbg->id, 'created_by' => $this->admin->id,
            'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'scopes' => $scopes, 'revoked' => false])->save();
        $t = $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $c->id, 'client_secret' => 'api-secret-api-secret', 'scope' => implode(' ', $scopes)])->json('access_token');

        return fn () => $this->flushHeaders()->withHeaders(['Authorization' => "Bearer $t", 'Accept' => 'application/json']);
    }

    private function claims(string $jwt): array
    {
        return json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true);
    }

    // ------------------------------------------------------------ agents

    public function test_organization_agent_gets_its_own_roles_and_tokens(): void
    {
        $api = $this->api(['agents:read', 'agents:write']);
        $r = $api()->postJson('/api/v1/orgs/cakesbygrace/agents', ['name' => 'Stock bot', 'apps' => ['xpenses' => 'member']])->assertCreated();
        [$cid, $secret, $id] = [$r->json('client_id'), $r->json('client_secret'), $r->json('id')];
        $this->assertStringStartsWith('agt_', $id);

        $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $cid, 'client_secret' => $secret])->assertStatus(400)->assertJsonPath('error', 'invalid_target');
        $tok = $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $cid, 'client_secret' => $secret, 'audience' => 'xpenses'])->assertOk()->json('access_token');
        $c = $this->claims($tok);
        $this->assertSame([$id, 'xpenses', ['member']], [$c['sub'], $c['aud'], $c['orgs']['cakesbygrace']['roles']]);
        $this->assertArrayNotHasKey('act', $c);

        // AuthZEN can ask about agents too.
        $m2m = new OAuthClient;
        $m2m->forceFill(['app_id' => $this->xp->id, 'type' => 'm2m', 'name' => 'svc', 'secret' => 's3cret-s3cret', 'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'revoked' => false])->save();
        $az = $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $m2m->id, 'client_secret' => 's3cret-s3cret', 'scope' => 'authzen'])->json('access_token');
        $this->flushHeaders()->withHeaders(['Authorization' => "Bearer $az"])->postJson('/access/v1/evaluation', ['subject' => ['type' => 'agent', 'id' => $id],
            'action' => ['name' => 'expense.submit'], 'resource' => ['type' => 'app', 'id' => 'xpenses']])->assertJsonPath('decision', true);

        // Owner tier needs an org owner; suspension stops tokens.
        $api()->putJson("/api/v1/agents/$id/access", ['apps' => ['xpenses' => 'none']])->assertOk();
        $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $cid, 'client_secret' => $secret, 'audience' => 'xpenses'])->assertStatus(400);
        $api()->putJson("/api/v1/agents/$id/access", ['apps' => ['xpenses' => 'finance']])->assertOk();
        $api()->patchJson("/api/v1/agents/$id", ['status' => 'suspended'])->assertOk()->assertJsonPath('usable', false);
        $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $cid, 'client_secret' => $secret, 'audience' => 'xpenses'])->assertStatus(400)->assertJsonPath('error', 'unauthorized_client');
        $new = $api()->postJson("/api/v1/agents/$id/secret")->assertOk()->json('client_secret');
        \Illuminate\Support\Once::flush(); // Passport memoizes client lookups per process; a real request starts fresh
        $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $cid, 'client_secret' => $secret, 'audience' => 'xpenses'])->assertStatus(401); // old credentials gone
        $this->assertNotSame($secret, $new);
    }

    public function test_personal_agent_acts_for_its_owner_and_stops_with_them(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'finance');
        [, $jwt] = PersonalTokens::create($maria, 'cli', ['me:read', 'me:write'], 7);
        $r = $this->flushHeaders()->withHeaders(['Authorization' => "Bearer $jwt"])->postJson('/api/v1/me/agents', ['name' => 'My assistant', 'org' => 'cakesbygrace'])->assertCreated();
        $tok = $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $r->json('client_id'), 'client_secret' => $r->json('client_secret'), 'audience' => 'xpenses'])
            ->assertOk()->json('access_token');
        $c = $this->claims($tok);
        $this->assertSame([$maria->sub(), ['finance']], [$c['act']['sub'], $c['orgs']['cakesbygrace']['roles']]);
        $this->assertArrayNotHasKey('alab-apparel', $c['orgs']);

        $maria->update(['status' => 'disabled']);
        $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $r->json('client_id'), 'client_secret' => $r->json('client_secret'), 'audience' => 'xpenses'])
            ->assertStatus(400)->assertJsonPath('error', 'unauthorized_client');
    }

    public function test_console_agents_page(): void
    {
        $as = fn () => $this->actingAs($this->admin)->withSession(['auth.mfa' => true]);
        $as()->post("/admin/orgs/{$this->cbg->id}/agents", ['name' => 'Night bot'])->assertSessionHas('agent_secret');
        $a = Agent::firstOrFail();
        $as()->put("/admin/orgs/{$this->cbg->id}/agents/{$a->id}/access", ['apps' => ['xpenses' => 'member']])->assertSessionHas('status');
        $as()->get("/admin/orgs/{$this->cbg->id}/agents")->assertOk()->assertSee('Night bot');
        $as()->get("/admin/orgs/{$this->alab->id}/agents")->assertNotFound();
        $as()->post("/admin/orgs/{$this->cbg->id}/agents/{$a->id}/status");
        $this->assertSame('suspended', $a->fresh()->status);
    }

    // ------------------------------------------------------------ CIBA

    public function test_ciba_approve_on_your_phone(): void
    {
        Mail::fake();
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        $this->getJson('/.well-known/openid-configuration')->assertJsonPath('backchannel_authentication_endpoint', config('clauth.issuer').'/oauth/bc-authorize');
        $start = fn (array $x = []) => $this->post('/oauth/bc-authorize', $x + ['client_id' => $this->client->id, 'client_secret' => $this->secret, 'scope' => 'openid clauth',
            'login_hint' => $maria->email, 'binding_message' => 'Pay ₱1,250 at counter 2']);
        $start(['client_secret' => 'wrong'])->assertStatus(401);
        $start(['login_hint' => 'nobody@x.ph'])->assertStatus(400)->assertJsonPath('error', 'unknown_user_id');
        $id = $start()->assertOk()->assertJsonPath('interval', 5)->json('auth_req_id');
        Mail::assertSent(\App\Mail\TemplateMail::class, fn ($m) => $m->hasTo($maria->email) && str_contains($m->link, "/approve/$id"));

        $poll = fn () => $this->post('/oauth/token', ['grant_type' => 'urn:openid:params:grant-type:ciba', 'auth_req_id' => $id, 'client_id' => $this->client->id, 'client_secret' => $this->secret]);
        $poll()->assertStatus(400)->assertJsonPath('error', 'authorization_pending');
        $poll()->assertJsonPath('error', 'slow_down');

        $this->actingAs($maria)->withSession(['auth.via' => 'password', 'auth.mfa' => true, 'auth.amr' => ['pwd']]);
        $this->get("/approve/$id")->assertOk()->assertSee('Pay ₱1,250 at counter 2');
        $this->post("/approve/$id", ['decision' => 'approve'])->assertOk();
        $tok = $poll()->assertOk()->json();
        $this->assertSame($maria->sub(), $this->claims($tok['access_token'])['sub']);
        $this->assertContains('mca', $this->claims($tok['access_token'])['amr']);
        $this->assertNotEmpty($tok['id_token']);
        $poll()->assertStatus(400)->assertJsonPath('error', 'invalid_grant'); // once

        $id2 = $start()->json('auth_req_id');
        $this->post("/approve/$id2", ['decision' => 'deny'])->assertOk();
        $this->post('/oauth/token', ['grant_type' => 'urn:openid:params:grant-type:ciba', 'auth_req_id' => $id2, 'client_id' => $this->client->id, 'client_secret' => $this->secret])
            ->assertJsonPath('error', 'access_denied');
    }

    // ------------------------------------------------------------ DCR + CIMD

    public function test_dynamic_client_registration(): void
    {
        $this->postJson('/oauth/register', ['client_name' => 'X', 'redirect_uris' => ['https://x.test/cb']])->assertStatus(401);
        $api = $this->api(['apps:read', 'apps:write']);
        $init = $api()->postJson('/api/v1/apps/xpenses/registration-tokens', ['name' => 'Partners', 'uses' => 1])->assertCreated()->json('initial_access_token');
        $reg = fn ($body) => $this->flushHeaders()->withHeaders(['Authorization' => "Bearer $init"])->postJson('/oauth/register', $body);
        $reg(['client_name' => 'Bad', 'redirect_uris' => ['http://evil.test/cb']])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        $r = $reg(['client_name' => 'Partner app', 'redirect_uris' => ['https://partner.test/cb'], 'token_endpoint_auth_method' => 'none'])->assertCreated()->json();
        $this->assertArrayNotHasKey('client_secret', $r);
        $this->assertSame('public', OAuthClient::find($r['client_id'])->type);
        $reg(['client_name' => 'Again', 'redirect_uris' => ['https://partner.test/cb']])->assertStatus(401); // one use only

        $mgmt = fn () => $this->flushHeaders()->withHeaders(['Authorization' => 'Bearer '.$r['registration_access_token']]);
        $mgmt()->getJson('/oauth/register/'.$r['client_id'])->assertOk()->assertJsonPath('client_name', 'Partner app');
        $mgmt()->putJson('/oauth/register/'.$r['client_id'], ['client_name' => 'Partner', 'redirect_uris' => ['https://partner.test/cb2']])->assertOk()->assertJsonPath('redirect_uris', ['https://partner.test/cb2']);
        $this->flushHeaders()->withHeaders(['Authorization' => 'Bearer nope'])->getJson('/oauth/register/'.$r['client_id'])->assertStatus(401);
        $mgmt()->deleteJson('/oauth/register/'.$r['client_id'])->assertNoContent();
        $this->assertTrue(OAuthClient::find($r['client_id'])->revoked);
    }

    public function test_client_id_metadata_documents(): void
    {
        ClientMetadata::$resolve = fn ($h) => $h === 'tool.test' ? ['93.184.216.34'] : ['10.0.0.5'];
        Http::fake(['https://tool.test/client.json' => Http::response(['client_id' => 'https://tool.test/client.json', 'client_name' => 'MCP Tool', 'redirect_uris' => ['http://127.0.0.1:7777/cb']]),
            'https://lying.test/*' => Http::response(['client_id' => 'https://other.test/x', 'redirect_uris' => ['https://x.test/cb']])]);
        $api = $this->api(['apps:read', 'apps:write']);
        $api()->getJson('/api/v1/cimd/preview?url='.urlencode('https://tool.test/client.json'))->assertOk()->assertJsonPath('client_name', 'MCP Tool');
        $api()->getJson('/api/v1/cimd/preview?url='.urlencode('https://internal.test/client.json'))->assertStatus(422);
        ClientMetadata::$resolve = fn ($h) => ['93.184.216.34'];
        $api()->getJson('/api/v1/cimd/preview?url='.urlencode('https://lying.test/x'))->assertStatus(422);
        $api()->postJson('/api/v1/apps/xpenses/clients', ['cimd_url' => 'https://tool.test/client.json'])->assertCreated()->assertJsonPath('name', 'MCP Tool')->assertJsonPath('type', 'public');
        ClientMetadata::$resolve = null;
    }

    // ------------------------------------------------------------ config as code

    public function test_export_and_import_with_secrets_and_dry_run(): void
    {
        Team::create(['organization_id' => $this->cbg->id, 'key' => 'ayala', 'name' => 'Ayala']);
        Connection::create(['organization_id' => $this->cbg->id, 'type' => 'http', 'key' => 'cbg-hr', 'name' => 'HR', 'status' => 'active',
            'config' => ['url' => 'https://hr.cakesbygrace.ph/check', 'secret' => 'super-secret-value-123']]);
        $api = $this->api(['config:read', 'config:write']);
        $doc = $api()->getJson('/api/v1/export?org=cakesbygrace')->assertOk()->json();
        $this->assertSame('${secret:HR_SECRET}', $doc['connections'][0]['config']['secret']);
        $this->assertStringNotContainsString('super-secret-value-123', json_encode($doc));

        // Change the document, then plan and apply into the same organization.
        $doc['teams'][] = ['key' => 'talisay', 'name' => 'Talisay', 'description' => null];
        $doc['teams'][0]['name'] = 'Ayala Center';
        $api()->postJson('/api/v1/import', ['org' => 'cakesbygrace', 'document' => $doc])->assertStatus(422)->assertJsonPath('errors.0', fn ($e) => str_contains($e, 'HR_SECRET'));
        $api()->putJson('/api/v1/secrets/HR_SECRET', ['org' => 'cakesbygrace', 'value' => 'rotated-secret-456'])->assertOk()->assertJsonMissingPath('value');
        $plan = $api()->postJson('/api/v1/import', ['org' => 'cakesbygrace', 'document' => $doc])->assertOk()->assertJsonPath('applied', false);
        $this->assertSame(1, $plan->json('summary.create'));
        $this->assertNull(Team::where('key', 'talisay')->first());
        $api()->postJson('/api/v1/import', ['org' => 'cakesbygrace', 'document' => $doc, 'dry_run' => false])->assertOk()->assertJsonPath('applied', true);
        $this->assertSame(['Ayala Center', true], [Team::where('key', 'ayala')->value('name'), Team::where('key', 'talisay')->exists()]);
        $this->assertSame('rotated-secret-456', Connection::where('key', 'cbg-hr')->first()->cfg('secret'));
        $api()->getJson('/api/v1/secrets?org=cakesbygrace')->assertJsonPath('data.0', fn ($r) => $r['name'] === 'HR_SECRET' && ! isset($r['value']));

        // Apps must exist on the target.
        $doc['apps'][] = ['key' => 'not-here', 'roles' => []];
        $api()->postJson('/api/v1/import', ['org' => 'cakesbygrace', 'document' => $doc])->assertStatus(422);
        $api()->postJson('/api/v1/import', ['org' => 'alab-apparel', 'document' => $doc])->assertNotFound();
    }

    public function test_openapi_still_matches_router(): void
    {
        $routes = array_keys(\App\Api\OpenApi::routes());
        sort($routes);
        $docs = array_keys(\App\Api\OpenApi::DOCS);
        sort($docs);
        $this->assertSame($docs, $routes);
    }
}
