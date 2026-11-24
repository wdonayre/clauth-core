<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\ReleaseNote;
use App\Models\Report;
use App\Models\User;
use App\Services\TwoFactor;

/** Redesign 2026-11: Overview, Activity, Reports and Release notes read as plain sentences and keep their scoping. */
class OverviewActivityTest extends ClauthTestCase
{
    private User $owner;

    private User $ana;

    private User $maria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser('owner@clauth.test', ['name' => 'William Donayre', 'clauth_role' => 'owner']);
        $this->owner->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->ana = $this->makeUser('ana@cakesbygrace.ph', ['name' => 'Ana Cruz']);
        $this->maria = $this->makeUser('maria@cakesbygrace.ph', ['name' => 'Maria Santos', 'last_login_at' => now(), 'locked_until' => now()->addMinutes(15)]);
        $this->grant($this->maria, $this->cbg, 'member');
        $this->event('grant.resolved', 'role', ['app' => 'xpenses', 'role' => 'finance', 'cause' => 'profiles changed'], ['app' => 'xpenses', 'role' => 'member'], $this->ana->id);
        $this->event('signin.success', 'signin', ['app' => 'xpenses', 'amr' => ['pwd', 'otp']], null, $this->maria->id, org: false);
        $this->event('signin.locked', 'security', ['until' => now()->addMinutes(15)->toIso8601String()], null, $this->maria->id);
        $this->event('user.invited', 'admin', ['email' => 'new@cakesbygrace.ph'], null, $this->owner->id);
        $this->event('weird.thing', 'admin', ['x' => 1], null, $this->ana->id);
    }

    private function event(string $event, string $cat, ?array $after, ?array $before, string $actor, bool $org = true): AuditEvent
    {
        return AuditEvent::create(['at' => now(), 'actor_user_id' => $actor, 'actor_type' => 'user', 'event' => $event, 'category' => $cat,
            'subject_type' => 'user', 'subject_id' => $this->maria->id, 'app_id' => $this->xp->id, 'organization_id' => $org ? $this->cbg->id : null,
            'ip' => '203.0.113.24', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/131.0 Safari/537.36',
            'after' => $after, 'before' => $before, 'request_id' => 'r1']);
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withSession(['auth.mfa' => true]);
    }

    public function test_overview_shows_greeting_stats_apps_and_sentences(): void
    {
        $page = $this->as($this->owner)->get('/admin')->assertOk()
            ->assertSee('William')->assertSee('Connect an app')->assertSee('Invite people')
            ->assertSee(route('admin.users.index', ['invite' => 1]), false)
            ->assertSee('Signed in today')->assertSee('Waiting on invites')
            ->assertSee('Needs your attention')->assertSee('Maria Santos is locked out after failed sign-ins')
            ->assertSee('Your apps')->assertSee('1 person · 1 signed in today')
            ->assertSee('Recent activity')->getContent();
        $this->assertStringContainsString('<b>Ana Cruz</b> gave <b>Maria Santos</b> the <span class="badge info">Finance</span> role in Xpenses · CakesbyGrace', $page);
        $this->assertStringContainsString('<b>You</b> invited <b>new@cakesbygrace.ph</b> to CakesbyGrace', $page);
        $this->assertStringNotContainsString('{&quot;', $page, 'no raw JSON');
    }

    public function test_activity_filters_group_by_day_and_export(): void
    {
        $this->as($this->owner)->get('/admin/audit')->assertOk()->assertSee('Today')
            ->assertSee('Password + 2-step code · Chrome on Windows · 203.0.113.24')
            ->assertSee('was locked out after too many wrong passwords', false)
            ->assertSee('Weird Thing'); // fallback for unknown events
        $this->as($this->owner)->get('/admin/audit?cat=signin')->assertOk()->assertSee('signed in to')->assertDontSee('locked out');
        $this->as($this->owner)->get('/admin/audit?q=Ana')->assertOk()->assertSee('gave')->assertDontSee('signed in to <b>');
        $this->as($this->owner)->get('/admin/audit?app=xpenses&range=today')->assertOk()->assertSee('gave');
        $this->as($this->owner)->get('/admin/audit?range=custom&from=2001-01-01&to=2001-01-02')->assertOk()->assertSee('No activity matches');
        $csv = $this->as($this->owner)->get('/admin/audit.csv?cat=role')->streamedContent();
        $this->assertStringContainsString('Ana Cruz gave Maria Santos the Finance role in Xpenses', $csv);
    }

    public function test_outside_admin_sees_none_of_this(): void
    {
        $other = Organization::create(['slug' => 'other-co', 'name' => 'Other Co', 'verified_at' => now(), 'terms_version' => config('clauth.terms_version')]);
        $bob = $this->makeUser('bob@other.test', ['name' => 'Bob']);
        $bob->organizations()->attach($other->id, ['role' => 'admin']);
        foreach (['/admin', '/admin/audit', '/admin/audit?app=xpenses', '/admin/audit.csv'] as $url) {
            $res = $this->as($bob)->get($url)->assertOk();
            $body = $res->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse ? $res->streamedContent() : $res->getContent();
            $this->assertStringNotContainsString('Maria', $body, $url);
            $this->assertStringNotContainsString('Xpenses', $body, $url);
        }
        $this->as($bob)->get('/admin/reports')->assertNotFound();
        $this->as($bob)->get('/admin/updates')->assertNotFound();
    }

    public function test_reports_tabs_and_release_notes(): void
    {
        $r = Report::create(['host' => 'login-foo.test', 'reason' => 'phishing', 'details' => 'Link from a fake email', 'app_id' => $this->xp->id, 'reporter_user_id' => $this->maria->id]);
        $this->as($this->owner)->get('/admin/reports')->assertOk()->assertSee('Possible phishing')->assertSee('Reported by maria@cakesbygrace.ph')
            ->assertSee('Suspend the app')->assertSee('Mark resolved');
        $this->as($this->owner)->post('/admin/reports/'.$r->id.'/close')->assertSessionHasNoErrors();
        $this->as($this->owner)->get('/admin/reports?show=closed')->assertOk()->assertSee('login-foo.test')->assertSee('Resolved')->assertSee('by William Donayre');
        $this->as($this->owner)->get('/admin/reports?show=changes')->assertOk()->assertSee('Recent changes by outside organizations');

        $as = $this->as($this->owner);
        $as->get('/admin/updates')->assertOk()->assertSee('Release notes')->assertSee('New release note');
        $as->post('/admin/updates', ['app_id' => $this->xp->id, 'title' => 'Later thing', 'published_at' => now()->addDays(3)->format('Y-m-d\TH:i')])->assertRedirect('/admin/updates');
        $note = ReleaseNote::firstOrFail();
        $as->get('/admin/updates')->assertSee('Scheduled')->assertSee('by you');
        $as->get('/admin/updates?edit='.$note->id)->assertOk()->assertSee('Edit release note')->assertSee('data-open-on-load', false);
        $as->put('/admin/updates/'.$note->id, ['app_id' => $this->xp->id, 'title' => 'Now thing'])->assertRedirect('/admin/updates');
        $as->get('/admin/updates')->assertSee('Now thing')->assertSee('Published')->assertSee('read by 0 of');
        $as->delete('/admin/updates/'.$note->id)->assertSessionHasNoErrors();
        $this->assertSame(0, ReleaseNote::count());
        $as->get('/admin')->assertOk(); // release-note audit events render
        $as->get('/admin/audit?cat=app')->assertOk()->assertSee('removed a release note');
    }
}
