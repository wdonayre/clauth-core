<?php

namespace App\Services;

use App\Mail\InviteMail;
use App\Models\Grant;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class Invites
{
    /** Creates an invite and returns [invite, plainToken]. */
    public static function create(array $data, ?User $by): array
    {
        $token = Str::random(48);
        $invite = Invite::create([
            'email' => mb_strtolower(trim($data['email'])),
            'name' => $data['name'],
            'organization_id' => $data['organization_id'] ?? null,
            'grants' => array_values($data['grants'] ?? []),
            'clauth_role' => $data['clauth_role'] ?? null,
            'is_org_admin' => (bool) ($data['is_org_admin'] ?? false),
            'require_mfa' => (bool) ($data['require_mfa'] ?? false),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
            'invited_by' => $by?->id,
        ]);
        $user = User::firstOrCreate(['email' => $invite->email], ['name' => $invite->name, 'status' => 'invited']);
        Audit::log('user.invited', 'admin', $user, after: ['email' => $invite->email, 'grants' => $invite->grants], orgId: $invite->organization_id);

        return [$invite, $token];
    }

    /** Invite links open on the invited app's own sign-in domain when it has one. */
    public static function url(string $token, ?Invite $invite = null): string
    {
        return $invite ? Domains::urlFor($invite->primaryApp(), 'invite/'.$token) : route('invite.show', $token);
    }

    public static function send(Invite $invite, string $token): void
    {
        Mail::to($invite->email)->send(new InviteMail($invite, self::url($token, $invite)));
    }

    /** Joins the org, creates grants, applies admin roles; marks the invite used. */
    public static function apply(Invite $invite, User $user): void
    {
        if ($invite->organization_id) {
            $user->organizations()->syncWithoutDetaching([$invite->organization_id => ['joined_at' => now()]]);
            if ($invite->is_org_admin) {
                $user->organizations()->updateExistingPivot($invite->organization_id, ['role' => 'admin']);
            }
            foreach ($invite->grants ?? [] as $g) {
                Grant::updateOrCreate(
                    ['user_id' => $user->id, 'app_id' => $g['app_id'], 'organization_id' => $invite->organization_id],
                    ['app_role_id' => $g['role_id'], 'granted_by' => $invite->invited_by, 'granted_at' => now()],
                );
            }
        }
        if ($invite->clauth_role === 'owner') {
            $user->forceFill(['clauth_role' => 'owner'])->save();
        }
        $invite->update(['accepted_at' => now()]);
        Audit::log('user.invite_accepted', 'admin', $user, after: ['invite' => $invite->id], orgId: $invite->organization_id, actorId: $user->id, actorType: 'user');
    }
}
