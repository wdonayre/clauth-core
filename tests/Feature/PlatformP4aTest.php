<?php

namespace Tests\Feature;

use App\Api\OpenApi;
use App\Mail\LoginCodeMail;
use App\Models\AccessProfile;
use App\Models\AppRole;
use App\Models\Connection;
use App\Models\Grant;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\Claims;
use App\Services\Settings;
use App\Services\SigninPolicies;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/** P4a: sign-in policies (+ history, open sign-up, http check), PAR, device sign-in (SPEC-platform §8.1, §8.3). */
class PlatformP4aTest extends ClauthTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->role('member')->update(['tier' => 'member']);
        $this->admin = $this->makeUser('owner@cakesbygrace.ph', ['name' => 'Grace']);
        $this->admin->organizations()->attach($this->cbg->id, ['role' => 'owner', 'joined_at' => now()]);
        $this->admin->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
    }

    private function authorizeUrl(array $extra = []): string
    {
        return '/oauth/authorize?'.http_build_query($extra + ['client_id' => $this->client->id, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code',
            'scope' => 'openid', 'state' => 'st', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256']);
    }

    public function test_rules_come_from_app_then_org_then_platform_with_history_and_restore(): void
    {
        $this->assertSame('default', SigninPolicies::effective($this->xp)[1]);
        SigninPolicies::save(null, null, SigninPolicies::validate(['email_code' => false], null, null), $this->admin);
        $this->assertSame('platform', SigninPolicies::effective($this->xp)[1]);
        SigninPolicies::save(null, $this->cbg, SigninPolicies::validate(['two_step' => 'always'], null, $this->cbg), $this->admin);
        [$r, $src] = SigninPolicies::effective($this->xp);
        $this->assertSame(['org', 'always', true], [$src, $r['two_step'], $r['email_code']]); // whole policy, no mixing

        $as = fn () => $this->actingAs($this->admin)->withSession(['auth.mfa' => true]);
        $as()->put('/admin/apps/xpenses/sign-in-rules', ['password' => '1', 'two_step' => 'default', 'registration' => 'invite', 'connections_mode' => 'all'])->assertSessionHas('status');
        $as()->put('/admin/apps/xpenses/sign-in-rules', ['password' => '0', 'google' => '0', 'email_code' => '0', 'sms_code' => '0', 'connections_mode' => 'some',
            'two_step' => 'default', 'registration' => 'invite'])->assertSessionHasErrors('password'); // must leave a way in
        $as()->put('/admin/apps/xpenses/sign-in-rules', ['password' => '1', 'two_step' => 'always', 'registration' => 'invite', 'connections_mode' => 'all'])->assertSessionHas('status');
        $p = SigninPolicies::find($this->xp, null);
        $this->assertSame([2, 'always'], [$p->version, $p->rules['two_step']]);
        $as()->get('/admin/apps/xpenses/sign-in-rules')->assertOk()->assertSee('Version 2')->assertSee('Restore');
        $as()->post('/admin/apps/xpenses/sign-in-rules/restore', ['version' => 1])->assertSessionHas('status');
        $this->assertSame(['default', 3], [$p->fresh()->rules['two_step'], $p->fresh()->version]);
        $as()->post('/admin/apps/xpenses/sign-in-rules/reset');
        $this->assertSame('org', SigninPolicies::effective($this->xp)[1]);
    }

    public function test_two_step_always_and_methods_are_enforced(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        SigninPolicies::save($this->xp, null, SigninPolicies::validate(['two_step' => 'always'], $this->xp, null), $this->admin);
        $this->assertTrue(Claims::mfaRequired($maria, $this->xp));

        // Password not allowed for Xpenses: the form is gone and a password session must sign in again.
        SigninPolicies::save($this->xp, null, SigninPolicies::validate(['password' => false, 'email_code' => true], $this->xp, null), $this->admin);
        Settings::set(['email_code_signin' => true]);
        $this->actingAs($maria)->withSession(['auth.via' => 'password', 'auth.mfa' => false]);
        $this->keep($this->get($this->authorizeUrl()))->assertRedirect('/login');
        $this->assertGuest();
        $this->keep($this->get('/login'))->assertSee('asks you to sign in another way')->assertDontSee('name="password"', false)->assertSee('one-time code');
        $this->keep($this->post('/login', ['email' => $maria->email, 'password' => self::PASSWORD]))->assertSessionHasErrors('email');
        $this->assertGuest();

        // The email code is allowed: sign in with it, then the app hands out a code.
        Mail::fake();
        $this->keep($this->post('/login/code', ['email' => $maria->email, 'via' => 'email']));
        $code = null;
        Mail::assertSent(LoginCodeMail::class, function ($m) use (&$code) {
            $code = $m->code;

            return true;
        });
        $this->keep($this->post('/login/code/verify', ['code' => $code]))->assertRedirect();
        $this->assertSame('email_code', session('auth.via'));
        $this->assertStringStartsWith(self::REDIRECT.'?code=', (string) $this->keep($this->get($this->authorizeUrl()))->headers->get('Location'));
    }

    public function test_open_sign_up_with_domain_list_and_profile(): void
    {
        Mail::fake();
        $staff = AccessProfile::create(['organization_id' => $this->cbg->id, 'key' => 'staff', 'name' => 'Staff']);
        DB::table('access_profile_roles')->insert(['access_profile_id' => $staff->id, 'app_id' => $this->xp->id, 'app_role_id' => $this->role('member')->id]);
        $this->keep($this->get($this->authorizeUrl()));
        Settings::set(['account_signup' => false]);
        $this->get('/signup')->assertNotFound();
        Settings::set(['account_signup' => true]);
        SigninPolicies::save($this->xp, null, SigninPolicies::validate(['registration' => 'open', 'open_domains' => 'cakesbygrace.ph', 'open_profile' => 'staff'], $this->xp, null), $this->admin);
        $this->keep($this->get('/login'))->assertSee('Create an account');

        $form = ['name' => 'Ana Reyes', 'email' => 'ana@gmail.com', 'password' => 'a long enough password', 'password_confirmation' => 'a long enough password', 'terms' => '1'];
        $this->keep($this->post('/signup', $form))->assertSessionHasErrors('email');
        $this->keep($this->post('/signup', ['email' => 'ana@cakesbygrace.ph'] + $form))->assertRedirect('/signup');
        $code = null;
        Mail::assertSent(LoginCodeMail::class, function ($m) use (&$code) {
            $code = $m->code;

            return $m->hasTo('ana@cakesbygrace.ph');
        });
        $this->keep($this->post('/signup/verify', ['code' => '000000']))->assertSessionHasErrors('code');
        $this->keep($this->post('/signup/verify', ['code' => $code]))->assertRedirect();
        $ana = User::firstWhere('email', 'ana@cakesbygrace.ph');
        $this->assertSame(['active', 'Ana Reyes'], [$ana->status, $ana->name]);
        $this->assertTrue(Hash::check('a long enough password', $ana->password));
        $this->assertSame('member', Grant::where('user_id', $ana->id)->first()?->role?->key);
        $this->assertAuthenticatedAs($ana);

        // An owner-tier profile can't be handed out by sign-up.
        AppRole::create(['app_id' => $this->xp->id, 'key' => 'owner', 'name' => 'Owner', 'tier' => 'owner']);
        $boss = AccessProfile::create(['organization_id' => $this->cbg->id, 'key' => 'boss', 'name' => 'Boss']);
        DB::table('access_profile_roles')->insert(['access_profile_id' => $boss->id, 'app_id' => $this->xp->id, 'app_role_id' => AppRole::where('key', 'owner')->value('id')]);
        $this->expectException(ValidationException::class);
        SigninPolicies::validate(['registration' => 'open', 'open_profile' => 'boss'], $this->xp, null);
    }

    public function test_http_check_allows_refuses_and_fails_closed(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        Connection::create(['organization_id' => $this->cbg->id, 'type' => 'http', 'key' => 'hr', 'name' => 'HR', 'status' => 'active',
            'config' => ['url' => 'https://hr.cakesbygrace.ph/clauth', 'secret' => 'sixteen-chars-or-more']]);
        SigninPolicies::save($this->xp, null, SigninPolicies::validate(['check' => 'hr'], $this->xp, null), $this->admin);
        $answer = (object) ['body' => ['allow' => false, 'message' => 'Maria is on leave.']];
        Http::fake(['https://hr.cakesbygrace.ph/*' => fn () => Http::response($answer->body, $answer->status ?? 200)]);

        $this->actingAs($maria)->withSession(['auth.via' => 'password']);
        $loc = (string) $this->get($this->authorizeUrl())->headers->get('Location');
        $this->assertStringContainsString('error=access_denied', $loc);
        $this->assertStringContainsString('Maria is on leave.', urldecode($loc));
        Http::assertSent(fn ($r) => $r->hasHeader('X-Clauth-Signature', 'sha256='.hash_hmac('sha256', $r->body(), 'sixteen-chars-or-more')) && $r['user']['email'] === $maria->email);

        $answer->body = ['allow' => true];
        $this->assertStringStartsWith(self::REDIRECT.'?code=', (string) $this->get($this->authorizeUrl())->headers->get('Location'));

        $answer->body = 'down';
        $answer->status = 500;
        $this->assertStringContainsString('error=access_denied', (string) $this->get($this->authorizeUrl())->headers->get('Location'));
    }

    public function test_pushed_authorization_request(): void
    {
        $this->getJson('/.well-known/openid-configuration')->assertJsonPath('pushed_authorization_request_endpoint', config('clauth.issuer').'/oauth/par');
        $this->post('/oauth/par', ['client_id' => $this->client->id, 'client_secret' => 'wrong', 'redirect_uri' => self::REDIRECT])->assertStatus(401);
        $this->post('/oauth/par', ['client_id' => $this->client->id, 'client_secret' => $this->secret, 'redirect_uri' => 'https://evil.test/cb'])->assertStatus(400);
        $uri = $this->post('/oauth/par', ['client_id' => $this->client->id, 'client_secret' => $this->secret, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code',
            'scope' => 'openid', 'state' => 'par-state', 'code_challenge' => str_repeat('b', 43), 'code_challenge_method' => 'S256'])->assertCreated()->json('request_uri');
        $loc = (string) $this->get('/oauth/authorize?'.http_build_query(['client_id' => $this->client->id, 'request_uri' => $uri]))->headers->get('Location');
        $this->assertStringContainsString('state=par-state', $loc);
        $this->get('/oauth/authorize?'.http_build_query(['client_id' => $this->client->id, 'request_uri' => $uri]))->assertStatus(400); // one use
    }

    public function test_device_sign_in(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        $device = new OAuthClient;
        $device->forceFill(['app_id' => $this->xp->id, 'type' => 'device', 'name' => 'POS', 'secret' => null, 'redirect_uris' => [],
            'grant_types' => ['urn:ietf:params:oauth:grant-type:device_code', 'refresh_token'], 'revoked' => false])->save();

        $this->post('/oauth/device', ['client_id' => $this->client->id, 'scope' => 'openid'])->assertStatus(400); // not a device client
        $d = $this->post('/oauth/device', ['client_id' => $device->id, 'scope' => 'openid clauth offline_access'])->assertOk()->json();
        $this->assertStringEndsWith('/device', $d['verification_uri']);
        $poll = fn () => $this->post('/oauth/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'client_id' => $device->id, 'device_code' => $d['device_code']]);
        $poll()->assertStatus(400)->assertJsonPath('error', 'authorization_pending');

        $this->get('/device?user_code=XXXX-XXXX')->assertRedirect(); // signed out → sign in first
        $this->actingAs($maria)->withSession(['auth.via' => 'password', 'auth.mfa' => true]);
        $this->get('/device?user_code=WRONG-CODE')->assertOk()->assertSee('isn’t valid');
        $this->get('/device?user_code='.$d['user_code'])->assertOk()->assertSee('Xpenses')->assertSee('Yes, sign in');
        $this->post('/device', ['user_code' => $d['user_code'], 'decision' => 'approve'])->assertOk()->assertSee('You’re signed in');

        $this->travel(10)->seconds();
        $tok = $poll()->assertOk()->json();
        $this->assertNotEmpty($tok['refresh_token']);
        $claims = base64_decode(strtr(explode('.', $tok['access_token'])[1], '-_', '+/'));
        $this->assertSame($maria->sub(), json_decode($claims, true)['sub']);

        // Someone without access can't approve a device for the app.
        $d2 = $this->post('/oauth/device', ['client_id' => $device->id, 'scope' => 'openid'])->json();
        $nobody = $this->makeUser('nobody@x.ph');
        $this->actingAs($nobody)->withSession(['auth.via' => 'password', 'auth.mfa' => true]);
        $this->post('/device', ['user_code' => $d2['user_code'], 'decision' => 'approve'])->assertOk()->assertSee('Not signed in');
        $this->travel(10)->seconds();
        $this->post('/oauth/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'client_id' => $device->id, 'device_code' => $d2['device_code']])
            ->assertStatus(401)->assertJsonPath('error', 'access_denied');
    }

    public function test_policy_api_and_openapi(): void
    {
        $c = new OAuthClient;
        $c->forceFill(['type' => 'api', 'name' => 's', 'secret' => 'api-secret-api-secret', 'organization_id' => $this->cbg->id, 'created_by' => $this->admin->id,
            'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'scopes' => ['apps:read', 'apps:write'], 'revoked' => false])->save();
        $t = $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $c->id, 'client_secret' => 'api-secret-api-secret', 'scope' => 'apps:read apps:write'])->json('access_token');
        $api = fn () => $this->flushHeaders()->withHeaders(['Authorization' => "Bearer $t", 'Accept' => 'application/json']);
        $api()->getJson('/api/v1/apps/xpenses/policy')->assertOk()->assertJsonPath('source', 'default');
        $api()->putJson('/api/v1/apps/xpenses/policy', ['two_step' => 'always', 'sms_second_step' => false])->assertOk()->assertJsonPath('rules.two_step', 'always')->assertJsonPath('version', 1);
        $api()->putJson('/api/v1/apps/xpenses/policy', ['two_step' => 'sometimes'])->assertStatus(422);
        $api()->putJson('/api/v1/apps/xpenses/policy', ['google' => false])->assertOk()->assertJsonPath('version', 2);
        $api()->getJson('/api/v1/apps/xpenses/policy/versions')->assertOk()->assertJsonCount(2, 'data');
        $api()->postJson('/api/v1/apps/xpenses/policy/restore', ['version' => 1])->assertOk()->assertJsonPath('rules.google', true)->assertJsonPath('version', 3);
        $api()->deleteJson('/api/v1/apps/xpenses/policy')->assertNoContent();
        $api()->getJson('/api/v1/policy')->assertStatus(403);

        $routes = array_keys(OpenApi::routes());
        sort($routes);
        $docs = array_keys(OpenApi::DOCS);
        sort($docs);
        $this->assertSame($docs, $routes);
    }
}
