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
        $u = $this->makeUser('owner@thecakelabs.com', ['name' => 'William Donayre', 'clauth_role' => 'owner']);
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
        $this->artisan('clauth:owner', ['email' => 'william@thecakelabs.com', '--name' => 'William Donayre'])
            ->expectsOutputToContain('/invite/')->assertSuccessful();
        $this->assertSame('invited', User::where('email', 'william@thecakelabs.com')->value('status'));
    }
}
