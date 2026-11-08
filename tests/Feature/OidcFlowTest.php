<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Grant;
use App\Models\OAuthClient;
use App\Models\SigningKey;
use App\Services\Sessions;
use App\Services\SigningKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/** M0 + SPEC §14 acceptance tests for the OIDC flow. */
class OidcFlowTest extends ClauthTestCase
{
    public function test_discovery_and_jwks_are_published(): void
    {
        $d = $this->getJson('/.well-known/openid-configuration')->assertOk()->json();
        $this->assertSame(config('clauth.issuer'), $d['issuer']);
        $this->assertSame(['S256'], $d['code_challenge_methods_supported']);
        $jwks = $this->getJson('/.well-known/jwks.json')->assertOk()->json();
        $this->assertNotEmpty($jwks['keys']);
        $this->assertSame('RS256', $jwks['keys'][0]['alg']);
    }

    public function test_m0_sample_app_signs_in_and_validates_jwt_offline(): void
    {
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'finance');
        $this->grant($user, $this->alab, 'member');

        [$code, $state, $verifier, $q] = $this->authorizeCode($user);
        $this->assertNotEmpty($code, 'no code: '.json_encode($q));

        $tok = $this->exchange($code, $verifier)->assertOk()->json();
        $this->assertArrayHasKey('id_token', $tok);
        $this->assertArrayHasKey('refresh_token', $tok);

        $jwks = $this->getJson('/.well-known/jwks.json')->json();
        $access = $this->verifyOffline($tok['access_token'], $jwks, 'xpenses');
        $c = $access->claims();
        $this->assertSame(config('clauth.issuer'), $c->get('iss'));
        $this->assertSame($user->sub(), $c->get('sub'));
        $this->assertSame(['finance'], $c->get('orgs')['cakesbygrace']['roles']);
        $this->assertSame(['expense.approve', 'expense.view_all', 'report.export'], $c->get('orgs')['cakesbygrace']['permissions']);
        $this->assertSame(['member'], $c->get('orgs')['alab-apparel']['roles']);
        $this->assertLessThanOrEqual(15 * 60 + 5, $c->get('exp')->getTimestamp() - time());

        $id = $this->verifyOffline($tok['id_token'], $jwks, $this->client->id);
        $this->assertSame('n-0S6_WzA2Mj', $id->claims()->get('nonce'));
        $this->assertSame($user->email, $id->claims()->get('email'));

        $this->withToken($tok['access_token'])->getJson('/userinfo')->assertOk()->assertJsonPath('sub', $user->sub())->assertJsonPath('orgs.cakesbygrace.roles.0', 'finance');
    }

    public function test_pkce_is_required(): void
    {
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'member');
        $r = $this->authorizeCode($user, extra: ['code_challenge' => null, 'code_challenge_method' => null]);
        $this->assertIsArray($r);
        $this->assertSame('invalid_request', $r[3]['error'] ?? null);
    }

    public function test_user_without_grant_gets_access_denied_and_audit(): void
    {
        $user = $this->makeUser();
        [$code, , , $q] = $this->authorizeCode($user);
        $this->assertNull($code);
        $this->assertSame('access_denied', $q['error']);
        $this->assertTrue(AuditEvent::where('event', 'signin.denied')->where('subject_id', $user->id)->exists());
    }

    public function test_role_change_shows_in_next_refreshed_token(): void
    {
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'member');
        [$code, , $verifier] = $this->authorizeCode($user);
        $tok = $this->exchange($code, $verifier)->json();

        Grant::where('user_id', $user->id)->update(['app_role_id' => $this->role('finance')->id]);
        $new = $this->refresh($tok['refresh_token'])->assertOk()->json();
        $claims = $this->verifyOffline($new['access_token'], $this->getJson('/.well-known/jwks.json')->json(), 'xpenses')->claims();
        $this->assertSame(['finance'], $claims->get('orgs')['cakesbygrace']['roles']);
    }

    public function test_refresh_token_reuse_revokes_family_and_audits(): void
    {
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'member');
        [$code, , $verifier] = $this->authorizeCode($user);
        $first = $this->exchange($code, $verifier)->json();
        $second = $this->refresh($first['refresh_token'])->assertOk()->json();

        $this->refresh($first['refresh_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertTrue(AuditEvent::where('event', 'security.refresh_reuse')->exists());
        // The legitimate newer token is dead too.
        $this->refresh($second['refresh_token'])->assertStatus(400);
    }

    public function test_disabled_user_cannot_refresh(): void
    {
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'member');
        [$code, , $verifier] = $this->authorizeCode($user);
        $tok = $this->exchange($code, $verifier)->json();
        $user->update(['status' => 'disabled']);
        Sessions::revokeAll($user);
        $this->refresh($tok['refresh_token'])->assertStatus(400);
        $this->withToken($tok['access_token'])->getJson('/userinfo')->assertStatus(401);
    }

    public function test_key_rotation_keeps_old_tokens_valid_and_uses_new_kid(): void
    {
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'member');
        [$code, , $verifier] = $this->authorizeCode($user);
        $old = $this->exchange($code, $verifier)->json();
        $oldKid = SigningKeys::active()->kid;

        $new = SigningKeys::generate('staged');
        SigningKeys::activate($new);

        $jwks = $this->getJson('/.well-known/jwks.json')->json();
        $this->assertEqualsCanonicalizing([$oldKid, $new->kid], array_column($jwks['keys'], 'kid'));
        $this->verifyOffline($old['access_token'], $jwks, 'xpenses');
        $this->withToken($old['access_token'])->getJson('/userinfo')->assertOk();

        $fresh = $this->refresh($old['refresh_token'])->assertOk()->json();
        $t = $this->verifyOffline($fresh['access_token'], $jwks, 'xpenses');
        $this->assertSame($new->kid, $t->headers()->get('kid'));
        $this->assertSame('retiring', SigningKey::where('kid', $oldKid)->value('status'));
    }

    public function test_paused_app_blocks_sign_in(): void
    {
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'member');
        $this->xp->update(['status' => 'paused']);
        $r = $this->authorizeCode($user);
        $this->assertInstanceOf(TestResponse::class, $r);
        $r->assertStatus(403);
    }

    public function test_logout_redirects_only_to_registered_uri(): void
    {
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'member');
        [$code, , $verifier] = $this->authorizeCode($user);
        $tok = $this->exchange($code, $verifier)->json();
        $this->get('/oauth/logout?'.http_build_query(['id_token_hint' => $tok['id_token'], 'post_logout_redirect_uri' => 'https://evil.test/']))->assertOk();
        $this->get('/oauth/logout?'.http_build_query(['id_token_hint' => $tok['id_token'], 'post_logout_redirect_uri' => 'https://xpenses.test/', 'state' => 'abc']))
            ->assertRedirect('https://xpenses.test/?state=abc');
    }

    public function test_m2m_client_credentials_and_user_lookup(): void
    {
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'finance');
        $m2m = new OAuthClient;
        $m2m->forceFill(['app_id' => $this->xp->id, 'type' => 'm2m', 'name' => 'svc', 'secret' => 's3cret-s3cret', 'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'revoked' => false])->save();
        $tok = $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $m2m->id, 'client_secret' => 's3cret-s3cret', 'scope' => 'users:read'])->assertOk()->json();
        $this->withToken($tok['access_token'])->getJson('/api/v1/users/'.$user->sub())->assertOk()->assertJsonPath('orgs.cakesbygrace.roles.0', 'finance');
        // Web clients can't use client credentials.
        $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $this->client->id, 'client_secret' => $this->secret])->assertStatus(400);
    }

    public function test_revoke_endpoint_kills_refresh_token(): void
    {
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'member');
        [$code, , $verifier] = $this->authorizeCode($user);
        $tok = $this->exchange($code, $verifier)->json();
        $this->post('/oauth/revoke', ['client_id' => $this->client->id, 'client_secret' => $this->secret, 'token' => $tok['refresh_token']])->assertOk();
        $this->assertSame(0, DB::table('oauth_refresh_tokens')->where('revoked', false)->count());
    }
}
