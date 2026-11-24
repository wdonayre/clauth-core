<?php

namespace Tests\Feature;

use App\Mail\InviteMail;
use App\Models\AccessProfile;
use App\Models\Application;
use App\Models\AppPermission;
use App\Models\AppRole;
use App\Models\Grant;
use App\Models\Invite;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\Access;
use App\Services\Claims;
use App\Services\Invites;
use App\Services\PersonalTokens;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Platform API P2: teams, profile fields, AuthZEN, SCIM (SPEC-platform §6.3, §6.4, §9.1, §9.2, §14 tests 6–7). */
class PlatformP2Test extends ClauthTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->role('finance')->update(['tier' => 'admin', 'position' => 0]);
        $this->role('member')->update(['tier' => 'member', 'position' => 1]);
        $this->admin = $this->makeUser('owner@cakesbygrace.ph', ['name' => 'Grace']);
        $this->admin->organizations()->attach($this->cbg->id, ['role' => 'owner', 'joined_at' => now()]);
        $this->admin->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
    }

    private function apiClient(array $scopes, ?string $orgId = null): array
    {
        $c = new OAuthClient;
        $c->forceFill(['type' => 'api', 'name' => 'script', 'secret' => 'api-secret-api-secret', 'organization_id' => $orgId ?? $this->cbg->id,
            'created_by' => $this->admin->id, 'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'scopes' => $scopes, 'revoked' => false])->save();

        return [$c, $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $c->id, 'client_secret' => 'api-secret-api-secret',
            'scope' => implode(' ', $scopes)])->assertOk()->json('access_token')];
    }

    private function bearer(string $t)
    {
        return $this->flushHeaders()->withHeaders(['Authorization' => 'Bearer '.$t, 'Accept' => 'application/json']);
    }

    private function scimToken(): string
    {
        [$c] = $this->apiClient(['scim']);

        return PersonalTokens::mint($c, null, 'SCIM', ['scim'], 365, 'scim')[1];
    }

    private function scim(string $t)
    {
        return $this->flushHeaders()->withHeaders(['Authorization' => 'Bearer '.$t, 'Content-Type' => 'application/scim+json', 'Accept' => 'application/scim+json']);
    }

    // ------------------------------------------------------------ teams & fields

    public function test_teams_and_fields_over_the_api_and_in_tokens(): void
    {
        [, $t] = $this->apiClient(['orgs:read', 'orgs:write']);
        $this->bearer($t)->postJson('/api/v1/orgs/cakesbygrace/teams', ['name' => 'Ayala store'])->assertCreated()->assertJsonPath('key', 'ayala-store');
        $this->bearer($t)->patchJson('/api/v1/orgs/cakesbygrace/teams/ayala-store', ['key' => 'x'])->assertStatus(422);
        $this->bearer($t)->postJson('/api/v1/orgs/cakesbygrace/fields', ['key' => 'employee_no', 'label' => 'Employee no.', 'type' => 'string', 'in_token' => true])->assertCreated();
        $this->bearer($t)->postJson('/api/v1/orgs/cakesbygrace/fields', ['key' => 'shirt', 'label' => 'Shirt size', 'type' => 'enum'])->assertStatus(422); // enum needs options
        $this->bearer($t)->postJson('/api/v1/orgs/cakesbygrace/fields', ['key' => 'shirt', 'label' => 'Shirt size', 'type' => 'enum', 'options' => ['S', 'M', 'L']])->assertCreated();

        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        $this->bearer($t)->patchJson('/api/v1/orgs/cakesbygrace/members/'.$maria->sub().'/profile', ['teams' => ['ayala-store'], 'fields' => ['employee_no' => 'E-17', 'shirt' => 'XL']])
            ->assertStatus(422)->assertJsonPath('errors', ['fields.shirt' => ['Shirt size must be one of: S, M, L.']]);
        $this->bearer($t)->patchJson('/api/v1/orgs/cakesbygrace/members/'.$maria->sub().'/profile', ['teams' => ['ayala-store'], 'fields' => ['employee_no' => 'E-17', 'shirt' => 'M']])
            ->assertOk()->assertJsonPath('teams', ['ayala-store'])->assertJsonPath('fields.shirt', 'M');

        // Tokens: team keys, and only the fields marked "send to apps".
        $claims = Claims::orgs($maria, $this->xp);
        $this->assertSame(['ayala-store'], $claims['cakesbygrace']['teams']);
        $this->assertSame(['employee_no' => 'E-17'], $claims['cakesbygrace']['fields']);
        // Nobody else's token changes shape.
        $this->grant($other = $this->makeUser('ana@cakesbygrace.ph'), $this->cbg, 'member');
        $this->assertArrayNotHasKey('teams', Claims::orgs($other, $this->xp)['cakesbygrace']);

        // Leaving the organization drops teams and values.
        Access::forgetMember($maria, $this->cbg);
        $this->assertSame(0, DB::table('team_user')->count() + DB::table('user_attributes')->count());
    }

    public function test_people_edit_only_self_editable_fields_with_a_personal_token(): void
    {
        [, $t] = $this->apiClient(['orgs:write']);
        $this->bearer($t)->postJson('/api/v1/orgs/cakesbygrace/fields', ['key' => 'nickname', 'label' => 'Nickname', 'type' => 'string', 'editable_by' => 'self'])->assertCreated();
        $this->bearer($t)->postJson('/api/v1/orgs/cakesbygrace/fields', ['key' => 'salary_band', 'label' => 'Band', 'type' => 'number'])->assertCreated();
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        [, $jwt] = PersonalTokens::create($maria, 'me', ['me:read', 'me:write'], 7);
        $this->bearer($jwt)->patchJson('/api/v1/me/orgs/cakesbygrace/profile', ['fields' => ['nickname' => 'Mar']])->assertOk()->assertJsonPath('fields.nickname', 'Mar');
        $this->bearer($jwt)->patchJson('/api/v1/me/orgs/cakesbygrace/profile', ['fields' => ['salary_band' => 9]])->assertStatus(422);
    }

    public function test_console_teams_fields_page_and_person_page(): void
    {
        $as = fn () => $this->actingAs($this->admin)->withSession(['auth.mfa' => true]);
        $as()->post("/admin/orgs/{$this->cbg->id}/teams", ['name' => 'Talisay', 'key' => 'talisay'])->assertSessionHas('status');
        $as()->post("/admin/orgs/{$this->cbg->id}/fields", ['label' => 'Start date', 'key' => 'start_date', 'type' => 'date', 'required' => '1'])->assertSessionHasNoErrors();
        $as()->post("/admin/orgs/{$this->cbg->id}/fields", ['label' => 'Shift', 'key' => 'shift', 'type' => 'enum', 'options' => "AM\nPM"])->assertSessionHasNoErrors();
        $as()->get("/admin/orgs/{$this->cbg->id}/teams-fields")->assertOk()->assertSee('Talisay')->assertSee('Start date');
        $as()->get("/admin/orgs/{$this->alab->id}/teams-fields")->assertNotFound();

        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'member');
        $as()->get("/admin/users/{$maria->id}")->assertOk()->assertSee('Talisay')->assertSee('Start date');
        // Saving access with an untouched empty required field still works; changed values are saved.
        $as()->post("/admin/users/{$maria->id}/grants", ['organization_id' => $this->cbg->id, 'teams_shown' => '1', 'teams' => ['talisay'],
            'fields' => ['start_date' => '', 'shift' => 'PM']])->assertSessionHasNoErrors();
        $this->assertSame(['talisay'], \App\Services\OrgData::teamKeys($maria, $this->cbg));
        $this->assertSame(['shift' => 'PM'], \App\Services\OrgData::values($maria, $this->cbg));
    }

    public function test_invite_carries_teams_and_fields(): void
    {
        [, $t] = $this->apiClient(['orgs:write', 'users:write']);
        $this->bearer($t)->postJson('/api/v1/orgs/cakesbygrace/teams', ['name' => 'Talisay', 'key' => 'talisay'])->assertCreated();
        $this->bearer($t)->postJson('/api/v1/orgs/cakesbygrace/fields', ['key' => 'employee_no', 'label' => 'Employee no.', 'type' => 'string'])->assertCreated();
        $this->bearer($t)->postJson('/api/v1/invites', ['email' => 'ana@cakesbygrace.ph', 'name' => 'Ana', 'org' => 'cakesbygrace', 'teams' => ['nope'], 'send' => false])->assertStatus(422);
        $this->bearer($t)->postJson('/api/v1/invites', ['email' => 'ana@cakesbygrace.ph', 'name' => 'Ana', 'org' => 'cakesbygrace', 'teams' => ['talisay'],
            'fields' => ['employee_no' => 'E-9'], 'send' => false])->assertCreated();
        Invites::apply(Invite::firstOrFail(), $ana = User::firstWhere('email', 'ana@cakesbygrace.ph'));
        $this->assertSame(['talisay'], \App\Services\OrgData::teamKeys($ana, $this->cbg));
        $this->assertSame(['employee_no' => 'E-9'], \App\Services\OrgData::values($ana, $this->cbg));
    }

    // ------------------------------------------------------------ AuthZEN

    private function m2mToken(Application $app, string $scope = 'authzen'): string
    {
        $c = new OAuthClient;
        $c->forceFill(['app_id' => $app->id, 'type' => 'm2m', 'name' => 'svc', 'secret' => 's3cret-s3cret', 'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'revoked' => false])->save();

        return $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $c->id, 'client_secret' => 's3cret-s3cret', 'scope' => $scope])->json('access_token');
    }

    public function test_authzen_evaluation_batch_and_search(): void
    {
        $this->getJson('/.well-known/authzen-configuration')->assertOk()->assertJsonPath('access_evaluation_endpoint', config('clauth.issuer').'/access/v1/evaluation');
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'finance');
        $t = $this->m2mToken($this->xp);
        $ask = fn (array $body) => $this->bearer($t)->postJson('/access/v1/evaluation', $body);
        $subj = ['type' => 'user', 'id' => $maria->sub()];
        $ask(['subject' => $subj, 'action' => ['name' => 'expense.approve'], 'resource' => ['type' => 'app', 'id' => 'xpenses']])
            ->assertOk()->assertJsonPath('decision', true)->assertJsonPath('context.orgs', ['cakesbygrace']);
        $ask(['subject' => $subj, 'action' => ['name' => 'expense.approve'], 'resource' => ['type' => 'app', 'id' => 'xpenses', 'properties' => ['org' => 'alab-apparel']]])
            ->assertJsonPath('decision', false);
        $ask(['subject' => $subj, 'action' => ['name' => 'role:finance']])->assertJsonPath('decision', true); // resource defaults to the caller's app
        $ask(['subject' => ['type' => 'user', 'id' => 'maria@cakesbygrace.ph'], 'action' => ['name' => 'expense.submit']])->assertJsonPath('decision', false);

        $other = Application::create(['key' => 'cebpos', 'name' => 'CebPOS', 'status' => 'live']);
        $ask(['subject' => $subj, 'action' => ['name' => 'x'], 'resource' => ['type' => 'app', 'id' => 'cebpos']])->assertJsonPath('decision', false);

        $this->bearer($t)->postJson('/access/v1/evaluations', ['subject' => $subj, 'resource' => ['type' => 'app', 'id' => 'xpenses'],
            'evaluations' => [['action' => ['name' => 'expense.approve']], ['action' => ['name' => 'expense.submit']], ['action' => ['name' => 'report.export']]],
            'options' => ['evaluations_semantic' => 'deny_on_first_deny']])
            ->assertOk()->assertJsonCount(2, 'evaluations')->assertJsonPath('evaluations.1.decision', false);
        $this->bearer($t)->postJson('/access/v1/search/action', ['subject' => $subj, 'resource' => ['type' => 'app', 'id' => 'xpenses']])
            ->assertOk()->assertJsonPath('results', [['name' => 'expense.approve'], ['name' => 'expense.view_all'], ['name' => 'report.export']]);

        // A user token (not client credentials) can't ask.
        $maria->update(['status' => 'disabled']);
        $ask(['subject' => $subj, 'action' => ['name' => 'expense.approve']])->assertJsonPath('decision', false);
        $this->flushHeaders()->postJson('/access/v1/evaluation', [])->assertStatus(401);
    }

    /** §14 test 7: the decision equals the permission check computed from the token. */
    public function test_authzen_agrees_with_token_permissions(): void
    {
        $perms = AppPermission::where('app_id', $this->xp->id)->pluck('key')->all();
        $users = collect(range(1, 6))->map(function ($i) {
            $u = $this->makeUser("p$i@test.ph");
            foreach ([$this->cbg, $this->alab] as $org) {
                if (random_int(0, 2)) {
                    $this->grant($u, $org, random_int(0, 1) ? 'finance' : 'member');
                }
            }

            return $u;
        });
        $t = $this->m2mToken($this->xp);
        for ($n = 0; $n < 100; $n++) {
            $u = $users->random();
            $perm = $perms[array_rand($perms)];
            $org = [null, 'cakesbygrace', 'alab-apparel'][random_int(0, 2)];
            $claims = Claims::orgs($u, $this->xp);
            $expected = $org ? in_array($perm, $claims[$org]['permissions'] ?? [], true) : collect($claims)->contains(fn ($c) => in_array($perm, $c['permissions'], true));
            $res = ['type' => 'app', 'id' => 'xpenses'] + ($org ? ['properties' => ['org' => $org]] : []);
            $got = $this->bearer($t)->postJson('/access/v1/evaluation', ['subject' => ['type' => 'user', 'id' => $u->sub()], 'action' => ['name' => $perm], 'resource' => $res])->json('decision');
            $this->assertSame($expected, $got, "user {$u->email} perm $perm org ".($org ?? 'any'));
        }
    }

    public function test_authzen_api_client_only_sees_its_organization(): void
    {
        $jam = $this->makeUser('jam@alab.ph');
        $this->grant($jam, $this->alab, 'finance');
        [, $t] = $this->apiClient(['authzen']);
        $this->bearer($t)->postJson('/access/v1/evaluation', ['subject' => ['type' => 'user', 'id' => $jam->sub()], 'action' => ['name' => 'expense.approve'],
            'resource' => ['type' => 'app', 'id' => 'xpenses']])->assertOk()->assertJsonPath('decision', false);
    }

    // ------------------------------------------------------------ SCIM

    public function test_scim_discovery_and_errors(): void
    {
        $t = $this->scimToken();
        $this->scim($t)->getJson('/scim/v2/ServiceProviderConfig')->assertOk()->assertJsonPath('patch.supported', true)->assertHeader('Content-Type', 'application/scim+json');
        $this->scim($t)->getJson('/scim/v2/ResourceTypes')->assertOk()->assertJsonPath('totalResults', 2);
        $this->scim($t)->getJson('/scim/v2/Schemas')->assertOk();
        $this->scim($t)->getJson('/scim/v2/Users/usr_nope')->assertNotFound()->assertJsonPath('schemas', ['urn:ietf:params:scim:api:messages:2.0:Error'])->assertJsonPath('status', '404');
        $this->scim($t)->getJson('/scim/v2/Users?filter='.urlencode('title co "x"'))->assertStatus(400)->assertJsonPath('scimType', 'invalidFilter');
        $this->flushHeaders()->getJson('/scim/v2/Users')->assertStatus(401)->assertJsonPath('status', '401');
        // An API client without the scim scope can't.
        [, $other] = $this->apiClient(['users:read']);
        $this->scim($other)->getJson('/scim/v2/Users')->assertStatus(403)->assertJsonPath('schemas.0', 'urn:ietf:params:scim:api:messages:2.0:Error');
    }

    /** §14 test 6. */
    public function test_scim_provisioning_lifecycle(): void
    {
        Mail::fake();
        $t = $this->scimToken();
        $staff = AccessProfile::create(['organization_id' => $this->cbg->id, 'key' => 'staff', 'name' => 'Staff']);
        DB::table('access_profile_roles')->insert(['access_profile_id' => $staff->id, 'app_id' => $this->xp->id, 'app_role_id' => $this->role('member')->id]);

        // New person: account created, member of the org, invite email sent.
        $r = $this->scim($t)->postJson('/scim/v2/Users', ['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'], 'userName' => 'Ana@CakesbyGrace.ph',
            'externalId' => 'HR-1', 'name' => ['givenName' => 'Ana', 'familyName' => 'Reyes'], 'active' => true])->assertCreated()
            ->assertJsonPath('userName', 'ana@cakesbygrace.ph')->assertJsonPath('displayName', 'Ana Reyes')->assertJsonPath('externalId', 'HR-1');
        $ana = User::firstWhere('email', 'ana@cakesbygrace.ph');
        Mail::assertSent(InviteMail::class);
        $this->scim($t)->postJson('/scim/v2/Users', ['userName' => 'ana@cakesbygrace.ph'])->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');
        $this->scim($t)->getJson('/scim/v2/Users?filter='.urlencode('userName eq "ana@cakesbygrace.ph"'))->assertOk()->assertJsonPath('totalResults', 1);
        $this->scim($t)->getJson('/scim/v2/Users?filter='.urlencode('externalId eq "HR-1"'))->assertJsonPath('Resources.0.id', $ana->sub());

        // Group = access profile: membership gives the profile's roles.
        $this->scim($t)->patchJson('/scim/v2/Groups/prf_'.$staff->id, ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [['op' => 'Add', 'path' => 'members', 'value' => [['value' => $ana->sub()]]]]])->assertOk()->assertJsonPath('members.0.value', $ana->sub());
        $this->assertSame('member', Grant::where('user_id', $ana->id)->first()?->role?->key);

        // Entra-style deactivate: membership stays, access goes; reactivate brings it back.
        $this->scim($t)->patchJson('/scim/v2/Users/'.$ana->sub(), ['Operations' => [['op' => 'Replace', 'value' => ['active' => 'False']]]])->assertOk()->assertJsonPath('active', false);
        $this->assertFalse(Grant::where('user_id', $ana->id)->exists());
        $this->scim($t)->patchJson('/scim/v2/Users/'.$ana->sub(), ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => true]]])->assertJsonPath('active', true);
        $this->assertTrue(Grant::where('user_id', $ana->id)->exists());

        // Someone already in another organization is linked, not duplicated; SCIM can't rename them or change their email.
        $jam = $this->makeUser('jam@alab.ph', ['name' => 'Jam']);
        $this->grant($jam, $this->alab, 'finance');
        $this->scim($t)->postJson('/scim/v2/Users', ['userName' => 'jam@alab.ph', 'displayName' => 'Someone Else'])->assertCreated()->assertJsonPath('id', $jam->sub());
        $this->assertSame('Jam', $jam->fresh()->name);
        $this->scim($t)->patchJson('/scim/v2/Users/'.$jam->sub(), ['Operations' => [['op' => 'replace', 'path' => 'userName', 'value' => 'evil@x.ph']]])->assertStatus(400);
        $this->assertSame(1, User::where('email', 'jam@alab.ph')->count());

        // DELETE removes this organization's access only.
        $this->scim($t)->deleteJson('/scim/v2/Users/'.$jam->sub())->assertNoContent();
        $this->assertTrue(Grant::where('user_id', $jam->id)->where('organization_id', $this->alab->id)->exists());
        $this->assertFalse($jam->organizations()->where('organizations.id', $this->cbg->id)->exists());
        $this->scim($t)->getJson('/scim/v2/Users/'.$jam->sub())->assertNotFound();
    }

    public function test_scim_groups_create_replace_delete(): void
    {
        $t = $this->scimToken();
        $ana = $this->makeUser('ana@cakesbygrace.ph');
        $ana->organizations()->attach($this->cbg->id);
        $outsider = $this->makeUser('jam@alab.ph');
        $g = $this->scim($t)->postJson('/scim/v2/Groups', ['displayName' => 'Bakers', 'members' => [['value' => $ana->sub()]]])->assertCreated()->json();
        $this->scim($t)->postJson('/scim/v2/Groups', ['displayName' => 'Bakers'])->assertStatus(409);
        $this->scim($t)->putJson('/scim/v2/Groups/'.$g['id'], ['displayName' => 'Bakers', 'members' => [['value' => $outsider->sub()]]])->assertStatus(400);
        $this->scim($t)->getJson('/scim/v2/Groups?filter='.urlencode('displayName eq "Bakers"'))->assertJsonPath('Resources.0.members.0.value', $ana->sub());
        $this->scim($t)->patchJson('/scim/v2/Groups/'.$g['id'], ['Operations' => [['op' => 'remove', 'path' => 'members[value eq "'.$ana->sub().'"]']]])->assertJsonPath('members', []);
        $this->scim($t)->deleteJson('/scim/v2/Groups/'.$g['id'])->assertNoContent();
        $this->assertFalse(AccessProfile::where('name', 'Bakers')->exists());
    }

    public function test_console_makes_a_scim_token_and_replaces_the_old_one(): void
    {
        $as = fn () => $this->actingAs($this->admin)->withSession(['auth.mfa' => true]);
        $first = $as()->post("/admin/orgs/{$this->cbg->id}/api-clients/scim")->assertSessionHas('scim_token')->getSession()->get('scim_token')['token'];
        $this->scim($first)->getJson('/scim/v2/Users')->assertOk();
        $this->flushHeaders();
        $second = $as()->post("/admin/orgs/{$this->cbg->id}/api-clients/scim")->getSession()->get('scim_token')['token'];
        $this->scim($first)->getJson('/scim/v2/Users')->assertStatus(401);
        $this->scim($second)->getJson('/scim/v2/Users')->assertOk()->assertJsonPath('Resources.0.userName', 'owner@cakesbygrace.ph');
    }

    public function test_openapi_still_matches_router(): void
    {
        $routes = array_keys(\App\Api\OpenApi::routes());
        sort($routes);
        $docs = array_keys(\App\Api\OpenApi::DOCS);
        sort($docs);
        $this->assertSame($docs, $routes);
    }
}
