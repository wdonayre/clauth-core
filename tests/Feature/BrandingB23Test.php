<?php

namespace Tests\Feature;

use App\Models\AppDomain;
use App\Models\Application;
use App\Models\AppRole;
use App\Services\Domains;
use Illuminate\Support\Str;

/** SPEC-branding B2 (account portal on the organization's domain) and B3 (brand for apps, the bar). */
class BrandingB23Test extends ClauthTestCase
{
    private const D = 'https://accounts.cakesbygrace.test';

    private function domain(): AppDomain
    {
        $d = AppDomain::create(['host' => 'accounts.cakesbygrace.test', 'organization_id' => $this->cbg->id, 'trusted' => true,
            'verify_token' => Str::lower(Str::random(32)), 'status' => 'active', 'active_at' => now()]);
        $d->apps()->attach($this->xp->id);
        Domains::flush($d->host);

        return $d;
    }

    public function test_portal_only_when_branded_and_shows_only_that_organizations_apps(): void
    {
        $this->domain();
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        $erp = Application::create(['key' => 'erp', 'name' => 'Alab ERP', 'status' => 'live', 'home_url' => 'https://erp.test', 'owner_organization_id' => $this->alab->id]);
        $erp->organizations()->sync([$this->alab->id]);
        \App\Models\Grant::create(['user_id' => $maria->id, 'app_id' => $erp->id, 'organization_id' => $this->alab->id,
            'app_role_id' => AppRole::create(['app_id' => $erp->id, 'key' => 'member', 'name' => 'Member'])->id]);

        // Not branded: My account stays on the main address.
        $this->actingAs($maria)->get(self::D.'/account')->assertRedirect();
        $this->get(self::D.'/')->assertRedirect('/login');

        $this->cbg->update(['branding_allowed' => 'whitelabel', 'branding_mode' => 'whitelabel', 'account_name' => 'CbG Account', 'support_email' => 'help@cakesbygrace.ph']);
        $this->get(self::D.'/')->assertRedirect('/account');
        $html = $this->actingAs($maria)->get(self::D.'/account')->assertOk()
            ->assertSee('CbG Account')->assertSee('Xpenses')->assertDontSee('Alab ERP')->assertDontSee('API tokens')->getContent();
        $this->assertStringNotContainsString('Clauth', $html);
        // API tokens and linking stay on the main address.
        $this->actingAs($maria)->get(self::D.'/account/tokens')->assertRedirect();
        // Main address: the full Clauth account page, every app.
        $this->actingAs($maria)->get(rtrim(config('app.url'), '/').'/account')->assertOk()->assertSee('Alab ERP')->assertSee('API tokens');
        // Saving the profile on the portal stays on the portal.
        $this->actingAs($maria)->from(self::D.'/account')->post(self::D.'/account/profile', ['name' => 'Maria C.'])->assertRedirect(self::D.'/account');
    }

    public function test_brand_endpoint_for_apps(): void
    {
        $this->getJson('/api/v1/brand?app=nope')->assertNotFound();
        $this->getJson('/api/v1/brand?app=xpenses')->assertOk()->assertJsonPath('level', 'clauth')->assertJsonPath('button', 'Sign in with Clauth')->assertHeader('Access-Control-Allow-Origin', '*');

        $this->cbg->update(['branding_allowed' => 'whitelabel', 'branding_mode' => 'whitelabel']);
        // No own domain yet: co-branded on the main address.
        $this->getJson('/api/v1/brand?client_id='.$this->client->id)->assertOk()->assertJsonPath('level', 'cobrand')
            ->assertJsonPath('button', 'Continue with CakesbyGrace Account')->assertJsonPath('login_url', null);
        $this->domain();
        $this->getJson('/api/v1/brand?app=xpenses')->assertJsonPath('level', 'whitelabel')->assertJsonPath('login_url', self::D)
            ->assertJsonPath('account_url', self::D.'/account')->assertJsonPath('secured_by_clauth', false);
    }

    public function test_bar_carries_the_brand(): void
    {
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'member');
        [$code, , $verifier] = $this->authorizeCode($user);
        $tok = $this->exchange($code, $verifier)->json();
        $this->withToken($tok['access_token'])->getJson('/api/v1/bar')->assertOk()->assertJsonPath('brand.level', 'clauth');

        $this->cbg->update(['branding_allowed' => 'cobrand', 'branding_mode' => 'cobrand', 'account_name' => 'CbG Account']);
        $this->withToken($tok['access_token'])->getJson('/api/v1/bar')->assertOk()->assertJsonPath('brand.level', 'cobrand')->assertJsonPath('brand.account', 'CbG Account');
    }
}
