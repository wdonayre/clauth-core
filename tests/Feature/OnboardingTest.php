<?php

namespace Tests\Feature;

use App\Mail\InviteMail;
use App\Mail\OrgRequestMail;
use App\Models\Application;
use App\Models\Invite;
use App\Models\Organization;
use App\Models\OrganizationRequest;
use App\Models\User;
use App\Services\Claims;
use App\Services\Invites;
use App\Services\OrgLifecycle;
use App\Services\Settings;
use App\Services\TwoFactor;
use App\Support\Dns;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;

/** M10 (SPEC-organizations §8, §10.5): request → confirm → approve; domain proof; terms; export; deletion. */
class OnboardingTest extends ClauthTestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['clauth.reserved_parent_domains' => ['reserved.test']]);
        Mail::fake();
        $this->app->instance(Dns::class, new class extends Dns
        {
            public static array $txt = [];

            public function txt(string $name): array
            {
                return self::$txt[$name] ?? [];
            }

            public function target(string $host, string $mainHost, array $serverIps): string
            {
                return 'none';
            }
        });
        $this->owner = $this->makeUser('owner@clauth.test', ['name' => 'William', 'clauth_role' => 'owner']);
        $this->owner->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withSession(['auth.mfa' => true]);
    }

    private array $form = ['org_name' => 'Foo Ltd', 'slug' => 'foo-ltd', 'website' => 'https://foo.test', 'contact_name' => 'Ana Cruz',
        'contact_email' => 'ana@foo.test', 'purpose' => 'Single sign-on for our staff rostering and payroll apps.', 'agree' => 1];

    public function test_closed_until_the_legal_documents_are_reviewed(): void
    {
        $this->get('/organizations/new')->assertOk()->assertSee('isn’t open to new organizations yet', false);
        $this->post('/organizations/new', $this->form)->assertRedirect('/login');            // needs an account
        $this->actingAs($this->makeUser('ana@foo.test'))->post('/organizations/new', $this->form)->assertNotFound();
        $this->get('/terms')->assertOk()->assertSee('Draft — needs review by a lawyer');
        $this->get('/dpa')->assertOk()->assertSee('Data Processing Agreement');
    }

    public function test_signed_in_request_approve_makes_the_person_owner(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Settings::set(['legal_reviewed' => true]);
        $this->get('/terms')->assertOk()->assertDontSee('Draft — needs review by a lawyer');
        $this->get('/organizations/new')->assertOk()->assertSee('First create a Clauth account');
        $ana = $this->makeUser('ana@foo.test', ['name' => 'Ana Cruz']);
        $form = ['org_name' => 'Foo Ltd', 'slug' => 'foo-ltd', 'website' => 'https://foo.test', 'country' => 'Philippines', 'team_size' => '11-50',
            'purpose' => 'Single sign-on for our staff rostering and payroll apps.', 'agree' => 1];
        $this->actingAs($ana)->get('/organizations/new')->assertOk()->assertSee('Set up your organization')->assertSee('ana@foo.test');
        $this->actingAs($ana)->post('/organizations/new', ['agree' => 0] + $form)->assertSessionHasErrors('agree');
        $this->actingAs($ana)->post('/organizations/new', ['slug' => 'cebucake-x'] + $form)->assertSessionHasErrors('slug'); // reserved prefix
        $this->actingAs($ana)->post('/organizations/new', $form + ['website_url' => 'spam'])->assertRedirect('/account'); // honeypot: dropped
        $this->assertSame(0, OrganizationRequest::count());

        $this->actingAs($ana)->post('/organizations/new', $form)->assertRedirect('/account');
        $req = OrganizationRequest::firstOrFail();
        $this->assertSame([$ana->id, 'ana@foo.test', 'pending'], [$req->user_id, $req->contact_email, $req->status]);
        $this->assertNotNull($req->email_verified_at, 'the account’s email is already proven');
        Mail::assertSent(OrgRequestMail::class, fn ($m) => $m->hasTo('owner@clauth.test') && $m->kind === 'new');
        // One open request per account.
        $this->actingAs($ana)->post('/organizations/new', ['slug' => 'foo-two'] + $form)->assertRedirect('/account')->assertSessionHasErrors('request');
        $this->actingAs($ana)->get('/account')->assertSee('Foo Ltd is waiting for review')->assertSee('Edit request');

        $this->as($this->owner)->get('/admin')->assertSee('1 organization request is waiting');
        $this->as($this->owner)->get('/admin/org-requests')->assertOk()->assertSee('Foo Ltd')->assertSee('Email matches website')->assertSee('Clauth sign-up');
        $this->as($this->owner)->get('/admin/org-requests/'.$req->id)->assertOk()->assertSee('Single sign-on for our staff')->assertSee('Short name is free')->assertSee('First request from this account');

        $this->as($this->owner)->post('/admin/org-requests/'.$req->id.'/approve', ['people_limit' => 25, 'apps_limit' => 3, 'branding_allowed' => 'cobrand'])->assertSessionHasNoErrors();
        $org = Organization::where('slug', 'foo-ltd')->firstOrFail();
        $this->assertFalse($org->is_platform);
        $this->assertNull($org->verified_at);
        $this->assertFalse($org->needsTerms(), 'accepted on the request form');
        $this->assertSame([25, 3, 'cobrand'], [$org->limit('people'), $org->limit('apps'), $org->branding_allowed]);
        $this->assertSame('owner', $ana->fresh()->orgRole($org->id), 'no invite: the account that asked is the owner');
        $this->assertSame(0, Invite::where('email', 'ana@foo.test')->count());
        Mail::assertSent(OrgRequestMail::class, fn ($m) => $m->hasTo('ana@foo.test') && $m->kind === 'approved');
        $this->assertTrue(Claims::policyRequiresMfa($ana->fresh()), 'owners must use 2-step');
        $this->actingAs($ana->fresh())->get('/account')->assertSee('Foo Ltd is approved');
    }

    public function test_legacy_public_form_request_still_invites_the_contact(): void
    {
        $req = OrganizationRequest::create(['org_name' => 'Old Co', 'slug' => 'old-co', 'contact_name' => 'Ana Cruz', 'contact_email' => 'ana@old.test',
            'purpose' => 'Single sign-on for our staff apps.', 'email_verified_at' => now()]);
        $this->as($this->owner)->post('/admin/org-requests/'.$req->id.'/approve')->assertSessionHasNoErrors();
        $org = Organization::where('slug', 'old-co')->firstOrFail();
        $invite = Invite::where('email', 'ana@old.test')->firstOrFail();
        $this->assertSame('owner', $invite->org_role);
        Mail::assertSent(InviteMail::class);
        $ana = User::where('email', 'ana@old.test')->first() ?? $this->makeUser('ana@old.test');
        Invites::apply($invite, $ana);
        $this->assertSame('owner', $ana->orgRole($org->id));
    }

    public function test_reject_emails_the_contact(): void
    {
        $req = OrganizationRequest::create(['org_name' => 'Spam Co', 'slug' => 'spam-co', 'contact_name' => 'X', 'contact_email' => 'x@spam.test',
            'purpose' => 'whatever whatever whatever', 'email_verified_at' => now()]);
        $this->as($this->owner)->post('/admin/org-requests/'.$req->id.'/reject', ['reason' => 'Not a fit right now.'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $req->fresh()->status);
        Mail::assertSent(OrgRequestMail::class, fn ($m) => $m->kind === 'rejected' && $m->hasTo('x@spam.test'));
    }

    public function test_domain_proof_terms_export_and_deletion(): void
    {
        $foo = Organization::create(['slug' => 'foo-ltd', 'name' => 'Foo Ltd']);
        $olga = $this->makeUser('olga@foo.test', ['name' => 'Olga']);
        $olga->organizations()->attach($foo->id, ['role' => 'owner']);
        $olga->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $only = $this->makeUser('only@foo.test', ['name' => 'Only Here']);
        $only->organizations()->attach($foo->id);
        $both = $this->makeUser('both@foo.test', ['name' => 'Both']);
        $both->organizations()->attach([$foo->id, $this->cbg->id]);

        // Terms: needed before publishing an app.
        $this->assertTrue($foo->needsTerms());
        $policy = ['privacy_url' => 'https://foo.test/p', 'terms_url' => 'https://foo.test/t', 'support_email' => 'h@foo.test'];
        $this->as($olga)->post('/admin/apps', ['name' => 'Rota', 'key' => 'rota', 'type' => 'web', 'redirect_uri' => 'https://foo.test/cb', 'publisher' => $foo->id] + $policy)->assertSessionHasErrors('publisher');
        $this->as($olga)->post('/admin/orgs/'.$foo->id.'/terms', ['agree' => 1])->assertSessionHasNoErrors();
        $this->as($olga)->post('/admin/apps', ['name' => 'Rota', 'key' => 'rota', 'type' => 'web', 'redirect_uri' => 'https://foo.test/cb', 'publisher' => $foo->id] + $policy)->assertSessionHasNoErrors();

        // Domain proof.
        $this->as($olga)->post('/admin/orgs/'.$foo->id.'/domain', ['domain' => 'reserved.test'])->assertSessionHasErrors('domain');
        $this->as($olga)->post('/admin/orgs/'.$foo->id.'/domain', ['domain' => 'foo.test'])->assertSessionHasNoErrors();
        $foo->refresh();
        $this->as($olga)->get('/admin/orgs/'.$foo->id.'/domain')->assertOk()->assertSee(OrgLifecycle::txtValue($foo));
        $this->as($olga)->post('/admin/orgs/'.$foo->id.'/domain/check');
        $this->assertNull($foo->fresh()->domain_verified_at);
        $dns = app(Dns::class);
        $dns::$txt['_clauth-org.foo.test'] = [OrgLifecycle::txtValue($foo)];
        $this->as($olga)->post('/admin/orgs/'.$foo->id.'/domain/check');
        $this->assertNotNull($foo->fresh()->domain_verified_at);

        // Export has this organization's people and nothing of others.
        $json = $this->as($olga)->get('/admin/orgs/'.$foo->id.'/export')->assertOk()->assertHeader('Content-Disposition')->json();
        $this->assertEqualsCanonicalizing(['olga@foo.test', 'only@foo.test', 'both@foo.test'], array_column($json['people'], 'email'));
        $this->assertStringNotContainsString('cakesbygrace', json_encode($json));
        $this->as($olga)->get('/admin/orgs/'.$this->cbg->id.'/export')->assertNotFound();

        // Deletion: request, purge after 30 days; people only here are deleted, the others stay.
        $this->as($olga)->post('/admin/orgs/'.$foo->id.'/deletion', ['confirm' => 'nope'])->assertSessionHasErrors('confirm');
        $this->as($olga)->post('/admin/orgs/'.$foo->id.'/deletion', ['confirm' => 'foo-ltd'])->assertSessionHasNoErrors();
        $this->artisan('clauth:orgs-purge')->assertSuccessful();
        $this->assertNotNull(Organization::find($foo->id), 'not before 30 days');
        $foo->forceFill(['deletion_requested_at' => now()->subDays(31)])->save();
        $this->artisan('clauth:orgs-purge')->assertSuccessful();
        $this->assertNull(Organization::withTrashed()->find($foo->id));
        $this->assertNull(Application::withTrashed()->where('key', 'rota')->first());
        $this->assertNull(User::withTrashed()->find($only->id));
        $this->assertNotNull(User::find($both->id), 'still in CakesbyGrace');
        $this->assertNull(User::withTrashed()->find($olga->id), 'belonged only to Foo');
    }

    public function test_williams_organizations_cannot_be_deleted(): void
    {
        $this->as($this->owner)->post('/admin/orgs/'.$this->cbg->id.'/deletion', ['confirm' => 'cakesbygrace'])->assertStatus(422);
    }
}
