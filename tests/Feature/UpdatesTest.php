<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\OAuthClient;
use App\Models\ReleaseNote;
use App\Services\TwoFactor;

class UpdatesTest extends ClauthTestCase
{
    private function token(): array
    {
        $user = $this->makeUser();
        $this->grant($user, $this->cbg, 'member');
        [$code, , $verifier] = $this->authorizeCode($user);

        return [$user, $this->exchange($code, $verifier)->json('access_token')];
    }

    public function test_bar_shows_this_apps_and_every_app_notes_with_unread_count_then_marks_read(): void
    {
        $other = Application::create(['key' => 'cebpos', 'name' => 'CebPOS', 'status' => 'live']);
        $mine = ReleaseNote::create(['app_id' => $this->xp->id, 'version' => 'v0.17.0', 'title' => 'Faster receipts', 'published_at' => now()->subHour()]);
        $all = ReleaseNote::create(['app_id' => null, 'title' => 'Clauth bar is here', 'published_at' => now()->subMinutes(5)]);
        ReleaseNote::create(['app_id' => $other->id, 'title' => 'POS only', 'published_at' => now()]);          // other app
        ReleaseNote::create(['app_id' => $this->xp->id, 'title' => 'Later', 'published_at' => now()->addDay()]); // scheduled
        [, $token] = $this->token();

        $up = $this->withToken($token)->getJson('/api/v1/bar')->assertOk()->json('updates');
        $this->assertSame(2, $up['unread']);
        $this->assertSame([$all->id, $mine->id], array_column($up['items'], 'id'));
        $this->assertSame('Every app', $up['items'][0]['app']);

        $this->withToken($token)->postJson('/api/v1/bar/read', ['ids' => [$mine->id, 999999]])->assertOk()->assertJsonPath('read', 1);
        $this->assertSame(1, $this->withToken($token)->getJson('/api/v1/bar')->json('updates.unread'));
        $this->withToken($token)->postJson('/api/v1/bar/read', ['all' => true])->assertOk();
        $this->assertSame(0, $this->withToken($token)->getJson('/api/v1/bar')->json('updates.unread'));
    }

    public function test_app_can_post_its_own_release_note_and_same_version_updates_it(): void
    {
        $m2m = new OAuthClient;
        $m2m->forceFill(['app_id' => $this->xp->id, 'type' => 'm2m', 'name' => 'deploy', 'secret' => 'deploy-secret-123', 'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'revoked' => false])->save();
        $get = fn ($scope) => $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $m2m->id, 'client_secret' => 'deploy-secret-123', 'scope' => $scope])->json('access_token');

        $this->withToken($get('users:read'))->postJson('/api/v1/release-notes', ['title' => 'x'])->assertStatus(401);
        $t = $get('updates:write');
        $this->withToken($t)->postJson('/api/v1/release-notes', ['version' => 'v1.2.0', 'title' => 'First'])->assertCreated();
        $this->withToken($t)->postJson('/api/v1/release-notes', ['version' => 'v1.2.0', 'title' => 'Fixed title'])->assertOk();
        $this->assertSame(1, ReleaseNote::where('app_id', $this->xp->id)->count());
        $this->assertSame('Fixed title', ReleaseNote::first()->title);
        $this->assertSame('api', ReleaseNote::first()->source);
    }

    public function test_owner_posts_update_from_admin(): void
    {
        $owner = $this->makeUser('owner@clauth.test', ['name' => 'William Donayre', 'clauth_role' => 'owner']);
        $owner->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $as = $this->actingAs($owner)->withSession(['auth.mfa' => true]);
        $as->get('/admin/updates')->assertOk()->assertSee('Post an update');
        $as->post('/admin/updates', ['app_id' => $this->xp->id, 'version' => 'v0.17.0', 'title' => 'Faster receipts', 'body' => "Uploads in the background\nBigger photos"])->assertRedirect('/admin/updates');
        $as->get('/admin/updates')->assertSee('Faster receipts')->assertSee('read by 0');
    }
}
