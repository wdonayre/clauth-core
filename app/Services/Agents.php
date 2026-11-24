<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Application;
use App\Models\AppRole;
use App\Models\OAuthClient;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Agents (SPEC-platform §9.3): creation, credentials, access and the claims their tokens carry. */
class Agents
{
    public static function create(Organization $org, ?User $owner, string $name, ?string $description, ?User $by): Agent
    {
        $a = Agent::create(['organization_id' => $org->id, 'owner_user_id' => $owner?->id, 'name' => $name, 'description' => $description, 'created_by' => $by?->id]);
        Audit::log('agent.created', 'admin', $a, after: ['name' => $name, 'personal' => (bool) $owner], orgId: $org->id, actorId: $by?->id);

        return $a;
    }

    public static function client(Agent $a): ?OAuthClient
    {
        return OAuthClient::where('type', 'agent')->where('agent_id', $a->id)->where('revoked', false)->first();
    }

    /** New credentials (the old ones stop working). Returns [client_id, secret]; the secret is shown once. */
    public static function rotate(Agent $a, ?User $by): array
    {
        OAuthClient::where('type', 'agent')->where('agent_id', $a->id)->get()->each(function ($c) {
            $c->forceFill(['revoked' => true])->save();
            DB::table('oauth_access_tokens')->where('client_id', $c->id)->update(['revoked' => true]);
        });
        $secret = Str::random(48);
        $c = new OAuthClient;
        $c->forceFill(['type' => 'agent', 'name' => 'Agent: '.$a->name, 'secret' => $secret, 'agent_id' => $a->id, 'organization_id' => $a->organization_id,
            'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'revoked' => false, 'secret_expires_at' => now()->addYear()])->save();
        Audit::log('agent.secret_rotated', 'security', $a, orgId: $a->organization_id, actorId: $by?->id);

        return [$c->id, $secret];
    }

    /** Set an organization agent's role per app ({app_key: role_key|null}). Owner-tier roles need an owner of the org. */
    public static function setAccess(Agent $a, array $apps, User $by): void
    {
        abort_if($a->isPersonal(), 422, 'A person’s own agent has the same access as that person; it has no roles of its own.');
        $org = $a->organization;
        foreach ($apps as $key => $roleKey) {
            $app = $org->apps()->where('apps.key', $key)->first();
            abort_unless($app, 422, "{$key} is not installed in {$org->slug}.");
            if ($roleKey === null || $roleKey === 'none') {
                DB::table('agent_grants')->where(['agent_id' => $a->id, 'app_id' => $app->id])->delete();

                continue;
            }
            $role = AppRole::where('app_id', $app->id)->where('key', $roleKey)->first();
            abort_unless($role && $role->assignable, 422, "{$roleKey} is not a role that can be given in {$key}.");
            abort_if($role->tier === 'owner' && ! Access::canTouchOwnerTier($by, $org->id), 403, 'Only an owner of the organization can give an agent an Owner-tier role.');
            DB::table('agent_grants')->updateOrInsert(['agent_id' => $a->id, 'app_id' => $app->id], ['app_role_id' => $role->id, 'updated_at' => now(), 'created_at' => now()]);
        }
        Audit::log('agent.access_changed', 'role', $a, after: $apps, orgId: $org->id, actorId: $by->id);
    }

    /** {app_key: role_key} of an organization agent. */
    public static function access(Agent $a): array
    {
        return DB::table('agent_grants')->join('apps', 'apps.id', '=', 'agent_grants.app_id')->join('app_roles', 'app_roles.id', '=', 'agent_grants.app_role_id')
            ->where('agent_id', $a->id)->pluck('app_roles.key', 'apps.key')->all();
    }

    /** The orgs claim for the agent's token to $app (same shape as people's). Empty = no access. */
    public static function claims(Agent $a, Application $app): array
    {
        if (! $a->usable() || ! $app->organizations()->where('organizations.id', $a->organization_id)->exists()) {
            return [];
        }
        if ($a->isPersonal()) {
            $all = Claims::orgs($a->owner, $app);

            return array_intersect_key($all, [$a->organization->slug => 1]);
        }
        $roleId = DB::table('agent_grants')->where(['agent_id' => $a->id, 'app_id' => $app->id])->value('app_role_id');
        $role = $roleId ? AppRole::with('permissions')->find($roleId) : null;
        if (! $role) {
            return [];
        }
        $perms = $role->permissionKeys();
        sort($perms);

        return [$a->organization->slug => ['roles' => $role->tokenKeys(), 'permissions' => $perms]];
    }

    public static function json(Agent $a): array
    {
        $c = self::client($a);

        return ['id' => $a->sub(), 'name' => $a->name, 'description' => $a->description, 'org' => $a->organization?->slug,
            'owner' => $a->owner?->sub(), 'status' => $a->status, 'usable' => $a->usable(), 'client_id' => $c?->id,
            'access' => $a->isPersonal() ? 'same as owner' : (object) self::access($a),
            'last_used_at' => $a->last_used_at?->toIso8601String(), 'created_at' => $a->created_at?->toIso8601String()];
    }
}
