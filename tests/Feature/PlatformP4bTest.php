<?php

namespace Tests\Feature;

use App\Mail\InviteMail;
use App\Mail\TemplateMail;
use App\Models\OAuthClient;
use App\Models\SigninTheme;
use App\Models\User;
use App\Services\Invites;
use App\Services\SigninPolicies;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** P4b: flow execution API, themes, templates, translations, CORS (SPEC-platform §8.2, §8.4, §8.5, §10). */
class PlatformP4bTest extends ClauthTestCase
{
    private OAuthClient $mobile;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mobile = new OAuthClient;
        $this->mobile->forceFill(['app_id' => $this->xp->id, 'type' => 'public', 'name' => 'Xpenses mobile', 'secret' => null,
            'redirect_uris' => ['xpenses://auth'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false])->save();
        $this->admin = $this->makeUser('owner@cakesbygrace.ph', ['name' => 'Grace']);
        $this->admin->organizations()->attach($this->cbg->id, ['role' => 'owner', 'joined_at' => now()]);
        $this->admin->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
    }

    private function flow(array $body)
    {
        return $this->postJson('/api/v1/flow/execute', $body + ['client_id' => $this->mobile->id]);
    }

    private function start(string $verifier)
    {
        return $this->flow(['redirect_uri' => 'xpenses://auth', 'scope' => 'openid clauth offline_access', 'state' => 'st',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'])->assertOk()->json('flow_id');
    }

    // ------------------------------------------------------------ flow execution

    public function test_app_native_sign_in_with_password_and_two_step(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        $secret = TwoFactor::newSecret();
        $maria->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        $this->flow(['redirect_uri' => 'https://evil.test', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256'])->assertStatus(400);
        $this->postJson('/api/v1/flow/execute', ['client_id' => $this->client->id, 'redirect_uri' => self::REDIRECT])->assertStatus(400); // web client: not allowed

        $verifier = Str::random(64);
        $id = $this->start($verifier);
        $this->flow(['flow_id' => $id, 'action' => 'password', 'password' => 'x'])->assertStatus(422); // must identify first
        $this->flow(['flow_id' => $id, 'action' => 'identify', 'email' => 'Maria@CakesbyGrace.ph'])->assertOk()->assertJsonPath('step', 'method')->assertJsonPath('methods', ['password']);
        $this->flow(['flow_id' => $id, 'action' => 'password', 'password' => 'wrong'])->assertStatus(401);
        $this->flow(['flow_id' => $id, 'action' => 'password', 'password' => self::PASSWORD])->assertOk()->assertJsonPath('step', 'two_step')->assertJsonPath('methods', ['totp']);
        $this->flow(['flow_id' => $id, 'action' => 'totp', 'code' => '000000'])->assertStatus(401);
        $done = $this->flow(['flow_id' => $id, 'action' => 'totp', 'code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk()->assertJsonPath('step', 'done')->assertJsonPath('state', 'st')->json();

        $tok = $this->post('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $this->mobile->id, 'redirect_uri' => 'xpenses://auth',
            'code' => $done['code'], 'code_verifier' => $verifier])->assertOk()->json();
        $claims = json_decode(base64_decode(strtr(explode('.', $tok['access_token'])[1], '-_', '+/')), true);
        $this->assertSame(['pwd', 'otp'], $claims['amr']);
        $this->flow(['flow_id' => $id, 'action' => 'totp', 'code' => '123456'])->assertNotFound(); // flow is used up
    }

    public function test_flow_respects_access_lockout_and_sends_browser_when_needed(): void
    {
        $nobody = $this->makeUser('nobody@x.ph');
        $id = $this->start(Str::random(64));
        $this->flow(['flow_id' => $id, 'action' => 'identify', 'email' => $nobody->email]);
        $this->flow(['flow_id' => $id, 'action' => 'password', 'password' => self::PASSWORD])->assertStatus(403); // no access to Xpenses

        // 2-step required but not set up: only the browser can do that.
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        SigninPolicies::save($this->xp, null, SigninPolicies::validate(['two_step' => 'always'], $this->xp, null), $this->admin);
        $id = $this->start(Str::random(64));
        $this->flow(['flow_id' => $id, 'action' => 'identify', 'email' => $maria->email]);
        $r = $this->flow(['flow_id' => $id, 'action' => 'password', 'password' => self::PASSWORD])->assertOk()->assertJsonPath('step', 'browser');
        $this->assertStringContainsString('/oauth/authorize?', $r->json('url'));

        // Wrong passwords lock the account like the hosted page does.
        \App\Services\Settings::set(['lockout_attempts' => 3]);
        $id = $this->start(Str::random(64));
        $this->flow(['flow_id' => $id, 'action' => 'identify', 'email' => $maria->email]);
        foreach (range(1, 3) as $i) {
            $this->flow(['flow_id' => $id, 'action' => 'password', 'password' => 'nope'])->assertStatus(401);
        }
        $this->assertTrue($maria->fresh()->isLocked());
    }

    // ------------------------------------------------------------ themes

    public function test_designed_theme_renders_and_is_assignable(): void
    {
        $as = fn () => $this->actingAs($this->admin)->withSession(['auth.mfa' => true]);
        $as()->post("/admin/orgs/{$this->cbg->id}/themes", ['name' => 'Grace pink', 'base' => 'daylight', 'accent' => '#BE185D', 'background' => '#FFF1F2',
            'font' => 'Poppins', 'radius' => 20, 'buttons' => 'pill', 'layout' => 'split', 'tagline' => 'Fresh cakes, every day.'])->assertRedirect();
        $t = SigninTheme::firstOrFail();
        $as()->post("/admin/orgs/{$this->cbg->id}/themes", ['name' => 'Bad', 'base' => 'daylight', 'accent' => 'pink'])->assertSessionHasErrors('accent');
        $as()->get("/admin/orgs/{$this->alab->id}/themes")->assertNotFound();

        $as()->post('/admin/apps/xpenses/branding', ['login_theme' => 'designed:'.$t->key])->assertSessionHasNoErrors();
        $this->assertSame($t->id, $this->xp->fresh()->signin_theme_id);
        auth()->logout();
        $this->flushSession();
        $this->keep($this->get('/oauth/authorize?'.http_build_query(['client_id' => $this->client->id, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code',
            'scope' => 'openid', 'state' => 's', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256'])));
        $this->keep($this->get('/login'))->assertOk()->assertSee('family=Poppins', false)->assertSee('auth light split', false)
            ->assertSee('Fresh cakes, every day.')->assertSee('--btn-radius:999px', false)->assertSee('--bg:#FFF1F2', false);

        // Another organization's theme can't be used.
        $other = SigninTheme::create(['organization_id' => $this->alab->id, 'key' => 'alab', 'name' => 'Alab', 'settings' => ['base' => 'midnight']]);
        $as()->post('/admin/apps/xpenses/branding', ['login_theme' => 'designed:alab'])->assertSessionHasErrors('login_theme');
        $this->assertSame(['apps' => ['xpenses'], 'domains' => []], \App\Services\Themes::usages($t));
    }

    public function test_themes_api(): void
    {
        $c = new OAuthClient;
        $c->forceFill(['type' => 'api', 'name' => 's', 'secret' => 'api-secret-api-secret', 'organization_id' => $this->cbg->id, 'created_by' => $this->admin->id,
            'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'scopes' => ['apps:read', 'apps:write'], 'revoked' => false])->save();
        $tok = $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $c->id, 'client_secret' => 'api-secret-api-secret', 'scope' => 'apps:read apps:write'])->json('access_token');
        $api = fn () => $this->flushHeaders()->withHeaders(['Authorization' => "Bearer $tok", 'Accept' => 'application/json']);
        $api()->getJson('/api/v1/themes')->assertOk()->assertJsonCount(count(\App\Services\Theme::NAMES), 'data')->assertJsonPath('data.0.built_in', true);
        $key = $api()->postJson('/api/v1/themes', ['name' => 'Night', 'org' => 'cakesbygrace', 'base' => 'midnight', 'font' => 'Manrope', 'layout' => 'center'])->assertCreated()->json('key');
        $api()->postJson('/api/v1/themes', ['name' => 'X', 'org' => 'cakesbygrace', 'base' => 'neon'])->assertStatus(422);
        $api()->patchJson("/api/v1/themes/$key", ['radius' => 4])->assertOk()->assertJsonPath('settings.radius', 4)->assertJsonPath('settings.font', 'Manrope');
        $api()->putJson('/api/v1/apps/xpenses/branding', ['theme' => $key])->assertOk()->assertJsonPath('theme', $key);
        $api()->getJson('/api/v1/themes/resolve?app=xpenses')->assertOk()->assertJsonPath('theme', $key)->assertJsonPath('fonts', ['Manrope']);
        $api()->getJson("/api/v1/themes/$key/usages")->assertJsonPath('apps', ['xpenses']);
        $api()->deleteJson("/api/v1/themes/$key")->assertNoContent();
        $this->assertNull($this->xp->fresh()->signin_theme_id);
    }

    // ------------------------------------------------------------ templates & languages

    public function test_templates_for_invites_with_fallback_and_language(): void
    {
        Mail::fake();
        [$invite, $token] = Invites::create(['email' => 'ana@cakesbygrace.ph', 'name' => 'Ana', 'organization_id' => $this->cbg->id], $this->admin);
        Invites::send($invite, $token);
        Mail::assertSent(InviteMail::class);

        $as = fn () => $this->actingAs($this->admin)->withSession(['auth.mfa' => true]);
        $as()->post("/admin/orgs/{$this->cbg->id}/messages", ['key' => 'invite', 'locale' => 'en', 'subject' => 'Join {{org}}', 'body' => 'Hi {{name}}, no link here'])->assertSessionHasErrors('body');
        $as()->post("/admin/orgs/{$this->cbg->id}/messages", ['key' => 'login_code', 'locale' => 'en', 'subject' => 'x', 'body' => '{{code}}'])->assertSessionHasErrors('key');
        $as()->post("/admin/orgs/{$this->cbg->id}/messages", ['key' => 'invite', 'locale' => 'en', 'subject' => 'Join {{org}}', 'body' => "Hi {{name}},\n\nOpen {{link}} to start."])->assertSessionHas('status');
        Invites::send($invite, $token);
        Mail::assertSent(TemplateMail::class, fn ($m) => $m->subjectLine === 'Join CakesbyGrace' && str_contains($m->bodyText, 'Hi Ana,') && str_contains($m->bodyText, '/invite/'));
        $this->assertSame('Join CakesbyGrace', Mail::sent(TemplateMail::class)->first()->envelope()->subject);
    }

    public function test_filipino_sign_in_pages(): void
    {
        $this->get('/login?lang=fil')->assertOk()->assertSee('Mag-sign in')->assertSee('Nakalimutan ang password?')->assertSee('<html lang="fil">', false)
            ->assertCookie('clauth_lang', 'fil');
        $this->withHeaders(['Accept-Language' => 'tl-PH,tl;q=0.9,en;q=0.5'])->get('/login')->assertSee('Mag-sign in');
        $this->withHeaders(['Accept-Language' => 'en-US'])->get('/login?lang=en')->assertSee('Forgot password?');
        // Platform wording change through the API applies on the page.
        DB::table('translation_overrides')->insert(['locale' => 'fil', 'key' => 'Forgot password?', 'text' => 'Nalimutan ang password?', 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\Cache::forget('i18n:over:fil');
        app('translator')->setLoaded([]);
        $this->get('/login?lang=fil')->assertSee('Nalimutan ang password?');
    }

    public function test_cors_for_browser_apps(): void
    {
        $this->mobile->forceFill(['redirect_uris' => ['https://spa.cakesbygrace.ph/callback']])->save();
        \Illuminate\Support\Facades\Cache::forget('oauth:cors-origins');
        $this->call('OPTIONS', '/oauth/token', [], [], [], ['HTTP_ORIGIN' => 'https://spa.cakesbygrace.ph', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'])
            ->assertStatus(204)->assertHeader('Access-Control-Allow-Origin', 'https://spa.cakesbygrace.ph');
        $this->call('OPTIONS', '/oauth/token', [], [], [], ['HTTP_ORIGIN' => 'https://evil.test'])->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->withHeaders(['Origin' => 'https://anyone.test'])->get('/.well-known/openid-configuration')->assertHeader('Access-Control-Allow-Origin', '*');
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
