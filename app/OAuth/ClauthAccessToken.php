<?php

namespace App\OAuth;

use App\Models\Application;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\Claims;
use App\Services\SigningKeys;
use DateTimeImmutable;
use Laravel\Passport\Bridge\AccessToken;
use Lcobucci\JWT\Encoding\ChainedFormatter;

/**
 * Access token as an RS256 JWT signed with Clauth's rotating key (`kid` header), carrying the
 * per-app `orgs` claim (SPEC §6.2). Apps verify it offline against /.well-known/jwks.json.
 */
class ClauthAccessToken extends AccessToken
{
    private ?string $jwt = null;

    public function toString(): string
    {
        return $this->jwt ??= $this->build();
    }

    private function build(): string
    {
        $key = SigningKeys::active();
        $config = SigningKeys::config($key);
        $client = OAuthClient::find($this->getClient()->getIdentifier());
        $app = $client?->app;
        $now = new DateTimeImmutable;
        $scopes = array_map(fn ($s) => $s->getIdentifier(), $this->getScopes());
        $ctx = app(TokenContext::class);

        $builder = $config->builder(ChainedFormatter::withUnixTimestampDates())
            ->withHeader('kid', $key->kid)
            ->issuedBy(config('clauth.issuer'))
            ->permittedFor($app?->key ?? $this->getClient()->getIdentifier())
            ->identifiedBy($this->getIdentifier())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($this->getExpiryDateTime())
            ->withClaim('client_id', $this->getClient()->getIdentifier())
            ->withClaim('scope', implode(' ', $scopes));

        $userId = $this->getUserIdentifier();
        if ($userId !== null && $user = User::find($userId)) {
            $builder = $builder->relatedTo($user->sub());
            if (in_array('email', $scopes, true) || in_array('clauth', $scopes, true)) {
                $builder = $builder->withClaim('email', $user->email);
            }
            if (in_array('profile', $scopes, true) || in_array('clauth', $scopes, true)) {
                $builder = $builder->withClaim('name', $user->name);
            }
            $builder = $builder->withClaim('amr', $ctx->amr);
            if ($app && in_array('clauth', $scopes, true)) {
                $builder = $builder->withClaim('orgs', (object) Claims::orgs($user, $app));
            }
        } else {
            $builder = $builder->relatedTo('client_'.$this->getClient()->getIdentifier());
        }

        if ($app) {
            Application::whereKey($app->id)->update(['last_token_at' => now()]);
        }

        return $builder->getToken($config->signer(), $config->signingKey())->toString();
    }
}
