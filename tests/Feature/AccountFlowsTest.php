<?php

namespace Tests\Feature;

use App\Mail\InviteMail;
use App\Models\Application;
use App\Models\AppPermission;
use App\Models\AppRole;
use App\Models\AuditEvent;
use App\Models\Grant;
use App\Models\User;
use App\Services\Theme;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\Mail;

class AccountFlowsTest extends ClauthTestCase
{
    private function signInWithCode(User $user): void
    {
        $this->keep($this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]))->assertRedirect('/login/2fa');
        $code = TwoFactor::engine()->getCurrentOtp($user->fresh()->two_factor_secret);
        $this->keep($this->post('/login/2fa', ['code' => $code]))->assertRedirect();
    }

    private function owner(): User
    {
        $u = $this->makeUser('owner@clauth.test', ['name' => 'William Donayre', 'clauth_role' => 'owner']);
        $u->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => TwoFactor::recoveryCodes()])->save();

        return $u;
    }

    public function test_invite_accept_three_steps_then_sign_in_with_2fa(): void
    {
        Mail::fake();
        $admin = $this->owner();
        $this->actingAs($admin)->withSession(['auth.mfa' => true])
            ->post('/admin/users/invite', ['name' => 'Ana Reyes', 'email' => 'ana@cakesbygrace.ph', 'organization_id' => $this->cbg->id,
                'roles' => [$this->xp->id => $this->role('finance')->id], 'require_mfa' => 1, 'send' => 1])
            ->assertRedirect();
        Mail::assertSent(InviteMail::class);
        $link = session('invite_link')['link'];
        auth()->logout();
        $this->flushSession();

        $path = parse_url($link, PHP_URL_PATH);
        $this->keep($this->get($path))->assertOk()->assertSee('Create your account')->assertSee('Finance');
        $this->keep($this->post($path, ['name' => 'Ana Reyes', 'password' => 'a long enough password', 'password_confirmation' => 'a long enough password', 'terms' => 1]))
            ->assertRedirect('/login/2fa/setup');
        $this->keep($this->get('/login/2fa/setup'))->assertOk()->assertSee('Step 2 of 3');
        $secret = session('login.mfa_secret');
        $this->keep($this->post('/login/2fa/setup', ['code' => TwoFactor::engine()->getCurrentOtp($secret)]))->assertRedirect('/login/2fa/codes');
        $this->keep($this->get('/login/2fa/codes'))->assertOk()->assertSee('Step 3 of 3');
        $this->keep($this->post('/login/2fa/codes', ['saved' => 1]))->assertRedirect('https://xpenses.test');

        $ana = User::where('email', 'ana@cakesbygrace.ph')->first();
        $this->assertSame('active', $ana->status);
        $this->assertTrue($ana->hasTwoFactor());
        $this->assertTrue(Grant::where('user_id', $ana->id)->where('organization_id', $this->cbg->id)->exists());

        // Link is single-use.
        $this->get($path)->assertStatus(410);
    }

    public function test_onboarding_offers_2fa_and_can_skip_unless_required(): void
    {
        Mail::fake();
        $admin = $this->owner();
        $invite = function (string $email, bool $require) use ($admin) {
            $this->actingAs($admin)->withSession(['auth.mfa' => true])->post('/admin/users/invite', ['name' => 'New Person', 'email' => $email,
                'organization_id' => $this->cbg->id, 'roles' => [$this->xp->id => $this->role('member')->id], 'require_mfa' => $require ? 1 : 0, 'send' => 1]);
            $link = session('invite_link')['link'];
            auth()->logout();
            $this->flushSession();
            $this->defaultCookies = [];

            return parse_url($link, PHP_URL_PATH);
        };
        $accept = fn ($path) => $this->keep($this->post($path, ['name' => 'New Person', 'password' => 'a long enough password', 'password_confirmation' => 'a long enough password', 'terms' => 1]));

        // Not required: setup is offered with a Skip button, and skipping signs them in without 2-step.
        $path = $invite('optional@cakesbygrace.ph', false);
        $this->keep($this->get($path))->assertOk();
        $accept($path)->assertRedirect('/login/2fa/setup');
        $this->keep($this->get('/login/2fa/setup'))->assertOk()->assertSee('Skip for now');
        $this->keep($this->post('/login/2fa/skip'))->assertRedirect('https://xpenses.test');
        $this->assertFalse(User::where('email', 'optional@cakesbygrace.ph')->first()->hasTwoFactor());

        // Required by the invite: no Skip button, and the skip endpoint refuses.
        auth()->logout();
        $this->flushSession();
        $this->defaultCookies = [];
        $path = $invite('required@cakesbygrace.ph', true);
        $this->keep($this->get($path))->assertOk();
        $accept($path)->assertRedirect('/login/2fa/setup');
        $this->keep($this->get('/login/2fa/setup'))->assertOk()->assertDontSee('Skip for now');
        $this->keep($this->post('/login/2fa/skip'))->assertRedirect('/login/2fa/setup');
        $this->assertGuest();
    }

    public function test_admin_can_turn_off_2fa_for_one_user_even_when_invite_and_app_require_it(): void
    {
        Mail::fake();
        $admin = $this->owner();
        $this->xp->update(['require_mfa_roles' => ['finance']]);
        $as = fn () => $this->actingAs($admin)->withSession(['auth.mfa' => true]);
        $as()->post('/admin/users/invite', ['name' => 'Ana Reyes', 'email' => 'ana@cakesbygrace.ph', 'organization_id' => $this->cbg->id,
            'roles' => [$this->xp->id => $this->role('finance')->id], 'require_mfa' => 1, 'send' => 1])->assertRedirect();
        $link = session('invite_link')['link'];
        $ana = User::where('email', 'ana@cakesbygrace.ph')->first();

        // Admins can't be exempted; the admin can't exempt themselves either.
        $as()->post("/admin/users/{$admin->id}/mfa-off")->assertStatus(422);
        $as()->get("/admin/users/{$ana->id}")->assertOk()->assertSee('Turn off 2-step');
        $as()->post("/admin/users/{$ana->id}/mfa-off")->assertRedirect()->assertSessionHas('status');
        $this->assertTrue($ana->fresh()->mfa_exempt);
        $as()->get("/admin/users/{$ana->id}")->assertSee('Require 2-step again');
        auth()->logout();
        $this->flushSession();

        // Invite asked for 2-step, Finance role requires it — the override wins: no setup step.
        $path = parse_url($link, PHP_URL_PATH);
        $this->keep($this->get($path))->assertOk();
        $this->keep($this->post($path, ['name' => 'Ana Reyes', 'password' => 'a long enough password', 'password_confirmation' => 'a long enough password', 'terms' => 1]))
            ->assertRedirect('https://xpenses.test');
        $this->assertFalse($ana->fresh()->hasTwoFactor());

        // Turning the override off brings the normal rule back.
        auth()->logout();
        $this->flushSession();
        $this->defaultCookies = [];
        $as()->post("/admin/users/{$ana->id}/mfa-on")->assertRedirect()->assertSessionHas('status');
        $this->assertFalse($ana->fresh()->mfa_exempt);
        $this->assertTrue(\App\Services\Claims::policyRequiresMfa($ana->fresh(), $this->xp));
    }

    public function test_turning_off_2fa_removes_an_existing_setup(): void
    {
        $admin = $this->owner();
        $user = $this->makeUser();
        $user->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['aaaaa-bbbbb']])->save();
        $this->actingAs($admin)->withSession(['auth.mfa' => true])->post("/admin/users/{$user->id}/mfa-off")->assertRedirect();
        auth()->logout();
        $this->flushSession();
        $this->assertFalse($user->fresh()->hasTwoFactor());
        $this->keep($this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]))->assertRedirect('/account');
    }

    public function test_lost_phone_help_and_break_glass_reset_command(): void
    {
        $user = $this->makeUser(attrs: ['clauth_role' => 'owner']);
        $user->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['aaaaa-bbbbb']])->save();
        $this->keep($this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]))->assertRedirect('/login/2fa');
        $this->keep($this->get('/login/2fa'))->assertOk()->assertSee('Lost your phone?')->assertSee('reset your 2-step verification');
        $this->flushSession();
        $this->defaultCookies = [];

        $this->artisan('clauth:reset-2fa', ['email' => 'nobody@example.com'])->assertFailed();
        $this->artisan('clauth:reset-2fa', ['email' => $user->email])->expectsConfirmation('Reset their 2-step verification and sign them out everywhere?', 'no')->assertFailed();
        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->artisan('clauth:reset-2fa', ['email' => strtoupper($user->email), '--force' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->hasTwoFactor());
        $this->assertDatabaseHas('audit_events', ['event' => 'security.mfa_reset']);

        // Owner must set up 2-step again on the new phone.
        $this->keep($this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]))->assertRedirect('/login/2fa/setup');
    }

    public function test_login_requires_code_when_2fa_enabled(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['aaaaa-bbbbb']])->save();
        $this->keep($this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]))->assertRedirect('/login/2fa');
        $this->keep($this->post('/login/2fa', ['code' => '000000']))->assertSessionHasErrors('code');
        $this->keep($this->post('/login/2fa', ['recovery_code' => 'aaaaa-bbbbb']))->assertRedirect('/account');
        $this->assertSame([], $user->fresh()->two_factor_recovery_codes);
        $this->get('/account')->assertOk()->assertSee('Maria Santos');
    }

    public function test_lockout_after_failed_passwords(): void
    {
        $user = $this->makeUser();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'.$i]);
        }
        $this->assertTrue($user->fresh()->isLocked());
        $this->assertTrue(AuditEvent::where('event', 'signin.locked')->exists());
    }

    public function test_org_admin_cannot_see_other_org_users(): void
    {
        $orgAdmin = $this->makeUser('boss@cakesbygrace.ph', ['name' => 'Grace']);
        $orgAdmin->organizations()->attach($this->cbg->id, ['role' => 'admin']);
        $orgAdmin->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $alabUser = $this->makeUser('jun@alabapparel.com', ['name' => 'Jun']);
        $this->grant($alabUser, $this->alab, 'member');
        $cbgUser = $this->makeUser('maria@cakesbygrace.ph');
        $this->grant($cbgUser, $this->cbg, 'member');

        $as = $this->actingAs($orgAdmin)->withSession(['auth.mfa' => true]);
        $as->get('/admin/users/'.$alabUser->id)->assertNotFound();
        $as->get('/admin/users/'.$cbgUser->id)->assertOk();
        $as->get('/admin/users')->assertOk()->assertSee('Maria Santos')->assertDontSee('jun@alabapparel.com');
        // M7: org admins see the apps their organization publishes, nothing else.
        $alabApp = Application::create(['key' => 'alab-studio', 'name' => 'Alab Studio', 'status' => 'live', 'owner_organization_id' => $this->alab->id]);
        $as->get('/admin/apps')->assertOk()->assertSee('Xpenses')->assertDontSee('Alab Studio');
        $as->get('/admin/apps/alab-studio')->assertNotFound();
        $as->post('/admin/users/'.$alabUser->id.'/grants', ['organization_id' => $this->alab->id])->assertNotFound();
    }

    public function test_admin_requires_2fa_session(): void
    {
        $admin = $this->owner();
        $this->actingAs($admin)->get('/admin')->assertRedirect('/login/2fa');
    }

    public function test_every_admin_screen_renders(): void
    {
        $admin = $this->owner();
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'finance');
        $as = $this->actingAs($admin)->withSession(['auth.mfa' => true]);
        $as->get('/admin')->assertOk()->assertSee('Overview');
        $as->get('/admin/users')->assertOk();
        $as->get('/admin/users/'.$user->id)->assertOk()->assertSee('Token preview');
        $as->get('/admin/apps')->assertOk()->assertSee('Xpenses');
        foreach (['roles', 'brand', 'settings', 'users', 'integration'] as $tab) {
            $as->get('/admin/apps/xpenses?tab='.$tab)->assertOk();
        }
        $as->get('/admin/apps/xpenses/preview?mode=signin&theme=daylight&accent=%23FBBF24')->assertOk()->assertSee('Continue to');
        $as->get('/admin/apps/xpenses/preview?mode=register&theme=midnight')->assertOk()->assertSee('Create your account');
        $as->get('/admin/orgs')->assertOk()->assertSee('CakesbyGrace');
        $as->get('/admin/audit')->assertOk();
        $as->get('/admin/audit.csv')->assertOk();
        $as->get('/admin/settings')->assertOk()->assertSee('Signing keys');
        $as->get('/account')->assertOk()->assertSee('Your apps');
    }

    public function test_matrix_edit_marks_role_edited_and_manifest_sync_keeps_it(): void
    {
        $admin = $this->owner();
        $finance = $this->role('finance');
        $submit = AppPermission::where('key', 'expense.submit')->first();
        $as = $this->actingAs($admin)->withSession(['auth.mfa' => true]);
        $as->post('/admin/apps/xpenses/matrix', ['matrix' => [$finance->id => [$submit->id]]])->assertRedirect();
        $this->assertTrue($finance->fresh()->edited);

        $manifest = json_encode(['app' => 'xpenses', 'permissions' => [['key' => 'expense.submit'], ['key' => 'expense.approve'], ['key' => 'report.export']],
            'roles' => [['key' => 'finance', 'name' => 'Finance', 'permissions' => ['expense.approve', 'report.export']], ['key' => 'owner', 'name' => 'Owner', 'permissions' => ['*']]]]);
        $as->post('/admin/apps/xpenses/manifest', ['manifest' => $manifest])->assertRedirect();
        $this->assertSame(['expense.submit'], $finance->fresh()->permissionKeys()); // kept the admin's edit
        $this->assertNotNull(AppPermission::where('key', 'expense.view_all')->value('deprecated_at'));
        $this->assertCount(3, AppRole::where('key', 'owner')->first()->permissionKeys());
        $this->assertNotEmpty($this->xp->fresh()->manifest_diff);
    }

    public function test_branding_saves_and_picks_readable_button_text(): void
    {
        $admin = $this->owner();
        $this->actingAs($admin)->withSession(['auth.mfa' => true])
            ->post('/admin/apps/xpenses/branding', ['login_theme' => 'daylight', 'accent_color' => '#22D3EE'])->assertSessionHasNoErrors();
        $this->assertSame('daylight', $this->xp->fresh()->login_theme);
        $this->actingAs($admin)->withSession(['auth.mfa' => true])
            ->post('/admin/apps/xpenses/branding', ['login_theme' => 'daylight', 'accent_color' => 'yellow'])->assertSessionHasErrors('accent_color');
        $this->assertSame('#111111', Theme::for($this->xp->fresh()->forceFill(['accent_color' => '#FBBF24']))['vars']['on-accent']);
        $this->assertSame('#FFFFFF', Theme::for($this->xp->fresh()->forceFill(['accent_color' => '#4338CA']))['vars']['on-accent']);
    }

    public function test_owner_command_prints_setup_link(): void
    {
        $this->artisan('clauth:owner', ['email' => 'owner2@clauth.test', '--name' => 'William Donayre'])
            ->expectsOutputToContain('/invite/')->assertSuccessful();
        $this->assertSame('invited', User::where('email', 'owner2@clauth.test')->value('status'));
    }
}
