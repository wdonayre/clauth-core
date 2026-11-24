<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Invite;
use App\Models\User;
use App\Services\Audit;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\Mail;

/** People list, person tabs, invite pop-up (console redesign 2026-11). */
class PeoplePagesTest extends ClauthTestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser('william@thecakelabs.com', ['name' => 'William', 'clauth_role' => 'owner']);
        $this->owner->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withSession(['auth.mfa' => true]);
    }

    public function test_people_list_shows_counts_filters_pending_invites_and_paging(): void
    {
        Mail::fake();
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'finance');
        $off = $this->makeUser('rico@cebucake.com', ['name' => 'Rico Tan', 'status' => 'disabled']);
        $off->organizations()->attach($this->alab->id);
        $this->as($this->owner)->post('/admin/users/invite', ['emails' => 'ana@alabapparel.com', 'organization_id' => $this->alab->id,
            'roles' => [$this->xp->id => $this->role('member')->id], 'send' => 0])->assertRedirect();

        $page = $this->as($this->owner)->get('/admin/users')->assertOk()
            ->assertSee('Everyone who can sign in, and what they can use.')
            ->assertSee('Turned off')->assertSee('Invite pending')->assertSee('Invited by you', false)
            ->assertSee('Maria Santos')->assertSee('Finance')->assertSee('Showing 1–4 of 4', false)
            ->assertSee('Organization: All')->assertSee('App: All')->assertSee('Invite people');
        $this->assertStringContainsString('id="invite-dlg"', $page->getContent());
        $this->assertStringNotContainsString('data-open-on-load', $page->getContent());
        $this->as($this->owner)->get('/admin/users?invite=1')->assertSee('data-open-on-load', false);

        $this->as($this->owner)->get('/admin/users?status=disabled')->assertSee('Rico Tan')->assertDontSee('maria@cakesbygrace.ph');
        $this->as($this->owner)->get('/admin/users?status=invited')->assertSee('ana@alabapparel.com')->assertDontSee('Rico Tan');
        $this->as($this->owner)->get('/admin/users?org=cakesbygrace')->assertSee('Maria Santos')->assertDontSee('Rico Tan');
        // The organization's People tab links by id; the invite pop-up opens with that organization chosen.
        $this->as($this->owner)->get('/admin/users?invite=1&org='.$this->alab->id)->assertSee('Rico Tan')->assertDontSee('maria@cakesbygrace.ph')
            ->assertSee('data-open-on-load', false)->assertSee('<option value="'.$this->alab->id.'" selected>', false);
        $this->as($this->owner)->get('/admin/users?app=xpenses&q=maria')->assertSee('Maria Santos')->assertDontSee('rico@cebucake.com');
    }

    public function test_export_is_a_csv_of_the_filtered_list_and_stays_in_scope(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'finance');
        $jun = $this->makeUser('jun@alabapparel.com', ['name' => 'Jun']);
        $this->grant($jun, $this->alab, 'member');

        $csv = $this->as($this->owner)->get('/admin/users?export=csv&org=cakesbygrace')->assertOk()->streamedContent();
        $this->assertStringContainsString('name,email,status', $csv);
        $this->assertStringContainsString('maria@cakesbygrace.ph', $csv);
        $this->assertStringContainsString('Xpenses: Finance', $csv);
        $this->assertStringNotContainsString('jun@alabapparel.com', $csv);

        // An organization admin only exports their own organization's people and memberships.
        $grace = $this->makeUser('grace@cakesbygrace.ph', ['name' => 'Grace']);
        $grace->organizations()->attach($this->cbg->id, ['role' => 'admin']);
        $grace->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $jun->organizations()->attach($this->cbg->id);
        $csv = $this->as($grace)->get('/admin/users?export=csv')->streamedContent();
        $this->assertStringContainsString('jun@alabapparel.com', $csv);
        $this->assertStringNotContainsString('Alab Apparel', $csv);
    }

    public function test_invite_several_emails_at_once(): void
    {
        Mail::fake();
        $this->as($this->owner)->post('/admin/users/invite', ['emails' => "ana.reyes@cakesbygrace.ph, jo@cakesbygrace.ph\nana.reyes@cakesbygrace.ph",
            'organization_id' => $this->cbg->id, 'roles' => [$this->xp->id => $this->role('finance')->id], 'require_mfa' => 1, 'send' => 0])
            ->assertRedirect()->assertSessionHas('invite_links', fn ($l) => count($l) === 2);
        $this->assertSame(2, Invite::count());
        $this->assertSame('Ana Reyes', User::where('email', 'ana.reyes@cakesbygrace.ph')->value('name'));
        $this->assertTrue(Invite::where('email', 'jo@cakesbygrace.ph')->value('require_mfa'));
        $this->as($this->owner)->get('/admin/users?status=invited')->assertSee('2 invites created')->assertSee('jo@cakesbygrace.ph');

        $this->as($this->owner)->post('/admin/users/invite', ['emails' => 'nope, ok@x.test', 'organization_id' => $this->cbg->id])
            ->assertSessionHasErrors('emails');
        $this->assertSame(2, Invite::count());
    }

    public function test_person_tabs_render(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'finance');
        $maria->organizations()->attach($this->alab->id);
        Audit::log('signin.success', 'signin', $maria, after: ['app' => 'xpenses'], appId: $this->xp->id, actorId: $maria->id, actorType: 'user');
        $this->actingAs($this->owner);
        Audit::log('grant.override_set', 'role', $maria, ['role' => 'member'], ['role' => 'finance', 'reason' => 'Covering for Ana'], $this->xp->id, $this->cbg->id);

        $as = fn () => $this->as($this->owner);
        $as()->get("/admin/users/{$maria->id}")->assertOk()
            ->assertSee('CakesbyGrace')->assertSee('Alab Apparel')->assertSee('What Xpenses can see')->assertSee('Show the raw token')
            ->assertSee('aria-current="page"', false)->assertDontSee('Add to another organization');
        $maria->organizations()->detach($this->alab->id);
        $as()->get("/admin/users/{$maria->id}")->assertOk()->assertSee('Add to another organization');
        $as()->get("/admin/users/{$maria->id}?tab=profile")->assertOk()->assertSee('Full name')->assertSee('Organizations')->assertSee('Remove');
        $as()->get("/admin/users/{$maria->id}?tab=security")->assertOk()->assertSee('How they sign in')->assertSee('Devices &amp; sessions', false)
            ->assertSee('Recent sign-ins')->assertSee('Turn off this account');
        $as()->get("/admin/users/{$maria->id}?tab=activity")->assertOk()->assertSee('Today')
            ->assertSee('Signed in to')->assertSee('Covering for Ana')->assertSee('by hand')->assertSee('Open in Activity with more filters');
        $as()->get("/admin/users/{$maria->id}?tab=bogus")->assertOk()->assertSee('What Xpenses can see');
    }

    public function test_profile_tab_saves_details_and_org_fields_together(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        $this->as($this->owner)->post("/admin/orgs/{$this->cbg->id}/teams", ['name' => 'Talisay', 'key' => 'talisay']);
        $this->as($this->owner)->post("/admin/orgs/{$this->cbg->id}/fields", ['label' => 'Shift', 'key' => 'shift', 'type' => 'enum', 'options' => "AM\nPM"]);

        $this->as($this->owner)->put("/admin/users/{$maria->id}", ['name' => 'Maria S. Santos', 'email' => $maria->email, 'phone' => '+639170000000',
            'orgdata' => [$this->cbg->id => ['fields' => ['shift' => 'AM'], 'teams_shown' => '1', 'teams' => ['talisay']]]])
            ->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->assertSame('Maria S. Santos', $maria->fresh()->name);
        $this->assertSame(['shift' => 'AM'], \App\Services\OrgData::values($maria, $this->cbg));
        $this->assertSame(['talisay'], \App\Services\OrgData::teamKeys($maria, $this->cbg));

        // Not a member of Alab Apparel → no writing its data through this form.
        $this->as($this->owner)->put("/admin/users/{$maria->id}", ['name' => 'Maria', 'email' => $maria->email,
            'orgdata' => [$this->alab->id => ['fields' => ['x' => 'y']]]])->assertNotFound();
    }

    public function test_activity_hides_other_organizations_events_from_an_org_admin(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        $this->grant($maria, $this->alab, 'member');
        $grace = $this->makeUser('grace@alabapparel.com', ['name' => 'Grace']);
        $grace->organizations()->attach($this->alab->id, ['role' => 'admin']);
        $grace->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        AuditEvent::create(['at' => now(), 'actor_type' => 'admin', 'event' => 'profile.assigned', 'category' => 'role', 'subject_type' => 'user',
            'subject_id' => $maria->id, 'organization_id' => $this->cbg->id, 'after' => ['profile' => 'secret-cbg-role']]);

        $page = $this->as($grace)->get("/admin/users/{$maria->id}?tab=activity")->assertOk()->getContent();
        $this->assertStringNotContainsString('Secret Cbg Role', $page);
        $this->assertStringNotContainsString('CakesbyGrace', $page);
        $this->as($this->owner)->get("/admin/users/{$maria->id}?tab=activity")->assertSee('Secret Cbg Role');
    }

    private function orgAdmin(string $email, \App\Models\Organization $org): User
    {
        $u = $this->makeUser($email, ['name' => 'Admin '.$org->name]);
        $u->organizations()->attach($org->id, ['role' => 'admin']);
        $u->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();

        return $u;
    }

    public function test_bulk_actions_change_only_visible_people_and_are_audited(): void
    {
        Mail::fake();
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        $jo = $this->makeUser('jo@cakesbygrace.ph', ['name' => 'Jo Reyes']);
        $this->grant($jo, $this->cbg, 'member');
        $rico = $this->makeUser('rico@alabapparel.com', ['name' => 'Rico Tan']);
        $rico->organizations()->attach($this->alab->id);

        $this->as($this->owner)->get('/admin/users')->assertOk()->assertSee('id="pp-bulk"', false)
            ->assertSee('aria-label="Select Maria Santos"', false)->assertSee('Select all on this page');

        // Turn off, then back on.
        $this->as($this->owner)->post('/admin/users/bulk', ['action' => 'disable', 'ids' => [$maria->id, $jo->id]])->assertSessionHas('status');
        $this->assertSame('disabled', $maria->fresh()->status);
        $this->assertSame('disabled', $jo->fresh()->status);
        $this->assertSame(2, AuditEvent::where('event', 'user.disabled')->count());
        $this->as($this->owner)->post('/admin/users/bulk', ['action' => 'enable', 'ids' => [$maria->id, $jo->id]]);
        $this->assertSame('active', $maria->fresh()->status);
        $this->assertSame(2, AuditEvent::where('event', 'user.enabled')->count());

        // Never yourself.
        $this->as($this->owner)->post('/admin/users/bulk', ['action' => 'disable', 'ids' => [$this->owner->id]]);
        $this->assertSame('active', $this->owner->fresh()->status);

        // Add a job role (members of its organization only), then remove from the organization.
        $finance = \App\Models\AccessProfile::create(['organization_id' => $this->cbg->id, 'key' => 'finance', 'name' => 'Finance']);
        $this->as($this->owner)->post('/admin/users/bulk', ['action' => 'add-profile', 'profile_id' => $finance->id, 'ids' => [$maria->id, $rico->id]])
            ->assertSessionHas('status', fn ($m) => str_contains($m, '1 person') && str_contains($m, 'Skipped Rico Tan'));
        $this->assertTrue($maria->accessProfiles()->where('access_profiles.id', $finance->id)->exists());
        $this->assertFalse($rico->accessProfiles()->exists());
        $this->assertSame(1, AuditEvent::where('event', 'profile.assigned')->count());
        $this->as($this->owner)->post('/admin/users/bulk', ['action' => 'remove-org', 'organization_id' => $this->cbg->id, 'ids' => [$maria->id, $jo->id]]);
        $this->assertFalse($maria->organizations()->where('organizations.id', $this->cbg->id)->exists());
        $this->assertFalse(\App\Models\Grant::where('user_id', $jo->id)->exists());
        $this->assertSame(2, AuditEvent::where('event', 'user.updated')->whereJsonContains('after->left_org', $this->cbg->id)->count());

        // Resend invites: only invited people get a fresh link.
        $this->as($this->owner)->post('/admin/users/invite', ['emails' => 'ana@cakesbygrace.ph', 'organization_id' => $this->cbg->id, 'send' => 0]);
        $ana = User::where('email', 'ana@cakesbygrace.ph')->first();
        $this->as($this->owner)->post('/admin/users/bulk', ['action' => 'reinvite', 'ids' => [$ana->id, $rico->id]])
            ->assertRedirect()->assertSessionHas('invite_links', fn ($l) => count($l) === 1);
        $this->assertSame(1, Invite::where('email', 'ana@cakesbygrace.ph')->whereNull('revoked_at')->count());
        $this->assertSame(2, Invite::where('email', 'ana@cakesbygrace.ph')->count());
    }

    public function test_bulk_is_404_and_changes_nothing_when_any_id_is_outside_the_admins_organizations(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        $rico = $this->makeUser('rico@alabapparel.com', ['name' => 'Rico Tan']);
        $rico->organizations()->attach($this->alab->id);
        $grace = $this->orgAdmin('grace@alabapparel.com', $this->alab);

        $this->as($grace)->post('/admin/users/bulk', ['action' => 'disable', 'ids' => [$rico->id, $maria->id]])->assertNotFound();
        $this->as($grace)->post('/admin/users/bulk', ['action' => 'disable', 'ids' => [$maria->id]])->assertNotFound();
        $this->as($grace)->post('/admin/users/bulk', ['action' => 'disable', 'ids' => []])->assertNotFound();
        $this->assertSame('active', $rico->fresh()->status);
        $this->assertSame('active', $maria->fresh()->status);
        // A job role or organization they don't run is 404 too.
        $p = \App\Models\AccessProfile::create(['organization_id' => $this->cbg->id, 'key' => 'staff', 'name' => 'Staff']);
        $this->as($grace)->post('/admin/users/bulk', ['action' => 'add-profile', 'profile_id' => $p->id, 'ids' => [$rico->id]])->assertNotFound();
        $this->as($grace)->post('/admin/users/bulk', ['action' => 'remove-org', 'organization_id' => $this->cbg->id, 'ids' => [$rico->id]])->assertNotFound();
        // Their own people work.
        $this->as($grace)->post('/admin/users/bulk', ['action' => 'disable', 'ids' => [$rico->id]])->assertSessionHas('status');
        $this->assertSame('disabled', $rico->fresh()->status);
        $this->assertSame(0, AuditEvent::where('event', 'user.disabled')->where('subject_id', $maria->id)->count());
    }

    public function test_sign_a_person_out_of_one_session(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        \Illuminate\Support\Facades\DB::table('sessions')->insert([
            ['id' => 'sess-keep-'.str_repeat('a', 20), 'user_id' => $maria->id, 'ip_address' => '1.2.3.4', 'user_agent' => 'Mozilla/5.0 (Macintosh) Firefox/120', 'payload' => '', 'last_activity' => time()],
            ['id' => 'sess-kill-'.str_repeat('b', 20), 'user_id' => $maria->id, 'ip_address' => '1.2.3.5', 'user_agent' => 'Mozilla/5.0 (iPhone) Safari', 'payload' => '', 'last_activity' => time()],
        ]);
        $cs = \App\Models\ClientSession::create(['user_id' => $maria->id, 'client_id' => $this->client->id, 'app_id' => $this->xp->id, 'last_used_at' => now()]);

        $page = $this->as($this->owner)->get("/admin/users/{$maria->id}?tab=security")->assertOk()->getContent();
        $this->assertStringNotContainsString('sess-kill-', $page, 'raw session ids never reach the page');
        $handle = \App\Http\Controllers\Admin\UserController::sessionHandle('sess-kill-'.str_repeat('b', 20));
        $this->assertStringContainsString("/sessions/web/$handle", $page);

        $grace = $this->orgAdmin('grace@alabapparel.com', $this->alab);
        $this->as($grace)->delete("/admin/users/{$maria->id}/sessions/web/$handle")->assertNotFound();
        $this->as($grace)->delete("/admin/users/{$maria->id}/sessions/app/{$cs->id}")->assertNotFound();
        $this->assertSame(2, \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $maria->id)->count());

        $this->as($this->owner)->delete("/admin/users/{$maria->id}/sessions/web/$handle")->assertSessionHas('status');
        $this->assertSame(['sess-keep-'.str_repeat('a', 20)], \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $maria->id)->pluck('id')->all());
        $this->as($this->owner)->delete("/admin/users/{$maria->id}/sessions/web/nope")->assertNotFound();
        $this->as($this->owner)->delete("/admin/users/{$maria->id}/sessions/app/{$cs->id}")->assertSessionHas('status', 'Maria Santos was signed out of Xpenses.');
        $this->assertNull(\App\Models\ClientSession::find($cs->id));
        $this->assertSame(2, AuditEvent::where('event', 'security.session_ended')->where('subject_id', $maria->id)->count());
    }

    public function test_unlink_a_sign_in_only_while_another_way_remains(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        \App\Models\SocialIdentity::create(['user_id' => $maria->id, 'provider' => 'google', 'provider_id' => 'g-1', 'email' => 'maria@gmail.com']);
        $this->as($this->owner)->get("/admin/users/{$maria->id}?tab=security")->assertSee('Unlink');

        $grace = $this->orgAdmin('grace@alabapparel.com', $this->alab);
        $this->as($grace)->delete("/admin/users/{$maria->id}/links/google")->assertNotFound();
        $this->assertSame(1, $maria->socialIdentities()->count());

        // Google is the only way in → refused.
        $maria->forceFill(['password' => null])->save();
        $this->as($this->owner)->delete("/admin/users/{$maria->id}/links/google")->assertSessionHasErrors('unlink');
        $this->assertSame(1, $maria->socialIdentities()->count());
        $this->as($this->owner)->get("/admin/users/{$maria->id}?tab=security")->assertSee('only way they sign in');

        $maria->forceFill(['password' => self::PASSWORD])->save();
        $this->as($this->owner)->delete("/admin/users/{$maria->id}/links/google")->assertSessionHas('status');
        $this->assertSame(0, $maria->socialIdentities()->count());
        $this->assertSame(1, AuditEvent::where('event', 'security.social_unlinked')->where('subject_id', $maria->id)->count());
        $this->as($this->owner)->delete("/admin/users/{$maria->id}/links/google")->assertNotFound();
    }

    public function test_delete_account_is_for_clauth_owners_and_needs_the_email_typed(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        \App\Models\ClientSession::create(['user_id' => $maria->id, 'client_id' => $this->client->id, 'app_id' => $this->xp->id, 'last_used_at' => now()]);
        $this->as($this->owner)->get("/admin/users/{$maria->id}?tab=security")->assertSee('Delete account')->assertSee('id="del-dlg"', false);

        // Organization admins: 404 outside their organizations, 403 inside; they get "Remove from organization" instead.
        $other = $this->orgAdmin('grace@alabapparel.com', $this->alab);
        $this->as($other)->delete("/admin/users/{$maria->id}", ['confirm_email' => $maria->email])->assertNotFound();
        $grace = $this->orgAdmin('grace@cakesbygrace.ph', $this->cbg);
        $this->as($grace)->delete("/admin/users/{$maria->id}", ['confirm_email' => $maria->email])->assertForbidden();
        $this->as($grace)->get("/admin/users/{$maria->id}?tab=security")->assertOk()->assertDontSee('id="del-dlg"', false)->assertSee('Remove from organization');
        $this->assertNotNull($maria->fresh());

        // Never yourself; the typed email must match.
        $this->as($this->owner)->delete("/admin/users/{$this->owner->id}", ['confirm_email' => $this->owner->email])->assertStatus(422);
        $this->as($this->owner)->delete("/admin/users/{$maria->id}", ['confirm_email' => 'someone@else.test'])->assertSessionHasErrors('confirm_email');
        $this->assertNotNull($maria->fresh());

        $this->as($this->owner)->delete("/admin/users/{$maria->id}", ['confirm_email' => 'MARIA@cakesbygrace.ph'])->assertRedirect('/admin/users')->assertSessionHas('status');
        $this->assertNull(User::find($maria->id));
        $gone = User::withTrashed()->find($maria->id);
        $this->assertNotNull($gone->deleted_at);
        $this->assertStringEndsWith('@deleted.invalid', $gone->email);
        $this->assertFalse(\App\Models\Grant::where('user_id', $maria->id)->exists());
        $this->assertFalse(\App\Models\ClientSession::where('user_id', $maria->id)->exists());
        $this->assertSame(0, $gone->organizations()->count());
        $this->assertTrue(AuditEvent::where('event', 'user.deleted')->where('subject_id', $maria->id)->exists());
    }

    public function test_the_last_clauth_owner_cannot_be_deleted(): void
    {
        $other = $this->makeUser('second@thecakelabs.com', ['name' => 'Second', 'clauth_role' => 'owner']);
        $other->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        // Two owners: either may delete the other — but after that the one left is the last owner and can't go.
        $this->as($other)->delete("/admin/users/{$this->owner->id}", ['confirm_email' => $this->owner->email])->assertRedirect();
        $this->assertNull(User::find($this->owner->id));
        $this->assertSame(1, User::where('clauth_role', 'owner')->count());
        $this->as($other)->get("/admin/users/{$other->id}?tab=security")->assertOk()->assertDontSee('id="del-dlg"', false);
    }

    public function test_invite_personal_note_is_saved_and_emailed(): void
    {
        Mail::fake();
        $this->as($this->owner)->get('/admin/users?invite=1')->assertSee('Add a personal note')->assertSee('name="note"', false);
        $this->as($this->owner)->post('/admin/users/invite', ['emails' => 'ana@cakesbygrace.ph', 'organization_id' => $this->cbg->id,
            'note' => "Welcome to the team!\nSee you Monday.", 'send' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $invite = Invite::where('email', 'ana@cakesbygrace.ph')->first();
        $this->assertSame("Welcome to the team!\nSee you Monday.", $invite->note);
        Mail::assertSent(\App\Mail\InviteMail::class, function ($m) {
            $html = $m->render();

            return str_contains($html, 'A note from William') && str_contains($html, 'See you Monday.');
        });
        // Resending keeps the note.
        $ana = User::where('email', 'ana@cakesbygrace.ph')->first();
        $this->as($this->owner)->post("/admin/users/{$ana->id}/reinvite");
        $this->assertSame("Welcome to the team!\nSee you Monday.", Invite::whereNull('revoked_at')->where('email', 'ana@cakesbygrace.ph')->value('note'));

        $this->as($this->owner)->post('/admin/users/invite', ['emails' => 'bo@cakesbygrace.ph', 'organization_id' => $this->cbg->id, 'note' => str_repeat('x', 501)])
            ->assertSessionHasErrors('note');
        $this->assertFalse(Invite::where('email', 'bo@cakesbygrace.ph')->exists());
    }
}
