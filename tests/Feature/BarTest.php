<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AppRole;
use App\Models\Grant;
use App\Services\Sessions;

class BarTest extends ClauthTestCase
{
    public function test_bar_lists_orgs_for_this_app_and_only_apps_the_user_can_open(): void
    {
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'finance');
        $this->grant($user, $this->alab, 'member');
        $other = Application::create(['key' => 'alab-erp', 'name' => 'Alab ERP', 'status' => 'live', 'home_url' => 'https://erp.test']);
        $other->organizations()->sync([$this->alab->id]);
        $role = AppRole::create(['app_id' => $other->id, 'key' => 'sales', 'name' => 'Sales']);
        Grant::create(['user_id' => $user->id, 'app_id' => $other->id, 'organization_id' => $this->alab->id, 'app_role_id' => $role->id]);
        $hidden = Application::create(['key' => 'cebpos', 'name' => 'CebPOS', 'status' => 'live', 'home_url' => 'https://pos.test']); // no grant

        [$code, , $verifier] = $this->authorizeCode($user);
        $tok = $this->exchange($code, $verifier)->json();

        $bar = $this->withToken($tok['access_token'])->getJson('/api/v1/bar')->assertOk()->json();
        $this->assertSame('xpenses', $bar['app']['key']);
        $this->assertSame(['alab-apparel', 'cakesbygrace'], array_column($bar['orgs'], 'slug'));
        $this->assertSame('Finance', collect($bar['orgs'])->firstWhere('slug', 'cakesbygrace')['role']);
        $this->assertEqualsCanonicalizing(['xpenses', 'alab-erp'], array_column($bar['apps'], 'key'));
        $this->assertTrue(collect($bar['apps'])->firstWhere('key', 'xpenses')['current']);
        $this->assertSame('MS', $bar['user']['initials']);
        $this->assertNotNull($this->xp->fresh()->bar_seen_at);
    }

    public function test_bar_rejects_missing_revoked_or_machine_tokens(): void
    {
        $this->getJson('/api/v1/bar')->assertStatus(401);
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'member');
        [$code, , $verifier] = $this->authorizeCode($user);
        $tok = $this->exchange($code, $verifier)->json();
        Sessions::revokeAll($user);
        $this->withToken($tok['access_token'])->getJson('/api/v1/bar')->assertStatus(401);
    }

    public function test_bar_script_is_served_without_cookies(): void
    {
        $r = $this->get('/bar/v1')->assertOk();
        $this->assertStringContainsString('javascript', $r->headers->get('Content-Type'));
        $this->assertStringContainsString("customElements.define('clauth-bar'", $r->getContent());
        $this->assertEmpty($r->headers->getCookies());
    }

    public function test_signout_everywhere_revokes_all_sessions(): void
    {
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'member');
        [$code, , $verifier] = $this->authorizeCode($user);
        $tok = $this->exchange($code, $verifier)->json();
        $this->get('/signout-everywhere')->assertOk()->assertSee($user->email);
        $this->post('/signout-everywhere')->assertOk();
        $this->refresh($tok['refresh_token'])->assertStatus(400);
        $this->assertGuest();
    }
}
