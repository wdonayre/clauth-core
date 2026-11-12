<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Which organizations may use which apps (SPEC-organizations §5). An app is used in another organization only after
 * that organization's admin approved it. Removing an installation removes the grants it carried and signs those
 * people out of the app.
 */
class Installations
{
    /** Can $actor approve installs for $org (Clauth owner, or org owner/admin)? */
    public static function canApprove(User $actor, Organization $org): bool
    {
        return $actor->isOwner() || in_array($org->id, $actor->adminOrgIds(), true);
    }

    public static function status(Application $app, Organization $org): ?string
    {
        return DB::table('app_installations')->where('app_id', $app->id)->where('organization_id', $org->id)->value('status');
    }

    /**
     * Make $app available in $org. Active right away when $actor can approve for $org; otherwise a request
     * waiting for $org's admin (only for apps marked installable). Returns the resulting status.
     */
    public static function add(Application $app, Organization $org, User $actor): string
    {
        $current = self::status($app, $org);
        if ($current === 'active') {
            return 'active';
        }
        if (self::canApprove($actor, $org)) {
            DB::table('app_installations')->updateOrInsert(['app_id' => $app->id, 'organization_id' => $org->id], [
                'status' => 'active', 'requested_by' => $actor->id, 'approved_by' => $actor->id, 'approved_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::log('app.installed', 'app', $app, after: ['org' => $org->slug], appId: $app->id, orgId: $org->id);

            return 'active';
        }
        abort_unless($app->visibility === 'installable', 422, 'This app can only be used by its own organization. Mark it “Other organizations can request it” first.');
        if (! $current) {
            DB::table('app_installations')->insert(['app_id' => $app->id, 'organization_id' => $org->id, 'status' => 'requested',
                'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::log('app.install_requested', 'app', $app, after: ['org' => $org->slug], appId: $app->id, orgId: $org->id);
        }

        return 'requested';
    }

    public static function approve(Application $app, Organization $org, User $actor): void
    {
        abort_unless(self::canApprove($actor, $org) && self::status($app, $org) === 'requested', 404);
        DB::table('app_installations')->where('app_id', $app->id)->where('organization_id', $org->id)
            ->update(['status' => 'active', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_at' => now()]);
        Audit::log('app.installed', 'app', $app, after: ['org' => $org->slug, 'approved_request' => true], appId: $app->id, orgId: $org->id);
    }

    /** Remove (or decline) an installation; its grants go and those people are signed out of the app. */
    public static function remove(Application $app, Organization $org): void
    {
        $was = self::status($app, $org);
        if (! $was) {
            return;
        }
        foreach (Grant::where('app_id', $app->id)->where('organization_id', $org->id)->with('user')->get() as $g) {
            $g->delete();
            if ($g->user) {
                Sessions::revokeApp($g->user, $app->id);
            }
        }
        DB::table('app_installations')->where('app_id', $app->id)->where('organization_id', $org->id)->delete();
        Audit::log($was === 'active' ? 'app.uninstalled' : 'app.install_declined', 'app', $app, before: ['org' => $org->slug], appId: $app->id, orgId: $org->id);
    }

    /** Bring an app's active installations in line with $orgIds (only organizations $actor may change). */
    public static function syncFor(Application $app, array $orgIds, User $actor): void
    {
        foreach (Organization::whereIn('id', $orgIds)->get() as $org) {
            if (self::canApprove($actor, $org)) {
                self::add($app, $org, $actor);
            }
        }
        foreach ($app->organizations()->get() as $org) {
            if (! in_array($org->id, $orgIds, true) && self::canApprove($actor, $org) && $org->id !== $app->owner_organization_id) {
                self::remove($app, $org);
            }
        }
    }
}
