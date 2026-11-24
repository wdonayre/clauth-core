<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\Problem;
use App\Mail\LockoutMail;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\Audit;
use App\Services\AuthCodes;
use App\Services\Claims;
use App\Services\Consents;
use App\Services\Domains;
use App\Services\OneTimeCodes;
use App\Services\Settings;
use App\Services\SigninPolicies;
use App\Services\Sms;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use League\OAuth2\Server\AuthorizationServer;

/**
 * POST /api/v1/flow/execute — app-native sign-in (SPEC-platform §8.2). A phone or desktop app draws its own screens:
 * it starts a flow with the same parameters as /oauth/authorize (public client, PKCE S256, registered redirect_uri),
 * then answers one step at a time until it gets an authorization code, which it exchanges at /oauth/token as usual.
 * The same lockout, sign-in rules, 2-step and access checks as the hosted page; nothing can be skipped. Anything only
 * the browser can do (provider buttons, setting up 2-step, consent for an outside app) answers step "browser" with the
 * URL to open instead.
 *
 * Steps: identify (email) → method (password | send_code) → [code] → [two_step: totp | send_sms → sms] → done.
 */
class FlowController
{
    private const TTL = 600;

    public function __invoke(Request $request, AuthorizationServer $server)
    {
        $request->attributes->set('clauth_api', true);
        $in = $request->json()->all() ?: $request->all();
        if (empty($in['flow_id'])) {
            return $this->start($in);
        }
        $flow = Cache::get($this->key($in['flow_id']));
        if (! $flow || ($in['client_id'] ?? null) !== $flow['client_id']) {
            return Problem::make(404, 'Not found', 'This sign-in expired or doesn’t exist. Start again.');
        }
        if (($flow['fails'] ?? 0) >= 10) {
            Cache::forget($this->key($in['flow_id']));

            return Problem::make(429, 'Too many requests', 'Too many wrong answers. Start again.');
        }
        $action = (string) ($in['action'] ?? '');
        $user = isset($flow['user_id']) ? User::find($flow['user_id']) : null;

        return match (true) {
            $action === 'identify' && $flow['step'] === 'identify' => $this->identify($flow, (string) ($in['email'] ?? '')),
            $action === 'password' && $flow['step'] === 'method' => $this->password($server, $flow, $user, (string) ($in['password'] ?? '')),
            $action === 'send_code' && in_array($flow['step'], ['method', 'code'], true) => $this->sendCode($flow, $user, (string) ($in['via'] ?? 'email')),
            $action === 'code' && $flow['step'] === 'code' => $this->code($server, $flow, $user, (string) ($in['code'] ?? '')),
            $action === 'totp' && $flow['step'] === 'two_step' => $this->totp($server, $flow, $user, (string) ($in['code'] ?? '')),
            $action === 'send_sms' && $flow['step'] === 'two_step' => $this->sendSms($flow, $user),
            $action === 'sms' && $flow['step'] === 'two_step' => $this->sms($server, $flow, $user, (string) ($in['code'] ?? '')),
            default => Problem::make(422, 'Unprocessable', "Action “{$action}” isn’t possible at step “{$flow['step']}”."),
        };
    }

    private function start(array $in)
    {
        $client = OAuthClient::with('app')->find($in['client_id'] ?? null);
        if (! $client || $client->revoked || $client->type !== 'public' || ! $client->app) {
            return Problem::make(400, 'Invalid client', 'The flow API is for an app’s public (mobile/SPA) client.');
        }
        if (! in_array($in['redirect_uri'] ?? null, $client->redirect_uris ?? [], true)) {
            return Problem::make(400, 'Invalid request', 'redirect_uri is not registered for this client.');
        }
        if (($in['code_challenge_method'] ?? null) !== 'S256' || strlen((string) ($in['code_challenge'] ?? '')) < 43) {
            return Problem::make(400, 'Invalid request', 'PKCE with S256 is required.');
        }
        $app = $client->app;
        if ($app->isSuspended() || $app->status === 'paused') {
            return Problem::make(403, 'Forbidden', "{$app->name} is not available.");
        }
        $params = ['response_type' => 'code', 'client_id' => $client->id, 'redirect_uri' => $in['redirect_uri'], 'scope' => (string) ($in['scope'] ?? 'openid'),
            'code_challenge' => $in['code_challenge'], 'code_challenge_method' => 'S256'] + array_filter(['state' => $in['state'] ?? null, 'nonce' => $in['nonce'] ?? null]);
        $flow = ['id' => Str::random(40), 'client_id' => $client->id, 'app_id' => $app->id, 'params' => $params, 'step' => 'identify', 'fails' => 0];

        return $this->save($flow, ['app' => ['key' => $app->key, 'name' => $app->name], 'fields' => ['email']]);
    }

    /** Which ways the app accepts; never tells whether the account exists. */
    private function identify(array $flow, string $email)
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Problem::make(422, 'Invalid request', 'Send a valid email.', ['email' => ['Send a valid email.']]);
        }
        $app = $this->app($flow);
        $r = SigninPolicies::rules($app);
        $user = User::where('email', mb_strtolower(trim($email)))->first();
        $methods = array_values(array_filter([
            $r['password'] ? 'password' : null,
            Settings::get('email_code_signin') && $r['email_code'] ? 'email_code' : null,
            Settings::get('sms_signin') && Sms::available() && $r['sms_code'] ? 'sms_code' : null,
        ]));
        $flow['step'] = 'method';
        $flow['email'] = mb_strtolower(trim($email));
        $flow['user_id'] = $user && $user->status === 'active' ? $user->id : null;

        return $this->save($flow, ['methods' => $methods, 'browser_url' => $this->browserUrl($flow, ['login_hint' => $flow['email']])]);
    }

    private function password(AuthorizationServer $server, array $flow, ?User $user, string $password)
    {
        if (! SigninPolicies::rules($this->app($flow))['password']) {
            return Problem::make(422, 'Unprocessable', 'This app doesn’t use passwords.');
        }
        if (! $user || ! $user->password) {
            Hash::check($password, '$2y$12$abcdefghijklmnopqrstuuJ5a0rLBZT6Ql5rjZaEuk3E0Zy8lKcEW');

            return $this->wrong($flow, 'That email and password don’t match.');
        }
        if ($user->isLocked()) {
            return Problem::make(423, 'Locked', 'Too many wrong passwords. Try again later, or reset your password.');
        }
        if (! Hash::check($password, $user->password)) {
            $user->increment('failed_logins');
            Audit::log('signin.failed', 'signin', $user, after: ['attempts' => $user->failed_logins, 'via' => 'flow api'], actorId: $user->id, actorType: 'user');
            if ($user->failed_logins >= (int) Settings::get('lockout_attempts')) {
                $user->forceFill(['locked_until' => now()->addMinutes((int) Settings::get('lockout_minutes')), 'failed_logins' => 0])->save();
                Audit::log('signin.locked', 'security', $user, actorId: $user->id, actorType: 'system');
                rescue(fn () => Mail::to($user->email)->send(\App\Services\Templates::mail('lockout', null, ['name' => $user->name, 'minutes' => Settings::get('lockout_minutes')], $user->locale) ?? new LockoutMail($user)), report: true);
            }

            return $this->wrong($flow, 'That email and password don’t match.');
        }

        return $this->firstFactorDone($server, $flow, $user, ['pwd'], 'password');
    }

    private function sendCode(array $flow, ?User $user, string $via)
    {
        $app = $this->app($flow);
        $r = SigninPolicies::rules($app);
        $allowed = $via === 'sms' ? Settings::get('sms_signin') && Sms::available() && $r['sms_code'] : Settings::get('email_code_signin') && $r['email_code'];
        if (! $allowed) {
            return Problem::make(422, 'Unprocessable', 'That way to get a code isn’t available for this app.');
        }
        if ($user && ($via !== 'sms' || $user->phone_verified_at)) {
            try {
                $err = OneTimeCodes::send($via === 'sms' ? 'sms' : 'email', $via === 'sms' ? $user->phone : $user->email, 'signin', request()->ip());
            } catch (\Throwable $e) {
                report($e);
                $err = 'We couldn’t send the code. Try again.';
            }
            if ($err) {
                return Problem::make(429, 'Limit reached', $err);
            }
        }
        $flow['step'] = 'code';
        $flow['via'] = $via === 'sms' ? 'sms' : 'email';

        return $this->save($flow, ['sent_to' => $via === 'sms' ? 'the phone on the account' : $flow['email'], 'expires_in' => OneTimeCodes::TTL]);
    }

    private function code(AuthorizationServer $server, array $flow, ?User $user, string $code)
    {
        $ch = $flow['via'] ?? 'email';
        if (! $user || ! OneTimeCodes::verify($ch, $ch === 'sms' ? (string) $user->phone : $user->email, 'signin', $code)) {
            return $this->wrong($flow, 'That code isn’t right, or it expired.');
        }

        return $this->firstFactorDone($server, $flow, $user, [$ch === 'sms' ? 'sms' : 'email'], $ch === 'sms' ? 'sms_code' : 'email_code');
    }

    private function firstFactorDone(AuthorizationServer $server, array $flow, User $user, array $amr, string $via)
    {
        $app = $this->app($flow);
        if ($user->status !== 'active') {
            return $this->wrong($flow, 'That account can’t sign in.');
        }
        $flow['amr'] = $amr;
        $flow['via_method'] = $via;
        if (! SigninPolicies::allowsVia($app, $via)) {
            return Problem::make(422, 'Unprocessable', 'This app doesn’t accept that way to sign in.');
        }
        if (Claims::mfaRequired($user, $app)) {
            $sms = $user->hasSmsTwoStep() && ! in_array('sms', $amr, true) && SigninPolicies::rules($app)['sms_second_step'];
            if (! $user->hasTwoFactor() && ! $sms) {
                return $this->browser($flow, 'Set up 2-step verification in the browser first.');
            }
            $flow['step'] = 'two_step';

            return $this->save($flow, ['methods' => array_values(array_filter([$user->hasTwoFactor() ? 'totp' : null, $sms ? 'sms' : null])),
                'phone' => $sms ? Sms::mask($user->phone) : null]);
        }

        return $this->finish($server, $flow, $user);
    }

    private function totp(AuthorizationServer $server, array $flow, ?User $user, string $code)
    {
        if (! $user?->hasTwoFactor() || ! TwoFactor::verify($user->two_factor_secret, $code, $user->id)) {
            return $this->wrong($flow, 'That code isn’t right.');
        }
        $flow['amr'][] = 'otp';

        return $this->finish($server, $flow, $user);
    }

    private function sendSms(array $flow, ?User $user)
    {
        if (! $user?->hasSmsTwoStep() || in_array('sms', $flow['amr'] ?? [], true) || ! SigninPolicies::rules($this->app($flow))['sms_second_step']) {
            return Problem::make(422, 'Unprocessable', 'Text-message codes aren’t available here.');
        }
        try {
            $err = OneTimeCodes::send('sms', $user->phone, 'mfa', request()->ip());
        } catch (\Throwable $e) {
            report($e);
            $err = 'We couldn’t send the text.';
        }

        return $err ? Problem::make(429, 'Limit reached', $err) : $this->save($flow, ['sent_to' => Sms::mask($user->phone)]);
    }

    private function sms(AuthorizationServer $server, array $flow, ?User $user, string $code)
    {
        if (! $user?->hasSmsTwoStep() || in_array('sms', $flow['amr'] ?? [], true) || ! OneTimeCodes::verify('sms', (string) $user->phone, 'mfa', $code)) {
            return $this->wrong($flow, 'That code isn’t right, or it expired.');
        }
        $flow['amr'][] = 'sms';

        return $this->finish($server, $flow, $user);
    }

    private function finish(AuthorizationServer $server, array $flow, User $user)
    {
        $app = $this->app($flow);
        if (! Claims::canUse($user, $app)) {
            Cache::forget($this->key($flow['id']));
            Audit::log('signin.denied', 'signin', $user, after: ['app' => $app->key, 'via' => 'flow api'], appId: $app->id, actorId: $user->id, actorType: 'user');

            return Problem::make(403, 'Forbidden', 'You don’t have access to this app.');
        }
        if ($why = SigninPolicies::check($app, $user)) {
            Cache::forget($this->key($flow['id']));

            return Problem::make(403, 'Forbidden', $why);
        }
        if (Consents::missing($user, $app, explode(' ', $flow['params']['scope']))) {
            return $this->browser($flow, 'This app needs your OK once in the browser.');
        }
        $out = AuthCodes::issue($server, $flow['params'], $user, $flow['amr']);
        Cache::forget($this->key($flow['id']));

        return response()->json(['flow_id' => $flow['id'], 'step' => 'done', 'code' => $out['code'], 'state' => $out['state'], 'redirect_uri' => $flow['params']['redirect_uri']],
            200, ['Cache-Control' => 'no-store']);
    }

    private function wrong(array $flow, string $msg)
    {
        $flow['fails'] = ($flow['fails'] ?? 0) + 1;
        Cache::put($this->key($flow['id']), $flow, self::TTL);

        return Problem::make(401, 'Not accepted', $msg);
    }

    private function browser(array $flow, string $why)
    {
        Cache::forget($this->key($flow['id']));

        return response()->json(['flow_id' => $flow['id'], 'step' => 'browser', 'reason' => $why, 'url' => $this->browserUrl($flow, ['login_hint' => $flow['email'] ?? null])],
            200, ['Cache-Control' => 'no-store']);
    }

    private function browserUrl(array $flow, array $extra = []): string
    {
        return Domains::urlFor($this->app($flow), 'oauth/authorize?'.http_build_query($flow['params'] + array_filter($extra)));
    }

    private function save(array $flow, array $extra = [])
    {
        Cache::put($this->key($flow['id']), $flow, self::TTL);

        return response()->json(['flow_id' => $flow['id'], 'step' => $flow['step'], 'expires_in' => self::TTL] + $extra, 200, ['Cache-Control' => 'no-store']);
    }

    private function app(array $flow): \App\Models\Application
    {
        return \App\Models\Application::findOrFail($flow['app_id']);
    }

    private function key(string $id): string
    {
        return 'flow:'.hash('sha256', $id);
    }
}
