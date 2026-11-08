<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\AppPermission;
use App\Models\AppRole;
use App\Models\Grant;
use App\Models\OAuthClient;
use App\Models\Organization;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * LOCAL DEMO DATA ONLY (never run in production). Test login for the local owner:
 *   email owner@clauth.test, password from LOCAL_DEMO_PASSWORD (default below), TOTP secret printed by the seeder.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        abort_if(app()->environment('production'), 403, 'Demo seeder is local only.');
        Artisan::call('clauth:seed-orgs');
        $cbg = Organization::where('slug', 'cakesbygrace')->first();
        $alab = Organization::where('slug', 'alab-apparel')->first();

        $app = Application::firstOrCreate(['key' => 'demo'], ['name' => 'Demo App', 'color' => '#FBBF24', 'status' => 'live',
            'home_url' => 'http://127.0.0.1:8021', 'login_theme' => 'daylight', 'accent_color' => '#FBBF24']);
        $app->organizations()->sync([$cbg->id, $alab->id]);
        foreach (['expense.submit' => 'Create and submit expense claims', 'expense.approve' => 'Approve or reject claims', 'report.export' => 'Export reports'] as $k => $d) {
            AppPermission::firstOrCreate(['app_id' => $app->id, 'key' => $k], ['description' => $d, 'source' => 'manual']);
        }
        $member = AppRole::firstOrCreate(['app_id' => $app->id, 'key' => 'member'], ['name' => 'Member']);
        $finance = AppRole::firstOrCreate(['app_id' => $app->id, 'key' => 'finance'], ['name' => 'Finance', 'position' => 1]);
        $member->permissions()->sync(AppPermission::where('key', 'expense.submit')->pluck('id'));
        $finance->permissions()->sync(AppPermission::where('app_id', $app->id)->pluck('id'));
        if (! $app->clients()->exists()) {
            $c = new OAuthClient;
            $c->forceFill(['app_id' => $app->id, 'type' => 'public', 'name' => 'Demo App (public)', 'secret' => null,
                'redirect_uris' => ['http://127.0.0.1:8021/callback.php'], 'post_logout_redirect_uris' => ['http://127.0.0.1:8021/'],
                'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false])->save();
        }

        file_put_contents(base_path('tools/demo-client/.client_id'), $app->clients()->first()->id);

        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $owner = User::updateOrCreate(['email' => 'owner@clauth.test'], ['name' => 'Local Owner', 'status' => 'active', 'email_verified_at' => now(),
            'password' => env('LOCAL_DEMO_PASSWORD', 'local-demo-password-123'), 'clauth_role' => 'owner']);
        $owner->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => TwoFactor::recoveryCodes()])->save();
        $owner->organizations()->syncWithoutDetaching([$cbg->id, $alab->id]);
        Grant::updateOrCreate(['user_id' => $owner->id, 'app_id' => $app->id, 'organization_id' => $cbg->id], ['app_role_id' => $finance->id]);

        $maria = User::updateOrCreate(['email' => 'maria@clauth.test'], ['name' => 'Maria Santos', 'status' => 'active', 'email_verified_at' => now(),
            'password' => env('LOCAL_DEMO_PASSWORD', 'local-demo-password-123')]);
        $maria->organizations()->syncWithoutDetaching([$cbg->id]);
        Grant::updateOrCreate(['user_id' => $maria->id, 'app_id' => $app->id, 'organization_id' => $cbg->id], ['app_role_id' => $member->id]);

        $this->command?->info("Local owner owner@clauth.test — TOTP secret $secret (local only).");
    }
}
