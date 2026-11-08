<?php

namespace App\OAuth;

use App\Models\OAuthClient;
use App\Models\User;
use App\Services\SigningKeys;
use DateTimeImmutable;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;

/** Adds an OIDC `id_token` to token responses when the `openid` scope was granted. */
class IdTokenResponse extends BearerTokenResponse
{
    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        $scopes = array_map(fn ($s) => $s->getIdentifier(), $accessToken->getScopes());
        $params = ['scope' => implode(' ', $scopes)];
        $userId = $accessToken->getUserIdentifier();
        if (! in_array('openid', $scopes, true) || $userId === null || ! ($user = User::find($userId))) {
            return $params;
        }

        $ctx = app(TokenContext::class);
        $key = SigningKeys::active();
        $config = SigningKeys::config($key);
        $now = new DateTimeImmutable;
        $clientId = $accessToken->getClient()->getIdentifier();
        $client = OAuthClient::find($clientId);

        $b = $config->builder(ChainedFormatter::withUnixTimestampDates())
            ->withHeader('kid', $key->kid)
            ->issuedBy(config('clauth.issuer'))
            ->permittedFor($clientId)
            ->relatedTo($user->sub())
            ->issuedAt($now)
            ->expiresAt($now->modify('+'.config('clauth.id_token_minutes').' minutes'))
            ->withClaim('azp', $clientId)
            ->withClaim('amr', $ctx->amr);
        if ($ctx->authTime) {
            $b = $b->withClaim('auth_time', $ctx->authTime);
        }
        if ($ctx->nonce) {
            $b = $b->withClaim('nonce', $ctx->nonce);
        }
        if (in_array('email', $scopes, true)) {
            $b = $b->withClaim('email', $user->email)->withClaim('email_verified', $user->email_verified_at !== null);
        }
        if (in_array('profile', $scopes, true)) {
            $b = $b->withClaim('name', $user->name);
        }
        if ($client?->app) {
            $b = $b->withClaim('app', $client->app->key);
        }
        // at_hash: left half of SHA-256 of the access token
        $at = (string) $accessToken->toString();
        $b = $b->withClaim('at_hash', rtrim(strtr(base64_encode(substr(hash('sha256', $at, true), 0, 16)), '+/', '-_'), '='));

        $params['id_token'] = $b->getToken($config->signer(), $config->signingKey())->toString();

        return $params;
    }
}
