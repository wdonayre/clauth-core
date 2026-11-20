<?php

namespace Tests\Feature;

use App\Models\AccessProfile;
use App\Models\Application;
use App\Models\AppRole;
use App\Models\Grant;
use App\Models\GrantOverride;
use App\Models\Invite;
use App\Models\User;
use App\Services\Access;
use App\Services\Claims;
use App\Services\Installations;
use App\Services\Invites;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\DB;

/** Access profiles R2 (SPEC-roles §6, §9–§11, §13). */
class AccessProfilesTest extends ClauthTestCase
{
    private Application $erp;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        // Second app in CakesbyGrace with its own role names.
        $this->erp = Application::create(['key' => 'alab-erp', 'name' => 'Alab ERP', 'status' => 'live', 'owner_organization_id' => $this->cbg->id]);
        $this->erp->organizations()->sync([$this->cbg->id]);
        foreach ([['admin', 'owner'], ['accounting', 'admin'], ['production', 'member'], ['sales', 'member']] as $i => [$k, $t]) {
            AppRole::create(['app_id' => $this->erp->id, 'key' => $k, 'name' => ucfirst($k), 'tier' => $t, 'position' => $i]);
        }
        $this->role('finance')->update(['tier' => 'admin', 'position' => 0]);
        $this->role('member')->update(['tier' => 'member', 'position' => 1]);
        AppRole::create(['app_id' => $this->xp->id, 'key' => 'owner', 'name' => 'Owner', 'tier' => 'owner', 'position' => 2]);

        $this->owner = $this->makeUser('william@thecakelabs.com', ['name' => 'William', 'clauth_role' => 'owner']);
        $this->owner->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
    }

    private function erpRole(string $key): AppRole
    {
        return AppRole::where('app_id', $this->erp->id)->where('key', $key)->firstOrFail();
    }

    private function profile(string $name, array $map): AccessProfile
    {
        $p = AccessProfile::create(['organization_id' => $this->cbg->id, 'key' => strtolower($name), 'name' => $name]);
        foreach ($map as $appId => $roleId) {
            DB::table('access_profile_roles')->insert(['access_profile_id' => $p->id, 'app_id' => $appId, 'app_role_id' => $roleId]);
        }

        return $p;
    }

    private function member(string $email = 'maria@cakesbygrace.ph', string $role = 'member'): User
    {
        $u = $this->makeUser($email);
        $u->organizations()->attach($this->cbg->id, ['role' => $role]);

        return $u;
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withSession(['auth.mfa' => true]);
    }

    private function grantKey(User $u, Application $app): ?string
    {
        return Grant::with('role')->where(['user_id' => $u->id, 'app_id' => $app->id, 'organization_id' => $this->cbg->id])->first()?->role?->key;
    }

    public function test_a_profile_gives_the_mapped_role_in_every_app_and_tokens_stay_the_same_shape(): void
    {
        $finance = $this->profile('Finance', [$this->xp->id => $this->role('finance')->id, $this->erp->id => $this->erpRole('accounting')->id]);
        $maria = $this->member();
        Access::setProfiles($maria, $this->cbg, [$finance->id], $this->owner);

        $this->assertSame('finance', $this->grantKey($maria, $this->xp));
        $this->assertSame('accounting', $this->grantKey($maria, $this->erp));
        $orgs = Claims::orgs($maria, $this->xp);
        $this->assertSame(['finance'], $orgs['cakesbygrace']['roles']);
        $this->assertStringNotContainsString('Finance"', json_encode($orgs)); // no profile name in the token claim
        $this->assertSame('profile', Grant::where('user_id', $maria->id)->where('app_id', $this->xp->id)->value('source'));
    }

    public function test_two_profiles_higher_tier_wins_and_equal_tiers_use_publisher_order_with_conflict_flag(): void
    {
        $finance = $this->profile('Finance', [$this->erp->id => $this->erpRole('accounting')->id]);
        $prod = $this->profile('Production', [$this->erp->id => $this->erpRole('production')->id]);
        $sales = $this->profile('Sales', [$this->erp->id => $this->erpRole('sales')->id]);
        $maria = $this->member();

        Access::setProfiles($maria, $this->cbg, [$finance->id, $prod->id], $this->owner);
        $this->assertSame('accounting', $this->grantKey($maria, $this->erp));

        Access::setProfiles($maria, $this->cbg, [$prod->id, $sales->id], $this->owner);
        $this->assertSame('production', $this->grantKey($maria, $this->erp)); // listed first by the publisher
        $this->assertTrue(Access::explain($maria, $this->cbg)[$this->erp->id]['conflict']);
    }

    public function test_override_beats_profiles_and_clearing_it_restores_the_profile_role(): void
    {
        $finance = $this->profile('Finance', [$this->xp->id => $this->role('finance')->id]);
        $maria = $this->member();
        Access::setProfiles($maria, $this->cbg, [$finance->id], $this->owner);

        $this->as($this->owner)->post("/admin/users/{$maria->id}/grants", ['organization_id' => $this->cbg->id, 'profiles_shown' => 1,
            'profiles' => [$finance->id], 'roles' => [$this->xp->id => 'none'], 'reason' => 'On leave'])->assertRedirect();
        $this->assertNull($this->grantKey($maria, $this->xp));
        $this->assertSame('On leave', GrantOverride::where('user_id', $maria->id)->value('reason'));

        $this->as($this->owner)->post("/admin/users/{$maria->id}/grants", ['organization_id' => $this->cbg->id, 'profiles_shown' => 1,
            'profiles' => [$finance->id], 'roles' => [$this->xp->id => 'profile']])->assertRedirect();
        $this->assertSame('finance', $this->grantKey($maria, $this->xp));
        $this->assertSame(0, GrantOverride::count());
    }

    public function test_changing_a_mapping_updates_every_holder_and_no_access_signs_them_out(): void
    {
        $finance = $this->profile('Finance', [$this->xp->id => $this->role('finance')->id]);
        $maria = $this->member();
        $jo = $this->member('jo@cakesbygrace.ph');
        Access::setProfiles($maria, $this->cbg, [$finance->id], $this->owner);
        Access::setProfiles($jo, $this->cbg, [$finance->id], $this->owner);

        $this->as($this->owner)->put("/admin/orgs/{$this->cbg->id}/profiles/{$finance->id}", ['name' => 'Finance',
            'map' => [$this->xp->id => $this->role('member')->id, $this->erp->id => '']])->assertRedirect();
        $this->assertSame(['member', 'member'], [$this->grantKey($maria, $this->xp), $this->grantKey($jo, $this->xp)]);

        $this->as($this->owner)->put("/admin/orgs/{$this->cbg->id}/profiles/{$finance->id}", ['name' => 'Finance',
            'map' => [$this->xp->id => 'none']])->assertRedirect();
        $this->assertNull($this->grantKey($maria, $this->xp));
        $this->assertTrue(\App\Models\AuditEvent::where('event', 'grant.resolved')->exists());
        $this->assertTrue(\App\Models\AuditEvent::where('event', 'profile.mapping_changed')->exists());
    }

    public function test_roles_without_a_tier_or_not_assignable_cannot_be_mapped(): void
    {
        $p = $this->profile('Ops', []);
        $loose = AppRole::create(['app_id' => $this->erp->id, 'key' => 'temp', 'name' => 'Temp']);
        $this->as($this->owner)->put("/admin/orgs/{$this->cbg->id}/profiles/{$p->id}", ['name' => 'Ops', 'map' => [$this->erp->id => $loose->id]])->assertStatus(422);
        $this->erpRole('sales')->update(['assignable' => false]);
        $this->as($this->owner)->put("/admin/orgs/{$this->cbg->id}/profiles/{$p->id}", ['name' => 'Ops', 'map' => [$this->erp->id => $this->erpRole('sales')->id]])->assertStatus(422);
    }

    public function test_org_admin_cannot_touch_owner_tier_but_org_owner_can(): void
    {
        $ana = $this->member('ana@cakesbygrace.ph', 'admin');
        $ana->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $boss = $this->member('boss@cakesbygrace.ph', 'owner');
        $boss->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $p = $this->profile('Owners', []);
        $maria = $this->member();

        $this->as($ana)->put("/admin/orgs/{$this->cbg->id}/profiles/{$p->id}", ['name' => 'Owners', 'map' => [$this->erp->id => $this->erpRole('admin')->id]])->assertStatus(403);
        $this->as($boss)->put("/admin/orgs/{$this->cbg->id}/profiles/{$p->id}", ['name' => 'Owners', 'map' => [$this->erp->id => $this->erpRole('admin')->id]])->assertRedirect();
        $this->as($ana)->post("/admin/orgs/{$this->cbg->id}/profiles/{$p->id}/people", ['user_id' => $maria->id])->assertStatus(403);
        $this->as($ana)->post("/admin/users/{$maria->id}/grants", ['organization_id' => $this->cbg->id, 'roles' => [$this->xp->id => $this->role('owner')->id]])->assertStatus(403);
        $this->as($boss)->post("/admin/orgs/{$this->cbg->id}/profiles/{$p->id}/people", ['user_id' => $maria->id])->assertRedirect();
        $this->assertSame('admin', $this->grantKey($maria, $this->erp));
    }

    public function test_unmapped_app_gives_no_access_and_is_flagged(): void
    {
        $finance = $this->profile('Finance', [$this->xp->id => $this->role('finance')->id]);
        $maria = $this->member();
        Access::setProfiles($maria, $this->cbg, [$finance->id], $this->owner);

        $this->assertNull($this->grantKey($maria, $this->erp));
        $this->assertSame(['Finance'], Access::explain($maria, $this->cbg)[$this->erp->id]['unmapped']);
        $this->as($this->owner)->get("/admin/users/{$maria->id}")->assertOk()->assertSee('profile not set for this app')->assertSee('from Finance');
        $this->as($this->owner)->get("/admin/orgs/{$this->cbg->id}/profiles")->assertOk()->assertSee('1/2 apps set');
        $this->as($this->owner)->get("/admin/orgs/{$this->cbg->id}/profiles/{$finance->id}")->assertOk()->assertSee('not set — holders get no access yet');
    }

    public function test_migration_turned_existing_grants_into_overrides_without_changing_access(): void
    {
        // Grants created the old way (as before R2) …
        $maria = $this->member();
        $this->grant($maria, $this->cbg, 'finance');
        GrantOverride::create(['user_id' => $maria->id, 'organization_id' => $this->cbg->id, 'app_id' => $this->xp->id, 'app_role_id' => $this->role('finance')->id]);
        // … resolve to exactly the same grant.
        $this->assertSame([], Access::resolve($maria, $this->cbg, 'test'));
        $this->assertSame('finance', $this->grantKey($maria, $this->xp));
    }

    public function test_invite_with_profile_and_exception(): void
    {
        $finance = $this->profile('Finance', [$this->xp->id => $this->role('finance')->id, $this->erp->id => $this->erpRole('accounting')->id]);
        $this->as($this->owner)->post('/admin/users/invite', ['name' => 'Jo', 'email' => 'jo@cakesbygrace.ph', 'organization_id' => $this->cbg->id,
            'profiles' => [$finance->id], 'roles' => [$this->erp->id => $this->erpRole('sales')->id], 'send' => 0])->assertRedirect();
        $invite = Invite::where('email', 'jo@cakesbygrace.ph')->firstOrFail();
        $this->assertSame([$finance->id], $invite->profiles);

        $jo = User::where('email', 'jo@cakesbygrace.ph')->firstOrFail();
        $jo->update(['status' => 'active']);
        Invites::apply($invite, $jo);
        $this->assertSame('finance', $this->grantKey($jo, $this->xp));
        $this->assertSame('sales', $this->grantKey($jo, $this->erp)); // exception chosen in the invite
    }

    public function test_leaving_the_org_or_uninstalling_the_app_cleans_up(): void
    {
        $finance = $this->profile('Finance', [$this->xp->id => $this->role('finance')->id, $this->erp->id => $this->erpRole('accounting')->id]);
        $maria = $this->member();
        Access::setProfiles($maria, $this->cbg, [$finance->id], $this->owner);
        GrantOverride::create(['user_id' => $maria->id, 'organization_id' => $this->cbg->id, 'app_id' => $this->erp->id, 'app_role_id' => null]);

        Installations::remove($this->erp, $this->cbg);
        $this->assertFalse(DB::table('access_profile_roles')->where('app_id', $this->erp->id)->exists());
        $this->assertSame(0, GrantOverride::count());

        $this->as($this->owner)->delete("/admin/users/{$maria->id}/orgs", ['organization_id' => $this->cbg->id])->assertRedirect();
        $this->assertSame(0, Grant::where('user_id', $maria->id)->count());
        $this->assertSame(0, $maria->accessProfiles()->count());
    }

    public function test_deleting_a_profile_removes_the_access_it_gave(): void
    {
        $finance = $this->profile('Finance', [$this->xp->id => $this->role('finance')->id]);
        $maria = $this->member();
        Access::setProfiles($maria, $this->cbg, [$finance->id], $this->owner);

        $this->as($this->owner)->delete("/admin/orgs/{$this->cbg->id}/profiles/{$finance->id}")->assertRedirect();
        $this->assertNull($this->grantKey($maria, $this->xp));
        $this->assertSame(0, AccessProfile::count());
    }

    public function test_creating_a_profile_and_adding_a_person_through_the_screens(): void
    {
        $maria = $this->member();
        $this->as($this->owner)->post("/admin/orgs/{$this->cbg->id}/profiles", ['name' => 'Production'])->assertRedirect();
        $p = AccessProfile::where('key', 'production')->firstOrFail();
        $this->as($this->owner)->put("/admin/orgs/{$this->cbg->id}/profiles/{$p->id}", ['name' => 'Production',
            'map' => [$this->xp->id => $this->role('member')->id, $this->erp->id => $this->erpRole('production')->id]])->assertRedirect();
        $this->as($this->owner)->post("/admin/orgs/{$this->cbg->id}/profiles/{$p->id}/people", ['user_id' => $maria->id])->assertRedirect();

        $this->assertSame(['member', 'production'], [$this->grantKey($maria, $this->xp), $this->grantKey($maria, $this->erp)]);
        $this->as($this->owner)->delete("/admin/orgs/{$this->cbg->id}/profiles/{$p->id}/people/{$maria->id}")->assertRedirect();
        $this->assertNull($this->grantKey($maria, $this->erp));
    }
}
