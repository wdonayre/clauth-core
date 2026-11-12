<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Consent;
use App\Models\User;

/**
 * When a person must OK an app before it receives their details (SPEC-organizations §6.1): never for apps published by
 * William's own (platform) organizations or by an organization the person belongs to; otherwise once per app, and
 * again only when the app asks for more.
 */
class Consents
{
    /** Scopes that carry personal data (others, like offline_access, don't need to be shown). */
    private const SHOWN = ['openid', 'profile', 'email', 'clauth'];

    public static function exempt(User $user, Application $app): bool
    {
        return $app->isFirstParty() || $user->organizations()->where('organizations.id', $app->owner_organization_id)->exists();
    }

    /** Scopes the person still has to OK for this request ([] = nothing to ask). */
    public static function missing(User $user, Application $app, array $scopes): array
    {
        if (self::exempt($user, $app)) {
            return [];
        }
        $asked = array_values(array_intersect(self::SHOWN, $scopes ?: self::SHOWN));
        $c = Consent::where('user_id', $user->id)->where('app_id', $app->id)->whereNull('revoked_at')->first();

        return array_values(array_diff($asked, $c?->scopes ?? []));
    }

    /** Has this person an active consent for the app (for machine-to-machine lookups)? */
    public static function has(User $user, Application $app): bool
    {
        return self::exempt($user, $app) || Consent::where('user_id', $user->id)->where('app_id', $app->id)->whereNull('revoked_at')->exists();
    }

    public static function grant(User $user, Application $app, array $scopes): void
    {
        $c = Consent::firstOrNew(['user_id' => $user->id, 'app_id' => $app->id]);
        $prev = $c->exists && ! $c->revoked_at ? ($c->scopes ?? []) : [];
        $c->fill(['scopes' => array_values(array_unique(array_merge($prev, array_intersect(self::SHOWN, $scopes)))), 'granted_at' => now(), 'revoked_at' => null])->save();
        Audit::log('consent.granted', 'signin', $user, after: ['app' => $app->key, 'scopes' => $c->scopes], appId: $app->id, actorId: $user->id, actorType: 'user');
    }

    /** "Remove access" in My account: the app loses its consent and the person is signed out of it. */
    public static function revoke(User $user, Application $app): void
    {
        Consent::where('user_id', $user->id)->where('app_id', $app->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        Sessions::revokeApp($user, $app->id);
        Audit::log('consent.revoked', 'security', $user, before: ['app' => $app->key], appId: $app->id, actorId: $user->id, actorType: 'user');
    }
}
