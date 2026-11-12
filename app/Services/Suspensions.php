<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Suspending an organization or an app (SPEC-organizations §10.4): its apps' sign-ins and token refreshes fail at
 * once, its sign-in domains fall back to the main domain, and its console turns read-only for its staff.
 */
class Suspensions
{
    public static function suspendOrg(Organization $org, string $reason): void
    {
        $org->update(['suspended_at' => now(), 'suspend_reason' => $reason]);
        foreach ($org->publishedApps as $app) {
            self::cutTokens($app);
        }
        self::flushDomains($org);
        Audit::log('org.suspended', 'admin', $org, after: ['reason' => $reason], orgId: $org->id);
    }

    public static function restoreOrg(Organization $org): void
    {
        $org->update(['suspended_at' => null, 'suspend_reason' => null]);
        self::flushDomains($org);
        Audit::log('org.unsuspended', 'admin', $org, orgId: $org->id);
    }

    public static function suspendApp(Application $app, string $reason): void
    {
        $app->update(['suspended_at' => now(), 'suspend_reason' => $reason]);
        self::cutTokens($app);
        Audit::log('app.suspended', 'app', $app, after: ['reason' => $reason], appId: $app->id, orgId: $app->owner_organization_id);
    }

    public static function restoreApp(Application $app): void
    {
        $app->update(['suspended_at' => null, 'suspend_reason' => null]);
        Audit::log('app.unsuspended', 'app', $app, appId: $app->id, orgId: $app->owner_organization_id);
    }

    /** Access tokens stop working right away (refresh is refused by the token endpoint). */
    private static function cutTokens(Application $app): void
    {
        $clients = $app->clients()->pluck('id');
        $ids = DB::table('oauth_access_tokens')->whereIn('client_id', $clients)->where('revoked', false)->pluck('id');
        DB::table('oauth_access_tokens')->whereIn('id', $ids)->update(['revoked' => true]);
        DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $ids)->update(['revoked' => true]);
    }

    private static function flushDomains(Organization $org): void
    {
        foreach (DB::table('app_domains')->where('organization_id', $org->id)->pluck('host') as $h) {
            Domains::flush($h);
        }
    }
}
