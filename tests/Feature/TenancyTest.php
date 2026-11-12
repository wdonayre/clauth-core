<?php

namespace Tests\Feature;

use App\Models\AppDomain;
use App\Models\Application;
use App\Models\AppRole;
use App\Models\Grant;
use App\Models\Invite;
use App\Models\Organization;
use App\Models\ReleaseNote;
use App\Models\User;
use App\Services\Installations;
use App\Services\SigningKeys;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/** M7 (SPEC-organizations §13): organizations are tenants; nobody reaches another organization's things. */
class TenancyTest extends ClauthTestCase
{
    private Organization $other;

    private User $bob;      // admin of "Other Co" (an outside organization)

    private User $maria;    // member of CakesbyGrace (William's)

    private Application $foo; // published by Other Co

    protected function setUp(): void
    {
        parent::setUp();
        $this->other = Organization::create(['slug' => 'other-co', 'name' => 'Other Co', 'verified_at' => now()]);
        $this->bob = $this->staff('bob@other.test', 'Bob', $this->other, 'admin');
        $this->maria = $this->makeUser('maria@cakesbygrace.ph', ['name' => 'Maria Santos']);
        $this->grant($this->maria, $this->cbg, 'member');
        $this->foo = Application::create(['key' => 'foo-roster', 'name' => 'Foo Roster', 'status' => 'live', 'owner_organization_id' => $this->other->id]);
        $this->foo->organizations()->attach($this->other->id);
        AppRole::create(['app_id' => $this->foo->id, 'key' => 'member', 'name' => 'Member']);
    }

    private function staff(string $email, string $name, Organization $org, string $role): User
    {
        $u = $this->makeUser($email, ['name' => $name]);
        $u->organizations()->attach($org->id, ['role' => $role]);
        $u->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();

        return $u;
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withSession(['auth.mfa' => true]);
    }

    /** Every admin route, pointed at CakesbyGrace's things, answers 404 to an outside organization's admin. */
    public function test_isolation_matrix_over_every_admin_route(): void
    {
        $domain = AppDomain::create(['host' => 'auth.xpenses.test', 'organization_id' => $this->cbg->id, 'verify_token' => Str::lower(Str::random(32)), 'status' => 'active']);
        $domain->apps()->attach($this->xp->id);
        $key = SigningKeys::generate('staged');
        $note = ReleaseNote::create(['app_id' => $this->xp->id, 'version' => '1.0', 'title' => 'x', 'body' => 'x', 'published_at' => now()]);
        $params = [
            'app' => $this->xp->key, 'id' => $this->maria->id, 'org' => $this->cbg->id, 'client' => $this->client->id,
            'role' => $this->role('member')->id, 'key' => $key->id, 'note' => $note->id, 'action' => 'disable',
        ];
        $listing = ['admin.dashboard', 'admin.users.index', 'admin.apps.index', 'admin.orgs.index', 'admin.audit', 'admin.audit.export'];
        $creates = ['admin.users.invite', 'admin.apps.store'];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! str_starts_with($name, 'admin.') || in_array($name, $creates, true)) {
                continue;
            }
            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            $url = '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $params[$m[1]] ?? 'x', $route->uri());
            $res = $this->as($this->bob)->call($method, $url, ['_token' => csrf_token(), 'organization_id' => $this->cbg->id, 'app_id' => $this->xp->id, 'confirm' => 'xpenses']);
            if (in_array($name, $listing, true)) {
                $res->assertOk();
                $this->assertStringNotContainsString('maria@cakesbygrace.ph', $res->getContent(), "$name leaks a CakesbyGrace person");
                $this->assertStringNotContainsString('Xpenses', $res->getContent(), "$name leaks a CakesbyGrace app");
            } else {
                $this->assertSame(404, $res->status(), "$method $url ($name) should be 404 for another organization's admin");
            }
            $checked++;
        }
        $this->assertGreaterThan(40, $checked);
        $this->assertNotNull($this->xp->fresh(), 'nothing was deleted');
        $this->assertTrue(Grant::where('user_id', $this->maria->id)->exists(), 'nothing was changed');

        // Create routes aimed at CakesbyGrace don't create anything there.
        $this->as($this->bob)->post('/admin/users/invite', ['name' => 'X', 'email' => 'x@x.test', 'organization_id' => $this->cbg->id])->assertSessionHasErrors('organization_id');
        $this->as($this->bob)->post('/admin/apps', ['name' => 'Evil', 'key' => 'evil', 'type' => 'web', 'redirect_uri' => 'https://e.test/cb', 'publisher' => $this->cbg->id])->assertSessionHasErrors('publisher');
        $this->assertSame(0, Invite::count());
        $this->assertNull(Application::where('key', 'evil')->first());

        // …and the matrix isn't vacuous: Bob reaches his own organization's things.
        $this->as($this->bob)->get('/admin/apps/foo-roster')->assertOk();
        $this->as($this->bob)->get('/admin/orgs')->assertOk()->assertSee('Other Co');
        $this->as($this->bob)->post('/admin/apps', ['name' => 'Foo Two', 'key' => 'foo-two', 'type' => 'web', 'redirect_uri' => 'https://e.test/cb', 'publisher' => $this->other->id, 'privacy_url' => 'https://o.test/p', 'terms_url' => 'https://o.test/t', 'support_email' => 'h@o.test'])->assertSessionHasNoErrors();
        $this->assertSame($this->other->id, Application::where('key', 'foo-two')->value('owner_organization_id'));
        $this->assertSame('active', Installations::status(Application::where('key', 'foo-two')->first(), $this->other));
    }

    public function test_developer_manages_apps_but_not_people_or_installs(): void
    {
        $dan = $this->staff('dan@other.test', 'Dan', $this->other, 'developer');
        $this->as($dan)->get('/admin/apps')->assertOk()->assertSee('Foo Roster');
        $this->as($dan)->put('/admin/apps/foo-roster', ['name' => 'Foo Rota', 'status' => 'live', 'color' => '#22D3EE', 'privacy_url' => 'https://o.test/p', 'terms_url' => 'https://o.test/t', 'support_email' => 'h@o.test'])->assertSessionHasNoErrors();
        $this->assertSame('Foo Rota', $this->foo->fresh()->name);
        $this->as($dan)->get('/admin/users')->assertNotFound();
        $this->as($dan)->get('/admin/orgs')->assertNotFound();
        $this->as($dan)->post('/admin/orgs/'.$this->other->id.'/roles', ['email' => 'dan@other.test', 'role' => 'owner'])->assertNotFound();
        $this->as($dan)->delete('/admin/apps/foo-roster', ['confirm' => 'foo-roster'])->assertForbidden();
        $this->as($dan)->get('/admin/settings')->assertNotFound();
    }

    public function test_install_request_needs_the_other_organizations_admin_and_removal_revokes_access(): void
    {
        $grace = $this->staff('grace@cakesbygrace.ph', 'Grace', $this->cbg, 'admin');

        // A private app can't be offered to anyone.
        $this->as($this->bob)->post('/admin/apps/foo-roster/installations', ['slug' => 'cakesbygrace'])->assertStatus(422);
        $this->foo->update(['visibility' => 'installable']);
        $this->as($this->bob)->post('/admin/apps/foo-roster/installations', ['slug' => 'cakesbygrace'])->assertSessionHasNoErrors();
        $this->assertSame('requested', Installations::status($this->foo, $this->cbg));
        // Unknown slugs get the same answer (no probing which organizations exist).
        $this->as($this->bob)->post('/admin/apps/foo-roster/installations', ['slug' => 'nope'])->assertSessionHas('status');

        // Before approval: not usable, grants for it are ignored.
        $this->assertFalse($this->cbg->apps()->where('apps.id', $this->foo->id)->exists());
        $role = AppRole::where('app_id', $this->foo->id)->first();
        $this->as($grace)->post('/admin/users/'.$this->maria->id.'/grants', ['organization_id' => $this->cbg->id, 'roles' => [$this->foo->id => $role->id]]);
        $this->assertFalse(Grant::where('user_id', $this->maria->id)->where('app_id', $this->foo->id)->exists());

        // Grace sees and approves it; Bob can't approve for CakesbyGrace.
        $this->as($grace)->get('/admin/orgs')->assertOk()->assertSee('Foo Roster')->assertSee('Waiting for your approval');
        $this->as($this->bob)->post('/admin/orgs/'.$this->cbg->id.'/requests/'.$this->foo->key)->assertNotFound();
        $this->as($grace)->post('/admin/orgs/'.$this->cbg->id.'/requests/'.$this->foo->key)->assertSessionHasNoErrors();
        $this->assertSame('active', Installations::status($this->foo, $this->cbg));

        $this->as($grace)->post('/admin/users/'.$this->maria->id.'/grants', ['organization_id' => $this->cbg->id, 'roles' => [$this->foo->id => $role->id, $this->xp->id => $this->role('member')->id]]);
        $this->assertTrue(Grant::where('user_id', $this->maria->id)->where('app_id', $this->foo->id)->exists());
        // Bob (publisher) sees that CakesbyGrace uses it, but not who.
        $this->as($this->bob)->get('/admin/apps/foo-roster?tab=settings')->assertOk()->assertSee('CakesbyGrace')->assertDontSee('maria@cakesbygrace.ph');
        $this->as($this->bob)->get('/admin/apps/foo-roster?tab=users')->assertOk()->assertDontSee('maria@cakesbygrace.ph');

        DB::table('oauth_access_tokens')->insert(['id' => 'tok1', 'user_id' => $this->maria->id, 'client_id' => $this->client->id, 'revoked' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->as($grace)->delete('/admin/orgs/'.$this->cbg->id.'/apps/'.$this->foo->key)->assertSessionHasNoErrors();
        $this->assertNull(Installations::status($this->foo, $this->cbg));
        $this->assertFalse(Grant::where('user_id', $this->maria->id)->where('app_id', $this->foo->id)->exists());
        $this->assertTrue(Grant::where('user_id', $this->maria->id)->where('app_id', $this->xp->id)->exists(), 'other apps untouched');
    }

    public function test_a_person_in_two_organizations_shows_only_the_admins_own_organization(): void
    {
        $this->maria->organizations()->attach($this->other->id, ['role' => 'member']);
        $page = $this->as($this->bob)->get('/admin/users/'.$this->maria->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('CakesbyGrace', $page);
        $this->assertStringNotContainsString('Xpenses', $page);
        $this->as($this->bob)->get('/admin/users/'.$this->maria->id.'?org='.$this->cbg->id)->assertOk()->assertDontSee('CakesbyGrace');
        $csv = $this->as($this->bob)->get('/admin/audit.csv')->getContent();
        $this->assertStringNotContainsString('cakesbygrace', strtolower($csv));
    }

    public function test_org_roles_are_set_by_owners_only_and_a_domain_belongs_to_the_apps_publisher(): void
    {
        $this->as($this->bob)->post('/admin/orgs/'.$this->other->id.'/roles', ['email' => 'bob@other.test', 'role' => 'owner'])->assertNotFound(); // admins can't
        $owner = $this->staff('olga@other.test', 'Olga', $this->other, 'owner');
        $this->as($owner)->post('/admin/orgs/'.$this->other->id.'/roles', ['email' => 'bob@other.test', 'role' => 'developer'])->assertSessionHasNoErrors();
        $this->assertSame('developer', $this->bob->orgRole($this->other->id));
        $this->as($owner)->post('/admin/orgs/'.$this->other->id.'/roles', ['email' => 'maria@cakesbygrace.ph', 'role' => 'admin'])->assertSessionHasErrors('email');

        // CakesbyGrace's verified domain can't be attached to Other Co's app.
        $d = AppDomain::create(['host' => 'auth.cakes.test', 'organization_id' => $this->cbg->id, 'verify_token' => Str::lower(Str::random(32)), 'status' => 'active']);
        $d->apps()->attach($this->xp->id);
        $this->as($owner)->post('/admin/apps/foo-roster/domain', ['host' => 'auth.cakes.test', 'share' => 1])->assertSessionHasErrors('host');
        $this->assertFalse($d->fresh()->serves($this->foo));
        // Outside organizations' domains are untrusted; William's are trusted.
        $this->assertTrue($d->isTrusted());
        $mine = AppDomain::create(['host' => 'auth.foo.test', 'organization_id' => $this->other->id, 'verify_token' => Str::lower(Str::random(32)), 'status' => 'active']);
        $this->assertFalse($mine->isTrusted());
    }
}
