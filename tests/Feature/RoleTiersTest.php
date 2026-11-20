<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AppRole;
use App\Models\User;
use App\Services\Claims;
use App\Services\Manifest;
use App\Services\Settings;
use App\Services\TwoFactor;

/** Access profiles R1: role tiers, old keys, "can be assigned", the Access role and the tier 2-step rule (SPEC-roles). */
class RoleTiersTest extends ClauthTestCase
{
    private function manifest(array $roles): array
    {
        return ['app' => 'xpenses', 'permissions' => [['key' => 'expense.submit'], ['key' => 'expense.approve'],
            ['key' => 'expense.view_all'], ['key' => 'report.export']], 'roles' => $roles];
    }

    private function owner(): User
    {
        if ($u = User::where('email', 'william@thecakelabs.com')->first()) {
            return $u;
        }
        $u = $this->makeUser('william@thecakelabs.com', ['name' => 'William', 'clauth_role' => 'owner']);
        $u->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();

        return $u;
    }

    public function test_manifest_sets_tier_aliases_and_assignable(): void
    {
        Manifest::apply($this->xp, $this->manifest([
            ['key' => 'finance', 'tier' => 'admin', 'permissions' => ['expense.view_all'], 'aliases' => ['accounts', 'FINANCE', 'bad key!']],
            ['key' => 'member', 'tier' => 'member', 'permissions' => ['expense.submit']],
            ['key' => 'old_ops', 'tier' => 'nonsense', 'permissions' => [], 'assignable' => false],
        ]));

        $this->assertSame('admin', $this->role('finance')->tier);
        $this->assertSame('manifest', $this->role('finance')->tier_source);
        $this->assertSame(['accounts'], $this->role('finance')->aliases); // own key and invalid keys dropped
        $old = $this->role('old_ops');
        $this->assertNull($old->tier);                                      // unknown tier ignored → "no tier"
        $this->assertFalse($old->assignable);
    }

    public function test_tier_set_by_hand_survives_a_sync_and_is_reported(): void
    {
        $this->role('finance')->update(['tier' => 'owner', 'tier_source' => 'manual']);
        Manifest::apply($this->xp, $this->manifest([['key' => 'finance', 'tier' => 'admin', 'permissions' => ['expense.view_all']]]));

        $this->assertSame('owner', $this->role('finance')->tier);
        $this->assertSame(['manifest' => 'admin', 'clauth' => 'owner'], $this->xp->fresh()->manifest_diff['finance']['tier']);
        $this->as($this->owner())->get('/admin/apps/xpenses')->assertOk()->assertSee('tier — manifest');
    }

    public function test_token_sends_old_keys_of_a_renamed_role(): void
    {
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'finance');
        $this->role('finance')->update(['aliases' => ['accounts']]);

        $this->assertSame(['finance', 'accounts'], Claims::orgs($user, $this->xp)['cakesbygrace']['roles']);
    }

    public function test_app_without_roles_gets_an_access_role(): void
    {
        $app = Application::create(['key' => 'legacy', 'name' => 'Legacy', 'status' => 'live', 'owner_organization_id' => $this->cbg->id]);
        Manifest::apply($app, ['app' => 'legacy', 'permissions' => [], 'roles' => []]);

        $access = AppRole::where('app_id', $app->id)->sole();
        $this->assertSame(['access', 'member', 'system'], [$access->key, $access->tier, $access->source]);

        // and by hand, for an app with no manifest
        $other = Application::create(['key' => 'legacy2', 'name' => 'Legacy 2', 'status' => 'live', 'owner_organization_id' => $this->cbg->id]);
        $this->as($this->owner())->post('/admin/apps/legacy2/roles-access')->assertRedirect();
        $this->assertTrue(AppRole::where('app_id', $other->id)->where('key', 'access')->exists());
        $this->as($this->owner())->post('/admin/apps/legacy2/roles-access')->assertStatus(422);
    }

    public function test_admin_can_set_tier_and_old_keys_on_a_manual_role(): void
    {
        $role = $this->role('member');
        $this->as($this->owner())->patch("/admin/apps/xpenses/roles/{$role->id}", ['tier' => 'viewer', 'aliases' => 'staff, employee'])->assertRedirect();

        $role->refresh();
        $this->assertSame(['viewer', 'manual'], [$role->tier, $role->tier_source]);
        $this->assertSame(['staff', 'employee'], $role->aliases);
        $this->assertFalse($role->assignable); // checkbox left unticked
        $this->as($this->owner())->patch("/admin/apps/xpenses/roles/{$role->id}", ['tier' => 'boss'])->assertSessionHasErrors('tier');
    }

    public function test_manifest_roles_keep_their_old_keys_from_the_manifest(): void
    {
        Manifest::apply($this->xp, $this->manifest([['key' => 'approver', 'tier' => 'admin', 'permissions' => [], 'aliases' => ['accounts']]]));
        $role = $this->role('approver');
        $this->assertSame('manifest', $role->source);

        $this->as($this->owner())->patch("/admin/apps/xpenses/roles/{$role->id}", ['tier' => 'owner', 'aliases' => 'other'])->assertRedirect();
        $role->refresh();
        $this->assertSame('owner', $role->tier);     // tier can be changed in Clauth
        $this->assertSame(['accounts'], $role->aliases); // old keys stay as the manifest says
        $this->assertTrue($role->assignable);
    }

    public function test_tier_rule_requires_2_step_for_admin_tiers_with_preview(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'finance');
        $jo = $this->makeUser('jo@cakesbygrace.ph', ['name' => 'Jo']);
        $this->grant($jo, $this->cbg, 'member');
        $this->role('finance')->update(['tier' => 'admin']);
        $this->role('member')->update(['tier' => 'member']);

        $this->assertFalse(Claims::policyRequiresMfa($maria, $this->xp));
        $this->assertSame([$maria->id], Claims::tierRulePreview()->pluck('id')->all());
        $this->as($this->owner())->get('/admin/settings')->assertOk()->assertSee('asks 1 person')->assertSee('Xpenses Finance');

        Settings::set(['require_mfa_admin_tiers' => true]);
        $this->assertTrue(Claims::policyRequiresMfa($maria, $this->xp));
        $this->assertTrue(Claims::policyRequiresMfa($maria));
        $this->assertFalse(Claims::policyRequiresMfa($jo, $this->xp));

        $maria->update(['mfa_exempt' => true]);
        $this->assertFalse(Claims::policyRequiresMfa($maria->fresh(), $this->xp)); // exemption still wins
    }

    public function test_people_already_covered_by_an_app_rule_are_not_in_the_preview(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'finance');
        $this->role('finance')->update(['tier' => 'admin']);
        $this->xp->update(['require_mfa_roles' => ['finance']]);

        $this->assertTrue(Claims::tierRulePreview()->isEmpty());
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withSession(['auth.mfa' => true]);
    }
}
