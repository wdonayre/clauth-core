<?php

namespace Tests\Feature;

use App\Api\OpenApi;
use App\Models\AccessProfile;
use App\Models\AppRole;
use App\Models\AuditEvent;
use App\Models\Grant;
use App\Models\Invite;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\TwoFactor;

/** Platform API P1 (SPEC-platform §5, §6, §14). */
class PlatformApiTest extends ClauthTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->role('finance')->update(['tier' => 'admin', 'position' => 0]);
        $this->role('member')->update(['tier' => 'member', 'position' => 1]);
        // Org owner of CakesbyGrace (runs it; not a Clauth owner).
        $this->admin = $this->makeUser('owner@cakesbygrace.ph', ['name' => 'Grace']);
        $this->admin->organizations()->attach($this->cbg->id, ['role' => 'owner', 'joined_at' => now()]);
    }

    /** Make an API client the way the console does; returns [client, secret]. */
    private function apiClient(array $scopes, ?User $by = null, bool $platform = false): array
    {
        $c = new OAuthClient;
        $c->forceFill(['type' => 'api', 'name' => 'script', 'secret' => 'api-secret-api-secret', 'organization_id' => $platform ? null : $this->cbg->id,
            'created_by' => ($by ?? $this->admin)->id, 'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'scopes' => $scopes, 'revoked' => false])->save();

        return [$c, 'api-secret-api-secret'];
    }

    private function token(array $scopes, ?User $by = null, bool $platform = false, ?array $ask = null): string
    {
        [$c, $s] = $this->apiClient($scopes, $by, $platform);

        return $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $c->id, 'client_secret' => $s,
            'scope' => implode(' ', $ask ?? $scopes)])->assertOk()->json('access_token');
    }

    private function api(string $token)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);
    }

    public function test_capabilities_and_missing_scope_is_problem_json(): void
    {
        $t = $this->token(['users:read']);
        $this->api($t)->getJson('/api/v1/capabilities')->assertOk()
            ->assertJsonPath('scopes', ['users:read'])->assertJsonPath('orgs', ['cakesbygrace'])->assertJsonPath('acts_as', $this->admin->sub());
        $r = $this->api($t)->getJson('/api/v1/orgs')->assertStatus(403);
        $this->assertSame('application/problem+json', $r->headers->get('Content-Type'));
        $r->assertJsonPath('title', 'Missing scope')->assertJsonStructure(['type', 'title', 'status', 'detail', 'request_id']);
        $this->flushHeaders()->getJson('/api/v1/capabilities')->assertStatus(401)->assertJsonPath('title', 'Invalid token');
    }

    public function test_a_client_only_gets_the_scopes_it_was_given(): void
    {
        $t = $this->token(['users:read'], ask: ['users:read', 'users:write', 'settings:write']);
        $this->api($t)->getJson('/api/v1/capabilities')->assertJsonPath('scopes', ['users:read']);
    }

    public function test_app_m2m_clients_cannot_use_the_management_api_but_keep_their_lookup(): void
    {
        $m2m = new OAuthClient;
        $m2m->forceFill(['app_id' => $this->xp->id, 'type' => 'm2m', 'name' => 'svc', 'secret' => 's3cret-s3cret', 'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'revoked' => false])->save();
        $t = $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $m2m->id, 'client_secret' => 's3cret-s3cret', 'scope' => 'users:read users:write'])->json('access_token');
        $this->api($t)->getJson('/api/v1/users')->assertStatus(401)->assertJsonPath('title', 'Not an API client');
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        $this->api($t)->getJson('/api/v1/users/'.$maria->sub())->assertOk()->assertJsonPath('email', 'maria@cakesbygrace.ph')->assertJsonMissingPath('grants');
    }

    /** §14 test 1: another organization's records are 404, never 403. */
    public function test_org_client_cannot_see_other_organizations(): void
    {
        $t = $this->token(['users:read', 'users:write', 'orgs:read', 'access:read', 'access:write']);
        $mine = $this->makeUser();
        $this->grant($mine, $this->cbg, 'member');
        $theirs = $this->makeUser('jam@alab.ph', ['name' => 'Jam']);
        $this->grant($theirs, $this->alab, 'finance');
        AccessProfile::create(['organization_id' => $this->alab->id, 'key' => 'finance', 'name' => 'Finance']);

        $ids = collect($this->api($t)->getJson('/api/v1/users')->assertOk()->json('data'))->pluck('email');
        $this->assertContains('maria@cakesbygrace.ph', $ids);
        $this->assertNotContains('jam@alab.ph', $ids);
        $this->api($t)->getJson('/api/v1/users/'.$theirs->sub())->assertNotFound();
        $this->api($t)->getJson('/api/v1/users/jam@alab.ph')->assertNotFound();
        $this->api($t)->patchJson('/api/v1/users/'.$theirs->sub(), ['name' => 'X'])->assertNotFound();
        $this->api($t)->postJson('/api/v1/users/'.$theirs->sub().'/disable')->assertNotFound();
        $this->api($t)->getJson('/api/v1/orgs/alab-apparel')->assertNotFound();
        $this->api($t)->getJson('/api/v1/orgs/alab-apparel/profiles/finance')->assertNotFound();
        $this->api($t)->getJson('/api/v1/orgs/cakesbygrace/members/'.$theirs->sub().'/access')->assertNotFound();
        $this->api($t)->getJson('/api/v1/grants')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('active', $theirs->fresh()->status);
    }

    /** P1 "done when": invite with a profile, accept, read it back in Who has what; the audit log names the client. */
    public function test_invite_with_profile_then_alignment_and_audit(): void
    {
        $t = $this->token(['users:read', 'users:write', 'access:read', 'access:write', 'audit:read']);
        $this->api($t)->postJson('/api/v1/orgs/cakesbygrace/profiles', ['name' => 'Finance', 'apps' => ['xpenses' => 'finance']])
            ->assertCreated()->assertJsonPath('key', 'finance')->assertJsonPath('apps.xpenses', 'finance');
        $r = $this->api($t)->postJson('/api/v1/invites', ['email' => 'Ana@CakesbyGrace.ph', 'name' => 'Ana', 'org' => 'cakesbygrace', 'profiles' => ['finance'], 'send' => false])
            ->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('profiles', ['finance'])->assertJsonPath('email_sent', false);
        $this->assertStringContainsString('/invite/', $r->json('link'));

        \App\Services\Invites::apply(Invite::firstWhere('email', 'ana@cakesbygrace.ph'), $ana = User::firstWhere('email', 'ana@cakesbygrace.ph'));
        $row = collect($this->api($t)->getJson('/api/v1/orgs/cakesbygrace/alignment')->assertOk()->json('data'))->firstWhere('email', 'ana@cakesbygrace.ph');
        $this->assertSame('finance', $row['apps']['xpenses']['role']);
        $this->assertSame('profile', $row['apps']['xpenses']['source']);

        $created = AuditEvent::where('event', 'profile.created')->first();
        $this->assertSame('api', $created->actor_type);
        $this->assertSame($this->admin->id, $created->actor_user_id);
        $this->assertNotNull($created->actor_client_id);
        $this->api($t)->getJson('/api/v1/audit?event=user.invited')->assertOk()->assertJsonPath('data.0.actor_type', 'api');
    }

    /** §14 test 3: access set over the API ends in the same grant the console makes. */
    public function test_access_exceptions_and_profiles_over_the_api(): void
    {
        $t = $this->token(['access:read', 'access:write', 'orgs:write', 'orgs:read']);
        $maria = $this->makeUser();
        $this->api($t)->putJson('/api/v1/orgs/cakesbygrace/members/maria@cakesbygrace.ph')->assertCreated();
        $this->api($t)->putJson('/api/v1/orgs/cakesbygrace/members/'.$maria->sub().'/access', ['apps' => ['xpenses' => 'finance'], 'reason' => 'Covers payroll'])
            ->assertOk()->assertJsonPath('apps.xpenses.role', 'finance')->assertJsonPath('apps.xpenses.source', 'override')
            ->assertJsonPath('apps.xpenses.exception.reason', 'Covers payroll');
        $this->assertSame($this->role('finance')->id, Grant::where('user_id', $maria->id)->value('app_role_id'));

        $this->api($t)->postJson('/api/v1/orgs/cakesbygrace/profiles', ['name' => 'Staff', 'apps' => ['xpenses' => 'member']])->assertCreated();
        $this->api($t)->putJson('/api/v1/orgs/cakesbygrace/members/'.$maria->sub().'/access', ['profiles' => ['staff'], 'apps' => ['xpenses' => 'profile']])
            ->assertOk()->assertJsonPath('apps.xpenses.role', 'member')->assertJsonPath('apps.xpenses.from_profiles', ['Staff']);
        $this->api($t)->putJson('/api/v1/orgs/cakesbygrace/members/'.$maria->sub().'/access', ['apps' => ['xpenses' => 'boss']])->assertStatus(422);

        $this->api($t)->deleteJson('/api/v1/orgs/cakesbygrace/members/'.$maria->sub())->assertNoContent();
        $this->assertFalse(Grant::where('user_id', $maria->id)->exists());
    }

    public function test_owner_tier_needs_an_org_owner_behind_the_client(): void
    {
        AppRole::create(['app_id' => $this->xp->id, 'key' => 'owner', 'name' => 'Owner', 'tier' => 'owner', 'position' => 2]);
        $orgAdmin = $this->makeUser('admin@cakesbygrace.ph', ['name' => 'Admin']);
        $orgAdmin->organizations()->attach($this->cbg->id, ['role' => 'admin', 'joined_at' => now()]);
        $t = $this->token(['access:write'], $orgAdmin);
        $this->api($t)->postJson('/api/v1/orgs/cakesbygrace/profiles', ['name' => 'Bosses', 'apps' => ['xpenses' => 'owner']])->assertStatus(403);
    }

    /** §14 test 4. */
    public function test_idempotency_key_creates_one_invite(): void
    {
        $t = $this->token(['users:write']);
        $body = ['email' => 'ana@cakesbygrace.ph', 'name' => 'Ana', 'org' => 'cakesbygrace', 'send' => false];
        $a = $this->api($t)->withHeaders(['Idempotency-Key' => 'k-1'])->postJson('/api/v1/invites', $body)->assertCreated();
        $b = $this->api($t)->withHeaders(['Idempotency-Key' => 'k-1'])->postJson('/api/v1/invites', $body)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($a->json('id'), $b->json('id'));
        $this->assertSame(1, Invite::count());
        $this->api($t)->withHeaders(['Idempotency-Key' => 'k-1'])->postJson('/api/v1/invites', ['name' => 'Other'] + $body)->assertStatus(422);
    }

    public function test_client_stops_when_its_creator_no_longer_runs_the_org(): void
    {
        $t = $this->token(['users:read']);
        $this->api($t)->getJson('/api/v1/users')->assertOk();
        $this->admin->organizations()->updateExistingPivot($this->cbg->id, ['role' => 'member']);
        $this->api($t)->getJson('/api/v1/users')->assertStatus(403)->assertJsonPath('title', 'Client owner lost access');
    }

    public function test_settings_are_platform_only(): void
    {
        $this->api($this->token(['settings:read']))->getJson('/api/v1/settings')->assertStatus(403);
        $owner = $this->makeUser('william@thecakelabs.com', ['name' => 'William', 'clauth_role' => 'owner']);
        $t = $this->token(['settings:read', 'settings:write', 'users:read'], $owner, platform: true);
        $this->api($t)->patchJson('/api/v1/settings', ['session_idle_hours' => 24, 'legal_reviewed' => true])->assertOk()
            ->assertJsonPath('session_idle_hours', 24)->assertJsonPath('legal_reviewed', false);
        $alab = $this->makeUser('jam@alab.ph', ['name' => 'Jam']);
        $this->grant($alab, $this->alab, 'member');
        $this->api($t)->getJson('/api/v1/users/jam@alab.ph')->assertOk(); // platform clients see every organization
    }

    public function test_apps_change_only_through_their_publisher(): void
    {
        $t = $this->token(['apps:read', 'apps:write']);
        $this->api($t)->getJson('/api/v1/apps/xpenses')->assertOk()->assertJsonPath('roles.0.key', 'finance')->assertJsonPath('clients.0.id', $this->client->id);
        $r = $this->api($t)->postJson("/api/v1/apps/xpenses/clients/{$this->client->id}/rotate")->assertOk();
        $this->assertSame(48, strlen($r->json('secret')));

        // An Alab client sees Xpenses (installed there) but cannot change it or see its clients.
        $alabOwner = $this->makeUser('boss@alab.ph', ['name' => 'Boss']);
        $alabOwner->organizations()->attach($this->alab->id, ['role' => 'owner', 'joined_at' => now()]);
        [$c] = $this->apiClient(['apps:read', 'apps:write'], $alabOwner);
        $c->forceFill(['organization_id' => $this->alab->id])->save();
        $t2 = $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $c->id, 'client_secret' => 'api-secret-api-secret', 'scope' => 'apps:read apps:write'])->json('access_token');
        $this->api($t2)->getJson('/api/v1/apps/xpenses')->assertOk()->assertJsonMissingPath('clients');
        $this->api($t2)->postJson("/api/v1/apps/xpenses/clients/{$this->client->id}/revoke")->assertStatus(403);
    }

    public function test_pagination_uses_cursors(): void
    {
        $t = $this->token(['users:read']);
        foreach (range(1, 3) as $i) {
            $this->makeUser("p$i@cakesbygrace.ph")->organizations()->attach($this->cbg->id);
        }
        $first = $this->api($t)->getJson('/api/v1/users?limit=2')->assertOk()->assertJsonCount(2, 'data');
        $this->assertNotNull($first->json('next_cursor'));
        $this->api($t)->getJson('/api/v1/users?limit=2&cursor='.$first->json('next_cursor'))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('next_cursor', null);
    }

    /** §14 test 5: the OpenAPI document and the router agree. */
    public function test_openapi_matches_the_router(): void
    {
        $routes = array_keys(OpenApi::routes());
        sort($routes);
        $docs = array_keys(OpenApi::DOCS);
        sort($docs);
        $this->assertSame($docs, $routes, 'Every /api/v1 route needs a line in App\Api\OpenApi::DOCS, and nothing else.');
        $doc = $this->getJson('/api/v1/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0')->json();
        $this->assertSame(['users:write'], $doc['paths']['/invites']['post']['security'][0]['clientCredentials']);
        $this->assertSame([], $doc['paths']['/health/live']['get']['security']);
    }

    public function test_health_and_unknown_routes(): void
    {
        $this->getJson('/api/v1/health/live')->assertOk()->assertJsonPath('status', 'ok');
        $this->getJson('/api/v1/health/ready')->assertJsonStructure(['status', 'checks' => ['database', 'cache', 'signing_key']]);
        $r = $this->getJson('/api/v1/nope')->assertNotFound();
        $this->assertSame('application/problem+json', $r->headers->get('Content-Type'));
    }

    public function test_introspection_and_server_metadata(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->assertJsonPath('introspection_endpoint', config('clauth.issuer').'/oauth/introspect');
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'member');
        [$code, , $verifier] = $this->authorizeCode($user, 'openid clauth offline_access');
        $tok = $this->exchange($code, $verifier)->json();

        $this->post('/oauth/introspect', ['token' => $tok['access_token'], 'client_id' => $this->client->id, 'client_secret' => 'wrong'])->assertStatus(401);
        $this->post('/oauth/introspect', ['token' => $tok['access_token'], 'client_id' => $this->client->id, 'client_secret' => $this->secret])
            ->assertOk()->assertJsonPath('active', true)->assertJsonPath('sub', $user->sub())->assertJsonPath('client_id', $this->client->id);
        $this->post('/oauth/introspect', ['token' => $tok['refresh_token'], 'client_id' => $this->client->id, 'client_secret' => $this->secret])
            ->assertOk()->assertJsonPath('active', true)->assertJsonPath('token_type', 'refresh_token');

        // Another app's client learns nothing.
        [$other, $s] = $this->apiClient(['users:read']);
        $this->post('/oauth/introspect', ['token' => $tok['access_token'], 'client_id' => $other->id, 'client_secret' => $s])->assertOk()->assertExactJson(['active' => false]);

        $user->update(['status' => 'disabled']);
        $this->post('/oauth/introspect', ['token' => $tok['access_token'], 'client_id' => $this->client->id, 'client_secret' => $this->secret])->assertExactJson(['active' => false]);
    }

    public function test_console_creates_api_clients_for_own_org_only(): void
    {
        $this->admin->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $as = fn () => $this->actingAs($this->admin)->withSession(['auth.mfa' => true]);
        $as()->get("/admin/orgs/{$this->cbg->id}/api-clients")->assertOk()->assertSee('New API client')->assertDontSee('settings:write');
        $as()->post("/admin/orgs/{$this->cbg->id}/api-clients", ['name' => 'HR sync', 'scopes' => ['users:read', 'settings:write']])->assertSessionHasErrors('scopes.1');
        $as()->post("/admin/orgs/{$this->cbg->id}/api-clients", ['name' => 'HR sync', 'scopes' => ['users:read', 'access:write']])->assertSessionHas('created_client');
        $c = OAuthClient::where('type', 'api')->firstOrFail();
        $this->assertSame([$this->cbg->id, $this->admin->id, ['users:read', 'access:write']], [$c->organization_id, $c->created_by, $c->scopes]);
        $as()->get("/admin/orgs/{$this->alab->id}/api-clients")->assertNotFound();
        $as()->get('/admin/orgs/platform/api-clients')->assertNotFound();
        $as()->post("/admin/orgs/{$this->cbg->id}/api-clients/{$c->id}/revoke")->assertRedirect();
        $this->assertTrue($c->fresh()->revoked);
    }
}
