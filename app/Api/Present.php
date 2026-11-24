<?php

namespace App\Api;

use App\Models\AccessProfile;
use App\Models\Application;
use App\Models\AppRole;
use App\Models\AuditEvent;
use App\Models\Grant;
use App\Models\Invite;
use App\Models\OAuthClient;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** JSON shapes of /api/v1 (SPEC-platform §5): readable keys (usr_ sub, org slug, app key, role key, profile key). */
class Present
{
    /** {data:[…], next_cursor} with ?limit (default 50, max 200) and ?cursor. */
    public static function page(Builder $q, callable $map, Request $request, string $orderBy = 'id'): JsonResponse
    {
        $limit = max(1, min(200, (int) $request->query('limit', 50)));
        /** @var CursorPaginator $p */
        $p = $q->orderBy($orderBy)->cursorPaginate($limit);

        return response()->json(['data' => collect($p->items())->map($map)->values(), 'next_cursor' => $p->nextCursor()?->encode()]);
    }

    public static function user(User $u, ?array $orgIds = null, bool $detail = false): array
    {
        $orgs = $u->organizations->when($orgIds !== null, fn ($c) => $c->whereIn('id', $orgIds));
        $out = [
            'id' => $u->sub(), 'email' => $u->email, 'name' => $u->name, 'phone' => $u->phone,
            'status' => $u->status, 'locked' => $u->isLocked(), 'two_step' => (bool) $u->hasSecondFactor(), 'two_step_method' => $u->hasTwoFactor() ? 'app' : ($u->hasSmsTwoStep() ? 'sms' : null), 'two_step_exempt' => (bool) $u->mfa_exempt,
            'last_login_at' => $u->last_login_at?->toIso8601String(), 'created_at' => $u->created_at?->toIso8601String(),
            'orgs' => $orgs->map(fn ($o) => ['org' => $o->slug, 'role' => $o->pivot->role ?? 'member'])->values(),
        ];
        if ($detail) {
            $out['grants'] = Grant::with(['app', 'role', 'organization'])->where('user_id', $u->id)
                ->when($orgIds !== null, fn ($q) => $q->whereIn('organization_id', $orgIds))->get()->map(fn ($g) => self::grant($g))->values();
            $out['profiles'] = $u->accessProfiles()->with('organization')->when($orgIds !== null, fn ($q) => $q->whereIn('organization_id', $orgIds))->get()
                ->map(fn ($p) => ['org' => $p->organization->slug, 'profile' => $p->key, 'name' => $p->name])->values();
            $out['sign_in_methods'] = array_values(array_filter([$u->password ? 'password' : null, ...$u->socialIdentities->pluck('provider')->all()]));
        }

        return $out;
    }

    public static function org(Organization $o): array
    {
        return [
            'id' => $o->id, 'slug' => $o->slug, 'name' => $o->name, 'status' => $o->isSuspended() ? 'suspended' : ($o->status ?? 'active'),
            'verified' => $o->verified_at !== null, 'created_at' => $o->created_at?->toIso8601String(),
        ];
    }

    public static function app(Application $a, bool $detail = false): array
    {
        $out = [
            'key' => $a->key, 'name' => $a->name, 'status' => $a->isSuspended() ? 'suspended' : $a->status, 'home_url' => $a->home_url,
            'publisher' => $a->publisher?->slug, 'manifest_url' => $a->manifest_url, 'manifest_synced_at' => $a->manifest_synced_at?->toIso8601String(),
        ];
        if ($detail) {
            $out['roles'] = $a->roles()->with('permissions')->orderBy('position')->get()->map(fn ($r) => self::role($r))->values();
            $out['permissions'] = $a->permissions()->orderBy('key')->get()->map(fn ($p) => ['key' => $p->key, 'description' => $p->description, 'source' => $p->source, 'deprecated' => $p->deprecated_at !== null])->values();
            $out['clients'] = $a->clients()->get()->map(fn ($c) => self::client($c))->values();
        }

        return $out;
    }

    public static function role(AppRole $r): array
    {
        return ['key' => $r->key, 'name' => $r->name, 'description' => $r->description, 'tier' => $r->tier, 'assignable' => (bool) $r->assignable,
            'aliases' => $r->aliases ?? [], 'source' => $r->source, 'permissions' => $r->permissionKeys()];
    }

    public static function client(OAuthClient $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'type' => $c->type, 'revoked' => (bool) $c->revoked,
            'redirect_uris' => $c->redirect_uris ?? [], 'scopes' => $c->scopes, 'secret_expires_at' => $c->secret_expires_at?->toIso8601String(),
            'last_used_at' => $c->last_used_at?->toIso8601String()];
    }

    public static function grant(Grant $g): array
    {
        return ['id' => $g->id, 'user' => $g->user?->sub() ?? 'usr_'.$g->user_id, 'org' => $g->organization?->slug, 'app' => $g->app?->key,
            'role' => $g->role?->key, 'source' => $g->source, 'granted_at' => $g->granted_at?->toIso8601String()];
    }

    public static function profile(AccessProfile $p, bool $detail = false): array
    {
        $out = ['key' => $p->key, 'name' => $p->name, 'description' => $p->description, 'org' => $p->organization?->slug];
        if ($detail) {
            $apps = Application::whereIn('id', array_keys($p->mapping()))->pluck('key', 'id');
            $roles = AppRole::whereIn('id', array_filter($p->mapping()))->pluck('key', 'id');
            $out['apps'] = collect($p->mapping())->mapWithKeys(fn ($roleId, $appId) => [$apps[$appId] ?? $appId => $roleId ? ($roles[$roleId] ?? null) : 'none']);
            $out['people'] = $p->users()->orderBy('name')->get()->map(fn ($u) => $u->sub())->values();
        }

        return $out;
    }

    public static function invite(Invite $i): array
    {
        return ['id' => $i->id, 'email' => $i->email, 'name' => $i->name, 'org' => $i->organization?->slug,
            'status' => $i->accepted_at ? 'accepted' : ($i->revoked_at ? 'revoked' : ($i->expires_at?->isPast() ? 'expired' : 'pending')),
            'profiles' => AccessProfile::whereIn('id', $i->profiles ?? [])->pluck('key')->values(),
            'require_two_step' => (bool) $i->require_mfa, 'expires_at' => $i->expires_at?->toIso8601String(), 'created_at' => $i->created_at?->toIso8601String()];
    }

    public static function audit(AuditEvent $e): array
    {
        return ['id' => $e->id, 'at' => $e->at->toIso8601String(), 'event' => $e->event, 'category' => $e->category,
            'actor' => $e->actor_user_id ? 'usr_'.$e->actor_user_id : null, 'actor_type' => $e->actor_type, 'actor_client' => $e->actor_client_id,
            'subject' => $e->subject_type ? $e->subject_type.':'.$e->subject_id : null, 'app' => $e->app?->key, 'org' => $e->organization?->slug,
            'ip' => $e->ip, 'before' => $e->before, 'after' => $e->after, 'request_id' => $e->request_id];
    }
}
