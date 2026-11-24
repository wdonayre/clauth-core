<?php

namespace Tests\Feature;

use App\Models\AppDomain;
use App\Models\Grant;
use App\Models\SocialIdentity;
use App\Models\User;
use App\Services\Domains;
use App\Services\Invites;
use App\Services\Theme;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;

/** Sign-in redesign, round 2: accept an invite with Google (invited email only), real lock countdown, "Match device" look. */
class SigninExtrasTest extends ClauthTestCase
{
    private const MAIN = 'https://auth.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['clauth.issuer' => self::MAIN, 'app.url' => self::MAIN,
            'services.google.client_id' => 'test-client.apps.googleusercontent.com', 'services.google.client_secret' => 'test-secret']);
        Mail::fake();
    }

    private function google(string $id, ?string $email, bool $verified = true): void
    {
        $g = (new GoogleUser)->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $verified])->map(['id' => $id, 'email' => $email, 'name' => 'G']);
        $driver = Mockery::mock();
        $driver->shouldReceive('redirectUrl')->with(self::MAIN.'/login/google/callback')->andReturnSelf();
        $driver->shouldReceive('scopes')->andReturnSelf();
        $driver->shouldReceive('with')->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturn(redirect()->away('https://accounts.google.com/o/oauth2/auth?x=1'));
        $driver->shouldReceive('user')->andReturn($g);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('driver')->with('google')->andReturn($driver);
        Socialite::swap($factory);
    }

    /** @return array{0: \App\Models\Invite, 1: string} */
    private function invite(string $email = 'ana@cakesbygrace.ph'): array
    {
        return Invites::create(['email' => $email, 'name' => 'Ana Reyes', 'organization_id' => $this->cbg->id,
            'grants' => [['app_id' => $this->xp->id, 'role_id' => $this->role('finance')->id]]], null);
    }

    public function test_invite_page_offers_google_when_the_app_allows_it(): void
    {
        [, $token] = $this->invite();
        $this->get(self::MAIN."/invite/$token")->assertOk()->assertSee('Continue with Google')->assertSee('/login/google?invite=1', false);
        $this->assertSame($token, session('invite.token'));

        // Same rules as the sign-in page: no Google client, or the app's sign-in rules turn Google off → no button.
        config(['services.google.client_id' => null]);
        $this->get(self::MAIN."/invite/$token")->assertOk()->assertDontSee('Continue with Google');
    }

    public function test_accepting_an_invite_with_google_sets_up_the_invited_account(): void
    {
        [$invite, $token] = $this->invite();
        $this->google('g-ana', 'Ana@CakesbyGrace.ph');
        $this->keep($this->get(self::MAIN."/invite/$token"));
        $this->keep($this->get(self::MAIN.'/login/google?invite=1'))->assertRedirect('https://accounts.google.com/o/oauth2/auth?x=1');
        $this->assertSame($token, session('social.invite'));
        $this->assertSame('xpenses', session('login.app_key'));

        // Onboarding continues exactly as after "Create account": 2-step is offered and can be skipped.
        $this->keep($this->get(self::MAIN.'/login/google/callback'))->assertRedirect(self::MAIN.'/login/2fa/setup');
        $this->assertGuest();
        $this->keep($this->post(self::MAIN.'/login/2fa/skip'))->assertRedirect('https://xpenses.test');
        $ana = User::where('email', 'ana@cakesbygrace.ph')->first();
        $this->assertAuthenticatedAs($ana);
        $this->assertSame(['fed'], session('auth.amr'));
        $this->assertSame('google', session('auth.via'));
        $this->assertSame('active', $ana->status);
        $this->assertNull($ana->password);
        $this->assertNotNull($invite->fresh()->accepted_at);
        $this->assertTrue(Grant::where('user_id', $ana->id)->where('organization_id', $this->cbg->id)->exists());
        $this->assertDatabaseHas('social_identities', ['user_id' => $ana->id, 'provider' => 'google', 'provider_id' => 'g-ana']);
        $this->get(self::MAIN."/invite/$token")->assertStatus(410); // single use
    }

    public function test_only_the_invited_email_can_accept_with_google(): void
    {
        [$invite, $token] = $this->invite();

        // A different Google account goes back to the invite page with a fixed message; nothing is created or applied.
        $this->google('g-other', 'someone.else@gmail.com');
        $this->keep($this->get(self::MAIN."/invite/$token"));
        $this->keep($this->get(self::MAIN.'/login/google?invite=1'));
        $this->keep($this->get(self::MAIN.'/login/google/callback'))->assertRedirect(self::MAIN."/invite/$token?social_error=invite_email");
        $this->assertGuest();
        $this->assertNull(session('social.invite'));
        $this->get(self::MAIN."/invite/$token?social_error=invite_email")->assertOk()->assertSee('uses a different email');

        // The right address, but Google hasn't verified it.
        $this->google('g-ana', 'ana@cakesbygrace.ph', verified: false);
        $this->keep($this->get(self::MAIN.'/login/google?invite=1'));
        $this->keep($this->get(self::MAIN.'/login/google/callback'))->assertRedirect(self::MAIN."/invite/$token?social_error=unverified");

        $this->assertGuest();
        $this->assertNull($invite->fresh()->accepted_at);
        $this->assertSame('invited', User::where('email', 'ana@cakesbygrace.ph')->value('status'));
        $this->assertDatabaseMissing('social_identities', ['provider_id' => 'g-other']);
    }

    public function test_a_google_account_linked_to_someone_else_cannot_take_the_invite(): void
    {
        [$invite, $token] = $this->invite();
        $jo = $this->makeUser('jo@cakesbygrace.ph');
        SocialIdentity::create(['user_id' => $jo->id, 'provider' => 'google', 'provider_id' => 'g-jo']);
        $this->google('g-jo', 'ana@cakesbygrace.ph');
        $this->keep($this->get(self::MAIN."/invite/$token"));
        $this->keep($this->get(self::MAIN.'/login/google?invite=1'));
        $this->keep($this->get(self::MAIN.'/login/google/callback'))->assertRedirect(self::MAIN."/invite/$token?social_error=invite_email");
        $this->assertGuest();
        $this->assertNull($invite->fresh()->accepted_at);
        $this->assertSame($jo->id, SocialIdentity::where('provider_id', 'g-jo')->value('user_id'));
    }

    public function test_a_plain_google_sign_in_never_picks_up_an_old_invite(): void
    {
        [$invite, $token] = $this->invite();
        $this->keep($this->get(self::MAIN."/invite/$token"));
        $this->google('g-ana', 'ana@cakesbygrace.ph');
        // Started from the sign-in page (no ?invite): the invited-but-not-set-up account is still turned away.
        $this->keep($this->get(self::MAIN.'/login/google'));
        $this->assertNull(session('social.invite'));
        $this->get(self::MAIN.'/login/google/callback')->assertRedirect(self::MAIN.'/login?social_error=invited');
        $this->assertNull($invite->fresh()->accepted_at);

        // A made-up or used token is ignored.
        $this->get(self::MAIN.'/login/google?invite=not-a-real-token');
        $this->assertNull(session('social.invite'));
    }

    public function test_invite_on_an_app_domain_hops_to_main_with_the_invite(): void
    {
        $d = AppDomain::create(['host' => 'auth.xpenses.test', 'organization_id' => $this->cbg->id, 'trusted' => true,
            'verify_token' => Str::lower(Str::random(32)), 'status' => 'active', 'active_at' => now()]);
        $d->apps()->attach($this->xp->id);
        Domains::flush($d->host);
        [, $token] = $this->invite();

        $this->keep($this->get("https://auth.xpenses.test/invite/$token"))->assertOk()->assertSee('Continue with Google');
        $this->get('https://auth.xpenses.test/login/google?invite=1')
            ->assertRedirect(self::MAIN.'/login/google?'.http_build_query(['back' => 'auth.xpenses.test', 'app' => 'xpenses', 'invite' => $token]));

        $this->flushSession();
        $this->google('g-ana', 'ana@cakesbygrace.ph');
        $this->keep($this->get(self::MAIN.'/login/google?'.http_build_query(['back' => 'auth.xpenses.test', 'app' => 'xpenses', 'invite' => $token])));
        $this->assertSame($token, session('social.invite'));
        $this->keep($this->get(self::MAIN.'/login/google/callback'))->assertRedirect(self::MAIN.'/login/2fa/setup');
        $this->assertSame('https://auth.xpenses.test/login?sso=1', session('url.intended'));
    }

    public function test_locked_page_counts_down_the_real_time_left(): void
    {
        // Opened directly (no lock in this session): no timer, nothing about any account.
        $this->get('/login/locked')->assertOk()->assertDontSee('data-countdown-seconds', false)->assertSee('Try again in a few minutes');

        $user = $this->makeUser();
        $user->forceFill(['locked_until' => now()->addSeconds(437)])->save();
        $this->keep($this->post('/login', ['email' => $user->email, 'password' => 'whatever']))->assertRedirect('/login/locked');
        $html = $this->get('/login/locked')->assertOk()->assertSee('Too many tries')->getContent();
        $this->assertMatchesRegularExpression('/data-countdown-seconds="(43[5-7])"/', $html);
        $this->assertStringContainsString('>7:1', $html);
    }

    public function test_fresh_lockout_shows_the_configured_pause(): void
    {
        $user = $this->makeUser();
        for ($i = 0; $i < 5; $i++) {
            $r = $this->keep($this->post('/login', ['email' => $user->email, 'password' => 'wrong'.$i]));
        }
        $r->assertRedirect('/login/locked');
        $minutes = (int) \App\Services\Settings::get('lockout_minutes');
        $this->assertMatchesRegularExpression('/data-countdown-seconds="('.($minutes * 60 - 2).'|'.($minutes * 60 - 1).'|'.($minutes * 60).')"/', $this->get('/login/locked')->getContent());
    }

    public function test_match_device_theme_follows_the_visitors_setting(): void
    {
        $owner = $this->makeUser('owner@clauth.test', ['clauth_role' => 'owner']);
        $this->actingAs($owner)->withSession(['auth.mfa' => true])
            ->post('/admin/apps/xpenses/branding', ['login_theme' => 'auto', 'accent_color' => '#E4572E'])->assertSessionHasNoErrors();
        $this->assertSame('auto', $this->xp->fresh()->login_theme);
        $this->actingAs($owner)->withSession(['auth.mfa' => true])
            ->post('/admin/apps/xpenses/branding', ['login_theme' => 'sepia'])->assertSessionHasErrors('login_theme');
        $this->actingAs($owner)->withSession(['auth.mfa' => true])->get('/admin/apps/xpenses?tab=signin')->assertOk()->assertSee('Match device');

        $t = Theme::for($this->xp->fresh());
        $this->assertTrue($t['auto']);
        $this->assertSame('#F7F8FA', $t['vars']['bg']);
        $this->assertSame('#0F0F12', $t['dark_vars']['bg']);
        $this->assertSame('#E4572E', $t['vars']['accent']);
        $this->assertSame('#E4572E', $t['dark_vars']['accent']); // the app's accent on both sides
        $this->assertStringContainsString('--bg:#0F0F12 !important', Theme::darkCss($t));

        auth()->logout();
        $this->flushSession();
        $this->withSession(['login.app_key' => 'xpenses'])->get('/login')->assertOk()
            ->assertSee('class="auth light auto', false)->assertSee('@media (prefers-color-scheme: dark)', false)
            ->assertSee('media="(prefers-color-scheme: dark)"', false);

        // Other styles are unchanged (no dark block), and the preview iframe takes ?theme=auto.
        $this->assertNull(Theme::darkCss(Theme::for(null)));
        $this->actingAs($owner)->withSession(['auth.mfa' => true])->get('/admin/apps/xpenses/preview?theme=auto&mode=signin')->assertOk()->assertSee('auto', false)
            ->assertSee('prefers-color-scheme: dark', false);
        $this->actingAs($owner)->withSession(['auth.mfa' => true])->get('/admin/apps/xpenses/preview?theme=midnight&mode=signin')->assertOk()
            ->assertSee('class="auth dark', false)->assertDontSee('.auth.auto{', false);
    }
}
