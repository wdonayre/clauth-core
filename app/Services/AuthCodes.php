<?php

namespace App\Services;

use App\Models\ClientSession;
use App\Models\OAuthClient;
use App\Models\User;
use Defuse\Crypto\Crypto;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Support\Facades\Cache;
use Laravel\Passport\Bridge\User as BridgeUser;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;

/**
 * Issue an authorization code for a person who already proved who they are outside the browser flow (the flow
 * execution API, SPEC-platform §8.2). Same steps as /oauth/authorize after sign-in: the code, the nonce/amr kept for
 * the token exchange, the app session row and the audit event. The caller has already checked access and policy.
 */
class AuthCodes
{
    /** @return array{code: string, state: ?string} */
    public static function issue(AuthorizationServer $server, array $params, User $user, array $amr): array
    {
        $psr = (new ServerRequest('GET', '/oauth/authorize?'.http_build_query($params)))->withQueryParams($params);
        $req = $server->validateAuthorizationRequest($psr);
        $req->setUser(new BridgeUser($user->getAuthIdentifier()));
        $req->setAuthorizationApproved(true);
        $res = $server->completeAuthorizationRequest($req, new PsrResponse);
        parse_str((string) parse_url($res->getHeaderLine('Location'), PHP_URL_QUERY), $q);
        $payload = json_decode(Crypto::decryptWithPassword($q['code'], Passport::tokenEncryptionKey(app('encrypter'))), true);
        Cache::put('oidc:code:'.$payload['auth_code_id'], ['nonce' => $params['nonce'] ?? null, 'amr' => $amr, 'auth_time' => time()], 600);

        $client = OAuthClient::with('app')->find($params['client_id']);
        $user->forceFill(['last_login_at' => now(), 'last_login_app_id' => $client->app_id, 'failed_logins' => 0, 'locked_until' => null])->save();
        ClientSession::updateOrCreate(['user_id' => $user->id, 'client_id' => $client->id], ['app_id' => $client->app_id, 'amr' => $amr,
            'auth_time' => now(), 'last_used_at' => now(), 'ip' => request()->ip(), 'user_agent' => substr((string) request()->userAgent(), 0, 500)]);
        Audit::log('signin.success', 'signin', $user, after: ['app' => $client->app?->key, 'amr' => $amr, 'via' => 'flow api'], appId: $client->app_id, actorId: $user->id, actorType: 'user');

        return ['code' => $q['code'], 'state' => $q['state'] ?? null];
    }
}
