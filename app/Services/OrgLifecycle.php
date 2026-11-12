<?php

namespace App\Services;

use App\Models\AppDomain;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Consent;
use App\Models\Grant;
use App\Models\Invite;
use App\Models\Organization;
use App\Models\User;
use App\Support\Dns;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** An organization's own domain proof, data export and deletion (SPEC-organizations §8, §10.5). */
class OrgLifecycle
{
    public const GRACE_DAYS = 30;

    public static function txtName(Organization $org): string
    {
        return '_clauth-org.'.$org->domain;
    }

    public static function txtValue(Organization $org): string
    {
        return 'clauth-org='.$org->domain_token;
    }

    public static function setDomain(Organization $org, string $domain): void
    {
        $org->update(['domain' => strtolower($domain), 'domain_token' => Str::lower(Str::random(32)), 'domain_verified_at' => null]);
        Audit::log('org.domain_set', 'admin', $org, after: ['domain' => $org->domain], orgId: $org->id);
    }

    public static function checkDomain(Organization $org): bool
    {
        $ok = $org->domain && in_array(self::txtValue($org), array_map('trim', app(Dns::class)->txt(self::txtName($org))), true);
        if ($ok && ! $org->domain_verified_at) {
            $org->update(['domain_verified_at' => now()]);
            Audit::log('org.domain_verified', 'admin', $org, after: ['domain' => $org->domain], orgId: $org->id);
        }

        return $ok;
    }

    /** Everything Clauth holds about one organization, as plain data (no secrets, no other organizations). */
    public static function export(Organization $org): array
    {
        $people = $org->users()->orderBy('name')->get();
        $apps = $org->apps()->with('publisher')->get();

        return [
            'exported_at' => now()->toIso8601String(),
            'organization' => $org->only('slug', 'name', 'status', 'domain', 'created_at') + ['verified' => (bool) $org->verified_at],
            'people' => $people->map(fn (User $u) => ['sub' => $u->sub(), 'name' => $u->name, 'email' => $u->email, 'status' => $u->status,
                'role' => $u->pivot->role, 'joined_at' => $u->pivot->joined_at])->values(),
            'apps_used' => $apps->map(fn (Application $a) => ['key' => $a->key, 'name' => $a->name, 'publisher' => $a->publisher?->slug])->values(),
            'apps_published' => $org->publishedApps()->get(['key', 'name', 'status', 'visibility', 'created_at']),
            'access' => Grant::with(['user', 'app', 'role'])->where('organization_id', $org->id)->get()
                ->map(fn ($g) => ['email' => $g->user?->email, 'app' => $g->app?->key, 'role' => $g->role?->key, 'granted_at' => $g->granted_at])->values(),
            'invites' => Invite::where('organization_id', $org->id)->get(['email', 'name', 'expires_at', 'accepted_at', 'revoked_at', 'created_at']),
            'audit' => AuditEvent::where('organization_id', $org->id)->where('at', '>=', now()->subYear())->orderBy('at')
                ->get(['at', 'event', 'category', 'actor_type', 'ip', 'before', 'after']),
        ];
    }

    public static function requestDeletion(Organization $org, User $by): void
    {
        $org->update(['deletion_requested_at' => now(), 'deletion_requested_by' => $by->id]);
        Audit::log('org.deletion_requested', 'admin', $org, after: ['purge_after' => now()->addDays(self::GRACE_DAYS)->toDateString()], orgId: $org->id);
    }

    public static function cancelDeletion(Organization $org): void
    {
        $org->update(['deletion_requested_at' => null, 'deletion_requested_by' => null]);
        Audit::log('org.deletion_cancelled', 'admin', $org, orgId: $org->id);
    }

    /**
     * Hard delete after the grace period: memberships, access, invites, installations, its published apps (with their
     * clients, roles, consents, tokens) and its sign-in domains. People who belong nowhere else are deleted too.
     */
    public static function purge(Organization $org): void
    {
        abort_if($org->is_platform, 422);
        DB::transaction(function () use ($org) {
            $userIds = DB::table('organization_user')->where('organization_id', $org->id)->pluck('user_id');
            foreach ($org->publishedApps()->get() as $app) {
                $clients = DB::table('oauth_clients')->where('app_id', $app->id)->pluck('id');
                DB::table('oauth_refresh_tokens')->whereIn('access_token_id', DB::table('oauth_access_tokens')->whereIn('client_id', $clients)->pluck('id'))->delete();
                DB::table('oauth_access_tokens')->whereIn('client_id', $clients)->delete();
                DB::table('oauth_auth_codes')->whereIn('client_id', $clients)->delete();
                DB::table('oauth_clients')->whereIn('id', $clients)->delete();
                Grant::where('app_id', $app->id)->delete();
                Consent::where('app_id', $app->id)->delete();
                DB::table('app_installations')->where('app_id', $app->id)->delete();
                $app->forceDelete();
            }
            AppDomain::where('organization_id', $org->id)->get()->each(function ($d) {
                Domains::flush($d->host);
                $d->delete();
            });
            Grant::where('organization_id', $org->id)->get()->each(fn ($g) => $g->user ? Sessions::revokeApp($g->user, $g->app_id) : null);
            Grant::where('organization_id', $org->id)->delete();
            Invite::where('organization_id', $org->id)->delete();
            DB::table('app_installations')->where('organization_id', $org->id)->delete();
            DB::table('organization_user')->where('organization_id', $org->id)->delete();
            foreach (User::whereIn('id', $userIds)->whereNull('clauth_role')->whereDoesntHave('organizations')->get() as $u) {
                Sessions::revokeAll($u);
                $u->forceDelete();
            }
            Audit::log('org.purged', 'admin', null, before: ['slug' => $org->slug, 'name' => $org->name], actorType: 'system');
            $org->forceDelete();
        });
    }
}
