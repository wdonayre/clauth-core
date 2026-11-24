<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use App\Services\PersonalTokens;
use App\Services\Settings;
use App\Services\SigninPolicies;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\Mail;

/** Redesigned Settings, sign-in rules, My account and API tokens pages (2026-11 console redesign). */
class SettingsAccountPagesTest extends ClauthTestCase
{
    private function owner(): User
    {
        $u = $this->makeUser('william@thecakelabs.com', ['name' => 'William Donayre', 'clauth_role' => 'owner']);
        $u->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => TwoFactor::recoveryCodes()])->save();

        return $u;
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withSession(['auth.mfa' => true]);
    }

    public function test_settings_page_shows_every_section_and_saves(): void
    {
        $o = $this->owner();
        Connection::create(['type' => 'meta', 'key' => 'facebook', 'name' => 'Facebook', 'status' => 'active', 'config' => []]);
        $this->as($o)->get('/admin/settings')->assertOk()
            ->assertSee('Sign-in &amp; security', false)->assertSee('Ways people can sign in')->assertSee('Sessions')
            ->assertSee('Signing keys')->assertSee('Start rotation')->assertSee('Clauth admins')->assertSee('William Donayre')
            ->assertSee('Default sign-in rules')->assertSee(route('admin.settings.policy'))->assertSee('Facebook')
            ->assertSee('form="settings-form"', false)->assertSee('Send a test');

        $this->as($o)->put('/admin/settings', ['password_min_length' => 14, 'lockout_attempts' => 6, 'lockout_minutes' => 20, 'session_idle_hours' => 12,
            'trusted_device_days' => 10, 'sms_daily_cap' => 50, 'support_email' => 'help@example.com', 'email_code_signin' => '1'])->assertRedirect();
        $this->assertSame(14, (int) Settings::get('password_min_length'));
        $this->assertTrue((bool) Settings::get('email_code_signin'));
        $this->assertFalse((bool) Settings::get('new_device_email'));
    }

    public function test_send_a_test_email_does_not_change_settings(): void
    {
        Mail::fake();
        $o = $this->owner();
        $before = Settings::all();
        $this->as($o)->put('/admin/settings', ['send_test' => 1])->assertRedirect()->assertSessionHas('status', 'Test email sent to william@thecakelabs.com.');
        $this->assertSame($before, Settings::all());
        $this->assertDatabaseHas('audit_events', ['event' => 'settings.test_email']);
    }

    public function test_settings_stays_owner_only(): void
    {
        $maria = $this->makeUser();
        $maria->organizations()->attach($this->cbg->id, ['role' => 'admin', 'joined_at' => now()]);
        $this->as($maria)->get('/admin/settings')->assertNotFound();
        $this->as($maria)->put('/admin/settings', ['send_test' => 1])->assertNotFound();
        $this->as($maria)->get('/admin/settings/sign-in-rules')->assertNotFound();
    }

    public function test_sign_in_rules_render_for_platform_org_and_app(): void
    {
        $o = $this->owner();
        SigninPolicies::save(null, $this->cbg, SigninPolicies::validate(['email_code' => false], null, $this->cbg), $o);
        $this->as($o)->get('/admin/settings/sign-in-rules')->assertOk()->assertSee('Settings sections')->assertSee('Ways to sign in')->assertSee('Save rules');
        $this->as($o)->get("/admin/orgs/{$this->cbg->id}/sign-in-rules")->assertOk()->assertSee('CakesbyGrace')->assertSee('Version 1')->assertSee('Use the default instead');
        $this->as($o)->get('/admin/apps/xpenses/sign-in-rules')->assertOk()->assertSee('Back to Xpenses')->assertSee('New people')->assertSee('Now using:', false);
        $this->as($o)->put('/admin/apps/xpenses/sign-in-rules', ['password' => 1, 'two_step' => 'always', 'registration' => 'invite', 'connections_mode' => 'all'])->assertRedirect();
        $this->assertSame('always', SigninPolicies::rules($this->xp)['two_step']);
    }

    public function test_my_account_for_a_plain_member(): void
    {
        $maria = $this->makeUser('maria@cakesbygrace.ph', ['name' => 'Maria Santos']);
        $this->grant($maria, $this->cbg, 'member');
        $this->as($maria)->get('/account')->assertOk()->assertSee('Hi, Maria')->assertSee('Your apps')->assertSee('Xpenses')
            ->assertSee('How you sign in')->assertSee('data-theme-switch', false)->assertSee('data-mode="dark"', false)
            ->assertSee('Where you\'re signed in', false)->assertSee('Personal API tokens')->assertSee('Turn on');

        // Wrong current password re-opens the change-password dialog.
        $this->as($maria)->from('/account')->post('/account/password', ['_dlg' => 'pw', 'current_password' => 'nope', 'password' => 'a-long-new-password-1', 'password_confirmation' => 'a-long-new-password-1'])
            ->assertRedirect('/account');
        $this->as($maria)->get('/account')->assertSee('id="dlg-pw" aria-labelledby="dlg-pw-h"  data-open-on-load', false);

        $this->as($maria)->post('/account/profile', ['name' => 'Maria S.'])->assertRedirect();
        $this->assertSame('Maria S.', $maria->fresh()->name);
    }

    public function test_my_account_with_an_authenticator_shows_manage_and_new_codes(): void
    {
        $o = $this->owner();
        $this->as($o)->get('/account')->assertOk()->assertSee('Manage')->assertSee('10 recovery codes left')->assertSee('New recovery codes');
        $this->as($o)->post('/account/2fa/codes')->assertRedirect()->assertSessionHas('new_codes');
        $this->as($o)->withSession(['new_codes' => ['aaaa-bbbb']])->get('/account')->assertSee('Your new recovery codes')->assertSee('aaaa-bbbb');
    }

    public function test_api_tokens_create_shows_secret_once_and_revoke(): void
    {
        $maria = $this->makeUser('maria@cakesbygrace.ph', ['name' => 'Maria Santos']);
        $this->as($maria)->get('/account/tokens')->assertOk()->assertSee('Personal API tokens')->assertSee('No tokens yet')->assertDontSee('onsubmit', false);
        $r = $this->as($maria)->post('/account/tokens', ['name' => 'Laptop CLI', 'days' => 30, 'scopes' => ['me:read']]);
        $r->assertRedirect()->assertSessionHas('new_token');
        $tok = PersonalTokens::list($maria)->first();
        $this->as($maria)->withSession(['new_token' => ['name' => 'Laptop CLI', 'token' => 'jwt.example.x']])->get('/account/tokens')
            ->assertSee('Copy “Laptop CLI” now', false)->assertSee('jwt.example.x')->assertSee('Revoke');
        $this->as($maria)->delete('/account/tokens/'.$tok->id)->assertRedirect();
        $this->as($maria)->get('/account/tokens')->assertSee('Revoked');
    }
}
