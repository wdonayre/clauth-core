<?php

namespace Tests\Feature;

use App\Models\AppDomain;
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

    private function activeDomain(): AppDomain
    {
        $d = $this->xp->domain()->create(['host' => 'auth.xpenses.test', 'verify_token' => Str::lower(Str::random(32)), 'status' => 'active', 'active_at' => now()]);
        Domains::flush($d->host);

        return $d;
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
            if (str_starts_with($loc, self::REDIRECT)) {
                return $r;
            }
            $r = $this->hit(str_starts_with($loc, 'http') ? $loc : self::MAIN.$loc);
        }

        return $r;
    }

    private function authorizeUrl(string $base): string
    {
        $verifier = Str::random(64);

        return $base.'/oauth/authorize?'.http_build_query([
            'client_id' => $this->client->id, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code', 'scope' => 'openid clauth',
            'state' => 'st', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
        ]);
    }

    public function test_domain_lifecycle_txt_then_reachability_then_active(): void
    {
        $owner = $this->makeUser('owner@thecakelabs.com', ['name' => 'William', 'clauth_role' => 'owner']);
        $owner->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($owner)->withSession(['auth.mfa' => true])->post(self::MAIN.'/admin/apps/xpenses/domain', ['host' => 'https://Auth.Xpenses.test/'])->assertSessionHasNoErrors()->assertRedirect();
        $d = AppDomain::firstOrFail();
        $this->assertSame('auth.xpenses.test', $d->host);
        $this->assertSame('pending', $d->status);
        $this->actingAs($owner)->withSession(['auth.mfa' => true])->get(self::MAIN.'/admin/apps/xpenses?tab=brand')
            ->assertOk()->assertSee($d->txtName())->assertSee($d->txtValue())->assertSee('Waiting for DNS');

        // Proof is served for a not-yet-live domain; nothing else is.
        $this->get(self::APPD.$d->proofPath())->assertOk()->assertSee($d->proofBody());
        $this->get(self::APPD.'/login')->assertNotFound();

        $this->txt[$d->txtName()] = [$d->txtValue()];
        $this->assertSame('dns_ok', Domains::check($d)->status);
        Http::fake(['https://auth.xpenses.test/*' => Http::response($d->proofBody())]);
        $this->assertSame('reachable', Domains::check($d)->status);
        $this->assertSame('active', Domains::check($d)->status);

        // Daily check: TXT removed → problem after two failures → not served.
        $this->txt = [];
        $this->assertSame('active', Domains::check($d)->status);
        $this->assertSame('problem', Domains::check($d)->status);
        $this->get(self::APPD.'/login')->assertNotFound();
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
}
