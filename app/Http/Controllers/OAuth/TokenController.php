<?php

namespace App\Http\Controllers\OAuth;

use App\Models\ClientSession;
use App\Models\OAuthClient;
use App\Models\User;
use App\OAuth\TokenContext;
use App\Services\Audit;
use App\Services\Claims;
use App\Services\Sessions;
use Defuse\Crypto\Crypto;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Http\Controllers\ConvertsPsrResponses;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;

/** POST /oauth/token — code exchange, rotating refresh with reuse detection (SPEC §6.2), client credentials. */
class TokenController
{
    use ConvertsPsrResponses;

    public function __invoke(Request $request, ServerRequestInterface $psrRequest, AuthorizationServer $server, TokenContext $ctx)
    {
        $grant = $request->input('grant_type');
        $key = Passport::tokenEncryptionKey(app('encrypter'));
        // A suspended app (or one whose organization is suspended) gets no tokens at all (SPEC-organizations §10.4).
        $requester = OAuthClient::with('app.publisher')->find($request->input('client_id') ?: $request->getUser());
        if ($requester?->app?->isSuspended()) {
            return $this->error('unauthorized_client', 'This app is suspended.');
        }

        if ($grant === 'authorization_code' && $request->filled('code')) {
            try {
                $payload = json_decode(Crypto::decryptWithPassword($request->input('code'), $key), true);
                if ($meta = Cache::pull('oidc:code:'.($payload['auth_code_id'] ?? ''))) {
                    $ctx->nonce = $meta['nonce'] ?? null;
                    $ctx->amr = $meta['amr'] ?? ['pwd'];
                    $ctx->authTime = $meta['auth_time'] ?? null;
                }
            } catch (\Throwable) {
                // league rejects the bad code below
            }
        }

        if ($grant === 'refresh_token' && $request->filled('refresh_token')) {
            try {
                $payload = json_decode(Crypto::decryptWithPassword($request->input('refresh_token'), $key), true);
            } catch (\Throwable) {
                $payload = null;
            }
            if ($payload) {
                $user = User::find($payload['user_id'] ?? null);
                $client = OAuthClient::with('app')->find($payload['client_id'] ?? null);
                $row = DB::table('oauth_refresh_tokens')->where('id', $payload['refresh_token_id'] ?? '')->first();
                if ($user && $client && $row && $row->revoked) {
                    // A used refresh token came back: someone may have a copy. Kill the whole family.
                    Sessions::revokeClient($user, $client->id);
                    Audit::log('security.refresh_reuse', 'security', $user, after: ['client' => $client->id], appId: $client->app_id, actorId: $user->id, actorType: 'system');

                    return $this->error('invalid_grant', 'Refresh token was already used; all sessions for this app were signed out.');
                }
                if ($user && $client && ($user->status !== 'active' || ! $client->app || ! Claims::canUse($user, $client->app))) {
                    Sessions::revokeClient($user, $client->id);

                    return $this->error('invalid_grant', 'This user no longer has access to the app.');
                }
                if ($user && $client) {
                    $cs = ClientSession::where('user_id', $user->id)->where('client_id', $client->id)->first();
                    $ctx->amr = $cs?->amr ?? ['pwd'];
                    $ctx->authTime = $cs?->auth_time?->timestamp;
                }
            }
        }

        if ($grant === 'client_credentials') {
            $client = OAuthClient::find($request->input('client_id') ?: $request->getUser());
            if ($client && $client->type !== 'm2m') {
                return $this->error('unauthorized_client', 'Only machine-to-machine clients may use client credentials.');
            }
        }

        try {
            $psr = $server->respondToAccessTokenRequest($psrRequest, new PsrResponse);
        } catch (OAuthServerException $e) {
            return $this->convertResponse($e->generateHttpResponse(new PsrResponse));
        }

        if (isset($user, $client) && $user && $client && $grant === 'refresh_token') {
            ClientSession::where('user_id', $user->id)->where('client_id', $client->id)->update(['last_used_at' => now()]);
        }

        $response = $this->convertResponse($psr);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    private function error(string $code, string $description)
    {
        return response()->json(['error' => $code, 'error_description' => $description], 400, ['Cache-Control' => 'no-store']);
    }
}
