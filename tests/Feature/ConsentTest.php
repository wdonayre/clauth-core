<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AppRole;
use App\Models\Consent;
use App\Models\Grant;
use App\Models\OAuthClient;
use App\Models\Organization;
use App\Models\User;
use App\Services\Installations;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** M8 (SPEC-organizations §6, §13.5–6): apps outside a person's own organizations ask once before receiving details. */
class ConsentTest extends ClauthTestCase
{
    private Organization $foo;

    private Application $roster;

    private User $maria;

    private OAuthClient $xpClient;

    private string $xpSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->foo = Organization::create(['slug' => 'foo-ltd', 'name' => 'Foo Ltd']);
        $this->roster = Application::create(['key' => 'foo-roster', 'name' => 'Foo Roster', 'status' => 'live', 'owner_organization_id' => $this->foo->id,
            'visibility' => 'installable', 'privacy_url' => 'https://foo.test/privacy', 'terms_url' => 'https://foo.test/terms', 'support_email' => 'help@foo.test']);
        $role = AppRole::create(['app_id' => $this->roster->id, 'key' => 'member', 'name' => 'Member']);
        // Installed in CakesbyGrace (approved by William), Maria has access there.
        Installations::add($this->roster, $this->cbg, $this->makeUser('owner@thecakelabs.com', ['clauth_role' => 'owner']));
        $this->maria = $this->makeUser('maria@cakesbygrace.ph');
        $this->maria->organizations()->attach($this->cbg->id);
        Grant::create(['user_id' => $this->maria->id, 'app_id' => $this->roster->id, 'organization_id' => $this->cbg->id, 'app_role_id' => $role->id]);

        // From here on the test "app" is Foo Roster.
        [$this->xpClient, $this->xpSecret] = [$this->client, $this->secret];
        $this->secret = Str::random(40);
        $this->client = new OAuthClient;
        $this->client->forceFill(['app_id' => $this->roster->id, 'type' => 'web', 'name' => 'Roster web', 'secret' => $this->secret,
            'redirect_uris' => [self::REDIRECT], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false])->save();
    }

    private function consentAnswer(string $decision)
    {
        return $this->keep($this->post('/oauth/consent', ['decision' => $decision]));
    }

    public function test_outside_app_asks_once_then_signs_in_directly(): void
    {
        $r = $this->authorizeCode($this->maria);
        $this->assertFalse(is_array($r), 'expected the consent page, not a code');
        $r->assertOk()->assertSee('Continue to Foo Roster?')->assertSee('Foo Ltd')->assertSee('Unverified')
            ->assertSee('Your roles in this app at CakesbyGrace')->assertSee('https://foo.test/privacy', false);

        $r = $this->consentAnswer('allow');
        $r = $this->keep($this->get($r->headers->get('Location')));
        $this->assertStringStartsWith(self::REDIRECT.'?code=', $r->headers->get('Location'));
        $this->assertEqualsCanonicalizing(['openid', 'profile', 'email', 'clauth'], Consent::where('user_id', $this->maria->id)->value('scopes'));

        // Next time: straight back with a code; prompt=none works too.
        $this->assertNotNull($this->authorizeCode($this->maria)[0]);
        $this->assertNotNull($this->authorizeCode($this->maria, extra: ['prompt' => 'none'])[0]);
    }

    public function test_prompt_none_without_consent_and_cancel(): void
    {
        $this->authorizeCode($this->maria)->assertOk(); // signs in, consent page not answered
        [, , , $q] = $this->authorizeCode($this->maria, extra: ['prompt' => 'none']);
        $this->assertSame('consent_required', $q['error']);

        $this->authorizeCode($this->maria)->assertOk();
        $r = $this->consentAnswer('deny');
        $r = $this->keep($this->get($r->headers->get('Location')));
        $this->assertStringContainsString('error=access_denied', $r->headers->get('Location'));
        $this->assertSame(0, Consent::count());
    }

    public function test_asking_for_more_asks_again_and_prompt_consent_forces_it(): void
    {
        $this->authorizeCode($this->maria, 'openid profile')->assertOk();
        $this->keep($this->get($this->consentAnswer('allow')->headers->get('Location')));
        $this->assertNotNull($this->authorizeCode($this->maria, 'openid profile')[0]);
        $this->authorizeCode($this->maria, 'openid profile email')->assertOk()->assertSee('Your email address');
        $this->authorizeCode($this->maria, 'openid profile', ['prompt' => 'consent'])->assertOk()->assertSee('Continue to Foo Roster?');
    }

    public function test_own_organizations_and_williams_apps_never_ask(): void
    {
        // A Foo Ltd employee using Foo's own app.
        $ana = $this->makeUser('ana@foo.test', ['name' => 'Ana']);
        $ana->organizations()->attach($this->foo->id);
        $this->roster->organizations()->attach($this->foo->id);
        Grant::create(['user_id' => $ana->id, 'app_id' => $this->roster->id, 'organization_id' => $this->foo->id, 'app_role_id' => AppRole::where('app_id', $this->roster->id)->value('id')]);
        $this->assertNotNull($this->authorizeCode($ana)[0]);

        // William's app (Xpenses, platform org) even with prompt=consent.
        [$this->client, $this->secret] = [$this->xpClient, $this->xpSecret];
        $this->grant($this->maria, $this->cbg, 'member');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->defaultCookies = []; // a fresh browser
        $this->assertNotNull($this->authorizeCode($this->maria, extra: ['prompt' => 'consent'])[0]);
        $this->assertSame(0, Consent::count());
    }

    public function test_remove_access_in_my_account(): void
    {
        $this->authorizeCode($this->maria);
        $this->keep($this->get($this->consentAnswer('allow')->headers->get('Location')));
        DB::table('oauth_access_tokens')->insert(['id' => 'tok-r', 'user_id' => $this->maria->id, 'client_id' => $this->client->id, 'revoked' => false, 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/account')->assertOk()->assertSee("Apps you've allowed", false)->assertSee('Foo Roster');
        $this->delete('/account/consents/foo-roster')->assertSessionHas('status');
        $this->assertNotNull(Consent::first()->revoked_at);
        $this->assertTrue((bool) DB::table('oauth_access_tokens')->where('id', 'tok-r')->value('revoked'));
        $this->authorizeCode($this->maria)->assertOk()->assertSee('Continue to Foo Roster?'); // asked again
    }

    public function test_outside_app_user_lookup_needs_access_and_consent(): void
    {
        $m2m = new OAuthClient;
        $m2m->forceFill(['app_id' => $this->roster->id, 'type' => 'm2m', 'name' => 'svc', 'secret' => 's3cret-s3cret', 'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'revoked' => false])->save();
        $token = $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $m2m->id, 'client_secret' => 's3cret-s3cret', 'scope' => 'users:read'])->json('access_token');
        $stranger = $this->makeUser('nobody@x.test', ['name' => 'Nobody']);

        $this->withToken($token)->getJson('/api/v1/users/'.$this->maria->sub())->assertNotFound(); // no consent yet
        Consent::create(['user_id' => $this->maria->id, 'app_id' => $this->roster->id, 'scopes' => ['openid'], 'granted_at' => now()]);
        $this->withToken($token)->getJson('/api/v1/users/'.$this->maria->sub())->assertOk()->assertJsonPath('orgs.cakesbygrace.roles.0', 'member');
        $this->withToken($token)->getJson('/api/v1/users/'.$stranger->sub())->assertNotFound(); // no access to the app
    }

    public function test_reserved_names_and_policy_links_for_outside_apps(): void
    {
        $dev = $this->makeUser('dev@foo.test', ['name' => 'Dev']);
        $dev->organizations()->attach($this->foo->id, ['role' => 'developer']);
        $dev->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $as = fn () => $this->actingAs($dev)->withSession(['auth.mfa' => true]);
        $base = ['type' => 'web', 'redirect_uri' => 'https://foo.test/cb', 'publisher' => $this->foo->id,
            'privacy_url' => 'https://foo.test/privacy', 'terms_url' => 'https://foo.test/terms', 'support_email' => 'help@foo.test'];

        $as()->post('/admin/apps', $base + ['name' => 'Rota', 'key' => 'cebpos'])->assertSessionHasErrors('key');
        $as()->post('/admin/apps', $base + ['name' => 'Rota', 'key' => 'alab-rota'])->assertSessionHasErrors('key');
        $as()->post('/admin/apps', $base + ['name' => 'GCash Login', 'key' => 'rota'])->assertSessionHasErrors('name');
        $as()->post('/admin/apps', ['name' => 'Rota', 'key' => 'rota'] + array_diff_key($base, ['privacy_url' => 1]))->assertSessionHasErrors('privacy_url');
        $as()->post('/admin/apps', $base + ['name' => 'Rota', 'key' => 'rota'])->assertSessionHasNoErrors();
        $this->assertSame('https://foo.test/privacy', Application::where('key', 'rota')->value('privacy_url'));

        // Sign-in page for an outside app names its publisher.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->get('/oauth/authorize?'.http_build_query(['client_id' => $this->client->id, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code', 'scope' => 'openid',
            'state' => 's', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256']));
        $this->get('/login')->assertOk()->assertSee('Foo Ltd')->assertSee('Unverified');
    }
}
