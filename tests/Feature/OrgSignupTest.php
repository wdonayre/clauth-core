<?php

namespace Tests\Feature;

use App\Mail\AccessRequestMail;
use App\Mail\LoginCodeMail;
use App\Mail\OrgRequestMail;
use App\Models\AccessRequest;
use App\Models\Grant;
use App\Models\OrganizationRequest;
use App\Models\User;
use App\Services\Settings;
use App\Services\TwoFactor;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;

/** SPEC-signup.md: anyone creates an account; organizations need approval; invite-only apps can be asked for access. */
class OrgSignupTest extends ClauthTestCase
{
    private User $owner;

    private User $grace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
        $this->owner = $this->makeUser('owner@clauth.test', ['name' => 'William', 'clauth_role' => 'owner']);
        $this->owner->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->grace = $this->makeUser('grace@cakesbygrace.ph', ['name' => 'Grace']);
        $this->grace->organizations()->attach($this->cbg->id, ['role' => 'owner', 'joined_at' => now()]);
        $this->grace->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->client->forceFill(['app_id' => $this->xp->id, 'type' => 'web', 'name' => 'Xpenses web', 'secret' => $this->secret,
            'redirect_uris' => [self::REDIRECT], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false])->save();
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withSession(['auth.mfa' => true]);
    }

    private array $signup = ['name' => 'Maria Santos', 'email' => 'maria@bakeryco.ph', 'password' => 'a long enough password',
        'password_confirmation' => 'a long enough password', 'terms' => '1'];

    private function lastCode(string $to): string
    {
        $code = null;
        Mail::assertSent(LoginCodeMail::class, function ($m) use (&$code, $to) {
            if ($m->hasTo($to)) {
                $code = $m->code;
            }

            return $m->hasTo($to);
        });

        return $code;
    }

    private function request(User $u, array $over = []): OrganizationRequest
    {
        return OrganizationRequest::create($over + ['user_id' => $u->id, 'org_name' => 'Bakery Co.', 'slug' => 'bakery-co', 'website' => 'https://bakeryco.ph',
            'country' => 'Philippines', 'team_size' => '11-50', 'contact_name' => $u->name, 'contact_email' => $u->email,
            'purpose' => 'Sign-in for our two shops.', 'email_verified_at' => now()]);
    }

    public function test_anyone_can_create_an_account_on_clauth_and_lands_on_welcome(): void
    {
        $this->get('/login')->assertOk()->assertSee('Create an account');
        $this->get('/signup')->assertOk()->assertSee('One account for every app')->assertSee('doesn’t open any app', false);
        $this->keep($this->post('/signup', $this->signup))->assertRedirect('/signup');
        $this->keep($this->post('/signup/verify', ['code' => $this->lastCode('maria@bakeryco.ph')]))->assertRedirect('/welcome');
        $maria = User::firstWhere('email', 'maria@bakeryco.ph');
        $this->assertAuthenticatedAs($maria);
        $this->assertSame(0, $maria->organizations()->count(), 'an account alone is in no organization');
        $this->assertSame(0, Grant::where('user_id', $maria->id)->count());

        // Organizations closed (legal not reviewed): welcome only offers invite + My account.
        $this->get('/welcome')->assertOk()->assertSee('Welcome, Maria')->assertSee('I was invited')->assertDontSee('Set up an organization');
        Settings::set(['legal_reviewed' => true]);
        $this->get('/welcome')->assertSee('Set up an organization')->assertSee('Needs approval');
        $this->get('/account')->assertOk()->assertSee('Set up an organization')->assertSee('Get started');
    }

    public function test_account_sign_up_can_be_switched_off(): void
    {
        Settings::set(['account_signup' => false]);
        $this->get('/login')->assertOk()->assertDontSee('Create an account');
        $this->get('/signup')->assertNotFound();
    }

    public function test_welcome_accepts_only_invite_links_for_this_clauth(): void
    {
        $maria = $this->makeUser('maria@bakeryco.ph');
        $this->actingAs($maria)->post('/welcome/invite', ['link' => 'https://evil.test/invite/'.str_repeat('a', 48)])->assertSessionHasErrors('link');
        $this->actingAs($maria)->post('/welcome/invite', ['link' => url('/invite/'.str_repeat('a', 48))])->assertRedirect('/invite/'.str_repeat('a', 48));
    }

    public function test_reviewer_asks_a_question_and_the_answer_sends_it_back(): void
    {
        Settings::set(['legal_reviewed' => true]);
        $maria = $this->makeUser('maria@bakeryco.ph');
        $r = $this->request($maria);
        $this->as($this->owner)->post('/admin/org-requests/'.$r->id.'/info', ['message' => ''])->assertSessionHasErrors('message');
        $this->as($this->owner)->post('/admin/org-requests/'.$r->id.'/info', ['message' => 'Which apps will you add first?'])->assertRedirect('/admin/org-requests');
        $this->assertSame('info_requested', $r->fresh()->status);
        Mail::assertSent(OrgRequestMail::class, fn ($m) => $m->kind === 'info' && $m->hasTo('maria@bakeryco.ph'));
        $this->as($this->owner)->get('/admin/org-requests?tab=info')->assertSee('Bakery Co.')->assertSee('Waiting for their answer');
        $this->as($this->owner)->get('/admin/org-requests')->assertDontSee('Bakery Co.');

        $this->actingAs($maria)->get('/account')->assertSee('We have a question about Bakery Co.')->assertSee('Which apps will you add first?');
        $this->actingAs($maria)->get('/organizations/request/edit')->assertOk()->assertSee('The reviewer asked:');
        $this->actingAs($maria)->post('/organizations/request', ['org_name' => 'Bakery Co.', 'slug' => 'bakery-co', 'website' => 'https://bakeryco.ph',
            'country' => 'Philippines', 'team_size' => '11-50', 'purpose' => 'Sign-in for our two shops: CebPOS first, then Xpenses.', 'agree' => 1])->assertRedirect('/account');
        $this->assertSame('pending', $r->fresh()->status);
        Mail::assertSent(OrgRequestMail::class, fn ($m) => $m->kind === 'answered' && $m->hasTo('owner@clauth.test'));
    }

    public function test_cancel_decline_and_expiry(): void
    {
        Settings::set(['legal_reviewed' => true]);
        $maria = $this->makeUser('maria@bakeryco.ph');
        $r = $this->request($maria);
        $this->actingAs($maria)->post('/organizations/request/cancel')->assertRedirect('/account');
        $this->assertSame('cancelled', $r->fresh()->status);

        $r2 = $this->request($maria, ['slug' => 'bakery-two']);
        $this->as($this->owner)->post('/admin/org-requests/'.$r2->id.'/reject', ['reason' => 'Send your shop’s page.'])->assertRedirect('/admin/org-requests');
        Mail::assertSent(OrgRequestMail::class, fn ($m) => $m->kind === 'rejected' && $m->hasTo('maria@bakeryco.ph'));
        $this->actingAs($maria)->get('/account')->assertSee('We couldn’t approve Bakery Co.', false)->assertSee('Send your shop’s page.', false);

        $r3 = $this->request($maria, ['slug' => 'bakery-three']);
        OrganizationRequest::whereKey($r3->id)->update(['updated_at' => now()->subDays(31)]);
        $this->assertSame(1, OrganizationRequest::expireDue());
        $this->assertSame('expired', $r3->fresh()->status);
        Mail::assertSent(OrgRequestMail::class, fn ($m) => $m->kind === 'expired');
        $this->as($this->owner)->get('/admin/org-requests?tab=declined')->assertSee('bakery-two')->assertSee('bakery-three');
    }

    public function test_personal_email_and_taken_names_are_flagged(): void
    {
        $jun = $this->makeUser('jun@gmail.com', ['name' => 'Jun']);
        $r = $this->request($jun, ['org_name' => 'Runners', 'slug' => 'cakesbygrace', 'website' => null]);
        $this->as($this->owner)->get('/admin/org-requests')->assertSee('Personal email')->assertSee('Short name taken');
        $this->as($this->owner)->get('/admin/org-requests/'.$r->id)->assertSee('Personal email address')->assertSee('Short name is taken now');
        $this->as($this->owner)->post('/admin/org-requests/'.$r->id.'/approve')->assertSessionHasErrors('request');
        $this->as($this->grace)->get('/admin/org-requests')->assertNotFound();   // Clauth owners only
    }

    public function test_new_person_from_an_invite_only_app_asks_for_access_and_an_admin_approves(): void
    {
        // Guest opens Xpenses (invite-only) → creates an account there → "no access yet" page.
        $this->keep($this->get('/oauth/authorize?'.http_build_query(['client_id' => $this->client->id, 'redirect_uri' => self::REDIRECT,
            'response_type' => 'code', 'scope' => 'openid', 'state' => 'st', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256'])));
        $this->keep($this->get('/login'))->assertSee('Create an account');
        $this->keep($this->post('/signup', $this->signup))->assertRedirect('/signup');
        $r = $this->keep($this->post('/signup/verify', ['code' => $this->lastCode('maria@bakeryco.ph')]));
        $page = $this->keep($this->get((string) $r->headers->get('Location')));
        $page->assertOk()->assertSee('Xpenses is invite-only', false)->assertSee('Ask CakesbyGrace for access');

        $this->keep($this->post('/oauth/access-request', ['note' => 'I run the Talisay shop.']))->assertRedirect();
        $this->keep($this->post('/oauth/access-request', []));   // asking twice keeps one request
        $maria = User::firstWhere('email', 'maria@bakeryco.ph');
        $ar = AccessRequest::sole();
        $this->assertSame([$maria->id, $this->xp->id, 'pending', 'I run the Talisay shop.'], [$ar->user_id, $ar->app_id, $ar->status, $ar->note]);
        Mail::assertSent(AccessRequestMail::class, fn ($m) => $m->kind === 'new' && $m->hasTo('grace@cakesbygrace.ph'));

        $this->defaultCookies = [];
        $this->as($this->grace)->get('/admin/orgs/'.$this->cbg->id)->assertOk()->assertSee('1 person asked for access')->assertSee('I run the Talisay shop.');
        $this->as($this->grace)->post('/admin/orgs/'.$this->cbg->id.'/access-requests/'.$ar->id.'/approve', ['role' => $this->role('member')->id])->assertSessionHasNoErrors();
        $this->assertSame('approved', $ar->fresh()->status);
        $this->assertSame('member', $maria->fresh()->orgRole($this->cbg->id));
        $this->assertSame('member', Grant::where('user_id', $maria->id)->where('app_id', $this->xp->id)->first()?->role?->key);
        Mail::assertSent(AccessRequestMail::class, fn ($m) => $m->kind === 'approved' && $m->hasTo('maria@bakeryco.ph'));
    }

    public function test_set_up_your_own_organization_from_an_app_notes_the_app(): void
    {
        Settings::set(['legal_reviewed' => true]);
        $maria = $this->makeUser('maria@bakeryco.ph');
        $this->actingAs($maria)->get('/organizations/new?from=xpenses')->assertOk();
        $this->actingAs($maria)->post('/organizations/new', ['org_name' => 'Bakery Co.', 'slug' => 'bakery-co', 'country' => 'Philippines',
            'team_size' => '1-10', 'purpose' => 'Expenses for our two shops, then the POS.', 'agree' => 1])->assertRedirect('/account');
        $this->assertSame($this->xp->id, OrganizationRequest::sole()->source_app_id);
        $this->as($this->owner)->get('/admin/org-requests')->assertSee('Xpenses');
    }
}
