<?php

namespace Tests\Feature;

use App\Models\AccessProfile;
use App\Models\Application;
use App\Models\AppRole;
use App\Models\Grant;
use App\Models\GrantOverride;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\DB;

/** Access profiles R3: limits, starter profiles, new-app prompt, Suggest profiles, Who has what (SPEC-roles §9, §11). */
class AccessProfilesR3Test extends ClauthTestCase
{
    private Application $erp;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->erp = Application::create(['key' => 'alab-erp', 'name' => 'Alab ERP', 'status' => 'live', 'owner_organization_id' => $this->cbg->id]);
        $this->erp->organizations()->sync([$this->cbg->id]);
        foreach ([['admin', 'owner'], ['accounting', 'admin'], ['production', 'member'], ['auditor', 'viewer']] as $i => [$k, $t]) {
            AppRole::create(['app_id' => $this->erp->id, 'key' => $k, 'name' => ucfirst($k), 'tier' => $t, 'position' => $i]);
        }
        $this->role('finance')->update(['tier' => 'admin', 'position' => 0]);
        $this->role('member')->update(['tier' => 'member', 'position' => 1]);
        $this->owner = $this->makeUser('william@thecakelabs.com', ['name' => 'William', 'clauth_role' => 'owner']);
        $this->owner->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
    }

    private function erpRole(string $key): AppRole
    {
        return AppRole::where('app_id', $this->erp->id)->where('key', $key)->firstOrFail();
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withSession(['auth.mfa' => true]);
    }

    /** Someone with access set the old way (exceptions only), as after the R2 migration. */
    private function legacy(string $email, array $roles): User
    {
        $u = $this->makeUser($email, ['name' => ucfirst(strstr($email, '@', true))]);
        $u->organizations()->syncWithoutDetaching([$this->cbg->id]);
        foreach ($roles as $appId => $role) {
            GrantOverride::create(['user_id' => $u->id, 'organization_id' => $this->cbg->id, 'app_id' => $appId, 'app_role_id' => $role->id]);
            Grant::create(['user_id' => $u->id, 'organization_id' => $this->cbg->id, 'app_id' => $appId, 'app_role_id' => $role->id, 'source' => 'override']);
        }

        return $u;
    }

    private function grantKeys(User $u): array
    {
        return Grant::with('role', 'app')->where('user_id', $u->id)->get()->mapWithKeys(fn ($g) => [$g->app->key => $g->role->key])->sort()->all();
    }

    public function test_profile_limit_for_unverified_organizations(): void
    {
        $org = Organization::create(['slug' => 'outside', 'name' => 'Outside Co']);
        $boss = $this->makeUser('boss@outside.test');
        $boss->organizations()->attach($org->id, ['role' => 'owner']);
        $boss->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        foreach (range(1, 10) as $i) {
            AccessProfile::create(['organization_id' => $org->id, 'key' => "p$i", 'name' => "P$i"]);
        }
        $this->as($boss)->post("/admin/orgs/{$org->id}/profiles", ['name' => 'One more'])->assertSessionHasErrors('name');
        $this->assertSame(10, AccessProfile::where('organization_id', $org->id)->count());
    }

    public function test_new_organization_gets_owner_and_member_profiles_and_its_owner_invite_uses_owner(): void
    {
        $this->as($this->owner)->post('/admin/orgs', ['name' => 'New Co', 'slug' => 'new-co'])->assertRedirect();
        $org = Organization::where('slug', 'new-co')->firstOrFail();
        $this->assertSame(['member', 'owner'], AccessProfile::where('organization_id', $org->id)->pluck('key')->sort()->values()->all());

        // Installing an app later: the owner profile is suggested the app's owner-tier role, member its member-tier role.
        DB::table('app_installations')->insert(['app_id' => $this->erp->id, 'organization_id' => $org->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $owner = AccessProfile::where('organization_id', $org->id)->where('key', 'owner')->first();
        $member = AccessProfile::where('organization_id', $org->id)->where('key', 'member')->first();
        $erp = $this->erp->load('roles');
        $this->assertSame('admin', Access::suggestFor($owner, $erp)->key);
        $this->assertSame('production', Access::suggestFor($member, $erp)->key);
    }

    public function test_new_app_banner_and_set_for_every_profile_with_suggestions(): void
    {
        $finance = AccessProfile::create(['organization_id' => $this->cbg->id, 'key' => 'finance', 'name' => 'Finance']);
        DB::table('access_profile_roles')->insert(['access_profile_id' => $finance->id, 'app_id' => $this->xp->id, 'app_role_id' => $this->role('finance')->id]);
        $maria = $this->makeUser();
        $maria->organizations()->attach($this->cbg->id);
        Access::setProfiles($maria, $this->cbg, [$finance->id], $this->owner);

        $this->as($this->owner)->get("/admin/orgs/{$this->cbg->id}/profiles")->assertOk()->assertSee('Set Alab ERP for every profile');
        // Finance's highest tier is admin → ERP's admin-tier role (accounting) is suggested.
        $this->as($this->owner)->get("/admin/orgs/{$this->cbg->id}/profiles/apps/alab-erp")->assertOk()->assertSee('suggested');
        $this->assertSame('accounting', Access::suggestFor($finance, $this->erp->load('roles'))->key);

        $this->as($this->owner)->put("/admin/orgs/{$this->cbg->id}/profiles/apps/alab-erp", ['map' => [$finance->id => $this->erpRole('accounting')->id]])->assertRedirect();
        $this->assertSame(['alab-erp' => 'accounting', 'xpenses' => 'finance'], $this->grantKeys($maria));
        $this->as($this->owner)->get("/admin/orgs/{$this->cbg->id}/profiles")->assertDontSee('Set Alab ERP for every profile');
    }

    public function test_suggest_profiles_groups_people_and_accepting_changes_nobodys_access(): void
    {
        $a = $this->legacy('ana@cakesbygrace.ph', [$this->xp->id => $this->role('finance'), $this->erp->id => $this->erpRole('accounting')]);
        $b = $this->legacy('ben@cakesbygrace.ph', [$this->xp->id => $this->role('finance'), $this->erp->id => $this->erpRole('accounting')]);
        $c = $this->legacy('cy@cakesbygrace.ph', [$this->erp->id => $this->erpRole('production')]);
        $before = [$this->grantKeys($a), $this->grantKeys($b), $this->grantKeys($c)];

        $s = Access::suggestions($this->cbg, $this->owner);
        $this->assertCount(2, $s);
        $this->assertSame(2, $s[0]['people']->count());                 // biggest group first
        $this->assertSame('Xpenses Finance + Alab ERP Accounting', $s[0]['name']);
        $this->as($this->owner)->get("/admin/orgs/{$this->cbg->id}/profiles/suggest")->assertOk()->assertSee('Create and give to 2 people');

        $this->as($this->owner)->post("/admin/orgs/{$this->cbg->id}/profiles/suggest", ['key' => $s[0]['key'], 'name' => 'Finance'])->assertRedirect();
        $p = AccessProfile::where('key', 'finance')->firstOrFail();
        $this->assertEquals([$this->erp->id => $this->erpRole('accounting')->id, $this->xp->id => $this->role('finance')->id], $p->mapping());
        $this->assertSame($before, [$this->grantKeys($a), $this->grantKeys($b), $this->grantKeys($c)]);
        $this->assertSame(0, GrantOverride::whereIn('user_id', [$a->id, $b->id])->count()); // covered → exceptions gone
        $this->assertSame(1, GrantOverride::where('user_id', $c->id)->count());
        $this->assertSame('profile', Grant::where('user_id', $a->id)->where('app_id', $this->xp->id)->value('source'));
        $this->assertCount(1, Access::suggestions($this->cbg, $this->owner)); // only Cy left
    }

    public function test_suggestion_with_owner_tier_needs_an_org_owner_and_untiered_roles_block_it(): void
    {
        $this->legacy('ana@cakesbygrace.ph', [$this->erp->id => $this->erpRole('admin')]);
        $admin = $this->makeUser('adm@cakesbygrace.ph');
        $admin->organizations()->attach($this->cbg->id, ['role' => 'admin']);
        $admin->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $s = Access::suggestions($this->cbg, $admin)[0];
        $this->assertStringContainsString('only an owner', $s['blocked']);
        $this->as($admin)->post("/admin/orgs/{$this->cbg->id}/profiles/suggest", ['key' => $s['key'], 'name' => 'Owners'])->assertStatus(422);

        $this->erpRole('admin')->update(['tier' => null]);
        $this->assertStringContainsString('Set a tier', Access::suggestions($this->cbg, $this->owner)[0]['blocked']);
    }

    public function test_who_has_what_shows_sources_filters_and_csv(): void
    {
        $finance = AccessProfile::create(['organization_id' => $this->cbg->id, 'key' => 'finance', 'name' => 'Finance']);
        DB::table('access_profile_roles')->insert(['access_profile_id' => $finance->id, 'app_id' => $this->xp->id, 'app_role_id' => $this->role('finance')->id]);
        $maria = $this->makeUser();
        $maria->organizations()->attach($this->cbg->id);
        Access::setProfiles($maria, $this->cbg, [$finance->id], $this->owner);
        $this->legacy('ana@cakesbygrace.ph', [$this->erp->id => $this->erpRole('production')]);

        $url = "/admin/orgs/{$this->cbg->id}/alignment";
        $this->as($this->owner)->get($url)->assertOk()->assertSee('Maria Santos')->assertSee('Ana')->assertSee('exception');
        $this->as($this->owner)->get("$url?filter=exceptions")->assertOk()->assertSee('ana@cakesbygrace.ph')->assertDontSee('maria@cakesbygrace.ph');
        $this->as($this->owner)->get("$url?filter=notset")->assertOk()->assertSee('maria@cakesbygrace.ph');   // Finance hasn't set ERP
        $this->as($this->owner)->get("$url?filter=noprofile")->assertOk()->assertSee('ana@cakesbygrace.ph')->assertDontSee('maria@cakesbygrace.ph');
        $csv = $this->as($this->owner)->get("$url.csv")->assertOk()->getContent();
        $this->assertStringContainsString('"Maria Santos",maria@cakesbygrace.ph,Finance', $csv);
        $this->assertStringContainsString('Production (exception)', $csv);
    }
}
