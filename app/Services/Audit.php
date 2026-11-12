<?php

namespace App\Services;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Writes audit_events for every admin mutation and security event (SPEC §10.8). */
class Audit
{
    private static ?string $requestId = null;

    public static function log(
        string $event,
        string $category,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?string $appId = null,
        ?string $orgId = null,
        ?string $actorId = null,
        ?string $actorType = null,
    ): AuditEvent {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $user = $actorId === null ? auth()->user() : null;
        $actorId ??= $user?->id;
        $actorType ??= $actorId ? (($user && $user->isAdmin() && $category === 'admin') || $category === 'role' ? 'admin' : 'user') : 'system';

        return AuditEvent::create([
            'at' => now(),
            'actor_user_id' => $actorId,
            'actor_type' => $actorType,
            'event' => $event,
            'category' => $category,
            'subject_type' => $subject ? Str::snake(class_basename($subject)) : null,
            'subject_id' => $subject?->getKey(),
            'app_id' => $appId,
            'organization_id' => $orgId,
            'ip' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 500, '') : null,
            'before' => $before,
            'after' => $after,
            'request_id' => self::$requestId ??= (string) Str::ulid(),
        ]);
    }

    public static function label(string $event): string
    {
        return [
            'signin.success' => 'Signed in',
            'signin.failed' => 'Failed sign-in',
            'signin.denied' => 'Sign-in denied (no access)',
            'signin.locked' => 'Account locked',
            'security.mfa_enabled' => '2-step verification turned on',
            'security.mfa_reset' => '2-step verification reset',
            'security.recovery_code_used' => 'Recovery code used',
            'security.refresh_reuse' => 'Refresh token reuse — sessions revoked',
            'security.password_changed' => 'Password changed',
            'security.password_reset' => 'Password reset',
            'security.signout_everywhere' => 'Signed out everywhere',
            'security.key_staged' => 'Signing key staged',
            'security.key_activated' => 'Signing key activated',
            'security.key_retired' => 'Signing key retired',
            'grant.changed' => 'App role changed',
            'grant.removed' => 'App access removed',
            'user.invited' => 'User invited',
            'user.invite_accepted' => 'Invite accepted',
            'user.updated' => 'User updated',
            'user.disabled' => 'User disabled',
            'user.enabled' => 'User enabled',
            'user.unlocked' => 'User unlocked',
            'app.registered' => 'App registered',
            'app.updated' => 'App updated',
            'app.secret_rotated' => 'Client secret rotated',
            'app.client_added' => 'Client added',
            'app.manifest_synced' => 'Manifest synced',
            'app.role_changed' => 'Role permissions changed',
            'app.branding_changed' => 'Sign-in page changed',
            'app.release_note' => 'Release note posted or changed',
            'app.connected' => 'App connected (first sign-in through Clauth)',
            'app.domain_added' => 'Custom sign-in domain added',
            'app.domain_removed' => 'Custom sign-in domain removed',
            'app.domain_active' => 'Custom sign-in domain is live',
            'app.domain_problem' => 'Custom sign-in domain has a problem',
            'org.created' => 'Organization created',
            'org.updated' => 'Organization updated',
            'settings.updated' => 'Settings updated',
        ][$event] ?? $event;
    }
}
