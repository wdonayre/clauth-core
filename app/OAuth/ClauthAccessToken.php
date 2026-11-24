<?php

namespace App\OAuth;

use App\Models\Application;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\Audit;
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

    /** sub = agt_…, aud = the app, act = the owner (a person's own agent), orgs = the agent's access. */
    private function agentToken(OAuthClient $client, Application $app): string
    {
        $key = SigningKeys::active();
        $config = SigningKeys::config($key);
        $agent = \App\Models\Agent::with(['organization', 'owner'])->find($client->agent_id);
        $now = new DateTimeImmutable;
        $b = $config->builder(ChainedFormatter::withUnixTimestampDates())
            ->withHeader('kid', $key->kid)->issuedBy(config('clauth.issuer'))->permittedFor($app->key)
            ->identifiedBy($this->getIdentifier())->issuedAt($now)->canOnlyBeUsedAfter($now)->expiresAt($this->getExpiryDateTime())
            ->relatedTo($agent->sub())->withClaim('client_id', $client->id)->withClaim('scope', 'clauth')
            ->withClaim('agent', ['name' => $agent->name, 'org' => $agent->organization->slug])
            ->withClaim('orgs', (object) \App\Services\Agents::claims($agent, $app));
        if ($agent->owner) {
            $b = $b->withClaim('act', ['sub' => $agent->owner->sub()]);
        }

        return $b->getToken($config->signer(), $config->signingKey())->toString();
    }

    private function build(): string
    {
        $key = SigningKeys::active();
        $config = SigningKeys::config($key);
        $client = OAuthClient::find($this->getClient()->getIdentifier());
        $app = $client?->app;
        $ctx = app(TokenContext::class);
        if ($client?->type === 'agent' && $ctx->audience) {
            return $this->agentToken($client, $ctx->audience);
        }
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
        if ($ctx->authorizationDetails) {
            $builder = $builder->withClaim('authorization_details', $ctx->authorizationDetails);
        }

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
            // First real sign-in through Clauth: the app is connected. ("paused" stays a manual choice.)
            if ($userId !== null && $app->status === 'not_connected') {
                Application::whereKey($app->id)->where('status', 'not_connected')->update(['status' => 'live']);
                Audit::log('app.connected', 'app', $app, ['status' => 'not_connected'], ['status' => 'live'], $app->id, actorType: 'system');
            }
        }

        return $builder->getToken($config->signer(), $config->signingKey())->toString();
    }
}
