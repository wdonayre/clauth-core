<?php

namespace App\Http\Controllers\OAuth;

use App\Models\OAuthClient;
use App\Models\User;
use App\Services\Claims;
use App\Services\SigningKeys;
use App\Services\Sso;
use App\Services\Theme;
use Defuse\Crypto\Crypto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;

/** Discovery, JWKS, userinfo, revocation and RP-initiated logout. */
class OidcController
{
    public function discovery()
    {
        $iss = config('clauth.issuer');

        return response()->json([
            'issuer' => $iss,
            'authorization_endpoint' => "$iss/oauth/authorize",
            'token_endpoint' => "$iss/oauth/token",
            'userinfo_endpoint' => "$iss/userinfo",
            'jwks_uri' => "$iss/.well-known/jwks.json",
            'revocation_endpoint' => "$iss/oauth/revoke",
            'introspection_endpoint' => "$iss/oauth/introspect",
            'pushed_authorization_request_endpoint' => "$iss/oauth/par",
            'device_authorization_endpoint' => "$iss/oauth/device",
            'introspection_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'revocation_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'end_session_endpoint' => "$iss/oauth/logout",
            'scopes_supported' => array_keys(config('clauth.scopes')),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token', 'client_credentials', 'urn:ietf:params:oauth:grant-type:device_code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'amr', 'azp', 'at_hash', 'email', 'email_verified', 'name', 'orgs'],
            'prompt_values_supported' => ['none', 'login'],
        ])->header('Cache-Control', 'public, max-age=300');
    }

    /** RFC 8414 server metadata: the same document as OIDC discovery (for OAuth-only clients, MCP servers). */
    public function oauthMetadata()
    {
        return $this->discovery();
    }

    /**
     * POST /oauth/introspect (RFC 7662). Confidential clients only. A token is reported only to its own client or to
     * another client of the same app (an app's API checking its web client's tokens); anything else is {active:false}.
     */
    public function introspect(Request $request)
    {
        $client = OAuthClient::find($request->input('client_id') ?: $request->getUser());
        if (! $client || $client->revoked || ! $client->confidential() || ! Hash::check((string) ($request->input('client_secret') ?: $request->getPassword()), $client->secret)) {
            return response()->json(['error' => 'invalid_client'], 401, ['WWW-Authenticate' => 'Basic realm="clauth"', 'Cache-Control' => 'no-store']);
        }
        $inactive = response()->json(['active' => false], 200, ['Cache-Control' => 'no-store']);
        $raw = (string) $request->input('token');
        $mine = fn (?string $owner) => $owner && ($owner === $client->id || ($client->app_id && OAuthClient::whereKey($owner)->value('app_id') === $client->app_id));
        $userOk = function (?string $userId): bool {
            return $userId === null || User::whereKey($userId)->where('status', 'active')->exists();
        };

        if ($t = SigningKeys::verify($raw)) {
            $c = $t->claims();
            $row = DB::table('oauth_access_tokens')->where('id', $c->get('jti'))->where('revoked', false)->first();
            if (! $row || ! $mine($c->get('client_id')) || ! $userOk($row->user_id) || OAuthClient::whereKey($c->get('client_id'))->value('revoked')) {
                return $inactive;
            }
            $aud = $c->get('aud');

            return response()->json(array_filter([
                'active' => true, 'token_type' => 'Bearer', 'scope' => $c->get('scope'), 'client_id' => $c->get('client_id'),
                'sub' => $c->get('sub'), 'aud' => is_array($aud) && count($aud) === 1 ? $aud[0] : $aud, 'iss' => config('clauth.issuer'),
                'exp' => $c->get('exp')?->getTimestamp(), 'iat' => $c->get('iat')?->getTimestamp(), 'jti' => $c->get('jti'),
            ], fn ($v) => $v !== null), 200, ['Cache-Control' => 'no-store']);
        }
        try {
            $p = json_decode(Crypto::decryptWithPassword($raw, Passport::tokenEncryptionKey(app('encrypter'))), true);
        } catch (\Throwable) {
            return $inactive;
        }
        $row = isset($p['refresh_token_id']) ? DB::table('oauth_refresh_tokens')->where('id', $p['refresh_token_id'])->where('revoked', false)->first() : null;
        if (! $row || ($p['expire_time'] ?? 0) < time() || ! $mine($p['client_id'] ?? null) || ! $userOk($p['user_id'] ?? null)) {
            return $inactive;
        }

        return response()->json(['active' => true, 'token_type' => 'refresh_token', 'client_id' => $p['client_id'], 'scope' => implode(' ', $p['scopes'] ?? []),
            'sub' => isset($p['user_id']) ? 'usr_'.$p['user_id'] : null, 'exp' => $p['expire_time'] ?? null, 'iss' => config('clauth.issuer')], 200, ['Cache-Control' => 'no-store']);
    }

    public function jwks()
    {
        return response()->json(SigningKeys::jwks())->header('Cache-Control', 'public, max-age=300');
    }

    public function userinfo(Request $request)
    {
        $token = SigningKeys::verify((string) $request->bearerToken());
        $claims = $token?->claims();
        if (! $token || ! str_contains(' '.$claims->get('scope').' ', ' openid ')
            || DB::table('oauth_access_tokens')->where('id', $claims->get('jti'))->where('revoked', false)->doesntExist()) {
            return response()->json(['error' => 'invalid_token'], 401, ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        }
        $user = User::findBySub((string) $claims->get('sub'));
        if (! $user || $user->status !== 'active') {
            return response()->json(['error' => 'invalid_token'], 401);
        }
        $client = OAuthClient::with('app')->find($claims->get('client_id'));
        if ($client?->app?->isSuspended()) {
            return response()->json(['error' => 'invalid_token'], 401);
        }
        $scopes = explode(' ', (string) $claims->get('scope'));
        $out = ['sub' => $user->sub()];
        if (in_array('email', $scopes)) {
            $out += ['email' => $user->email, 'email_verified' => $user->email_verified_at !== null];
        }
        if (in_array('profile', $scopes)) {
            $out['name'] = $user->name;
        }
        if (in_array('clauth', $scopes) && $client?->app) {
            $out['orgs'] = (object) Claims::orgs($user, $client->app);
        }

        return response()->json($out)->header('Cache-Control', 'no-store');
    }

    /** RFC 7009. Always 200 for unknown tokens. */
    public function revoke(Request $request)
    {
        $clientId = $request->input('client_id') ?: $request->getUser();
        $client = OAuthClient::find($clientId);
        if (! $client || ($client->confidential() && ! Hash::check((string) ($request->input('client_secret') ?: $request->getPassword()), $client->secret))) {
            return response()->json(['error' => 'invalid_client'], 401);
        }
        $raw = (string) $request->input('token');
        try {
            $p = json_decode(Crypto::decryptWithPassword($raw, Passport::tokenEncryptionKey(app('encrypter'))), true);
            if (($p['client_id'] ?? null) === $client->id && isset($p['refresh_token_id'])) {
                DB::table('oauth_refresh_tokens')->where('id', $p['refresh_token_id'])->update(['revoked' => true]);
                DB::table('oauth_access_tokens')->where('id', $p['access_token_id'] ?? '')->update(['revoked' => true]);
            }
        } catch (\Throwable) {
            $t = SigningKeys::verify($raw, false);
            if ($t && $t->claims()->get('client_id') === $client->id) {
                $jti = $t->claims()->get('jti');
                DB::table('oauth_access_tokens')->where('id', $jti)->update(['revoked' => true]);
                DB::table('oauth_refresh_tokens')->where('access_token_id', $jti)->update(['revoked' => true]);
            }
        }

        return response()->json([], 200);
    }

    public function logout(Request $request)
    {
        $redirect = $request->input('post_logout_redirect_uri');
        $clientId = $request->input('client_id');
        if ($hint = $request->input('id_token_hint')) {
            $t = SigningKeys::verify($hint, false);
            $aud = $t?->claims()->get('aud');
            $clientId = is_array($aud) ? ($aud[0] ?? null) : $aud;
        }
        $client = $clientId ? OAuthClient::with('app')->find($clientId) : null;

        if (Auth::check()) {
            Sso::endFamily($request);
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($redirect && $client && in_array($redirect, $client->post_logout_redirect_uris ?? [], true)) {
            $state = $request->input('state');

            return redirect()->away($redirect.($state ? (str_contains($redirect, '?') ? '&' : '?').'state='.urlencode($state) : ''));
        }

        return view('auth.signed-out', ['theme' => Theme::for($client?->app)]);
    }
}
