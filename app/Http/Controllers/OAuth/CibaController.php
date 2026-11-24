<?php

namespace App\Http\Controllers\OAuth;

use App\Models\OAuthClient;
use App\Models\User;
use App\Services\Audit;
use App\Services\AuthCodes;
use App\Services\Claims;
use App\Services\SigninPolicies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use League\OAuth2\Server\AuthorizationServer;

/**
 * CIBA, poll mode (SPEC-platform §8.3): an app's server asks Clauth to get a person's OK ("approve this payment at the
 * counter"); the person approves on their phone at /approve/{id} (email link, or My account), and the app polls
 * /oauth/token with grant_type urn:openid:params:grant-type:ciba. On approval Clauth issues an internal authorization
 * code (PKCE) for the app's web client, so the tokens are exactly the ones the browser flow gives.
 */
class CibaController
{
    public const GRANT = 'urn:openid:params:grant-type:ciba';

    public const TTL = 300;

    public function start(Request $request)
    {
        $client = OAuthClient::with('app')->find($request->input('client_id') ?: $request->getUser());
        $secret = (string) ($request->input('client_secret') ?: $request->getPassword());
        if (! $client || $client->revoked || ! $client->confidential() || ! Hash::check($secret, $client->secret) || $client->type !== 'web' || ! $client->app) {
            return $this->err('invalid_client', 'Use the app’s web (confidential) client.', 401);
        }
        $scope = (string) $request->input('scope');
        if (! in_array('openid', explode(' ', $scope), true)) {
            return $this->err('invalid_scope', 'scope must include openid.');
        }
        $hint = (string) $request->input('login_hint');
        if ($idt = $request->input('id_token_hint')) {
            $hint = (string) (\App\Services\SigningKeys::verify((string) $idt, false)?->claims()->get('sub') ?? '');
        }
        $user = str_contains($hint, '@') ? User::where('email', mb_strtolower($hint))->first() : User::findBySub($hint);
        if (! $user || $user->status !== 'active' || ! Claims::canUse($user, $client->app)) {
            return $this->err('unknown_user_id', 'No one with access to this app matches login_hint.');
        }
        $message = Str::limit(trim((string) $request->input('binding_message')), 60, '');
        $id = Str::random(48);
        $ttl = min(self::TTL, max(30, (int) ($request->input('requested_expiry') ?: self::TTL)));
        DB::table('ciba_requests')->insert(['id' => $id, 'client_id' => $client->id, 'user_id' => $user->id, 'scope' => Str::limit($scope, 500, ''),
            'binding_message' => $message ?: null, 'status' => 'pending', 'expires_at' => now()->addSeconds($ttl), 'created_at' => now(), 'updated_at' => now()]);
        rescue(fn () => Mail::to($user->email)->send(new \App\Mail\TemplateMail(
            __(':app asks for your OK', ['app' => $client->app->name]),
            __(':app asks you to approve a sign-in:', ['app' => $client->app->name])."\n\n".($message ? "“{$message}”\n\n" : '').
            __('Open the link to approve or refuse. It works for :n minutes. If you didn’t expect this, refuse it.', ['n' => intdiv($ttl, 60) ?: 1]),
            route('ciba.show', $id))), report: true);
        Audit::log('signin.ciba_requested', 'signin', $user, after: ['app' => $client->app->key, 'message' => $message], appId: $client->app_id, actorType: 'app');

        return response()->json(['auth_req_id' => $id, 'expires_in' => $ttl, 'interval' => 5], 200, ['Cache-Control' => 'no-store']);
    }

    /** GET /approve/{id} — signed in; 2-step done if the app needs it. */
    public function show(Request $request, string $id)
    {
        $r = $this->pending($id, $request->user());
        $client = OAuthClient::with('app')->find($r->client_id);
        if (! $request->session()->get('auth.mfa') && Claims::mfaRequired($request->user(), $client->app)) {
            $request->session()->put(['url.intended' => $request->fullUrl(), 'login.user_id' => $request->user()->id]);

            return redirect()->route('login.mfa');
        }

        return view('auth.ciba', ['theme' => \App\Services\Theme::for($client->app), 'r' => $r, 'app' => $client->app]);
    }

    public function decide(Request $request, string $id, AuthorizationServer $server)
    {
        $user = $request->user();
        $r = $this->pending($id, $user);
        $client = OAuthClient::with('app')->find($r->client_id);
        $app = $client->app;
        $approve = $request->input('decision') === 'approve';
        if ($approve && (! Claims::canUse($user, $app) || ! SigninPolicies::allowsVia($app, $request->session()->get('auth.via')) || SigninPolicies::check($app, $user))) {
            $approve = false;
        }
        if (! $approve) {
            DB::table('ciba_requests')->where('id', $id)->update(['status' => 'denied', 'updated_at' => now()]);
            Audit::log('signin.ciba_denied', 'signin', $user, after: ['app' => $app->key], appId: $app->id, actorId: $user->id, actorType: 'user');

            return view('auth.device-done', ['theme' => \App\Services\Theme::for($app), 'app' => $app, 'approved' => false, 'message' => __('Refused. :app was told no.', ['app' => $app->name])]);
        }
        $verifier = Str::random(64);
        $out = AuthCodes::issue($server, ['response_type' => 'code', 'client_id' => $client->id, 'redirect_uri' => $client->redirect_uris[0], 'scope' => $r->scope,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'],
            $user, [...$request->session()->get('auth.amr', ['pwd']), 'mca']);
        DB::table('ciba_requests')->where('id', $id)->update(['status' => 'approved', 'code' => Crypt::encryptString($out['code']), 'verifier' => $verifier, 'updated_at' => now()]);

        return view('auth.device-done', ['theme' => \App\Services\Theme::for($app), 'app' => $app, 'approved' => true, 'message' => null]);
    }

    /**
     * Token request for the CIBA grant (called from TokenController). Returns a response, or the parameters of an
     * authorization_code exchange to hand to the token endpoint.
     */
    public static function exchange(Request $request): array|\Illuminate\Http\JsonResponse
    {
        $client = OAuthClient::find($request->input('client_id') ?: $request->getUser());
        $secret = (string) ($request->input('client_secret') ?: $request->getPassword());
        if (! $client || ! $client->confidential() || ! Hash::check($secret, $client->secret)) {
            return (new self)->err('invalid_client', null, 401);
        }
        $r = DB::table('ciba_requests')->where('id', (string) $request->input('auth_req_id'))->where('client_id', $client->id)->first();
        if (! $r || now()->greaterThan($r->expires_at)) {
            return (new self)->err('expired_token', 'This request expired. Start a new one.');
        }
        if ($r->status === 'pending') {
            $tooSoon = $r->last_polled_at && now()->diffInSeconds($r->last_polled_at, true) < 4;
            DB::table('ciba_requests')->where('id', $r->id)->update(['last_polled_at' => now()]);

            return (new self)->err($tooSoon ? 'slow_down' : 'authorization_pending', null);
        }
        if ($r->status !== 'approved') {
            return (new self)->err($r->status === 'denied' ? 'access_denied' : 'invalid_grant', null);
        }
        DB::table('ciba_requests')->where('id', $r->id)->where('status', 'approved')->update(['status' => 'used', 'updated_at' => now()]);

        return ['grant_type' => 'authorization_code', 'code' => Crypt::decryptString($r->code), 'code_verifier' => $r->verifier,
            'redirect_uri' => $client->redirect_uris[0], 'client_id' => $client->id, 'client_secret' => $secret];
    }

    private function pending(string $id, User $user): object
    {
        $r = DB::table('ciba_requests')->where('id', $id)->where('user_id', $user->id)->where('status', 'pending')->where('expires_at', '>', now())->first();
        abort_unless($r, 404);

        return $r;
    }

    private function err(string $code, ?string $desc, int $status = 400)
    {
        return response()->json(array_filter(['error' => $code, 'error_description' => $desc]), $status, ['Cache-Control' => 'no-store']);
    }
}
