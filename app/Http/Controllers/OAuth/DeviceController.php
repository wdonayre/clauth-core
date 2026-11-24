<?php

namespace App\Http\Controllers\OAuth;

use App\Models\ClientSession;
use App\Models\OAuthClient;
use App\Services\Audit;
use App\Services\Claims;
use App\Services\SigninPolicies;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Request;
use Laravel\Passport\Bridge\DeviceCodeRepository;
use Laravel\Passport\Http\Controllers\ConvertsPsrResponses;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Device sign-in, RFC 8628 (SPEC-platform §8.3): a TV, POS terminal or command-line tool asks POST /oauth/device for a
 * code, shows "go to <issuer>/device and enter ABCD-EFGH", and polls /oauth/token. The person signs in on their phone,
 * checks which app is asking, and approves. Same access, 2-step and sign-in-rule checks as the browser flow.
 */
class DeviceController
{
    use ConvertsPsrResponses;

    public const GRANT = 'urn:ietf:params:oauth:grant-type:device_code';

    public function code(Request $request, ServerRequestInterface $psr, AuthorizationServer $server)
    {
        $client = OAuthClient::with('app')->find($request->input('client_id') ?: $request->getUser());
        if (! $client || $client->revoked || ! in_array(self::GRANT, $client->grant_types ?? [], true)) {
            return response()->json(['error' => 'unauthorized_client', 'error_description' => 'This client is not set up for device sign-in.'], 400);
        }
        if ($client->app?->isSuspended() || $client->app?->status === 'paused') {
            return response()->json(['error' => 'unauthorized_client', 'error_description' => 'This app is not available.'], 400);
        }
        try {
            $r = $this->convertResponse($server->respondToDeviceAuthorizationRequest($psr, new PsrResponse));
            $r->headers->set('Cache-Control', 'no-store');

            return $r;
        } catch (OAuthServerException $e) {
            return $this->convertResponse($e->generateHttpResponse(new PsrResponse));
        }
    }

    /** GET /device — enter the code (signed in; 2-step done when the person needs it). */
    public function show(Request $request, DeviceCodeRepository $codes)
    {
        $code = $this->normalise((string) $request->query('user_code'));
        $entity = $code ? $codes->getDeviceCodeEntityByUserCode($code) : null;
        $client = $entity ? OAuthClient::with('app')->find($entity->getClient()->getIdentifier()) : null;
        if ($client?->app && ($r = $this->needsMore($request, $client))) {
            return $r;
        }

        return view('auth.device', ['theme' => \App\Services\Theme::for($client?->app), 'code' => $code, 'client' => $client,
            'bad' => $code && ! $entity, 'user' => $request->user()]);
    }

    public function decide(Request $request, DeviceCodeRepository $codes, AuthorizationServer $server)
    {
        $code = $this->normalise((string) $request->input('user_code'));
        $entity = $code ? $codes->getDeviceCodeEntityByUserCode($code) : null;
        $client = $entity ? OAuthClient::with('app')->find($entity->getClient()->getIdentifier()) : null;
        if (! $entity || ! $client?->app) {
            return redirect()->route('passport.device')->withErrors(['user_code' => 'That code isn’t valid or has expired. Check the screen of your device.']);
        }
        if ($r = $this->needsMore($request, $client)) {
            return $r;
        }
        $user = $request->user();
        $app = $client->app;
        $approve = $request->input('decision') === 'approve';
        if ($approve && ! Claims::canUse($user, $app)) {
            $approve = false;
            $msg = 'You don’t have access to '.$app->name.'.';
        } elseif ($approve && ($why = SigninPolicies::check($app, $user))) {
            $approve = false;
            $msg = $why;
        }
        $server->completeDeviceAuthorizationRequest($entity->getIdentifier(), (string) $user->id, $approve);
        if ($approve) {
            ClientSession::updateOrCreate(['user_id' => $user->id, 'client_id' => $client->id], ['app_id' => $app->id,
                'amr' => $request->session()->get('auth.amr', ['pwd']), 'auth_time' => now(), 'last_used_at' => now(),
                'ip' => $request->ip(), 'user_agent' => 'Device sign-in']);
            Audit::log('signin.success', 'signin', $user, after: ['app' => $app->key, 'via' => 'device code'], appId: $app->id, actorId: $user->id, actorType: 'user');
        }

        return view('auth.device-done', ['theme' => \App\Services\Theme::for($app), 'app' => $app, 'approved' => $approve, 'message' => $msg ?? null]);
    }

    /** Not signed in the way this app needs (2-step, sign-in rules) → finish that first and come back. */
    private function needsMore(Request $request, OAuthClient $client)
    {
        $user = $request->user();
        $back = route('passport.device', ['user_code' => $request->input('user_code', $request->query('user_code'))]);
        if (! SigninPolicies::allowsVia($client->app, $request->session()->get('auth.via'))) {
            auth()->logout();
            $request->session()->put(['url.intended' => $back, 'login.app_key' => $client->app->key, 'login.policy_notice' => "{$client->app->name} asks you to sign in another way."]);

            return redirect()->route('login');
        }
        if (! $request->session()->get('auth.mfa') && Claims::mfaRequired($user, $client->app)) {
            $request->session()->put(['url.intended' => $back, 'login.user_id' => $user->id]);

            return redirect()->route('login.mfa');
        }

        return null;
    }

    private function normalise(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }
}
