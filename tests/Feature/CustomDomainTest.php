<?php

namespace Tests\Feature;

use App\Models\AppDomain;
use App\Models\Application;
use App\Models\AppRole;
use App\Models\Grant;
use App\Models\OAuthClient;
use App\Models\Organization;
use App\Models\User;
use App\Services\Domains;
use App\Services\Invites;
use App\Services\TwoFactor;
use App\Support\Dns;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Custom sign-in domains (phase 1) and single sign-on across domains (phase 2). */
class CustomDomainTest extends ClauthTestCase
{
    private const MAIN = 'https://auth.test';

    private const APPD = 'https://auth.xpenses.test';

    private array $jars = [];

    private array $txt = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['clauth.issuer' => self::MAIN, 'app.url' => self::MAIN, 'session.driver' => 'database']);
        $this->app->forgetInstance('session');
        $this->app->forgetInstance('session.store');
        $test = $this;
        $this->app->instance(Dns::class, new class($test) extends Dns
        {
            public function __construct(private $t) {}

            public function txt(string $name): array
            {
                return $this->t->txtFor($name);
            }

            public function target(string $host, string $mainHost, array $serverIps): string
            {
                return 'cname';
            }
        });
    }

    public function txtFor(string $name): array
    {
        return $this->txt[$name] ?? [];
    }

    private function activeDomain(bool $trusted = true): AppDomain
    {
        $d = AppDomain::create(['host' => 'auth.xpenses.test', 'organization_id' => $this->cbg->id, 'trusted' => $trusted,
            'verify_token' => Str::lower(Str::random(32)), 'status' => 'active', 'active_at' => now()]);
        $d->apps()->attach($this->xp->id);
        Domains::flush($d->host);

        return $d;
    }

    /** A second app (like Alab ERP next to Alab Studio) with its own client. */
    private function secondApp(array $orgs): array
    {
        $app = Application::create(['key' => 'erp', 'name' => 'Alab ERP', 'status' => 'live', 'home_url' => 'https://erp.test', 'owner_organization_id' => $orgs[0]->id]);
        $app->organizations()->sync(array_map(fn ($o) => $o->id, $orgs));
        $role = AppRole::create(['app_id' => $app->id, 'key' => 'member', 'name' => 'Member']);
        $client = new OAuthClient;
        $client->forceFill(['app_id' => $app->id, 'type' => 'web', 'name' => 'ERP web', 'secret' => Str::random(40), 'redirect_uris' => ['https://erp.test/cb'],
            'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false])->save();

        return [$app, $client, $role];
    }

    private function owner(): User
    {
        $owner = $this->makeUser('owner@thecakelabs.com', ['name' => 'William', 'clauth_role' => 'owner']);
        $owner->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();

        return $owner;
    }

    /** A browser: one cookie jar per host, follows nothing automatically. */
    private function hit(string $url, string $method = 'GET', array $data = [])
    {
        $host = parse_url($url, PHP_URL_HOST);
        $this->defaultCookies = $this->jars[$host] ?? [];
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();          // a real browser: no session state shared between requests
        $this->app->forgetInstance('session.store');
        $r = $method === 'GET' ? $this->get($url) : $this->post($url, $data);
        foreach ($r->headers->getCookies() as $c) {
            if ($c->isCleared()) {
                unset($this->jars[$host][$c->getName()]);
            } elseif (($v = $r->getCookie($c->getName(), true)?->getValue()) !== null) {
                $this->jars[$host][$c->getName()] = $v;
            }
        }

        return $r;
    }

    private function follow($r, int $max = 8)
    {
        while ($max-- > 0 && $r->isRedirect()) {
            $loc = $r->headers->get('Location');
            $h = parse_url($loc, PHP_URL_HOST);
            if ($h && ! in_array($h, ['auth.test', 'auth.xpenses.test'], true)) {
                return $r; // back at an app
            }
            $r = $this->hit(str_starts_with($loc, 'http') ? $loc : self::MAIN.$loc);
        }

        return $r;
    }

    private function authorizeUrl(string $base, ?OAuthClient $client = null): string
    {
        $verifier = Str::random(64);
        $client ??= $this->client;

        return $base.'/oauth/authorize?'.http_build_query([
            'client_id' => $client->id, 'redirect_uri' => $client->redirect_uris[0], 'response_type' => 'code', 'scope' => 'openid clauth',
            'state' => 'st', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
        ]);
    }

    public function test_domain_lifecycle_txt_then_reachability_then_active(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->withSession(['auth.mfa' => true])->post(self::MAIN.'/admin/apps/xpenses/domain', ['host' => 'https://Auth.Xpenses.test/'])->assertSessionHasNoErrors()->assertRedirect();
        $d = AppDomain::firstOrFail();
        $this->assertSame('auth.xpenses.test', $d->host);
        $this->assertSame('pending', $d->status);
        $this->assertTrue($d->isTrusted(), 'owned by one of William’s own organizations');
        $this->assertSame($this->cbg->id, $d->organization_id, 'the app’s publisher owns the domain');
        $this->assertTrue($d->serves($this->xp));
        $this->actingAs($owner)->withSession(['auth.mfa' => true])->get(self::MAIN.'/admin/apps/xpenses?tab=brand')
            ->assertOk()->assertSee($d->txtName())->assertSee($d->txtValue())->assertSee('Waiting for DNS');

        // Proof is served for a not-yet-live domain; sign-in pages fall back to the main domain.
        $this->get(self::APPD.$d->proofPath())->assertOk()->assertSee($d->proofBody());
        $this->get(self::APPD.'/login')->assertRedirect(self::MAIN.'/login');
        $this->get(self::APPD.'/admin')->assertNotFound();

        $this->txt[$d->txtName()] = [$d->txtValue()];
        $this->assertSame('dns_ok', Domains::check($d)->status);
        Http::fake(['https://auth.xpenses.test/*' => Http::response($d->proofBody())]);
        $this->assertSame('reachable', Domains::check($d)->status);
        $this->assertSame('active', Domains::check($d)->status);

        // Daily check: TXT removed → problem after two failures → not served; sign-in keeps working on main.
        $this->txt = [];
        $this->assertSame('active', Domains::check($d)->status);
        $this->assertSame('problem', Domains::check($d)->status);
        $q = parse_url($this->authorizeUrl(self::APPD), PHP_URL_QUERY);
        $this->get(self::APPD.'/oauth/authorize?'.$q)->assertRedirect(self::MAIN.'/oauth/authorize?'.$q);
        $this->post(self::APPD.'/login', [])->assertNotFound();
    }

    public function test_app_domain_serves_branded_pages_only_and_links_stay_on_it(): void
    {
        $d = $this->activeDomain();
        $this->get('https://unknown.example/login')->assertNotFound();
        $this->get(self::APPD.'/admin')->assertRedirect(self::MAIN.'/admin');
        $this->get(self::APPD.'/account')->assertRedirect(self::MAIN.'/account');
        $this->getJson(self::APPD.'/api/v1/bar')->assertNotFound();
        $this->get(self::APPD.'/forgot-password')->assertOk()->assertSee('Reset your password')->assertSee('action="'.self::APPD.'/forgot-password"', false);

        [$invite, $token] = Invites::create(['name' => 'Ana', 'email' => 'ana@x.ph', 'organization_id' => $this->cbg->id,
            'grants' => [['app_id' => $this->xp->id, 'role_id' => $this->role('member')->id]]], null);
        $this->assertSame(self::APPD.'/invite/'.$token, Invites::url($token, $invite));
        $this->assertSame(self::APPD.'/invite/x', Domains::urlFor($this->xp, 'invite/x'));
    }

    public function test_signed_in_on_main_means_signed_in_on_app_domain_without_password(): void
    {
        $this->activeDomain();
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'member');
        $this->follow($this->hit(self::MAIN.'/login', 'POST', ['email' => $user->email, 'password' => self::PASSWORD]));
        $this->hit(self::MAIN.'/account')->assertOk();

        $r = $this->hit($this->authorizeUrl(self::APPD));
        $this->assertStringEndsWith('/login', $r->headers->get('Location'));
        $r = $this->hit(self::APPD.'/login');
        $this->assertStringStartsWith(self::MAIN.'/sso/check?', $r->headers->get('Location'));
        $r = $this->follow($r);
        $this->assertStringStartsWith(self::REDIRECT.'?code=', $r->headers->get('Location'), 'expected a code without a password prompt');
    }

    public function test_fresh_sign_in_on_app_domain_also_signs_in_main_and_sign_out_ends_both(): void
    {
        $this->activeDomain();
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'member');

        $this->hit($this->authorizeUrl(self::APPD));
        $r = $this->follow($this->hit(self::APPD.'/login'));             // main says "not signed in"
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Continue to <strong>Xpenses</strong>', $r->getContent());

        $r = $this->hit(self::APPD.'/login', 'POST', ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->assertStringStartsWith(self::MAIN.'/sso/adopt?ticket=', $r->headers->get('Location'));
        $r = $this->follow($r);
        $this->assertStringStartsWith(self::REDIRECT.'?code=', $r->headers->get('Location'));
        $this->hit(self::MAIN.'/account')->assertOk();                  // main is signed in too
        $this->assertSame(2, DB::table('sso_sessions')->where('user_id', $user->id)->count());

        $this->hit(self::APPD.'/oauth/logout');
        $this->hit(self::MAIN.'/account')->assertRedirect();            // ended on main as well
    }

    public function test_forged_or_replayed_tickets_do_not_sign_in(): void
    {
        $this->activeDomain();
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'member');
        $this->follow($this->hit(self::MAIN.'/login', 'POST', ['email' => $user->email, 'password' => self::PASSWORD]));
        $this->hit(self::APPD.'/login');
        $check = $this->hit(self::MAIN.'/sso/check?'.http_build_query(['host' => 'auth.xpenses.test', 'state' => str_repeat('a', 40)]));
        // The ticket was minted for state "aaa…", but this browser's app-domain session expects a different state.
        $this->follow($check);
        $this->hit(self::APPD.'/oauth/authorize?'.parse_url($this->authorizeUrl(self::APPD), PHP_URL_QUERY));
        $this->assertGuest();

        $this->get(self::MAIN.'/sso/check?host=evil.example&state='.str_repeat('a', 40))->assertStatus(400);
        $this->get(self::MAIN.'/sso/adopt?ticket=nope')->assertRedirect();
    }

    public function test_a_verified_domain_is_shared_with_another_app_of_the_same_organization(): void
    {
        $d = $this->activeDomain();
        [$erp, $erpClient, $erpRole] = $this->secondApp([$this->cbg]);
        $owner = $this->owner();
        $admin = fn () => $this->actingAs($owner)->withSession(['auth.mfa' => true]);

        // Entering the host asks for confirmation first, with no new DNS records.
        $admin()->post(self::MAIN.'/admin/apps/erp/domain', ['host' => 'auth.xpenses.test'])->assertSessionHas('domain_share');
        $this->assertFalse($d->fresh()->serves($erp));
        $admin()->post(self::MAIN.'/admin/apps/erp/domain', ['host' => 'auth.xpenses.test', 'share' => 1])->assertSessionHasNoErrors();
        $d = $d->fresh();
        $this->assertTrue($d->serves($erp) && $d->serves($this->xp));
        $this->assertSame('active', $d->status);
        $admin()->get(self::MAIN.'/admin/apps/erp?tab=brand')->assertOk()->assertSee('Owned by CakesbyGrace')->assertSee('used by')->assertSee('(shared)')->assertSee('Stop using for Alab ERP');
        $this->assertSame(self::APPD.'/x', Domains::urlFor($erp, 'x'));

        // Each app's flow is branded for that app; a page opened directly shows the domain's name.
        $admin()->patch(self::MAIN.'/admin/apps/erp/domain', ['display_name' => 'Cake Group', 'trusted' => 1])->assertSessionHasNoErrors();
        $this->hit($this->authorizeUrl(self::APPD, $erpClient));
        $this->assertStringContainsString('Continue to <strong>Alab ERP</strong>', $this->follow($this->hit(self::APPD.'/login'))->getContent());
        $this->jars = [];
        $this->assertStringContainsString('Continue to <strong>Cake Group</strong>', $this->follow($this->hit(self::APPD.'/login'))->getContent());

        // Signed in once on the shared domain → both apps get a code without a password.
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'member');
        Grant::create(['user_id' => $user->id, 'app_id' => $erp->id, 'organization_id' => $this->cbg->id, 'app_role_id' => $erpRole->id]);
        $this->hit($this->authorizeUrl(self::APPD));
        $this->follow($this->hit(self::APPD.'/login', 'POST', ['email' => $user->email, 'password' => self::PASSWORD]));
        $this->assertStringStartsWith('https://erp.test/cb?code=', $this->follow($this->hit($this->authorizeUrl(self::APPD, $erpClient)))->headers->get('Location'));

        // Removing it from one app keeps it for the other; the last one out deletes it.
        $this->defaultCookies = [];
        $admin()->delete(self::MAIN.'/admin/apps/erp/domain')->assertSessionHasNoErrors()->assertRedirect();
        $this->assertFalse($d->fresh()->serves($erp));
        $this->assertTrue($d->fresh()->serves($this->xp));
        $admin()->delete(self::MAIN.'/admin/apps/xpenses/domain');
        $this->assertNull(AppDomain::find($d->id));
    }

    public function test_an_app_not_on_the_domain_signs_in_at_its_own_address(): void
    {
        $this->activeDomain();
        [, $erpClient] = $this->secondApp([$this->cbg]);
        $url = $this->authorizeUrl(self::APPD, $erpClient);
        $this->get($url)->assertRedirect(self::MAIN.'/oauth/authorize?'.parse_url($url, PHP_URL_QUERY));
    }

    public function test_another_organizations_domain_cannot_be_used_unless_the_claim_is_stale(): void
    {
        $d = $this->activeDomain();
        $other = Organization::create(['slug' => 'other', 'name' => 'Other Co']);
        [$erp] = $this->secondApp([$other]);
        $owner = $this->owner();
        $admin = fn () => $this->actingAs($owner)->withSession(['auth.mfa' => true]);

        $admin()->post(self::MAIN.'/admin/apps/erp/domain', ['host' => 'auth.xpenses.test', 'share' => 1])->assertSessionHasErrors('host');
        $this->assertFalse($d->fresh()->serves($erp));

        // A claim that never verified for a day can be replaced; the new owner verifies with its own token.
        $d->forceFill(['status' => 'pending', 'created_at' => now()->subDays(2)])->save();
        $admin()->post(self::MAIN.'/admin/apps/erp/domain', ['host' => 'auth.xpenses.test'])->assertSessionHasNoErrors();
        $new = AppDomain::where('host', 'auth.xpenses.test')->firstOrFail();
        $this->assertNotSame($d->id, $new->id);
        $this->assertSame($other->id, $new->organization_id);
        $this->assertNotSame($d->verify_token, $new->verify_token);
        $this->assertTrue($new->serves($erp) && ! $new->serves($this->xp));
    }

    public function test_untrusted_domain_asks_before_continuing_and_keeps_its_session_to_itself(): void
    {
        $this->cbg->update(['is_platform' => false]); // an outside organization's domain
        $this->activeDomain(trusted: false);
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'member');
        $this->follow($this->hit(self::MAIN.'/login', 'POST', ['email' => $user->email, 'password' => self::PASSWORD]));

        $this->hit($this->authorizeUrl(self::APPD));
        $r = $this->follow($this->hit(self::APPD.'/login'));
        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Continue as', $r->getContent());
        $this->assertStringNotContainsString('ticket=', $r->getContent());

        // Confirming hands over the sign-in.
        preg_match('/name="state" value="([^"]+)"/', $r->getContent(), $m);
        preg_match('/name="_token" value="([^"]+)"/', $r->getContent(), $c);
        $r = $this->hit(self::MAIN.'/sso/check', 'POST', ['_token' => $c[1], 'host' => 'auth.xpenses.test', 'state' => $m[1]]);
        $this->assertStringContainsString('ticket=', $r->headers->get('Location'));
        $this->assertStringStartsWith(self::REDIRECT.'?code=', $this->follow($r)->headers->get('Location'));

        // A fresh sign-in on an untrusted domain does not sign the browser in on the main domain.
        $this->jars = [];
        $this->hit($this->authorizeUrl(self::APPD));
        $this->follow($this->hit(self::APPD.'/login'));
        $r = $this->hit(self::APPD.'/login', 'POST', ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->assertStringNotContainsString('/sso/adopt', (string) $r->headers->get('Location'));
        $this->hit(self::MAIN.'/account')->assertRedirect();
    }
}
