<?php

namespace Tests\Feature;

use App\Models\AppDomain;
use App\Models\SocialIdentity;
use App\Models\User;
use App\Services\Domains;
use App\Services\TwoFactor;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;

/** "Continue with Google": invite-only matching, 2-step still applies, link/unlink, app-domain hop. */
class SocialLoginTest extends ClauthTestCase
{
    private const MAIN = 'https://auth.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['clauth.issuer' => self::MAIN, 'app.url' => self::MAIN,
            'services.google.client_id' => 'test-client.apps.googleusercontent.com', 'services.google.client_secret' => 'test-secret']);
    }

    private function google(string $id, ?string $email, bool $verified = true): void
    {
        $g = (new GoogleUser)->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $verified])->map(['id' => $id, 'email' => $email, 'name' => 'G']);
        $driver = Mockery::mock();
        $driver->shouldReceive('redirectUrl')->with(self::MAIN.'/login/google/callback')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($g);
        $this->useDriver($driver);
    }

    private function useDriver($driver): void
    {
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('driver')->with('google')->andReturn($driver);
        Socialite::swap($factory);
    }

    public function test_button_only_shows_when_configured(): void
    {
        $this->get(self::MAIN.'/login')->assertOk()->assertSee('Continue with Google');
        config(['services.google.client_id' => null]);
        $this->get(self::MAIN.'/login')->assertOk()->assertDontSee('Continue with Google');
        $this->get(self::MAIN.'/login/google')->assertNotFound();
    }

    public function test_existing_user_with_verified_google_email_signs_in_and_gets_linked(): void
    {
        $user = $this->makeUser();
        $this->google('g-111', 'Maria@CakesbyGrace.ph');
        $this->keep($this->get(self::MAIN.'/login/google/callback?state=x&code=y'))->assertRedirect(self::MAIN.'/account');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(['fed'], session('auth.amr'));
        $this->assertDatabaseHas('social_identities', ['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'g-111']);
        $this->assertDatabaseHas('audit_events', ['event' => 'security.social_linked']);
    }

    public function test_strangers_and_unverified_emails_are_turned_away(): void
    {
        $this->google('g-222', 'stranger@gmail.com');
        $this->get(self::MAIN.'/login/google/callback')->assertRedirect(self::MAIN.'/login?social_error=no_account');
        $this->assertGuest();
        $this->assertSame(0, User::count());

        $this->makeUser();
        $this->google('g-333', 'maria@cakesbygrace.ph', verified: false);
        $this->get(self::MAIN.'/login/google/callback')->assertRedirect(self::MAIN.'/login?social_error=unverified');
        $this->assertGuest();
        $this->get(self::MAIN.'/login?social_error=unverified')->assertSee('hasn’t verified');
        $this->get(self::MAIN.'/login?social_error=<script>')->assertDontSee('<script>', false);
    }

    public function test_invited_and_disabled_people_cannot_use_google(): void
    {
        $this->makeUser('ana@cakesbygrace.ph', ['status' => 'invited', 'password' => null]);
        $this->google('g-1', 'ana@cakesbygrace.ph');
        $this->get(self::MAIN.'/login/google/callback')->assertRedirect(self::MAIN.'/login?social_error=invited');
        $this->makeUser('bo@cakesbygrace.ph', ['status' => 'disabled']);
        $this->google('g-2', 'bo@cakesbygrace.ph');
        $this->get(self::MAIN.'/login/google/callback')->assertRedirect(self::MAIN.'/login?social_error=disabled');
        $this->assertGuest();
    }

    public function test_two_step_still_required_after_google(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['two_factor_secret' => $secret = TwoFactor::newSecret(), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['aaaaa-bbbbb']])->save();
        $this->google('g-111', 'maria@cakesbygrace.ph');
        $this->keep($this->get(self::MAIN.'/login/google/callback'))->assertRedirect(self::MAIN.'/login/2fa');
        $this->assertGuest();
        $this->keep($this->post(self::MAIN.'/login/2fa', ['code' => TwoFactor::engine()->getCurrentOtp($secret)]))->assertRedirect(self::MAIN.'/account');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(['fed', 'otp'], session('auth.amr'));
    }

    public function test_link_from_my_account_with_another_email_then_unlink(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser('jo@cakesbygrace.ph');
        SocialIdentity::create(['user_id' => $other->id, 'provider' => 'google', 'provider_id' => 'g-taken']);

        $this->actingAs($user)->get(self::MAIN.'/account')->assertSee('Link Google');
        $this->google('g-personal', 'maria.personal@gmail.com');
        $this->withSession(['social.link' => $user->id])->get(self::MAIN.'/login/google/callback')->assertRedirect(self::MAIN.'/account');
        $this->assertDatabaseHas('social_identities', ['user_id' => $user->id, 'provider_id' => 'g-personal', 'email' => 'maria.personal@gmail.com']);
        $this->get(self::MAIN.'/account')->assertSee('maria.personal@gmail.com')->assertSee('Unlink');

        // Someone else's Google account can't be taken over by linking it.
        $this->google('g-taken', 'jo@cakesbygrace.ph');
        $this->withSession(['social.link' => $user->id])->get(self::MAIN.'/login/google/callback')->assertSessionHasErrors('social');
        $this->assertSame($other->id, SocialIdentity::where('provider_id', 'g-taken')->value('user_id'));

        $this->delete(self::MAIN.'/account/link/google')->assertRedirect();
        $this->assertDatabaseMissing('social_identities', ['user_id' => $user->id]);
        $this->assertDatabaseHas('audit_events', ['event' => 'security.social_unlinked']);

        // The linked personal Gmail signs in to the work account.
        SocialIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'g-personal']);
        auth()->logout();
        $this->flushSession();
        $this->google('g-personal', 'maria.personal@gmail.com');
        $this->get(self::MAIN.'/login/google/callback')->assertRedirect(self::MAIN.'/account');
        $this->assertAuthenticatedAs($user);
    }

    public function test_started_on_an_app_domain_hops_to_main_and_returns(): void
    {
        $d = AppDomain::create(['host' => 'auth.xpenses.test', 'organization_id' => $this->cbg->id, 'trusted' => true,
            'verify_token' => Str::lower(Str::random(32)), 'status' => 'active', 'active_at' => now()]);
        $d->apps()->attach($this->xp->id);
        Domains::flush($d->host);

        $this->get('https://auth.xpenses.test/login/google')->assertRedirect(self::MAIN.'/login/google?back=auth.xpenses.test&app=xpenses');
        // On main: Google's redirect is prepared with the main-domain callback.
        $driver = Mockery::mock();
        $driver->shouldReceive('redirectUrl')->with(self::MAIN.'/login/google/callback')->andReturnSelf();
        $driver->shouldReceive('scopes')->andReturnSelf();
        $driver->shouldReceive('with')->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturn(redirect()->away('https://accounts.google.com/o/oauth2/auth?x=1'));
        $this->useDriver($driver);
        $this->keep($this->get(self::MAIN.'/login/google?back=auth.xpenses.test&app=xpenses'))->assertRedirect('https://accounts.google.com/o/oauth2/auth?x=1');
        $this->assertSame('auth.xpenses.test', session('social.back'));
        $this->assertSame('xpenses', session('login.app_key'));

        $this->makeUser();
        $this->google('g-111', 'maria@cakesbygrace.ph');
        $this->keep($this->get(self::MAIN.'/login/google/callback'))->assertRedirect('https://auth.xpenses.test/login?sso=1');

        // An unknown "back" host is ignored (no open redirect).
        $this->flushSession();
        $this->get(self::MAIN.'/login/google?back=evil.example');
        $this->assertNull(session('social.back'));
    }
}
