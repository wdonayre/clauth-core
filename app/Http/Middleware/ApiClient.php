<?php

namespace App\Http\Middleware;

use App\Api\ApiContext;
use App\Api\Problem;
use App\Models\OAuthClient;
use App\Services\Audit;
use App\Services\Domains;
use App\Services\SigningKeys;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Platform API guard (SPEC-platform §5): Bearer token of an API client, the given scope, rate limits.
 * The call then runs as the person who created the client (they must still run that organization),
 * and every audit row it writes is marked actor_type "api" with the client id.
 *   ->middleware('api.client:users:read')
 */
class ApiClient
{
    public function handle(Request $request, Closure $next, ?string $scope = null)
    {
        $request->attributes->set('clauth_api', true);
        // Custom sign-in domains serve sign-in pages only, never the API (SPEC-platform §5).
        $host = strtolower($request->getHost());
        if (! Domains::isMain($host) && ! in_array($host, Domains::mainAliases(), true)) {
            return Problem::make(404, 'Not found', 'The API is served at '.Domains::mainUrl('/api/v1/').' only.');
        }
        $t = SigningKeys::verify((string) $request->bearerToken());
        $c = $t?->claims();
        if (! $t || DB::table('oauth_access_tokens')->where('id', $c->get('jti'))->where('revoked', false)->doesntExist()) {
            return Problem::make(401, 'Invalid token', 'Send an access token from an API client: Authorization: Bearer <token>.')
                ->header('WWW-Authenticate', 'Bearer error="invalid_token"');
        }
        $client = OAuthClient::with(['organization', 'creator'])->find($c->get('client_id'));
        if (! $client || ! $client->isApi() || $client->revoked) {
            return Problem::make(401, 'Not an API client', 'Only API clients (Organization › API clients) can call this API.')
                ->header('WWW-Authenticate', 'Bearer error="invalid_token"');
        }
        $scopes = array_values(array_filter(explode(' ', (string) $c->get('scope'))));
        if ($scope && ! in_array($scope, $scopes, true)) {
            return Problem::make(403, 'Missing scope', "This call needs the {$scope} scope. Ask for it when requesting the token (the client must be allowed it).")
                ->header('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="'.$scope.'"');
        }
        $actor = $client->creator;
        $org = $client->organization;
        $authority = $actor && $actor->status === 'active' && ($client->isPlatformApi()
            ? $actor->isOwner()
            : ($actor->isOwner() || in_array($actor->orgRole($org?->id), ['owner', 'admin'], true)));
        if (! $authority) {
            return Problem::make(403, 'Client owner lost access', 'The person who created this API client no longer runs its organization. Create a new client.');
        }
        $write = ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
        if ($write && $org?->isSuspended()) {
            return Problem::make(403, 'Organization suspended', 'This organization is suspended. The API is read-only until Clauth restores it.');
        }
        $key = 'api:'.($write ? 'w' : 'r').':'.$client->id;
        $max = config($write ? 'clauth.api.per_minute_write' : 'clauth.api.per_minute_read');
        if (RateLimiter::tooManyAttempts($key, $max)) {
            return Problem::make(429, 'Too many requests', 'Slow down: this client has used its requests for this minute.')
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        app()->instance(ApiContext::class, new ApiContext($client, $actor, $scopes));
        Auth::guard('web')->setUser($actor); // services check "who is doing this" against the client's owner
        Audit::actingClient($client->id);
        try {
            $response = $next($request);
        } finally {
            Audit::actingClient(null);
            app()->forgetInstance(ApiContext::class);
        }
        $response->headers->set('RateLimit-Limit', (string) $max);
        $response->headers->set('RateLimit-Remaining', (string) RateLimiter::remaining($key, $max));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
