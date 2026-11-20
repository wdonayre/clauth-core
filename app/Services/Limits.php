<?php

namespace App\Services;

use App\Models\AppDomain;
use App\Models\Application;
use App\Models\Invite;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Per-organization limits (SPEC-organizations §10.1). Platform organizations have none; unverified ones start low.
 * A limit that's hit is a clear error for the person and an audit event for the platform admin.
 */
class Limits
{
    private const LABELS = [
        'apps' => 'apps', 'people' => 'people', 'invites_per_day' => 'invites today', 'domains' => 'sign-in domains',
        'installs' => 'installations in other organizations', 'profiles' => 'access profiles',
    ];

    public static function used(Organization $org, string $key): int
    {
        return match ($key) {
            'apps' => Application::where('owner_organization_id', $org->id)->count(),
            'people' => DB::table('organization_user')->where('organization_id', $org->id)->count(),
            'invites_per_day' => Invite::where('organization_id', $org->id)->where('created_at', '>=', now()->startOfDay())->count(),
            'domains' => AppDomain::where('organization_id', $org->id)->count(),
            'installs' => DB::table('app_installations')->join('apps', 'apps.id', '=', 'app_installations.app_id')
                ->where('apps.owner_organization_id', $org->id)->where('app_installations.organization_id', '!=', $org->id)->count(),
            'profiles' => DB::table('access_profiles')->where('organization_id', $org->id)->count(),
            default => 0,
        };
    }

    /** Null when $adding more fits; otherwise the message to show (and an audit event is written). */
    public static function exceeded(?Organization $org, string $key, int $adding = 1): ?string
    {
        $max = $org?->limit($key);
        if ($org === null || $max === null || self::used($org, $key) + $adding <= $max) {
            return null;
        }
        Audit::log('limit.reached', 'admin', $org, after: ['limit' => $key, 'max' => $max], orgId: $org->id);
        $label = self::LABELS[$key] ?? $key;

        return $max === 0
            ? "{$org->name} can’t add {$label} yet".($org->verified_at ? '.' : ' — this needs a verified organization.')
            : "{$org->name} has reached its limit of {$max} {$label}".($org->verified_at ? '. Ask Clauth support to raise it.' : '. Verifying the organization raises it.');
    }
}
