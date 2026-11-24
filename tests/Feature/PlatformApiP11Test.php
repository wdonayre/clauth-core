<?php

namespace Tests\Feature;

use App\Models\AccessProfile;
use App\Models\Application;
use App\Models\AppPermission;
use App\Models\AuditEvent;
use App\Models\ClientSession;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\PersonalTokens;
use App\Services\TwoFactor;

/** Platform API P1.1: personal tokens + /me, app writes, account delete, ETag (SPEC-platform §12.1). */
class PlatformApiP11Test extends ClauthTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->role('finance')->update(['tier' => 'admin', 'position' => 0]);
        $this->role('member')->update(['tier' => 'member', 'position' => 1]);
        $this->admin = $this->makeUser('owner@cakesbygrace.ph', ['name' => 'Grace']);
        $this->admin->organizations()->attach($this->cbg->id, ['role' => 'owner', 'joined_at' => now()]);
        $this->admin->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
    }

    private function clientToken(array $scopes, ?User $by = null, bool $platform = false): string
    {
        $c = new OAuthClient;
        $c->forceFill(['type' => 'api', 'name' => 'script', 'secret' => 'api-secret-api-secret', 'organization_id' => $platform ? null : $this->cbg->id,
            'created_by' => ($by ?? $this->admin)->id, 'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'scopes' => $scopes, 'revoked' => false])->save();

        return $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $c->id, 'client_secret' => 'api-secret-api-secret',
            'scope' => implode(' ', $scopes)])->assertOk()->json('access_token');
    }

    private function api(string $token)
    {
        return $this->flushHeaders()->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);
    }

    public function test_personal_token_from_my_account_works_on_me_and_can_be_revoked(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        $this->actingAs($maria)->get('/account/tokens')->assertOk()->assertSee('me:read')->assertDontSee('users:write');
        $this->actingAs($maria)->post('/account/tokens', ['name' => 'CLI', 'scopes' => ['me:read', 'users:write'], 'days' => 30])->assertSessionHasErrors('scopes.1');
        $jwt = $this->actingAs($maria)->post('/account/tokens', ['name' => 'CLI', 'scopes' => ['me:read', 'me:write'], 'days' => 30])->assertSessionHas('new_token')->getSession()->get('new_token')['token'];

        $this->api($jwt)->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', 'maria@cakesbygrace.ph')->assertJsonPath('grants.0.role', 'member');
        $this->api($jwt)->patchJson('/api/v1/me', ['name' => 'Maria S.'])->assertOk()->assertJsonPath('name', 'Maria S.');
        $this->api($jwt)->getJson('/api/v1/me/apps')->assertOk()->assertJsonPath('data.0.app', 'xpenses');
        $this->api($jwt)->getJson('/api/v1/users')->assertStatus(403); // no management scope
        $this->assertSame('api', AuditEvent::where('event', 'user.updated')->latest('id')->value('actor_type'));

        $cs = ClientSession::create(['user_id' => $maria->id, 'client_id' => $this->client->id, 'app_id' => $this->xp->id, 'last_used_at' => now()]);
        $this->api($jwt)->getJson('/api/v1/me/sessions')->assertOk()->assertJsonPath('apps.0.id', 'app_'.$cs->id);
        $this->api($jwt)->deleteJson('/api/v1/me/sessions/app_'.$cs->id)->assertNoContent();
        $this->assertNull(ClientSession::find($cs->id));

        $id = PersonalTokens::list($maria)->first()->id;
        $this->flushHeaders()->actingAs($maria)->delete('/account/tokens/'.$id)->assertRedirect();
        $this->api($jwt)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_management_scopes_need_two_step_and_follow_the_persons_roles(): void
    {
        $noTwoStep = $this->makeUser('admin@cakesbygrace.ph', ['name' => 'Admin']);
        $noTwoStep->organizations()->attach($this->cbg->id, ['role' => 'admin']);
        $this->actingAs($noTwoStep)->post('/account/tokens', ['name' => 'x', 'scopes' => ['users:read'], 'days' => 7])->assertSessionHasErrors('scopes');

        [, $jwt] = PersonalTokens::create($this->admin, 'ops', ['users:read', 'access:read'], 7);
        $jam = $this->makeUser('jam@alab.ph', ['name' => 'Jam']);
        $this->grant($jam, $this->alab, 'member');
        $this->api($jwt)->getJson('/api/v1/users/'.$this->admin->sub())->assertOk();
        $this->api($jwt)->getJson('/api/v1/users/jam@alab.ph')->assertNotFound(); // not their organization
        $this->api($jwt)->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('orgs', ['cakesbygrace']);

        $this->admin->organizations()->updateExistingPivot($this->cbg->id, ['role' => 'member']);
        $this->api($jwt)->getJson('/api/v1/users')->assertStatus(403); // lost the role, so the token lost the scope
    }

    public function test_api_clients_cannot_use_me(): void
    {
        $this->api($this->clientToken(['users:read']))->getJson('/api/v1/me')->assertStatus(403);
    }

    public function test_register_an_app_and_manage_its_roles(): void
    {
        $t = $this->clientToken(['apps:read', 'apps:write']);
        $r = $this->api($t)->postJson('/api/v1/apps', ['name' => 'Bakery POS', 'key' => 'bakery-pos', 'type' => 'web', 'redirect_uri' => 'https://pos.example.com/cb'])
            ->assertCreated()->assertJsonPath('publisher', 'cakesbygrace')->assertJsonPath('roles.0.key', 'member');
        $this->assertSame(48, strlen($r->json('client.secret')));
        $app = Application::where('key', 'bakery-pos')->firstOrFail();
        $this->assertTrue($app->organizations()->where('organizations.id', $this->cbg->id)->exists());

        $this->api($t)->postJson('/api/v1/apps', ['name' => 'Clauth thing', 'key' => 'clauth-x', 'type' => 'm2m'])->assertStatus(422); // reserved prefix
        $this->api($t)->postJson('/api/v1/apps/bakery-pos/permissions', ['key' => 'sale.void'])->assertCreated();
        $this->api($t)->postJson('/api/v1/apps/bakery-pos/roles', ['key' => 'manager', 'name' => 'Manager', 'tier' => 'admin'])->assertCreated();
        $this->api($t)->putJson('/api/v1/apps/bakery-pos/roles/manager/permissions', ['permissions' => ['sale.void']])->assertOk()->assertJsonPath('permissions', ['sale.void']);
        $this->api($t)->putJson('/api/v1/apps/bakery-pos/roles/manager/permissions', ['permissions' => ['nope']])->assertStatus(422);
        $this->api($t)->patchJson('/api/v1/apps/bakery-pos/roles/manager', ['aliases' => ['mgr'], 'assignable' => false])->assertOk()->assertJsonPath('aliases', ['mgr']);

        // Manifest roles: tier only.
        $this->role('finance')->update(['source' => 'manifest']);
        $this->api($t)->patchJson('/api/v1/apps/xpenses/roles/finance', ['name' => 'Money'])->assertStatus(422);
        $this->api($t)->patchJson('/api/v1/apps/xpenses/roles/finance', ['tier' => 'owner'])->assertOk()->assertJsonPath('tier', 'owner');

        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        $this->api($t)->deleteJson('/api/v1/apps/xpenses/roles/member')->assertStatus(409);

        $this->api($t)->postJson('/api/v1/apps/bakery-pos/clients', ['type' => 'm2m', 'name' => 'sync'])->assertCreated()->assertJsonStructure(['id', 'secret']);
        $this->api($t)->putJson('/api/v1/apps/bakery-pos/branding', ['login_theme' => 'midnight', 'accent_color' => '#22D3EE'])->assertOk()->assertJsonPath('login_theme', 'midnight');
        $this->api($t)->deleteJson('/api/v1/apps/bakery-pos', ['confirm' => 'wrong'])->assertStatus(422);
        $this->api($t)->deleteJson('/api/v1/apps/bakery-pos', ['confirm' => 'bakery-pos'])->assertNoContent();
        $this->assertNull(Application::where('key', 'bakery-pos')->first());
    }

    /** An org client only asks other organizations, even when its owner runs them too. */
    public function test_installing_elsewhere_is_a_request(): void
    {
        $this->admin->organizations()->attach($this->alab->id, ['role' => 'owner']);
        $t = $this->clientToken(['apps:write']);
        $this->api($t)->postJson('/api/v1/apps', ['name' => 'Cake Pricer', 'key' => 'cake-pricer', 'type' => 'm2m'])->assertCreated();
        Application::where('key', 'cake-pricer')->update(['visibility' => 'installable']);
        $this->api($t)->postJson('/api/v1/apps/cake-pricer/installations', ['org' => 'alab-apparel'])->assertStatus(202)->assertJsonPath('status', 'requested');
        $this->api($t)->postJson('/api/v1/apps/cake-pricer/installations', ['org' => 'no-such-org'])->assertJsonPath('status', 'requested');
        $this->api($t)->getJson('/api/v1/apps/cake-pricer/installations')->assertOk()->assertJsonPath('data.0.org', 'alab-apparel')->assertJsonPath('data.0.status', 'requested');
    }

    public function test_sign_in_domain_over_the_api(): void
    {
        $this->app->instance(\App\Support\Dns::class, new class extends \App\Support\Dns
        {
            public function txt(string $name): array
            {
                return [];
            }

            public function target(string $host, string $mainHost, array $serverIps): string
            {
                return 'none';
            }
        });
        \Illuminate\Support\Facades\Http::fake();
        $t = $this->clientToken(['apps:write']);
        $this->api($t)->postJson('/api/v1/apps/xpenses/domain', ['host' => 'https://auth.cakesbygrace.ph/login'])->assertCreated()
            ->assertJsonPath('host', 'auth.cakesbygrace.ph')->assertJsonPath('dns.0.type', 'TXT');
        $this->api($t)->postJson('/api/v1/apps', ['name' => 'Second', 'key' => 'second-app', 'type' => 'm2m'])->assertCreated();
        $this->api($t)->postJson('/api/v1/apps/second-app/domain', ['host' => 'auth.cakesbygrace.ph'])->assertStatus(409);
        $this->api($t)->postJson('/api/v1/apps/second-app/domain', ['host' => 'auth.cakesbygrace.ph', 'share' => true])->assertCreated()->assertJsonCount(2, 'apps');
        $this->api($t)->patchJson('/api/v1/apps/xpenses/domain', ['trusted' => true])->assertStatus(403);
        $this->api($t)->deleteJson('/api/v1/apps/xpenses/domain')->assertNoContent();
        $this->api($t)->getJson('/api/v1/apps/second-app/domain')->assertOk();
    }

    public function test_if_match_stops_lost_updates(): void
    {
        $t = $this->clientToken(['access:read', 'access:write']);
        AccessProfile::create(['organization_id' => $this->cbg->id, 'key' => 'staff', 'name' => 'Staff']);
        $etag = $this->api($t)->getJson('/api/v1/orgs/cakesbygrace/profiles/staff')->assertOk()->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->travel(2)->seconds();
        $this->api($t)->withHeaders(['If-Match' => $etag])->patchJson('/api/v1/orgs/cakesbygrace/profiles/staff', ['name' => 'Team'])->assertOk();
        $this->api($t)->withHeaders(['If-Match' => $etag])->patchJson('/api/v1/orgs/cakesbygrace/profiles/staff', ['name' => 'Crew'])
            ->assertStatus(412)->assertJsonPath('title', 'Changed since read');
        $this->api($t)->patchJson('/api/v1/orgs/cakesbygrace/profiles/staff', ['name' => 'Crew'])->assertOk(); // If-Match is optional
    }

    public function test_deleting_an_account_is_platform_only_and_frees_the_email(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        $this->api($this->clientToken(['users:write']))->deleteJson('/api/v1/users/'.$maria->sub())->assertStatus(403);

        $owner = $this->makeUser('william@thecakelabs.com', ['name' => 'William', 'clauth_role' => 'owner']);
        $t = $this->clientToken(['users:write', 'users:read'], $owner, platform: true);
        $this->api($t)->deleteJson('/api/v1/users/'.$owner->sub())->assertStatus(422);
        $this->api($t)->deleteJson('/api/v1/users/'.$maria->sub())->assertNoContent();
        $this->assertSoftDeleted('users', ['id' => $maria->id]);
        $this->assertDatabaseMissing('users', ['email' => 'maria@cakesbygrace.ph']);
        $this->assertDatabaseMissing('grants', ['user_id' => $maria->id]);
        $this->api($t)->postJson('/api/v1/invites', ['email' => 'maria@cakesbygrace.ph', 'name' => 'Maria', 'org' => 'cakesbygrace', 'send' => false])->assertCreated();
    }

    public function test_openapi_lists_personal_tokens(): void
    {
        $doc = $this->getJson('/api/v1/openapi.json')->assertOk()->json();
        $this->assertArrayHasKey('personalToken', $doc['components']['securitySchemes']);
        $this->assertSame([['personalToken' => []]], $doc['paths']['/me']['get']['security']);
    }
}
