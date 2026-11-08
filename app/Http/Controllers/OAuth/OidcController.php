<?php

namespace App\Http\Controllers\OAuth;

use App\Models\OAuthClient;
use App\Models\User;
use App\Services\Claims;
use App\Services\SigningKeys;
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
            'end_session_endpoint' => "$iss/oauth/logout",
            'scopes_supported' => array_keys(config('clauth.scopes')),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token', 'client_credentials'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'amr', 'azp', 'at_hash', 'email', 'email_verified', 'name', 'orgs'],
            'prompt_values_supported' => ['none', 'login'],
        ])->header('Cache-Control', 'public, max-age=300');
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
