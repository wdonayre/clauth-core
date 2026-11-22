<?php

namespace Tests\Feature;

use App\Models\UserDevice;
use App\Services\TwoFactor;
use Illuminate\Support\Str;

/** Fewer repeat sign-ins: "Keep me signed in" is ticked by default, and a trusted device skips the 2-step code again
 *  when Clauth's own session restarts from the remember cookie. */
class StaySignedInTest extends ClauthTestCase
{
    private function twoStepUser(array $attrs = [])
    {
        $user = $this->makeUser('maria@cakesbygrace.ph', $attrs);
        $user->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['aaaaa-bbbbb']])->save();
        $this->grant($user, $this->cbg, 'finance');

        return $user;
    }

    private function trustDevice($user, bool $trusted = true): void
    {
        $token = Str::random(40);
        UserDevice::create(['user_id' => $user->id, 'cookie_hash' => hash('sha256', $token), 'trusted_until' => $trusted ? now()->addDays(30) : null]);
        $this->withCookie('clauth_dev', $token);
    }

    public function test_keep_me_signed_in_is_ticked_by_default_but_respects_an_untick(): void
    {
        $this->get('/login')->assertOk()->assertSee('name="remember" value="1" checked', false);
        $this->withSession(['_old_input' => ['email' => 'x@y.test']])->get('/login')->assertDontSee('name="remember" value="1" checked', false);
    }

    public function test_remembered_session_on_trusted_device_skips_the_code(): void
    {
        $user = $this->twoStepUser();
        $this->xp->update(['require_mfa_roles' => ['finance']]);
        $this->trustDevice($user);
        $this->actingAs($user); // signed in again from the remember cookie: no 2-step flag in the session

        $result = $this->authorizeCode();
        $this->assertIsArray($result, is_array($result) ? '' : 'expected an authorization code, got a redirect to '.$result->headers->get('Location'));
        $this->assertNotEmpty($result[0]);
    }

    public function test_untrusted_device_still_asks_for_the_code(): void
    {
        $user = $this->twoStepUser();
        $this->xp->update(['require_mfa_roles' => ['finance']]);
        $this->trustDevice($user, trusted: false);
        $this->actingAs($user);

        $this->authorizeCode()->assertRedirect(route('login.mfa'));
    }

    public function test_admins_always_enter_the_code(): void
    {
        $user = $this->twoStepUser(['clauth_role' => 'owner']);
        $this->trustDevice($user);
        $this->actingAs($user);

        $this->authorizeCode()->assertRedirect(route('login.mfa'));
    }
}
