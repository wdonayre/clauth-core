<?php

namespace Tests\Feature;

use App\Mail\DomainTakenOverMail;
use App\Models\AppDomain;
use App\Models\Application;
use App\Models\AppRole;
use App\Models\AuditEvent;
use App\Models\Grant;
use App\Models\OAuthClient;
use App\Models\Organization;
use App\Models\Report;
use App\Models\User;
use App\Services\Claims;
use App\Services\Domains;
use App\Services\TwoFactor;
use App\Support\Dns;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/** M9 (SPEC-organizations §7, §10): limits, suspension, reserved domains, takeover notices, per-host certificates, reports. */
class SafetyTest extends ClauthTestCase
{
    private Organization $foo;

    private User $bob;

    private User $owner;

    private array $policy = ['privacy_url' => 'https://foo.test/p', 'terms_url' => 'https://foo.test/t', 'support_email' => 'h@foo.test'];

    protected function setUp(): void
    {
        parent::setUp();
        // No real DNS or HTTP from these tests.
        Http::preventStrayRequests();
        $this->app->instance(Dns::class, new class extends Dns
        {
            public function txt(string $name): array
            {
                return [];
            }

            public function target(string $host, string $mainHost, array $serverIps): string
            {
                return 'none';
            }
        });
        $this->foo = Organization::create(['slug' => 'foo-ltd', 'name' => 'Foo Ltd', 'terms_version' => config('clauth.terms_version')]);
        $this->bob = $this->staff('bob@foo.test', $this->foo, 'admin');
        $this->owner = $this->makeUser('owner@thecakelabs.com', ['name' => 'William', 'clauth_role' => 'owner']);
        $this->owner->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
    }

    private function staff(string $email, Organization $org, string $role): User
    {
        $u = $this->makeUser($email, ['name' => Str::before($email, '@')]);
        $u->organizations()->attach($org->id, ['role' => $role]);
        $u->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();

        return $u;
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withSession(['auth.mfa' => true]);
    }

    private function newApp(string $key)
    {
        return $this->as($this->bob)->post('/admin/apps', ['name' => ucfirst($key), 'key' => $key, 'type' => 'web', 'redirect_uri' => 'https://foo.test/cb', 'publisher' => $this->foo->id] + $this->policy);
    }

    public function test_unverified_organizations_have_low_limits_and_verified_ones_more(): void
    {
        $this->newApp('rota')->assertSessionHasNoErrors();
        $this->newApp('rota-two')->assertSessionHasNoErrors();
        $this->newApp('rota-three')->assertSessionHasErrors('name');
        $this->assertTrue(AuditEvent::where('event', 'limit.reached')->exists());

        // No sign-in domains and no installs elsewhere until verified.
        $this->as($this->bob)->post('/admin/apps/rota/domain', ['host' => 'auth.foo.test'])->assertSessionHasErrors('host');
        Application::where('key', 'rota')->update(['visibility' => 'installable']);
        $this->as($this->bob)->post('/admin/apps/rota/installations', ['slug' => 'cakesbygrace'])->assertStatus(422);

        // Verified (by William) → higher limits; per-org override wins.
        $this->as($this->owner)->put('/admin/orgs/'.$this->foo->id, ['name' => 'Foo Ltd', 'status' => 'active', 'verified' => 1, 'limits' => ['invites_per_day' => 1]])->assertSessionHasNoErrors();
        $this->newApp('rota-three')->assertSessionHasNoErrors();
        $this->as($this->bob)->post('/admin/users/invite', ['name' => 'A', 'email' => 'a@foo.test', 'organization_id' => $this->foo->id, 'send' => 0])->assertSessionHasNoErrors();
        $this->as($this->bob)->post('/admin/users/invite', ['name' => 'B', 'email' => 'b@foo.test', 'organization_id' => $this->foo->id, 'send' => 0])->assertSessionHasErrors('email');

        // William's organizations have no limits.
        $this->assertNull($this->cbg->limit('apps'));
    }

    public function test_suspending_an_organization_stops_its_apps_but_not_its_peoples_other_access(): void
    {
        $app = Application::create(['key' => 'rota', 'name' => 'Rota', 'status' => 'live', 'owner_organization_id' => $this->foo->id] + $this->policy);
        $app->organizations()->attach($this->foo->id);
        $role = AppRole::create(['app_id' => $app->id, 'key' => 'member', 'name' => 'Member']);
        $maria = $this->makeUser('maria@cakesbygrace.ph');
        $this->grant($maria, $this->cbg, 'member');               // Xpenses at CakesbyGrace
        $maria->organizations()->attach($this->foo->id);
        Grant::create(['user_id' => $maria->id, 'app_id' => $app->id, 'organization_id' => $this->foo->id, 'app_role_id' => $role->id]);
        $m2m = new OAuthClient;
        $m2m->forceFill(['app_id' => $app->id, 'type' => 'm2m', 'name' => 'svc', 'secret' => 's3cret-s3cret', 'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'revoked' => false])->save();
        $d = AppDomain::create(['host' => 'auth.foo.test', 'organization_id' => $this->foo->id, 'verify_token' => Str::lower(Str::random(32)), 'status' => 'active']);
        $d->apps()->attach($app->id);

        $this->as($this->owner)->post('/admin/orgs/'.$this->cbg->id.'/suspend', ['reason' => 'x'])->assertStatus(422); // William's own
        $this->as($this->owner)->post('/admin/orgs/'.$this->foo->id.'/suspend', ['reason' => 'Phishing reports'])->assertSessionHasNoErrors();

        $this->assertFalse(Claims::canUse($maria, $app->fresh()));
        $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $m2m->id, 'client_secret' => 's3cret-s3cret'])->assertStatus(400)->assertJsonPath('error', 'unauthorized_client');
        Domains::flush('auth.foo.test');
        $this->get('https://auth.foo.test/login')->assertRedirect(Domains::mainUrl('login'));
        URL::forceRootUrl(config('app.url')); // back on the main domain for the next requests
        // Console read-only for Foo's staff, with the reason shown.
        $this->as($this->bob)->get('/admin/apps/rota')->assertOk()->assertSee('Phishing reports');
        $this->as($this->bob)->put('/admin/apps/rota', ['name' => 'Rota', 'status' => 'live', 'color' => '#22D3EE'] + $this->policy)->assertForbidden();
        // Maria's other access is unaffected.
        $this->assertTrue(Claims::canUse($maria, $this->xp));

        $this->as($this->owner)->delete('/admin/orgs/'.$this->foo->id.'/suspend')->assertSessionHasNoErrors();
        $this->assertTrue(Claims::canUse($maria, $app->fresh()));

        // Suspending one app.
        $this->as($this->owner)->post('/admin/apps/rota/suspend', ['reason' => 'Fake login'])->assertSessionHasNoErrors();
        $this->assertFalse(Claims::canUse($maria, $app->fresh()));
        $this->assertTrue(AuditEvent::where('event', 'app.suspended')->exists());
    }

    public function test_reserved_parent_domains_and_takeover_notice(): void
    {
        Mail::fake();
        $this->as($this->owner)->put('/admin/orgs/'.$this->foo->id, ['name' => 'Foo Ltd', 'status' => 'active', 'verified' => 1]);
        $app = Application::create(['key' => 'rota', 'name' => 'Rota', 'status' => 'live', 'owner_organization_id' => $this->foo->id] + $this->policy);
        $this->as($this->bob)->post('/admin/apps/rota/domain', ['host' => 'login.thecakelabs.com'])->assertSessionHasErrors('host');
        $this->as($this->bob)->post('/admin/apps/rota/domain', ['host' => 'thecakelabs.com'])->assertSessionHasErrors('host');

        // Another org's stale claim (never verified for two days) is taken over; its owners get an email.
        $other = Organization::create(['slug' => 'old-co', 'name' => 'Old Co']);
        $this->staff('olga@old.test', $other, 'owner');
        $old = AppDomain::create(['host' => 'auth.sharedname.test', 'organization_id' => $other->id, 'verify_token' => Str::lower(Str::random(32)), 'status' => 'pending']);
        $old->forceFill(['created_at' => now()->subDays(2)])->save();
        $this->as($this->bob)->post('/admin/apps/rota/domain', ['host' => 'auth.sharedname.test'])->assertSessionHasNoErrors();
        $this->assertSame($this->foo->id, AppDomain::where('host', 'auth.sharedname.test')->value('organization_id'));
        Mail::assertSent(DomainTakenOverMail::class, fn ($m) => $m->hasTo('olga@old.test') && $m->newOrgName === 'Foo Ltd');
        $this->assertTrue(AuditEvent::where('event', 'app.domain_lost')->where('organization_id', $other->id)->exists());
    }

    public function test_per_host_certificates_report_one_domain_at_a_time(): void
    {
        config(['clauth.cert_mode' => 'per-host']);
        $a = AppDomain::create(['host' => 'auth.a.test', 'organization_id' => $this->cbg->id, 'verify_token' => Str::lower(Str::random(32)), 'status' => 'reachable']);
        $b = AppDomain::create(['host' => 'auth.b.test', 'organization_id' => $this->cbg->id, 'verify_token' => Str::lower(Str::random(32)), 'status' => 'reachable']);
        $this->app->instance(Dns::class, new class extends Dns
        {
            public function txt(string $name): array
            {
                return [];
            }

            public function target(string $host, string $mainHost, array $serverIps): string
            {
                return 'cname';
            }
        });
        Http::fake(['https://auth.a.test/*' => Http::response($a->proofBody())]);

        $this->artisan('clauth:domains', ['action' => 'cert-result', 'host' => 'auth.b.test', '--failed' => 'too many requests'])->assertSuccessful();
        $this->artisan('clauth:domains', ['action' => 'cert-result', 'host' => 'auth.a.test', '--ok' => true])->assertSuccessful();
        $this->assertSame('issued', $a->fresh()->cert_status);
        $this->assertSame('active', $a->fresh()->status);
        $this->assertSame('failed', $b->fresh()->cert_status);
        $this->assertSame('reachable', $b->fresh()->status, 'one failing host doesn’t touch the other');

        $this->artisan('clauth:domains', ['action' => 'nginx-conf', 'host' => 'auth.a.test', '--tls' => true])
            ->expectsOutputToContain('ssl_certificate /etc/letsencrypt/live/auth.a.test/fullchain.pem')->assertSuccessful();
        $this->artisan('clauth:domains', ['action' => 'nginx-conf', 'host' => 'evil.test'])->assertFailed();

        // Before its certificate, the reachability check uses plain HTTP.
        Http::fake(['http://auth.b.test/*' => Http::response($b->proofBody())]);
        $this->assertTrue(Domains::proofOk($b, false));
    }

    public function test_report_this_page_reaches_williams_queue(): void
    {
        $this->get('/report')->assertOk()->assertSee('What’s wrong?', false);
        $this->post('/report', ['reason' => 'phishing', 'details' => 'Looks like my bank'])->assertOk()->assertSee('Thanks for telling us');
        $r = Report::firstOrFail();
        $this->assertSame('open', $r->status);
        $this->as($this->owner)->get('/admin')->assertSee('sign-in page report(s) to review');
        $this->as($this->owner)->get('/admin/reports')->assertOk()->assertSee('Looks like my bank');
        $this->as($this->bob)->get('/admin/reports')->assertNotFound();
        $this->as($this->owner)->post('/admin/reports/'.$r->id.'/close')->assertSessionHasNoErrors();
        $this->assertSame('closed', $r->fresh()->status);
    }
}
