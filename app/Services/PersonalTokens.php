<?php

namespace App\Services;

use App\Models\OAuthClient;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lcobucci\JWT\Encoding\ChainedFormatter;

/**
 * Personal API tokens (SPEC-platform §5, P1.1): a person makes a token in My account › API tokens and uses it as a
 * Bearer token on /api/v1. It acts as that person (never more than they can do in the console) and is a normal
 * Clauth JWT whose jti is a row in oauth_access_tokens, so it can be revoked like any other token.
 */
class PersonalTokens
{
    /** Scopes anyone can have on their own token. */
    public const SELF_SCOPES = ['me:read', 'me:write'];

    public const DAYS = [7, 30, 90];

    /** The one oauth_clients row personal tokens belong to (type "personal"). */
    public static function client(): OAuthClient
    {
        $c = OAuthClient::where('type', 'personal')->first();
        if ($c) {
            return $c;
        }
        $c = new OAuthClient;
        $c->forceFill(['type' => 'personal', 'name' => 'Personal API tokens', 'secret' => null, 'redirect_uris' => [],
            'grant_types' => [], 'revoked' => false])->save();

        return $c;
    }

    /** Scopes $user may put on a personal token: their own account, plus management scopes when they run something. */
    public static function allowedScopes(User $user): array
    {
        $out = self::SELF_SCOPES;
        if ($user->isOwner() || $user->adminOrgIds() !== []) {
            $out = array_merge($out, ['users:read', 'users:write', 'orgs:read', 'orgs:write', 'access:read', 'access:write', 'audit:read']);
        }
        if ($user->isOwner() || $user->devOrgIds() !== []) {
            $out = array_merge($out, ['apps:read', 'apps:write']);
        }
        if ($user->isOwner()) {
            $out = array_merge($out, ['settings:read', 'settings:write']);
        }

        return array_values(array_unique($out));
    }

    /** Returns [row id, jwt]. The JWT is shown once. */
    public static function create(User $user, string $name, array $scopes, int $days): array
    {
        $out = self::mint(self::client(), $user, $name, $scopes, $days, 'personal');
        Audit::log('api.personal_token_created', 'security', $user, after: ['name' => $name, 'scopes' => $scopes, 'days' => $days], actorId: $user->id, actorType: 'user');

        return $out;
    }

    /**
     * A long-lived Clauth JWT for $client (and $user, or none: sub = client_…), backed by an oauth_access_tokens row
     * so it can be revoked. Used for personal tokens and SCIM tokens (HR systems want one fixed bearer token).
     */
    public static function mint(OAuthClient $client, ?User $user, string $name, array $scopes, int $days, string $use): array
    {
        $id = Str::random(80);
        $exp = now()->addDays($days);
        DB::table('oauth_access_tokens')->insert(['id' => $id, 'user_id' => $user?->id, 'client_id' => $client->id, 'name' => $name,
            'scopes' => json_encode(array_values($scopes)), 'revoked' => false, 'created_at' => now(), 'updated_at' => now(), 'expires_at' => $exp]);
        $key = SigningKeys::active();
        $config = SigningKeys::config($key);
        $now = new DateTimeImmutable;
        $jwt = $config->builder(ChainedFormatter::withUnixTimestampDates())
            ->withHeader('kid', $key->kid)
            ->issuedBy(config('clauth.issuer'))
            ->permittedFor(config('clauth.issuer').'/api')
            ->identifiedBy($id)
            ->relatedTo($user ? $user->sub() : 'client_'.$client->id)
            ->issuedAt($now)->canOnlyBeUsedAfter($now)->expiresAt($exp->toDateTimeImmutable())
            ->withClaim('client_id', $client->id)
            ->withClaim('scope', implode(' ', $scopes))
            ->withClaim('token_use', $use)
            ->getToken($config->signer(), $config->signingKey())->toString();

        return [$id, $jwt];
    }

    public static function list(User $user)
    {
        return DB::table('oauth_access_tokens')->where('user_id', $user->id)->where('client_id', self::client()->id)
            ->orderByDesc('created_at')->get()->map(function ($r) {
                $r->scopes = json_decode((string) $r->scopes, true) ?: [];

                return $r;
            });
    }

    public static function revoke(User $user, string $id): bool
    {
        $n = DB::table('oauth_access_tokens')->where('user_id', $user->id)->where('client_id', self::client()->id)->where('id', $id)->update(['revoked' => true]);
        if ($n) {
            Audit::log('api.personal_token_revoked', 'security', $user, after: ['token' => substr($id, 0, 8)], actorId: $user->id, actorType: 'user');
        }

        return (bool) $n;
    }
}
