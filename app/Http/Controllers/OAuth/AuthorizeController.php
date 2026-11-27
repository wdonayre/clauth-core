<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\OrganizationRequestController;
use App\Models\AccessRequest;
use App\Models\ClientSession;
use App\Models\OAuthClient;
use App\Models\UserDevice;
use App\Services\AppJoin;
use App\Services\Audit;
use App\Services\AuthDetails;
use App\Services\Claims;
use App\Services\Consents;
use App\Services\Domains;
use App\Services\Settings;
use App\Services\SigninPolicies;
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
 * GET /oauth/authorize — Authorization Code + PKCE (SPEC §6.1). Access is checked against grants before a code is
 * issued; a person not in an open sign-up app is offered "Join :app" first (AppJoin).
 */
class AuthorizeController
{
    use ConvertsPsrResponses;

    public function __invoke(Request $request, ServerRequestInterface $psrRequest, AuthorizationServer $server)
    {
        // Pushed request (RFC 9126): swap the request_uri for the parameters the app sent, then continue as usual.
        if ($request->filled('request_uri')) {
            $p = ParController::take((string) $request->query('request_uri'), (string) $request->query('client_id'));
            if (! $p) {
                return response()->view('auth.error', ['title' => 'Sign-in request expired', 'message' => 'Go back to the app and start signing in again.'], 400);
            }

            return redirect('/oauth/authorize?'.http_build_query($p));
        }
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
        if ($app->isSuspended()) {
            return response()->view('auth.error', ['title' => "{$app->name} is suspended", 'message' => 'Sign-in to this app has been stopped by Clauth. If you think this is a mistake, contact the app’s organization.', 'theme' => Theme::for(null)], 403);
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
        // The app's sign-in policy may not accept the way this person signed in (SPEC-platform §8.1): sign in again.
        if (! SigninPolicies::allowsVia($app, $request->session()->get('auth.via'))) {
            if ($prompt->contains('none')) {
                return $this->deny($server, $authRequest, $request, 'login_required');
            }
            auth()->logout();
            $request->session()->put(['url.intended' => $request->fullUrl(), 'login.client_id' => $client->id,
                'login.policy_notice' => "{$app->name} asks you to sign in another way."]);

            return redirect()->route('login');
        }
        if (! $verified && $this->onTrustedDevice($request, $user)) {
            // Clauth's own session restarted from "Keep me signed in": the 2-step flag is gone, but a device the
            // person trusted after a 2-step code stays trusted (same rule as the password sign-in; never for admins).
            $request->session()->put('auth.mfa', true);
            $verified = true;
        }
        if (! $verified) {
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('login.mfa');
        }

        if (! Claims::canUse($user, $app)) {
            // Open sign-up app, person already has a Clauth account: offer to join instead of a dead end.
            if ($request->session()->pull('join.answer.'.$client->id) === 'no') {
                return $this->deny($server, $authRequest, $request, 'access_denied', 'You chose not to join.');
            }
            if ($offer = AppJoin::offer($user, $app)) {
                if ($prompt->contains('none')) {
                    return $this->deny($server, $authRequest, $request, 'consent_required');
                }
                $request->session()->put('join.request', ['url' => $request->fullUrl(), 'client' => $client->id]);

                return response()->view('auth.join', ['app' => $app->loadMissing('publisher'), 'user' => $user, 'theme' => Theme::for($app),
                    'role' => $offer['role'], 'scopes' => array_values(array_filter(explode(' ', (string) $request->query('scope'))))]);
            }
            // Someone from outside the app's organization (often a brand-new account): instead of a dead end, let them
            // ask that organization for access, or set up their own organization (SPEC-signup.md).
            if (! $prompt->contains('none') && $app->publisher && ! $user->organizations()->whereKey($app->owner_organization_id)->exists()) {
                $request->session()->put('join.request', ['url' => $request->fullUrl(), 'client' => $client->id]);
                Audit::log('signin.denied', 'signin', $user, after: ['app' => $app->key, 'by' => 'no access yet: offered to ask or set up an organization'], appId: $app->id, actorId: $user->id, actorType: 'user');

                return response()->view('auth.no-access', ['app' => $app->loadMissing('publisher'), 'user' => $user, 'theme' => Theme::for($app),
                    'asked' => AccessRequest::where(['user_id' => $user->id, 'app_id' => $app->id, 'status' => 'pending'])->exists(),
                    'orgsOpen' => (bool) Settings::get('legal_reviewed'),
                    'openRequest' => OrganizationRequestController::openFor($user)]);
            }
            Audit::log('signin.denied', 'signin', $user, after: ['app' => $app->key], appId: $app->id, actorId: $user->id, actorType: 'user');

            return $this->deny($server, $authRequest, $request, 'access_denied', 'You don’t have access to this app.');
        }
        if ($why = SigninPolicies::check($app, $user)) {
            Audit::log('signin.denied', 'signin', $user, after: ['app' => $app->key, 'by' => 'policy check'], appId: $app->id, actorId: $user->id, actorType: 'user');

            return $this->deny($server, $authRequest, $request, 'access_denied', $why);
        }

        // Rich authorization request (RFC 9396): the person approves each item every time.
        $details = null;
        if (($raw = $request->query('authorization_details')) !== null) {
            $details = AuthDetails::parse((string) $raw, $app);
            if (is_string($details)) {
                return $this->deny($server, $authRequest, $request, 'invalid_authorization_details', $details);
            }
            $hash = hash('sha256', $client->id.'|'.$raw);
            $ans = $request->session()->pull('details.answer.'.$hash);
            if ($ans === 'no') {
                return $this->deny($server, $authRequest, $request, 'access_denied', 'You chose not to approve.');
            }
            if ($ans !== 'yes') {
                if ($prompt->contains('none')) {
                    return $this->deny($server, $authRequest, $request, 'consent_required');
                }
                $request->session()->put('details.request', ['url' => $request->fullUrl(), 'hash' => $hash]);

                return response()->view('auth.details', ['app' => $app, 'user' => $user, 'theme' => Theme::for($app),
                    'items' => array_map(fn ($i) => AuthDetails::describe($i, $app), $details)]);
            }
            $details = AuthDetails::process($app, $details, $user);
            if (is_string($details)) {
                return $this->deny($server, $authRequest, $request, 'access_denied', $details);
            }
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
                    'authorization_details' => $details,
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

    /** POST /oauth/details — the person's answer to an authorization_details request. */
    public function details(Request $request)
    {
        $req = $request->session()->pull('details.request');
        abort_unless($req && $request->user(), 404);
        $request->session()->put('details.answer.'.$req['hash'], $request->input('decision') === 'approve' ? 'yes' : 'no');

        return redirect()->to($req['url']);
    }

    /** Same check as Auth\Concerns::isTrustedDevice: a trusted, unexpired device cookie, and not a Clauth admin. */
    private function onTrustedDevice(Request $request, $user): bool
    {
        $token = $request->cookie('clauth_dev');
        if (! $token || $user->isAdmin()) {
            return false;
        }
        $device = UserDevice::where('user_id', $user->id)->where('cookie_hash', hash('sha256', $token))->first();

        return (bool) ($device?->trusted_until?->isFuture());
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
