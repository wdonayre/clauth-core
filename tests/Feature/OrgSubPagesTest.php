<?php

namespace Tests\Feature;

use App\Models\AccessProfile;
use App\Models\User;
use App\Services\Access;
use App\Services\TwoFactor;

/** Redesigned organization sub-pages render as tabs of the organization and keep their actions working. */
class OrgSubPagesTest extends ClauthTestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser('william@thecakelabs.com', ['name' => 'William', 'clauth_role' => 'owner']);
        $this->owner->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->role('finance')->update(['tier' => 'admin']);
        $this->role('member')->update(['tier' => 'member']);
    }

    private function as()
    {
        return $this->actingAs($this->owner)->withSession(['auth.mfa' => true]);
    }

    private function url(string $path): string
    {
        return "/admin/orgs/{$this->cbg->id}/$path";
    }

    public function test_job_roles_is_a_master_detail_tab_and_its_actions_work(): void
    {
        $this->as()->get($this->url('profiles'))->assertOk()->assertSee('aria-label="Organization"', false)
            ->assertSee('Start with your first job role')->assertSee('New job role');

        $this->as()->post($this->url('profiles'), ['name' => 'Finance', 'description' => 'Pays bills'])->assertRedirect();
        $p = AccessProfile::where('organization_id', $this->cbg->id)->where('name', 'Finance')->firstOrFail();
        // The list opens the first job role on the right.
        $this->as()->get($this->url('profiles'))->assertOk()->assertSee('Role it gives')->assertSee('Pays bills')->assertSee('0/1 apps set');

        $this->as()->put($this->url("profiles/{$p->id}"), ['name' => 'Finance', 'description' => 'Pays bills', 'map' => [$this->xp->id => $this->role('finance')->id]])
            ->assertSessionHasNoErrors();
        $maria = $this->makeUser();
        $maria->organizations()->attach($this->cbg->id);
        $this->as()->post($this->url("profiles/{$p->id}/people"), ['user_id' => $maria->id])->assertSessionHasNoErrors();
        $this->as()->get($this->url("profiles/{$p->id}"))->assertOk()->assertSee('Maria Santos has this job role')->assertSee('1/1 apps set');
        $this->assertSame('finance', Access::explain($maria, $this->cbg)[$this->xp->id]['role']?->key);

        $this->as()->get($this->url('profiles/apps/xpenses'))->assertOk()->assertSee('Xpenses in every job role');
        $this->as()->get($this->url('profiles/suggest'))->assertOk()->assertSee('Nothing to suggest');

        $this->as()->delete($this->url("profiles/{$p->id}/people/{$maria->id}"))->assertSessionHasNoErrors();
        $this->as()->delete($this->url("profiles/{$p->id}"))->assertRedirect($this->url('profiles'));
        $this->assertNull(AccessProfile::find($p->id));
    }

    public function test_who_has_what_shows_roles_per_app_and_searches(): void
    {
        $maria = $this->makeUser();
        $this->grant($maria, $this->cbg, 'finance');
        $ana = $this->makeUser('ana@cakesbygrace.ph', ['name' => 'Ana Cruz']);
        $ana->organizations()->attach($this->cbg->id);

        $this->as()->get($this->url('alignment'))->assertOk()->assertSee('Who has what')->assertSee('Download CSV')
            ->assertSee('Maria Santos')->assertSee('Ana Cruz')->assertSee('Xpenses');
        $this->as()->get($this->url('alignment').'?q=ana')->assertOk()->assertSee('ana@cakesbygrace.ph')->assertDontSee('maria@cakesbygrace.ph')
            ->assertSee('Showing 1 of');
        $this->as()->get($this->url('alignment.csv'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_teams_and_fields_crud(): void
    {
        $this->as()->post($this->url('teams'), ['_form' => 'new-team', 'name' => 'Talisay store', 'description' => 'Ground floor'])->assertSessionHasNoErrors();
        $this->as()->post($this->url('fields'), ['_form' => 'new-field', 'label' => 'Shift', 'key' => 'shift', 'type' => 'enum', 'options' => "AM\nPM", 'in_token' => '1'])
            ->assertSessionHasNoErrors();
        $field = $this->cbg->attributeDefs()->firstOrFail();
        $this->as()->get($this->url('teams-fields'))->assertOk()->assertSee('Talisay store')->assertSee('Ground floor')
            ->assertSee('2 options')->assertSee('id="field-'.$field->id.'"', false);
        $this->as()->put($this->url("fields/{$field->id}"), ['label' => 'Work shift', 'options' => "AM\nPM\nNight", 'editable_by' => 'admin'])->assertSessionHasNoErrors();
        $this->assertSame(['AM', 'PM', 'Night'], $field->fresh()->options);
        $this->as()->delete($this->url("fields/{$field->id}"))->assertSessionHasNoErrors();
        $this->as()->delete($this->url('teams/'.$this->cbg->teams()->firstOrFail()->id))->assertSessionHasNoErrors();
        $this->as()->get($this->url('teams-fields'))->assertOk()->assertSee('No teams yet')->assertSee('No profile fields yet');
    }

    public function test_api_and_directory_sync_show_secrets_once(): void
    {
        $this->as()->get($this->url('api-clients'))->assertOk()->assertSee('Directory sync')->assertSee('Not set up')->assertSee('No API clients yet');

        $res = $this->as()->post($this->url('api-clients'), ['name' => 'HR sync', 'scopes' => ['users:read']]);
        $secret = session('created_client')['secret'];
        $this->as()->from($this->url('api-clients'))->followingRedirects()->post($this->url('api-clients'), ['name' => 'ERP reports', 'scopes' => ['users:read', 'orgs:read']])
            ->assertSee('Copy the secret for “ERP reports” now', false)->assertSee('Read people')->assertSee('Read organizations');
        $this->as()->get($this->url('api-clients'))->assertOk()->assertDontSee($secret)->assertSee('HR sync');

        $this->as()->from($this->url('api-clients'))->followingRedirects()->post($this->url('api-clients/scim'))->assertSee('/scim/v2')->assertSee('is shown only once');
        $page = $this->as()->get($this->url('api-clients'))->assertOk()->assertSee('Turn off directory sync')->assertSee('Set up · last used never');
        $this->assertStringNotContainsString('<b style="font-weight:500">SCIM provisioning</b>', $page->getContent());   // not in the client list

        $this->as()->get('/admin/orgs/platform/api-clients')->assertOk()->assertSee('Platform API clients')->assertDontSee('id="sync-h"', false)->assertSee('Manage platform settings');
    }

    public function test_agents_connections_and_messages_render_and_work(): void
    {
        $this->as()->from($this->url('agents'))->followingRedirects()->post($this->url('agents'), ['name' => 'Night bot', 'description' => 'Reorders stock'])
            ->assertSee('Copy the credentials for “Night bot” now', false)->assertSee('Reorders stock');
        $this->as()->get($this->url('agents'))->assertOk()->assertSee('Night bot')->assertSee('Save access')->assertSee('Suspend');

        $this->as()->post($this->url('connections'), ['type' => 'email-smtp', 'name' => 'Our mail server',
            'config' => ['host' => 'smtp.test', 'port' => 587, 'from_address' => 'hi@cbg.test', 'password' => 'top-secret-value']])->assertSessionHasNoErrors();
        $this->as()->get($this->url('connections'))->assertOk()->assertSee('Senders &amp; checks', false)->assertSee('Our mail server')
            ->assertSee('Send test email to me')->assertDontSee('top-secret-value');
        $this->as()->get('/admin/orgs/platform/connections')->assertOk()->assertSee('Platform sign-in');

        $this->as()->post($this->url('messages'), ['key' => 'invite', 'locale' => 'en', 'subject' => 'Join us', 'body' => 'Hi! {{link}}'])->assertSessionHasNoErrors();
        $this->as()->get($this->url('messages'))->assertOk()->assertSee('Join us')->assertSee('Custom')->assertSee('Use built-in');
        $this->as()->post($this->url('messages'), ['key' => 'invite', 'locale' => 'en', 'subject' => 'Join us', 'body' => ''])->assertSessionHasNoErrors();
        $this->as()->get('/admin/orgs/platform/messages')->assertOk()->assertSee('Platform messages');
    }
}
