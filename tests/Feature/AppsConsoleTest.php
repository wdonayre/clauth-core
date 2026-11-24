<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use App\Services\TwoFactor;

/** Console redesign 2026-11: Apps list, connect-an-app, the app page tabs (old tab names too), new role, pause, themes. */
class AppsConsoleTest extends ClauthTestCase
{
    private function owner(): User
    {
        if ($u = User::where('email', 'william@thecakelabs.com')->first()) {
            return $u;
        }
        $u = $this->makeUser('william@thecakelabs.com', ['name' => 'William', 'clauth_role' => 'owner']);
        $u->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();

        return $u;
    }

    private function as()
    {
        return $this->actingAs($this->owner())->withSession(['auth.mfa' => true]);
    }

    public function test_apps_list_shows_cards_and_search(): void
    {
        Application::create(['key' => 'alab-studio', 'name' => 'Alab Studio', 'status' => 'not_connected', 'owner_organization_id' => $this->alab->id]);
        $this->as()->get('/admin/apps')->assertOk()->assertSee('Xpenses')->assertSee('Alab Studio')
            ->assertSee('Connect an app')->assertSee('Signed in today')->assertSee('Not connected');
        $this->as()->get('/admin/apps?q=studio')->assertOk()->assertSee('Alab Studio')->assertDontSee('xpenses.test');
        $this->as()->get('/admin/apps?status=live')->assertOk()->assertDontSee('>Alab Studio<', false);
        $this->as()->get('/admin/apps?new=1')->assertOk()->assertSee('data-open-on-load', false);
    }

    public function test_connect_an_app_shows_the_secret_once_on_the_new_app(): void
    {
        $r = $this->as()->post('/admin/apps', ['_form' => 'connect', 'name' => 'Rota', 'description' => 'Shift planning', 'key' => 'rota', 'type' => 'web',
            'redirect_uri' => 'https://rota.test/cb', 'publisher' => $this->cbg->id, 'color' => '#E4572E']);
        $r->assertSessionHasNoErrors()->assertRedirect(route('admin.apps.show', ['rota', 'tab' => 'developer']));
        $app = Application::where('key', 'rota')->firstOrFail();
        $this->assertSame('Shift planning', $app->description);
        $secret = session('created_client')['secret'];
        $this->as()->withSession(['created_client' => session('created_client'), 'connected' => true])->get('/admin/apps/rota?tab=developer')
            ->assertOk()->assertSee('Step 3 of 3')->assertSee('CLAUTH_CLIENT_SECRET='.$secret, false)->assertSee('Shift planning');
        // Afterwards the secret is gone.
        $this->as()->get('/admin/apps/rota?tab=developer')->assertOk()->assertDontSee($secret);

        // A failed connect re-opens the pop-up.
        $this->as()->from('/admin/apps')->post('/admin/apps', ['_form' => 'connect', 'name' => 'Rota', 'key' => 'rota', 'type' => 'web', 'redirect_uri' => 'https://rota.test/cb', 'publisher' => $this->cbg->id])
            ->assertSessionHasErrors('key');
    }

    public function test_every_tab_renders_with_new_and_old_names(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'finance');
        $as = $this->as();
        $as->get('/admin/apps/xpenses')->assertOk()->assertSee('What each role can do')->assertSee('expense.approve')->assertSee('aria-current="page"', false);
        $as->get('/admin/apps/xpenses?tab=people')->assertOk()->assertSee('maria@cakesbygrace.ph')->assertSee('Set by hand');
        $as->get('/admin/apps/xpenses?tab=users')->assertOk()->assertSee('maria@cakesbygrace.ph');
        $as->get('/admin/apps/xpenses?tab=people&org='.$this->alab->id)->assertOk()->assertDontSee('maria@cakesbygrace.ph');
        $as->get('/admin/apps/xpenses?tab=people&q=nobody')->assertOk()->assertSee('Nobody matches');
        foreach (['brand', 'signin'] as $t) {
            $as->get('/admin/apps/xpenses?tab='.$t)->assertOk()->assertSee('data-brand-form', false)->assertSee('data-preview', false)
                ->assertSee('data-swatch', false)->assertSee(route('admin.apps.policy', 'xpenses'));
        }
        $as->get('/admin/apps/xpenses?tab=settings')->assertOk()->assertSee('Danger zone')->assertSee('Who can use it')->assertSee('Suspend');
        foreach (['integration', 'developer'] as $t) {
            $as->get('/admin/apps/xpenses?tab='.$t)->assertOk()->assertSee($this->client->id)->assertSee('/.well-known/openid-configuration')->assertSee('Registration tokens');
        }
        $as->get('/admin/apps/xpenses?tab=nonsense')->assertOk()->assertSee('What each role can do');
    }

    public function test_new_role_with_actions_copy_and_two_step(): void
    {
        $this->as()->post('/admin/apps/xpenses/roles', ['_form' => 'role', 'name' => 'Auditor', 'key' => 'auditor', 'tier' => 'viewer',
            'permissions' => [$this->xp->permissions()->where('key', 'report.export')->value('id')], 'mfa' => 1])->assertSessionHasNoErrors();
        $auditor = $this->role('auditor');
        $this->assertSame(['report.export'], $auditor->permissionKeys());
        $this->assertContains('auditor', $this->xp->fresh()->require_mfa_roles);

        $this->as()->post('/admin/apps/xpenses/roles', ['name' => 'Finance 2', 'key' => 'finance2', 'copy_from' => $this->role('finance')->id])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing($this->role('finance')->permissionKeys(), $this->role('finance2')->permissionKeys());
    }

    public function test_pause_and_resume_and_description(): void
    {
        $base = ['name' => 'Xpenses', 'description' => 'Expense tracking', 'status' => 'live', 'color' => '#0B0D12', 'orgs' => [$this->cbg->id, $this->alab->id]];
        $this->as()->put('/admin/apps/xpenses', $base + ['pause' => 1])->assertSessionHasNoErrors();
        $this->assertSame('paused', $this->xp->fresh()->status);
        $this->assertSame('Expense tracking', $this->xp->fresh()->description);
        $this->as()->get('/admin/apps/xpenses?tab=settings')->assertOk()->assertSee('Resume sign-in');
        $this->as()->put('/admin/apps/xpenses', ['status' => 'paused', 'resume' => 1] + $base)->assertSessionHasNoErrors();
        $this->assertSame('live', $this->xp->fresh()->status);
    }

    public function test_themes_page_renders(): void
    {
        $this->as()->get('/admin/orgs/'.$this->cbg->id.'/themes')->assertOk()->assertSee('New theme')->assertSee('data-theme-preview', false);
        $this->as()->post('/admin/orgs/'.$this->cbg->id.'/themes', ['name' => 'Pink', 'base' => 'daylight', 'accent' => '#EC4899'])->assertSessionHasNoErrors();
        $id = \App\Models\SigninTheme::where('name', 'Pink')->value('id');
        $this->as()->get('/admin/orgs/'.$this->cbg->id.'/themes?edit='.$id)->assertOk()->assertSee('Edit Pink')->assertSee('Delete theme');
        $this->as()->get('/admin/apps/xpenses?tab=signin')->assertOk()->assertSee('Pink');
    }
}
