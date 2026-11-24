<?php

namespace Tests\Feature;

use App\Mail\InviteMail;
use App\Mail\LoginCodeMail;
use App\Models\Connection;
use App\Models\OAuthClient;
use App\Models\SocialIdentity;
use App\Models\User;
use App\Services\Connections;
use App\Services\Settings;
use App\Services\TwoFactor;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/** P3: connections — OIDC/OAuth/Facebook sign-in, SMS + email one-time codes, organization email sender (SPEC-platform §7). */
class PlatformP3Test extends ClauthTestCase
{
    private const MAIN = 'https://auth.test';

    private const IDP = 'https://idp.acme.test';

    private User $admin;

    private ?string $nonce = null;

    private array $idClaims = [];

    private array $texts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['clauth.issuer' => self::MAIN, 'app.url' => self::MAIN, 'services.google.client_id' => null]);
        $this->admin = $this->makeUser('owner@cakesbygrace.ph', ['name' => 'Grace']);
        $this->admin->organizations()->attach($this->cbg->id, ['role' => 'owner', 'joined_at' => now()]);
        $this->admin->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        Http::fake([
            self::IDP.'/.well-known/openid-configuration' => Http::response(['issuer' => self::IDP, 'authorization_endpoint' => self::IDP.'/authorize',
                'token_endpoint' => self::IDP.'/token', 'userinfo_endpoint' => self::IDP.'/userinfo']),
            self::IDP.'/token' => fn () => Http::response(['access_token' => 'at', 'id_token' => $this->jwt($this->idClaims + ['nonce' => $this->nonce])]),
            'https://portal.test/token' => Http::response(['access_token' => 'oat']),
            'https://portal.test/me' => Http::response(['user' => ['uid' => 'p-1', 'mail' => 'maria@cakesbygrace.ph', 'full' => 'Maria']]),
            'https://graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'fbt']),
            'https://graph.facebook.com/*/me*' => Http::response(['id' => 'fb-9', 'name' => 'Maria FB', 'email' => 'maria@cakesbygrace.ph']),
            'https://api.semaphore.co/*' => function (HttpRequest $r) {
                $this->texts[] = $r->data();

                return Http::response([['message_id' => 1]]);
            },
        ]);
    }

    private function jwt(array $claims): string
    {
        $b = fn ($a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');

        return $b(['alg' => 'RS256']).'.'.$b($claims).'.sig';
    }

    private function oidc(array $cfg = []): Connection
    {
        return Connection::create(['organization_id' => $this->cbg->id, 'type' => 'oidc', 'key' => 'cbg-m365', 'name' => 'CakesbyGrace Microsoft', 'status' => 'active',
            'config' => $cfg + ['issuer' => self::IDP, 'client_id' => 'cid', 'client_secret' => 'csecret', 'domains' => ['cakesbygrace.ph'], 'show_button' => true]]);
    }

    /** Start at /login/c/{key}, then come back to the callback like the provider would. */
    private function viaProvider(string $key, array $claims = [])
    {
        $r = $this->keep($this->get(self::MAIN."/login/c/$key"));
        $loc = (string) $r->headers->get('Location');
        parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
        $this->nonce = $q['nonce'] ?? null;
        $this->idClaims = $claims + ['iss' => self::IDP, 'aud' => 'cid', 'exp' => time() + 300, 'sub' => 'idp-1', 'email' => 'maria@cakesbygrace.ph', 'name' => 'Maria'];

        return [$loc, $q, $this->keep($this->get(self::MAIN."/login/c/$key/callback?".http_build_query(['state' => $q['state'] ?? 'x', 'code' => 'abc'])))];
    }

    // ------------------------------------------------------------ OIDC / OAuth / Facebook

    public function test_org_oidc_signs_in_a_member_and_links_them(): void
    {
        $this->oidc();
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        $this->get(self::MAIN.'/login')->assertDontSee('Continue with CakesbyGrace Microsoft'); // no app: platform buttons only
        $this->keep($this->get(self::MAIN.'/oauth/authorize?'.http_build_query(['client_id' => $this->client->id, 'redirect_uri' => self::REDIRECT,
            'response_type' => 'code', 'scope' => 'openid', 'state' => 's', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256'])));
        $this->get(self::MAIN.'/login')->assertSee('Continue with CakesbyGrace Microsoft'); // Xpenses is installed in CakesbyGrace

        [$loc, $q, $back] = $this->viaProvider('cbg-m365');
        $this->assertStringStartsWith(self::IDP.'/authorize?', $loc);
        $this->assertSame(['S256', 'cid'], [$q['code_challenge_method'], $q['client_id']]);
        $this->assertSame(self::MAIN.'/login/c/cbg-m365/callback', $q['redirect_uri']);
        $back->assertRedirect();
        $this->assertAuthenticatedAs($maria);
        $this->assertDatabaseHas('social_identities', ['user_id' => $maria->id, 'provider' => 'cbg-m365', 'provider_id' => 'idp-1']);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::IDP.'/token' && $r['code_verifier'] && $r['client_secret'] === 'csecret');
    }

    public function test_org_provider_cannot_take_over_someone_in_another_org_or_fake_a_token(): void
    {
        $this->oidc();
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        $this->grant($maria, $this->alab, 'member'); // works for two organizations
        [, , $back] = $this->viaProvider('cbg-m365');
        $back->assertRedirect(self::MAIN.'/login?social_error=link_first');
        $this->assertGuest();

        $this->flushSession();
        [, , $back] = $this->viaProvider('cbg-m365', ['aud' => 'someone-else']);
        $back->assertRedirect(self::MAIN.'/login?social_error=failed');
        [, , $back] = $this->viaProvider('cbg-m365', ['iss' => 'https://evil.test']);
        $back->assertRedirect(self::MAIN.'/login?social_error=failed');
        $this->get(self::MAIN.'/login/c/cbg-m365/callback?state=forged&code=abc')->assertRedirect(self::MAIN.'/login?social_error=failed');
        $this->assertGuest();
    }

    public function test_just_in_time_accounts_and_required_company_sign_in(): void
    {
        $this->oidc(['jit' => true, 'require' => true]);
        // Typing a company email + any password goes to the company's provider instead.
        $this->post(self::MAIN.'/login', ['email' => 'New.Person@cakesbygrace.ph', 'password' => 'whatever'])
            ->assertRedirect(self::MAIN.'/login/c/cbg-m365?login_hint=new.person%40cakesbygrace.ph');
        $this->viaProvider('cbg-m365', ['email' => 'new.person@cakesbygrace.ph', 'sub' => 'idp-2', 'name' => 'New Person']);
        $u = User::firstWhere('email', 'new.person@cakesbygrace.ph');
        $this->assertNotNull($u);
        $this->assertSame(['active', 'New Person'], [$u->status, $u->name]);
        $this->assertTrue($u->organizations()->where('organizations.id', $this->cbg->id)->exists());
        $this->assertAuthenticatedAs($u);

        // Other domains are not created.
        auth()->logout();
        $this->flushSession();
        [, , $back] = $this->viaProvider('cbg-m365', ['email' => 'x@gmail.com', 'sub' => 'idp-3']);
        $back->assertRedirect(self::MAIN.'/login?social_error=no_account');
    }

    public function test_plain_oauth_and_facebook_link_only_when_trusted_or_linked(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        Connection::create(['type' => 'oauth', 'key' => 'portal', 'name' => 'Portal', 'status' => 'active', 'config' => [
            'authorize_url' => 'https://portal.test/auth', 'token_url' => 'https://portal.test/token', 'userinfo_url' => 'https://portal.test/me',
            'client_id' => 'pc', 'client_secret' => 'ps', 'id_field' => 'user.uid', 'email_field' => 'user.mail', 'name_field' => 'user.full', 'trust_email' => true, 'show_button' => true]]);
        Connection::create(['type' => 'meta', 'key' => 'facebook', 'name' => 'Facebook', 'status' => 'active', 'config' => ['client_id' => 'fa', 'client_secret' => 'fs', 'show_button' => true]]);
        $this->get(self::MAIN.'/login')->assertSee('Continue with Portal')->assertSee('Continue with Facebook');

        // OAuth with trust_email: verified email isn't reported, so it is not trusted → link first.
        [, , $back] = $this->viaProvider('portal');
        $back->assertRedirect(self::MAIN.'/login?social_error=link_first');
        // Facebook never links by email unless the connection says so…
        $this->flushSession();
        [$loc, , $back] = $this->viaProvider('facebook');
        $this->assertStringStartsWith('https://www.facebook.com/', $loc);
        $back->assertRedirect(self::MAIN.'/login?social_error=link_first');
        // …but after linking it from My account, it signs in.
        $this->flushSession();
        $this->actingAs($maria)->get(self::MAIN.'/account')->assertSee('Link Facebook');
        $r = $this->keep($this->actingAs($maria)->get(self::MAIN.'/account/link/c/facebook'));
        parse_str((string) parse_url((string) $r->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->keep($this->get(self::MAIN.'/login/c/facebook/callback?'.http_build_query(['state' => $q['state'], 'code' => 'abc'])))->assertRedirect(self::MAIN.'/account');
        $this->assertDatabaseHas('social_identities', ['user_id' => $maria->id, 'provider' => 'facebook', 'provider_id' => 'fb-9']);
        auth()->logout();
        $this->flushSession();
        [, , $back] = $this->viaProvider('facebook');
        $this->assertAuthenticatedAs($maria);
        $this->assertSame(['fed'], session('auth.amr'));
    }

    // ------------------------------------------------------------ SMS and email codes

    private function smsGateway(): void
    {
        Connection::create(['type' => 'sms', 'key' => 'semaphore', 'name' => 'Semaphore', 'status' => 'active',
            'config' => ['driver' => 'semaphore', 'api_key' => 'sk', 'sender' => 'CLAUTH']]);
    }

    private function lastCode(): string
    {
        preg_match('/\b(\d{6})\b/', end($this->texts)['message'] ?? '', $m);

        return $m[1] ?? '';
    }

    public function test_sms_as_two_step_from_setup_to_next_sign_in(): void
    {
        $this->smsGateway();
        Settings::set(['require_mfa_everyone' => true]);
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');

        $this->keep($this->post(self::MAIN.'/login', ['email' => $maria->email, 'password' => self::PASSWORD]))->assertRedirect(self::MAIN.'/login/2fa/setup');
        $this->keep($this->get(self::MAIN.'/login/2fa/setup'))->assertSee('Use text messages');
        $this->keep($this->post(self::MAIN.'/login/2fa/sms-setup', ['phone' => '0917 123 4567']))->assertRedirect();
        $this->assertSame('639171234567', $this->texts[0]['number']);
        $this->keep($this->post(self::MAIN.'/login/2fa/sms-setup/confirm', ['code' => '000000']))->assertSessionHasErrors('code');
        $this->keep($this->post(self::MAIN.'/login/2fa/sms-setup/confirm', ['code' => $this->lastCode()]))->assertRedirect();
        $this->assertAuthenticatedAs($maria);
        $this->assertSame(['pwd', 'sms'], session('auth.amr'));
        $this->assertTrue($maria->fresh()->hasSmsTwoStep());

        // Next time: password, then a text.
        auth()->logout();
        $this->flushSession();
        $this->keep($this->post(self::MAIN.'/login', ['email' => $maria->email, 'password' => self::PASSWORD]))->assertRedirect(self::MAIN.'/login/2fa');
        $this->keep($this->get(self::MAIN.'/login/2fa'))->assertSee('Text me a code');
        $this->keep($this->post(self::MAIN.'/login/2fa/sms'))->assertRedirect();
        $this->keep($this->post(self::MAIN.'/login/2fa', ['code' => $this->lastCode()]))->assertRedirect();
        $this->assertAuthenticatedAs($maria);
    }

    public function test_admins_cannot_use_sms_two_step_and_the_daily_cap_holds(): void
    {
        $this->smsGateway();
        $this->admin->forceFill(['phone' => '+639171234567', 'phone_verified_at' => now(), 'sms_two_step' => true])->save();
        $this->assertFalse($this->admin->fresh()->hasSmsTwoStep());

        Settings::set(['sms_signin' => true, 'sms_daily_cap' => 0]);
        $maria = $this->makeUser('maria@cakesbygrace.ph', ['phone' => '+639171234567', 'phone_verified_at' => now()]);
        $this->keep($this->post(self::MAIN.'/login/code', ['email' => $maria->email, 'via' => 'sms']))->assertSessionHasErrors('email');
        $this->assertSame([], $this->texts);
    }

    public function test_sign_in_with_an_email_code(): void
    {
        Mail::fake();
        $this->get(self::MAIN.'/login/code')->assertNotFound();
        Settings::set(['email_code_signin' => true]);
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        $this->get(self::MAIN.'/login')->assertSee('one-time code');

        // Unknown address: same answer, nothing sent.
        $this->keep($this->post(self::MAIN.'/login/code', ['email' => 'nobody@x.ph', 'via' => 'email']))->assertRedirect(self::MAIN.'/login/code');
        $this->keep($this->get(self::MAIN.'/login/code'))->assertSee('If nobody@x.ph has an account');
        Mail::assertNothingSent();
        $this->keep($this->post(self::MAIN.'/login/code/restart'));

        $this->keep($this->post(self::MAIN.'/login/code', ['email' => $maria->email, 'via' => 'email']));
        $code = null;
        Mail::assertSent(LoginCodeMail::class, function ($m) use (&$code, $maria) {
            $code = $m->code;

            return $m->hasTo($maria->email);
        });
        $this->keep($this->post(self::MAIN.'/login/code/verify', ['code' => '111111']))->assertSessionHasErrors('code');
        $this->keep($this->post(self::MAIN.'/login/code/verify', ['code' => $code]))->assertRedirect();
        $this->assertAuthenticatedAs($maria);
        $this->assertSame(['email'], session('auth.amr'));
    }

    // ------------------------------------------------------------ email sender, console, API

    public function test_invites_go_out_through_the_organizations_own_sender(): void
    {
        Mail::fake();
        Connection::create(['organization_id' => $this->cbg->id, 'type' => 'email-smtp', 'key' => 'cbg-mail', 'name' => 'CbG mail', 'status' => 'active',
            'config' => ['host' => 'smtp.cakesbygrace.ph', 'port' => 587, 'from_address' => 'hello@cakesbygrace.ph', 'from_name' => 'Cakes by Grace']]);
        [$invite, $token] = \App\Services\Invites::create(['email' => 'ana@cakesbygrace.ph', 'name' => 'Ana', 'organization_id' => $this->cbg->id], $this->admin);
        \App\Services\Invites::send($invite, $token);
        Mail::assertSent(InviteMail::class, fn ($m) => ($m->from[0]['address'] ?? null) === 'hello@cakesbygrace.ph');
    }

    public function test_console_connections_page(): void
    {
        $as = fn () => $this->actingAs($this->admin)->withSession(['auth.mfa' => true]);
        $as()->post(self::MAIN."/admin/orgs/{$this->cbg->id}/connections", ['type' => 'meta', 'name' => 'FB'])->assertSessionHasErrors('type');
        $as()->post(self::MAIN."/admin/orgs/{$this->cbg->id}/connections", ['type' => 'oidc', 'name' => 'Microsoft 365',
            'config' => ['issuer' => self::IDP, 'client_id' => 'cid', 'client_secret' => 'top-secret-value', 'domains' => "cakesbygrace.ph\n@cbg.ph"]])->assertSessionHas('status');
        $c = Connection::firstOrFail();
        $this->assertSame(['cakesbygrace.ph', 'cbg.ph'], $c->cfg('domains'));
        $as()->get(self::MAIN."/admin/orgs/{$this->cbg->id}/connections")->assertOk()->assertSee('Microsoft 365')->assertDontSee('top-secret-value')
            ->assertSee(self::MAIN.'/login/c/'.$c->key.'/callback');
        // Saving without retyping the secret keeps it.
        $as()->put(self::MAIN."/admin/orgs/{$this->cbg->id}/connections/{$c->id}", ['name' => 'M365', 'status' => 'active',
            'config' => ['issuer' => self::IDP, 'client_id' => 'cid', 'client_secret' => '', 'domains' => 'cakesbygrace.ph']])->assertSessionHasNoErrors();
        $this->assertSame('top-secret-value', $c->fresh()->cfg('client_secret'));
        $as()->get(self::MAIN.'/admin/orgs/platform/connections')->assertNotFound();
        $as()->get(self::MAIN."/admin/orgs/{$this->alab->id}/connections")->assertNotFound();
        $this->assertStringNotContainsString('top-secret-value', json_encode(\App\Models\AuditEvent::where('event', 'connection.updated')->first()->before));
    }

    public function test_connections_api_masks_secrets_and_keeps_platform_types_to_platform(): void
    {
        $c = new OAuthClient;
        $c->forceFill(['type' => 'api', 'name' => 's', 'secret' => 'api-secret-api-secret', 'organization_id' => $this->cbg->id, 'created_by' => $this->admin->id,
            'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'scopes' => ['connections:read', 'connections:write'], 'revoked' => false])->save();
        $t = $this->post(self::MAIN.'/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $c->id, 'client_secret' => 'api-secret-api-secret',
            'scope' => 'connections:read connections:write'])->json('access_token');
        $api = fn () => $this->flushHeaders()->withHeaders(['Authorization' => "Bearer $t", 'Accept' => 'application/json']);
        $api()->postJson(self::MAIN.'/api/v1/connections', ['type' => 'sms', 'name' => 'x', 'org' => 'cakesbygrace', 'config' => ['driver' => 'semaphore', 'api_key' => 'k']])->assertStatus(422);
        $api()->postJson(self::MAIN.'/api/v1/connections', ['type' => 'oidc', 'name' => 'Okta', 'config' => ['issuer' => self::IDP]])->assertStatus(422); // no org
        $r = $api()->postJson(self::MAIN.'/api/v1/connections', ['type' => 'oidc', 'name' => 'Okta', 'org' => 'cakesbygrace',
            'config' => ['issuer' => self::IDP, 'client_id' => 'cid', 'client_secret' => 'sss', 'domains' => ['cakesbygrace.ph']]])->assertCreated()
            ->assertJsonPath('config.client_secret', '••••••')->assertJsonPath('org', 'cakesbygrace');
        $key = $r->json('key');
        $api()->patchJson(self::MAIN."/api/v1/connections/$key", ['status' => 'disabled', 'config' => ['client_id' => 'cid2']])->assertOk()->assertJsonPath('status', 'disabled');
        $this->assertSame(['cid2', 'sss'], [Connection::firstWhere('key', $key)->cfg('client_id'), Connection::firstWhere('key', $key)->cfg('client_secret')]);
        Connection::create(['type' => 'meta', 'key' => 'facebook', 'name' => 'Facebook', 'status' => 'active', 'config' => ['client_id' => 'fa', 'client_secret' => 'fs']]);
        $api()->getJson(self::MAIN.'/api/v1/connections/facebook')->assertNotFound();
        $api()->getJson(self::MAIN.'/api/v1/connections')->assertOk()->assertJsonCount(1, 'data');
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
