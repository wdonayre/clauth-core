<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiContext;
use App\Api\Present;
use App\Models\AccessProfile;
use App\Models\AppRole;
use App\Models\Invite;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Invites;
use App\Services\Limits;
use Illuminate\Http\Request;

/** /api/v1/invites — invite someone into an organization with profiles and/or per-app roles (SPEC-platform §6.1). */
class InvitesController
{
    use UsesApi;

    public function index(Request $request)
    {
        $request->validate(['status' => 'nullable|in:pending,accepted,revoked,expired', 'org' => 'nullable|string']);
        $q = Invite::with('organization')->when($this->api()->orgIds() !== null, fn ($q) => $q->whereIn('organization_id', $this->api()->orgIds()))
            ->when($request->query('org'), fn ($q, $o) => $q->where('organization_id', $this->api()->org($o)->id));
        match ($request->query('status')) {
            'pending' => $q->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now()),
            'accepted' => $q->whereNotNull('accepted_at'),
            'revoked' => $q->whereNotNull('revoked_at'),
            'expired' => $q->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '<=', now()),
            default => null,
        };

        return Present::page($q, fn (Invite $i) => Present::invite($i), $request);
    }

    /**
     * {email, name, org, profiles:[key], apps:{app_key: role_key}, require_two_step, org_role, send}
     * Returns the invite and its link (send=false lets the caller deliver the link itself).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|max:190', 'name' => 'required|string|max:120', 'org' => 'required|string',
            'profiles' => 'array', 'profiles.*' => 'string', 'apps' => 'array', 'apps.*' => 'string',
            'require_two_step' => 'boolean', 'org_role' => 'nullable|in:member,developer,admin', 'send' => 'boolean',
        ]);
        $org = $this->api()->org($data['org']);
        $me = $this->api()->actor;
        $existing = User::where('email', mb_strtolower($data['email']))->first();
        abort_if($existing?->status === 'disabled', 409, 'That person’s account is disabled. Enable it first.');
        $isMember = $existing && $existing->organizations()->where('organizations.id', $org->id)->exists();
        if ($msg = Limits::exceeded($org, 'invites_per_day') ?? ($isMember ? null : Limits::exceeded($org, 'people'))) {
            abort(429, $msg);
        }
        $profiles = collect($data['profiles'] ?? [])->map(fn ($k) => $this->api()->profile($org, $k));
        foreach ($profiles as $p) {
            abort_if($p->hasOwnerTier() && ! Access::canTouchOwnerTier($me, $org->id), 403, "Only an owner of {$org->name} can invite someone as {$p->name}.");
        }
        $grants = [];
        foreach ($data['apps'] ?? [] as $appKey => $roleKey) {
            $app = $org->apps()->where('apps.key', $appKey)->first();
            abort_unless($app, 422, "{$appKey} is not installed in {$org->slug}.");
            $role = AppRole::where('app_id', $app->id)->where('key', $roleKey)->first();
            abort_unless($role && $role->assignable, 422, "{$roleKey} is not a role that can be given in {$appKey}.");
            abort_if($role->tier === 'owner' && ! Access::canTouchOwnerTier($me, $org->id), 403, "Only an owner of {$org->name} can give an Owner-tier role.");
            $grants[] = ['app_id' => $app->id, 'role_id' => $role->id];
        }
        $orgRole = $data['org_role'] ?? null;
        abort_if(in_array($orgRole, ['admin'], true) && ! Access::canTouchOwnerTier($me, $org->id), 403, 'Only an owner of the organization can invite an admin.');
        [$invite, $token] = Invites::create([
            'email' => $data['email'], 'name' => $data['name'], 'organization_id' => $org->id, 'grants' => $grants,
            'profiles' => $profiles->pluck('id')->all(), 'require_mfa' => (bool) ($data['require_two_step'] ?? false),
            'org_role' => $orgRole && $orgRole !== 'member' ? $orgRole : null,
        ], $me);
        $sent = false;
        if ($request->boolean('send', true)) {
            $sent = (bool) rescue(fn () => Invites::send($invite, $token) ?? true, false, report: true);
        }

        return response()->json(Present::invite($invite->load('organization')) + ['link' => Invites::url($token, $invite), 'email_sent' => $sent], 201);
    }

    public function destroy(string $invite)
    {
        $i = $this->find($invite);
        abort_if($i->accepted_at, 409, 'The invite was already accepted.');
        $i->update(['revoked_at' => now()]);
        Audit::log('user.invite_revoked', 'admin', $i, after: ['email' => $i->email], orgId: $i->organization_id);

        return response()->noContent();
    }

    /** New link (the old one stops working) and, unless send=false, a new email. */
    public function resend(Request $request, string $invite)
    {
        $old = $this->find($invite);
        abort_if($old->accepted_at || $old->revoked_at, 409, 'Only a pending or expired invite can be sent again.');
        $old->update(['revoked_at' => now()]);
        [$new, $token] = Invites::create([
            'name' => $old->name, 'email' => $old->email, 'organization_id' => $old->organization_id, 'grants' => $old->grants,
            'profiles' => $old->profiles, 'require_mfa' => $old->require_mfa, 'org_role' => $old->org_role,
        ], $this->api()->actor);
        $sent = $request->boolean('send', true) && (bool) rescue(fn () => Invites::send($new, $token) ?? true, false, report: true);

        return response()->json(Present::invite($new->load('organization')) + ['link' => Invites::url($token, $new), 'email_sent' => $sent], 201);
    }

    private function find(string $id): Invite
    {
        $i = Invite::with('organization')->find($id);
        abort_unless($i && $i->organization_id && $this->api()->canOrg($i->organization_id), 404, 'Invite not found.');

        return $i;
    }
}
