<?php

namespace App\Http\Controllers\OAuth;

use App\Models\OAuthClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Pushed authorization requests, RFC 9126 (SPEC-platform §8.3): the app sends the sign-in request server-to-server and
 * gets a one-use request_uri for /oauth/authorize, so the parameters can't be tampered with on the way. 90 seconds.
 */
class ParController
{
    public const TTL = 90;

    public function __invoke(Request $request)
    {
        $client = OAuthClient::with('app')->find($request->input('client_id') ?: $request->getUser());
        $secret = (string) ($request->input('client_secret') ?: $request->getPassword());
        if (! $client || $client->revoked || ($client->confidential() && ! Hash::check($secret, $client->secret))) {
            return response()->json(['error' => 'invalid_client'], 401, ['Cache-Control' => 'no-store']);
        }
        if ($request->filled('request_uri')) {
            return response()->json(['error' => 'invalid_request', 'error_description' => 'request_uri is not allowed here.'], 400);
        }
        $params = collect($request->except(['client_secret', 'client_assertion', 'client_assertion_type']))->filter(fn ($v) => is_string($v))->all();
        $params['client_id'] = $client->id;
        if (empty($params['redirect_uri']) || ! in_array($params['redirect_uri'], $client->redirect_uris ?? [], true)) {
            return response()->json(['error' => 'invalid_request', 'error_description' => 'redirect_uri is not registered for this client.'], 400);
        }
        $id = Str::random(43);
        Cache::put('par:'.$id, $params, self::TTL);

        return response()->json(['request_uri' => 'urn:ietf:params:oauth:request_uri:'.$id, 'expires_in' => self::TTL], 201, ['Cache-Control' => 'no-store']);
    }

    /** The stored parameters for a request_uri (one use), or null. */
    public static function take(string $requestUri, string $clientId): ?array
    {
        $prefix = 'urn:ietf:params:oauth:request_uri:';
        if (! str_starts_with($requestUri, $prefix)) {
            return null;
        }
        $p = Cache::pull('par:'.substr($requestUri, strlen($prefix)));

        return $p && $p['client_id'] === $clientId ? $p : null;
    }
}
