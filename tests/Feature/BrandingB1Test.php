<?php

namespace Tests\Feature;

use App\Mail\InviteMail;
use App\Models\AppDomain;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\Domains;
use App\Services\Invites;
use App\Services\TwoFactor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** SPEC-branding B1: levels, the owner's switch, branded sign-in pages and emails, API. */
class BrandingB1Test extends ClauthTestCase
{
    private User $owner;

    private User $orgAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser('william@thecakelabs.com', ['name' => 'William', 'clauth_role' => 'owner']);
        $this->orgAdmin = $this->makeUser('grace@cakesbygrace.ph', ['name' => 'Grace']);
        $this->orgAdmin->organizations()->attach($this->cbg->id, ['role' => 'owner', 'joined_at' => now()]);
        foreach ([$this->owner, $this->orgAdmin] as $u) {
            $u->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        }
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withSession(['auth.mfa' => true]);
    }

    private function startFlow(string $base = ''): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->get($base.'/oauth/authorize?'.http_build_query(['client_id' => $this->client->id, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code', 'scope' => 'openid',
            'state' => 's', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256']));
    }

    private function domain(): AppDomain
    {
        $d = AppDomain::create(['host' => 'accounts.cakesbygrace.test', 'organization_id' => $this->cbg->id, 'trusted' => true,
            'verify_token' => Str::lower(Str::random(32)), 'status' => 'active', 'active_at' => now()]);
        $d->apps()->attach($this->xp->id);
        Domains::flush($d->host);

        return $d;
    }

    public function test_default_is_clauth_and_org_cannot_go_past_what_is_allowed(): void
    {
        $this->startFlow();
        $this->get('/login')->assertOk()->assertSee('Powered by')->assertDontSee('CakesbyGrace Account');

        $this->as($this->orgAdmin)->put("/admin/orgs/{$this->cbg->id}/branding", ['branding_mode' => 'cobrand'])->assertSessionHasErrors('branding_mode');
        $this->assertSame('clauth', $this->cbg->fresh()->branding_mode);

        // Clauth owner allows co-branding; the organization picks it.
        $this->as($this->owner)->put("/admin/orgs/{$this->cbg->id}", ['name' => 'CakesbyGrace', 'status' => 'active', 'is_platform' => 1, 'branding_allowed' => 'cobrand'])->assertSessionHasNoErrors();
        $this->as($this->orgAdmin)->put("/admin/orgs/{$this->cbg->id}/branding", ['branding_mode' => 'whitelabel'])->assertSessionHasErrors('branding_mode');
        $this->as($this->orgAdmin)->put("/admin/orgs/{$this->cbg->id}/branding", ['branding_mode' => 'cobrand', 'brand_color' => '#C2185B', 'support_email' => 'help@cakesbygrace.ph'])->assertSessionHasNoErrors();
        $this->as($this->orgAdmin)->get("/admin/orgs/{$this->cbg->id}/settings")->assertOk()->assertSee('Right now people see: <b>CakesbyGrace Account</b>', false);

        $this->startFlow();
        $this->get('/login')->assertOk()->assertSee('with your CakesbyGrace Account')->assertSee('Secured by Clauth')->assertDontSee('Powered by');

        // Lowering what is allowed lowers the choice.
        $this->as($this->owner)->put("/admin/orgs/{$this->cbg->id}", ['name' => 'CakesbyGrace', 'status' => 'active', 'is_platform' => 1, 'branding_allowed' => 'clauth']);
        $this->assertSame('clauth', $this->cbg->fresh()->branding_mode);
    }

    public function test_white_label_only_on_own_domain(): void
    {
        $this->cbg->update(['branding_allowed' => 'whitelabel', 'branding_mode' => 'whitelabel', 'account_name' => 'CbG Account', 'privacy_url' => 'https://cakesbygrace.ph/privacy']);

        // Main address: falls back to co-branded.
        $this->startFlow();
        $this->get('/login')->assertOk()->assertSee('CbG Account')->assertSee('Secured by Clauth');

        // Own sign-in domain: no Clauth anywhere on the page.
        $this->domain();
        $this->startFlow('https://accounts.cakesbygrace.test');
        $html = $this->withSession(['sso.checked' => true])->get('https://accounts.cakesbygrace.test/login')->assertOk()->getContent();
        $this->assertStringContainsString('CbG Account', $html);
        $this->assertStringContainsString('https://cakesbygrace.ph/privacy', $html);
        $this->assertStringNotContainsString('Clauth', $html);
        $this->assertStringContainsString('Report this page', $html);
    }

    public function test_logo_is_public_only_while_branded(): void
    {
        Storage::fake('local');
        $this->as($this->orgAdmin)->post("/admin/orgs/{$this->cbg->id}/logo", ['logo' => UploadedFile::fake()->image('l.png', 64, 64)])->assertSessionHasNoErrors();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->get('/brand/cakesbygrace/logo')->assertNotFound();
        $this->cbg->update(['branding_allowed' => 'cobrand', 'branding_mode' => 'cobrand']);
        $this->get('/brand/cakesbygrace/logo')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->startFlow();
        $this->get('/login')->assertSee('/brand/cakesbygrace/logo', false);
    }

    public function test_invite_email_white_label_and_sender_name(): void
    {
        Mail::fake();
        $this->cbg->update(['branding_allowed' => 'whitelabel', 'branding_mode' => 'whitelabel', 'support_email' => 'help@cakesbygrace.ph']);
        $this->domain();
        [$invite, $token] = Invites::create(['email' => 'new@cakesbygrace.ph', 'name' => 'New', 'organization_id' => $this->cbg->id,
            'grants' => [['app_id' => $this->xp->id, 'role_id' => $this->role('member')->id]]], $this->orgAdmin);
        Invites::send($invite, $token);
        Mail::assertSent(InviteMail::class, function (InviteMail $m) {
            $html = \App\Services\Brand::forMail($this->cbg->fresh(), fn () => $m->render());

            return collect($m->from)->contains(fn ($f) => $f['name'] === 'CakesbyGrace Account') && ! str_contains($html, 'Clauth') && str_contains($html, 'help@cakesbygrace.ph');
        });
    }

    public function test_api_branding(): void
    {
        $c = new OAuthClient;
        $c->forceFill(['type' => 'api', 'name' => 'script', 'secret' => 'api-secret-api-secret', 'organization_id' => $this->cbg->id,
            'created_by' => $this->orgAdmin->id, 'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'scopes' => ['orgs:read', 'orgs:write'], 'revoked' => false])->save();
        $this->cbg->update(['is_platform' => false]);
        $t = $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $c->id, 'client_secret' => 'api-secret-api-secret', 'scope' => 'orgs:read orgs:write'])->json('access_token');
        $api = fn () => $this->flushHeaders()->withHeaders(['Authorization' => 'Bearer '.$t, 'Accept' => 'application/json']);

        $api()->getJson('/api/v1/orgs/cakesbygrace/branding')->assertOk()->assertJsonPath('allowed', 'clauth')->assertJsonPath('effective_on_own_domain', 'clauth');
        $api()->patchJson('/api/v1/orgs/cakesbygrace/branding', ['allowed' => 'whitelabel'])->assertStatus(403);
        $api()->patchJson('/api/v1/orgs/cakesbygrace/branding', ['mode' => 'cobrand'])->assertStatus(422);
        $this->cbg->update(['branding_allowed' => 'whitelabel']);
        $api()->patchJson('/api/v1/orgs/cakesbygrace/branding', ['mode' => 'whitelabel', 'account_name' => 'CbG Account', 'color' => '#C2185B'])->assertOk()
            ->assertJsonPath('effective_on_own_domain', 'whitelabel')->assertJsonPath('effective_on_main_address', 'cobrand')->assertJsonPath('account_name', 'CbG Account');
    }
}
