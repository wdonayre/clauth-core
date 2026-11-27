<?php

namespace Tests\Feature;

use App\Models\AccessProfile;
use App\Models\Grant;
use App\Services\SigninPolicies;
use Illuminate\Support\Facades\DB;

/** "Join :app": a person who already has a Clauth account joins an open sign-up app instead of a dead end. */
class AppJoinTest extends ClauthTestCase
{
    private function openSignUp(array $extra = []): void
    {
        $p = AccessProfile::create(['organization_id' => $this->cbg->id, 'key' => 'viewer', 'name' => 'Viewer (self sign-up)']);
        DB::table('access_profile_roles')->insert(['access_profile_id' => $p->id, 'app_id' => $this->xp->id, 'app_role_id' => $this->role('member')->id]);
        $owner = $this->makeUser('owner@cakesbygrace.ph', ['name' => 'Grace']);
        SigninPolicies::save($this->xp, null, SigninPolicies::validate(array_merge(['registration' => 'open', 'open_profile' => 'viewer'], $extra), $this->xp, null), $owner);
    }

    public function test_signed_in_person_joins_open_app_and_gets_a_code(): void
    {
        $this->openSignUp();
        $ana = $this->makeUser('ana@gmail.com', ['name' => 'Ana Reyes']);
        $r = $this->authorizeCode($ana);
        $this->assertFalse(is_array($r), 'expected the join page, not a code');
        $r->assertOk()->assertSee('Join Xpenses')->assertSee('Xpenses will get')->assertSee('Member')->assertSee('ana@gmail.com');
        $this->assertSame(0, Grant::where('user_id', $ana->id)->count());

        $back = $this->keep($this->post('/oauth/join', ['decision' => 'join']));
        $back->assertRedirect();
        $this->assertSame('member', Grant::where('user_id', $ana->id)->first()?->role?->key);
        $this->assertTrue($ana->organizations()->where('organizations.id', $this->cbg->id)->exists());
        $this->assertDatabaseHas('audit_events', ['event' => 'user.joined_app']);

        // Back at /oauth/authorize: straight through to the app with a code.
        $done = $this->keep($this->get($back->headers->get('Location')));
        $this->assertStringStartsWith(self::REDIRECT.'?code=', (string) $done->headers->get('Location'));
    }

    public function test_cancel_returns_access_denied_and_grants_nothing(): void
    {
        $this->openSignUp();
        $ana = $this->makeUser('ana@gmail.com');
        $this->authorizeCode($ana)->assertOk();
        $back = $this->keep($this->post('/oauth/join', ['decision' => 'cancel']));
        $done = $this->keep($this->get($back->headers->get('Location')));
        $this->assertStringContainsString('error=access_denied', (string) $done->headers->get('Location'));
        $this->assertStringContainsString('not+to+join', (string) $done->headers->get('Location'));
        $this->assertSame(0, Grant::where('user_id', $ana->id)->count());
    }

    private function denied($r): void
    {
        $this->assertTrue(is_array($r), 'expected a redirect back to the app');
        $this->assertSame('access_denied', $r[3]['error'] ?? null);
    }

    /** Invite-only for this person: the "no access yet" page (ask / set up an organization); Back still denies. */
    private function noAccessThenBack($r): void
    {
        $this->assertFalse(is_array($r), 'expected the no-access page');
        $r->assertOk()->assertSee('is invite-only', false)->assertSee('Ask CakesbyGrace for access', false);
        $back = $this->keep($this->post('/oauth/join', ['decision' => 'cancel']));
        $this->denied($this->authorizeCodeFrom($back));
    }

    private function authorizeCodeFrom($r): array
    {
        $r = $this->keep($this->get((string) $r->headers->get('Location')));
        parse_str((string) parse_url((string) $r->headers->get('Location'), PHP_URL_QUERY), $q);

        return [$q['code'] ?? null, $q['state'] ?? null, null, $q];
    }

    public function test_invite_only_app_offers_to_ask_for_access(): void
    {
        $this->noAccessThenBack($this->authorizeCode($this->makeUser('ana@gmail.com')));
    }

    public function test_not_offered_when_email_domain_not_allowed(): void
    {
        $this->openSignUp(['open_domains' => 'cakesbygrace.ph']);
        $this->noAccessThenBack($this->authorizeCode($this->makeUser('ana@gmail.com')));
    }

    public function test_not_offered_to_someone_already_in_the_organization(): void
    {
        // An admin there decided what they have (maybe took access away), so no self-join.
        $this->openSignUp();
        $ben = $this->makeUser('ben@cakesbygrace.ph');
        $ben->organizations()->attach($this->cbg->id);
        $this->denied($this->authorizeCode($ben));
        $this->assertSame(0, Grant::where('user_id', $ben->id)->count());
    }

    public function test_silent_sign_in_never_shows_the_join_page(): void
    {
        $this->openSignUp();
        $ana = $this->makeUser('ana@gmail.com');
        $this->authorizeCode($ana)->assertOk();   // signs in, stops at the join page
        $r = $this->authorizeCode(null, 'openid profile email clauth', ['prompt' => 'none']);
        $this->assertSame('consent_required', $r[3]['error'] ?? null);
    }
}
