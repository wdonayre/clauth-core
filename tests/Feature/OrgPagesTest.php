<?php

namespace Tests\Feature;

use App\Models\AccessProfile;
use App\Models\AppDomain;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationRequest;
use App\Models\User;
use App\Services\Access;
use App\Services\TwoFactor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Console redesign 2026-11: organizations list, one page per organization tab, and the request review page. */
class OrgPagesTest extends ClauthTestCase
{
    private User $owner;   // Clauth owner

    private Organization $foo;

    private User $olga;    // owner of Foo Ltd (outside organization)

    private User $adam;    // admin of Foo Ltd

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->staff('owner@clauth.test', 'William', null, null, ['clauth_role' => 'owner']);
        $this->foo = Organization::create(['slug' => 'foo-ltd', 'name' => 'Foo Ltd', 'terms_version' => config('clauth.terms_version')]);
        $this->olga = $this->staff('olga@foo.test', 'Olga', $this->foo, 'owner');
        $this->adam = $this->staff('adam@foo.test', 'Adam', $this->foo, 'admin');
        $this->grant($this->makeUser('maria@cakesbygrace.ph'), $this->cbg, 'member');
    }

    private function staff(string $email, string $name, ?Organization $org, ?string $role, array $attrs = []): User
    {
        $u = $this->makeUser($email, ['name' => $name] + $attrs);
        if ($org) {
            $u->organizations()->attach($org->id, ['role' => $role]);
        }
        $u->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();

        return $u;
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withSession(['auth.mfa' => true]);
    }

    public function test_list_shows_counts_and_links_to_each_organization(): void
    {
        $this->as($this->owner)->get('/admin/orgs')->assertOk()
            ->assertSee('CakesbyGrace')->assertSee('Foo Ltd')->assertSee('/admin/orgs/'.$this->foo->id)
            ->assertSee('New organization')->assertSee('Platform API clients')->assertSee('Xpenses');
        $this->as($this->olga)->get('/admin/orgs')->assertOk()->assertSee('Foo Ltd')
            ->assertDontSee('CakesbyGrace')->assertDontSee('New organization')->assertDontSee('Platform API clients');
    }

    public function test_every_tab_renders_and_is_404_for_other_organizations(): void
    {
        foreach (['', '/apps', '/domain', '/settings'] as $tab) {
            $this->as($this->owner)->get('/admin/orgs/'.$this->cbg->id.$tab)->assertOk()->assertSee('CakesbyGrace')->assertSee('aria-current="page"', false);
            $this->as($this->olga)->get('/admin/orgs/'.$this->foo->id.$tab)->assertOk()->assertSee('Foo Ltd');
            $this->as($this->adam)->get('/admin/orgs/'.$this->foo->id.$tab)->assertOk();
            $this->as($this->olga)->get('/admin/orgs/'.$this->cbg->id.$tab)->assertNotFound();
        }
    }

    public function test_people_tab_lists_members_and_owners_change_roles(): void
    {
        $this->as($this->owner)->get('/admin/orgs/'.$this->cbg->id)->assertOk()
            ->assertSee('maria@cakesbygrace.ph')->assertSee('Invite people')
            ->assertSee(route('admin.users.index', ['invite' => 1, 'org' => $this->cbg->id]));
        $this->as($this->owner)->get('/admin/orgs/'.$this->cbg->id.'?q=nobody')->assertOk()->assertSee('No one matches');

        // Owners get a role picker; admins only see the role.
        $this->as($this->olga)->get('/admin/orgs/'.$this->foo->id)->assertSee('name="role"', false)->assertSee('adam@foo.test');
        $this->as($this->adam)->get('/admin/orgs/'.$this->foo->id)->assertOk()->assertDontSee('/admin/orgs/'.$this->foo->id.'/roles');
        $this->as($this->olga)->post('/admin/orgs/'.$this->foo->id.'/roles', ['email' => 'adam@foo.test', 'role' => 'developer'])->assertSessionHasNoErrors();
        $this->assertSame('developer', $this->adam->fresh()->orgRole($this->foo->id));
    }

    public function test_apps_tab_adds_and_removes_apps_and_shows_install_requests(): void
    {
        $this->as($this->owner)->get('/admin/orgs/'.$this->foo->id.'/apps')->assertOk()->assertSee('No apps yet')->assertSee('Add an app');
        $this->as($this->owner)->post('/admin/orgs/'.$this->foo->id.'/apps', ['app_id' => $this->xp->id])->assertSessionHasNoErrors();
        $this->as($this->owner)->get('/admin/orgs/'.$this->foo->id.'/apps')->assertSee('Xpenses')->assertSee('Remove from Foo Ltd');
        $this->as($this->owner)->delete('/admin/orgs/'.$this->foo->id.'/apps/'.$this->xp->key)->assertSessionHasNoErrors();
        $this->assertFalse($this->foo->apps()->exists());
    }

    public function test_domain_tab_shows_sign_in_addresses_and_domain_proof(): void
    {
        $d = AppDomain::create(['host' => 'auth.cbg.test', 'organization_id' => $this->cbg->id, 'verify_token' => Str::lower(Str::random(32)), 'status' => 'active', 'verified_at' => now()]);
        $d->apps()->attach($this->xp->id);
        $this->as($this->owner)->get('/admin/orgs/'.$this->cbg->id.'/domain')->assertOk()
            ->assertSee('auth.cbg.test')->assertSee($d->txtValue())->assertSee('Used by Xpenses')->assertDontSee('Your domain');
        $this->as($this->olga)->post('/admin/orgs/'.$this->foo->id.'/domain', ['domain' => 'foo.test'])->assertSessionHasNoErrors();
        $this->as($this->olga)->get('/admin/orgs/'.$this->foo->id.'/domain')->assertOk()->assertSee('Your domain')->assertSee('Check now')->assertSee('No sign-in address yet');
    }

    public function test_settings_tab_general_save_and_owner_only_controls(): void
    {
        $this->as($this->olga)->get('/admin/orgs/'.$this->foo->id.'/settings')->assertOk()
            ->assertSee('Danger zone')->assertSee('Delete organization')->assertDontSee('Suspend organization')->assertDontSee('Clauth owner controls');
        $this->as($this->olga)->put('/admin/orgs/'.$this->foo->id.'/general', ['name' => 'Foo Limited', 'website' => 'https://foo.test'])->assertSessionHasNoErrors();
        $this->assertSame('https://foo.test', $this->foo->fresh()->website);
        $this->assertSame('Foo Limited', $this->foo->fresh()->name);
        $this->as($this->olga)->put('/admin/orgs/'.$this->foo->id.'/general', ['name' => 'X', 'website' => 'javascript:alert(1)'])->assertSessionHasErrors('website');

        // Admins can't rename; Clauth owners see suspend + status controls; William's own orgs have no danger zone.
        $this->as($this->adam)->put('/admin/orgs/'.$this->foo->id.'/general', ['name' => 'Nope'])->assertNotFound();
        $this->as($this->adam)->get('/admin/orgs/'.$this->foo->id.'/settings')->assertOk()->assertSee('Only owners of')->assertDontSee('Delete organization');
        $this->as($this->owner)->get('/admin/orgs/'.$this->foo->id.'/settings')->assertSee('Suspend organization')->assertSee('Clauth owner controls');
        $this->as($this->owner)->get('/admin/orgs/'.$this->cbg->id.'/settings')->assertOk()->assertDontSee('Danger zone')->assertSee('Sign-in rules');
    }

    public function test_request_review_page_checks_and_decides(): void
    {
        $r = OrganizationRequest::create(['org_name' => 'Bar Co', 'slug' => 'bar-co', 'website' => 'https://www.bar.test', 'contact_name' => 'Dana',
            'contact_email' => 'dana@bar.test', 'purpose' => 'Sign-in for our payroll app.', 'email_verified_at' => now()]);
        $this->as($this->owner)->get('/admin/orgs')->assertSee('Bar Co')->assertSee('/admin/org-requests/'.$r->id);
        $this->as($this->owner)->get('/admin/org-requests/'.$r->id)->assertOk()->assertSee('Their request')
            ->assertSee('Email matches the website’s domain')->assertSee('Short name is free')->assertSee('Approve');
        $this->as($this->olga)->get('/admin/org-requests/'.$r->id)->assertNotFound();
        $this->as($this->owner)->post('/admin/org-requests/'.$r->id.'/approve')->assertSessionHasNoErrors();
        $org = Organization::where('slug', 'bar-co')->firstOrFail();
        $this->assertSame('https://www.bar.test', $org->website);
        $this->as($this->owner)->get('/admin/org-requests/'.$r->id)->assertOk()->assertSee('Approved')->assertSee('Open Bar Co')->assertDontSee('Note to Dana');
    }

    public function test_logo_upload_serve_and_remove(): void
    {
        Storage::fake('local');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $base = '/admin/orgs/'.$this->foo->id;

        $this->as($this->adam)->get($base.'/settings')->assertOk()->assertSee('No logo yet')->assertSee('name="logo"', false);
        $this->as($this->adam)->get($base.'/logo')->assertNotFound();
        $this->as($this->adam)->post($base.'/logo', ['logo' => UploadedFile::fake()->createWithContent('logo.png', $png)])->assertSessionHasNoErrors();
        $path = $this->foo->fresh()->logo_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        $this->assertTrue(AuditEvent::where('event', 'org.updated')->where('organization_id', $this->foo->id)->exists());

        $res = $this->as($this->olga)->get($base.'/logo');
        $res->assertOk()->assertHeader('Content-Type', 'image/png');
        // Shown in the org header, the list row and the sidebar switcher.
        $this->as($this->olga)->get($base)->assertOk()->assertSee(route('admin.orgs.logo.show', [$this->foo->id, 'v' => substr(md5($path), 0, 8)]), false);
        $this->as($this->olga)->get('/admin/orgs')->assertOk()->assertSee($base.'/logo?v=', false);

        // Replacing deletes the old file; bad files are refused.
        $this->as($this->olga)->post($base.'/logo', ['logo' => UploadedFile::fake()->createWithContent('new.png', $png)])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($path);
        $path = $this->foo->fresh()->logo_path;
        $this->as($this->olga)->post($base.'/logo', ['logo' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])
            ->assertSessionHasErrors('logo');
        $this->as($this->olga)->post($base.'/logo', ['logo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('logo');
        $this->as($this->olga)->post($base.'/logo', ['logo' => UploadedFile::fake()->createWithContent('big.png', $png.str_repeat('0', 600 * 1024))])->assertSessionHasErrors('logo');
        $this->assertSame($path, $this->foo->fresh()->logo_path);

        $this->as($this->adam)->delete($base.'/logo')->assertSessionHasNoErrors();
        $this->assertNull($this->foo->fresh()->logo_path);
        Storage::disk('local')->assertMissing($path);
        $this->as($this->adam)->get($base.'/logo')->assertNotFound();
        $this->assertSame(3, AuditEvent::where('event', 'org.updated')->where('organization_id', $this->foo->id)->count());
    }

    public function test_logo_routes_are_404_for_other_organizations_and_members(): void
    {
        Storage::fake('local');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $this->as($this->owner)->post('/admin/orgs/'.$this->cbg->id.'/logo', ['logo' => UploadedFile::fake()->createWithContent('logo.png', $png)])->assertSessionHasNoErrors();
        $path = $this->cbg->fresh()->logo_path;

        $this->as($this->olga)->get('/admin/orgs/'.$this->cbg->id.'/logo')->assertNotFound();
        $this->as($this->olga)->post('/admin/orgs/'.$this->cbg->id.'/logo', ['logo' => UploadedFile::fake()->createWithContent('logo.png', $png)])->assertNotFound();
        $this->as($this->olga)->delete('/admin/orgs/'.$this->cbg->id.'/logo')->assertNotFound();
        $dev = $this->staff('dev@foo.test', 'Dev', $this->foo, 'developer');
        $this->as($dev)->post('/admin/orgs/'.$this->foo->id.'/logo', ['logo' => UploadedFile::fake()->createWithContent('logo.png', $png)])->assertNotFound();
        $this->assertSame($path, $this->cbg->fresh()->logo_path);
        $this->assertNull($this->foo->fresh()->logo_path);
    }

    public function test_people_tab_suggestion_card_creates_the_job_role(): void
    {
        $this->role('member')->update(['tier' => 'member']);
        $this->grant($this->makeUser('ana@cakesbygrace.ph', ['name' => 'Ana']), $this->cbg, 'member');

        $this->as($this->owner)->get('/admin/orgs/'.$this->cbg->id)->assertOk()
            ->assertSee('2 people have the same app roles by hand')->assertSee('Create “Xpenses Member”', false)
            ->assertSee(route('admin.profiles.suggest.accept', $this->cbg->id));
        $this->as($this->owner)->from('/admin/orgs/'.$this->cbg->id)->post('/admin/orgs/'.$this->cbg->id.'/profiles/suggest',
            ['key' => Access::suggestions($this->cbg, $this->owner)[0]['key'], 'name' => 'Xpenses Member'])
            ->assertRedirect('/admin/orgs/'.$this->cbg->id)->assertSessionHasNoErrors();
        $p = AccessProfile::where('organization_id', $this->cbg->id)->where('name', 'Xpenses Member')->firstOrFail();
        $this->assertSame(2, $p->users()->count());
        $this->as($this->owner)->get('/admin/orgs/'.$this->cbg->id)->assertOk()->assertDontSee('have the same app roles by hand');
        // Foo Ltd has nobody with access: no card.
        $this->as($this->olga)->get('/admin/orgs/'.$this->foo->id)->assertOk()->assertDontSee('Suggestion');
    }
}
