<?php

namespace App\Http\Controllers\OAuth;

use App\Models\ClientSession;
use App\Models\OAuthClient;
use App\Services\Audit;
use App\Services\Claims;
use App\Services\Consents;
use App\Services\Domains;
use App\Services\Theme;
use Defuse\Crypto\Crypto;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Passport\Bridge\User as BridgeUser;
use Laravel\Passport\Http\Controllers\ConvertsPsrResponses;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /oauth/authorize — Authorization Code + PKCE (SPEC §6.1). No consent screen; access is
 * checked against grants before a code is issued, then the user is redirected back instantly.
 */
class AuthorizeController
{
    use ConvertsPsrResponses;

    public function __invoke(Request $request, ServerRequestInterface $psrRequest, AuthorizationServer $server)
    {
        try {
            $authRequest = $server->validateAuthorizationRequest($psrRequest);
        } catch (OAuthServerException $e) {
            return $this->fail($e, $request);
        }

        $client = OAuthClient::with('app')->find($authRequest->getClient()->getIdentifier());
        $app = $client?->app;
        if (! $client || ! $app || $client->type === 'm2m') {
            return response()->view('auth.error', ['title' => 'Unknown app', 'message' => 'This app isn’t registered with Clauth.'], 400);
        }

        // A custom sign-in domain only serves its own apps; any other app signs in at its own address.
        $domain = $request->attributes->get('clauth.domain');
        if ($domain && ! $domain->serves($app)) {
            return redirect()->away(Domains::urlFor($app, 'oauth/authorize?'.parse_url($request->getRequestUri(), PHP_URL_QUERY)));
        }

        // Everything after this is styled for the app.
        $request->session()->put('login.client_id', $client->id);

        if (! $authRequest->getCodeChallenge() || $authRequest->getCodeChallengeMethod() !== 'S256') {
            return $this->deny($server, $authRequest, $request, 'invalid_request', 'PKCE with S256 is required.');
        }
        if ($app->status === 'paused') {
            return response()->view('auth.error', ['title' => "{$app->name} is paused", 'message' => 'Sign-in to this app is turned off for now. Ask your admin.', 'theme' => Theme::for($app)], 403);
        }

        $prompt = collect(explode(' ', (string) $request->query('prompt')))->filter();
        $user = $request->user();
        $verified = $user && ($request->session()->get('auth.mfa', false) || ! Claims::mfaRequired($user, $app));

        if ($prompt->contains('none') && ! $verified) {
            return $this->deny($server, $authRequest, $request, 'login_required');
        }
        if ($prompt->contains('login') && ! $request->session()->pull('login.prompted')) {
            auth()->logout();
            $request->session()->put('login.prompted', true);
            $request->session()->put('url.intended', $request->fullUrl());
            $request->session()->put('login.client_id', $client->id);

            return redirect()->route('login');
        }
        if (! $user) {
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('login');
        }
        if (! $verified) {
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('login.mfa');
        }

        if (! Claims::canUse($user, $app)) {
            Audit::log('signin.denied', 'signin', $user, after: ['app' => $app->key], appId: $app->id, actorId: $user->id, actorType: 'user');

            return $this->deny($server, $authRequest, $request, 'access_denied', 'You don’t have access to this app.');
        }

        // Consent (SPEC-organizations §6): apps outside the person's own organizations ask once.
        $answer = $request->session()->pull('consent.answer.'.$client->id);
        if ($answer === 'no') {
            return $this->deny($server, $authRequest, $request, 'access_denied', 'You chose not to continue.');
        }
        if ($answer !== 'yes') {
            $scopes = array_values(array_filter(explode(' ', (string) $request->query('scope'))));
            $missing = Consents::missing($user, $app, $scopes);
            if ($prompt->contains('consent') && ! Consents::exempt($user, $app)) {
                $missing = array_values(array_intersect(['openid', 'profile', 'email', 'clauth'], $scopes ?: ['openid']));
            }
            if ($missing) {
                if ($prompt->contains('none')) {
                    return $this->deny($server, $authRequest, $request, 'consent_required');
                }
                $request->session()->put('consent.request', ['url' => $request->fullUrl(), 'client' => $client->id, 'scopes' => $scopes]);
                $orgNames = $user->grants()->where('app_id', $app->id)->with('organization')->get()->pluck('organization.name')->filter()->unique()->values();

                return response()->view('auth.consent', [
                    'app' => $app->loadMissing('publisher'), 'user' => $user, 'scopes' => $missing, 'orgNames' => $orgNames,
                    'theme' => Theme::for($app), 'labels' => config('clauth.scopes'),
                ]);
            }
        }

        $authRequest->setUser(new BridgeUser($user->getAuthIdentifier()));
        $authRequest->setAuthorizationApproved(true);
        try {
            $psr = $server->completeAuthorizationRequest($authRequest, new PsrResponse);
        } catch (OAuthServerException $e) {
            return $this->fail($e, $request);
        }

        // Remember nonce/amr for the token exchange (the code itself is opaque to us).
        parse_str((string) parse_url($psr->getHeaderLine('Location'), PHP_URL_QUERY), $q);
        if (! empty($q['code'])) {
            try {
                $payload = json_decode(Crypto::decryptWithPassword($q['code'], Passport::tokenEncryptionKey(app('encrypter'))), true);
                Cache::put('oidc:code:'.$payload['auth_code_id'], [
                    'nonce' => $request->query('nonce'),
                    'amr' => $request->session()->get('auth.amr', ['pwd']),
                    'auth_time' => $request->session()->get('auth.time', time()),
                ], 600);
            } catch (\Throwable) {
                // nonce is optional; continue
            }
        }

        $user->forceFill(['last_login_at' => now(), 'last_login_app_id' => $app->id])->save();
        ClientSession::updateOrCreate(
            ['user_id' => $user->id, 'client_id' => $client->id],
            ['app_id' => $app->id, 'amr' => $request->session()->get('auth.amr', ['pwd']),
                'auth_time' => now()->setTimestamp($request->session()->get('auth.time', time())),
                'last_used_at' => now(), 'ip' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 500)],
        );
        Audit::log('signin.success', 'signin', $user, after: ['app' => $app->key, 'amr' => $request->session()->get('auth.amr')], appId: $app->id, actorId: $user->id, actorType: 'user');
        $request->session()->forget('login.client_id');

        return $this->convertResponse($psr);
    }

    private function deny(AuthorizationServer $server, $authRequest, Request $request, string $error, ?string $hint = null)
    {
        $redirect = $authRequest->getRedirectUri() ?? ($authRequest->getClient()->getRedirectUri()[0] ?? null);
        $params = array_filter(['error' => $error, 'error_description' => $hint, 'state' => $authRequest->getState()]);
        $request->session()->forget('login.client_id');

        return redirect()->away($redirect.(str_contains($redirect, '?') ? '&' : '?').http_build_query($params));
    }

    private function fail(OAuthServerException $e, Request $request)
    {
        if ($e->hasRedirect()) {
            return $this->convertResponse($e->generateHttpResponse(new PsrResponse));
        }

        return response()->view('auth.error', ['title' => 'Sign-in request not valid', 'message' => $e->getMessage().($e->getHint() ? ' '.$e->getHint() : '')], 400);
    }
}
