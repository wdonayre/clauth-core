<?php

namespace App\Http\Controllers\Auth;

use App\Mail\LockoutMail;
use App\Models\User;
use App\Services\Audit;
use App\Services\Claims;
use App\Services\Connections;
use App\Services\SigninPolicies;
use App\Services\Domains;
use App\Services\Settings;
use App\Services\Sso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class LoginController
{
    use Concerns;

    public function show(Request $request)
    {
        if ($request->user() && ! $request->session()->has('login.client_id')) {
            return redirect()->route('account');
        }

        // On an app's own domain: first ask the main domain whether this browser is already signed in.
        $domain = $request->attributes->get('clauth.domain');
        if ($domain && $request->query('sso')) {
            // Back from "Continue with Google" on the main domain: ask main again so the new sign-in is picked up.
            $request->session()->forget(['sso.checked', 'login.prompted']);
        }
        if ($domain && ! $request->user() && ! $request->session()->get('sso.checked') && ! $request->session()->get('login.prompted')) {
            $state = Str::random(40);
            $request->session()->put(['sso.state' => $state, 'sso.return' => $request->session()->get('url.intended') ?: '/login']);

            return redirect()->away(Domains::mainUrl('sso/check?'.http_build_query(['host' => $domain->host, 'state' => $state])));
        }

        $socialError = SocialController::errors()[(string) $request->query('social_error')] ?? null;

        $app = $this->flowApp($request);
        $rules = SigninPolicies::rules($app);

        return view('auth.login', ['theme' => $this->theme($request), 'app' => $app,
            'google' => SocialController::enabled('google') && $rules['google'], 'socialError' => $socialError,
            'connections' => Connections::buttons($app, $request->attributes->get('clauth.domain'))->filter(fn ($c) => SigninPolicies::allowsVia($app, $c->key))->values(),
            'codeSignIn' => LoginCodeController::available($app), 'passwordForm' => $rules['password'],
            'signup' => $app && $rules['registration'] === 'open', 'policyNotice' => $request->session()->pull('login.policy_notice')]);
    }

    public function store(Request $request)
    {
        // People whose organization requires its own sign-in (e.g. Microsoft 365) go there, whatever they typed.
        if ($request->filled('email') && filter_var($request->input('email'), FILTER_VALIDATE_EMAIL) && ($c = Connections::required((string) $request->input('email')))) {
            return redirect()->route('login.connection', ['key' => $c->key, 'login_hint' => mb_strtolower(trim($request->input('email'))), 'remember' => $request->boolean('remember') ? 1 : null]);
        }
        if (! SigninPolicies::rules($this->flowApp($request))['password']) {
            return back()->withErrors(['email' => 'This app doesn’t use passwords. Use one of the buttons above.']);
        }
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::where('email', mb_strtolower(trim($data['email'])))->first();
        $fail = fn (string $msg) => back()->withInput($request->only('email', 'remember'))->withErrors(['email' => $msg]);

        if (! $user || ! $user->password) {
            if ($user && $user->status === 'invited') {
                return $fail('Your account isn’t set up yet. Use the link in your invite email.');
            }
            Hash::check($data['password'], '$2y$12$abcdefghijklmnopqrstuuJ5a0rLBZT6Ql5rjZaEuk3E0Zy8lKcEW'); // equal timing

            return $fail('That email and password don’t match.');
        }
        if ($user->status === 'disabled') {
            return $fail('This account is turned off. Ask your admin.');
        }
        if ($user->isLocked()) {
            return redirect()->route('login.locked');
        }
        if (! Hash::check($data['password'], $user->password)) {
            $user->increment('failed_logins');
            Audit::log('signin.failed', 'signin', $user, after: ['attempts' => $user->failed_logins], actorId: $user->id, actorType: 'user');
            if ($user->failed_logins >= (int) Settings::get('lockout_attempts')) {
                $user->forceFill(['locked_until' => now()->addMinutes((int) Settings::get('lockout_minutes')), 'failed_logins' => 0])->save();
                Audit::log('signin.locked', 'security', $user, after: ['until' => $user->locked_until->toIso8601String()], actorId: $user->id, actorType: 'system');
                rescue(fn () => Mail::to($user->email)->send(\App\Services\Templates::mail('lockout', null, ['name' => $user->name, 'minutes' => Settings::get('lockout_minutes')], $user->locale) ?? new LockoutMail($user)), report: true);

                return redirect()->route('login.locked');
            }

            return $fail('That email and password don’t match.');
        }

        $request->session()->regenerate();
        $request->session()->put(['login.user_id' => $user->id, 'login.remember' => $request->boolean('remember')]);
        $request->session()->forget('login.amr'); // first factor = password
        $app = $this->flowApp($request);

        if (Claims::mfaRequired($user, $app)) {
            if (! $user->hasSecondFactor()) {
                return redirect()->route('login.mfa.setup');
            }
            if ($this->isTrustedDevice($request, $user)) {
                return $this->completeLogin($request, $user, ['pwd'], true);
            }

            return redirect()->route('login.mfa');
        }

        return $this->completeLogin($request, $user, ['pwd'], false);
    }

    public function locked(Request $request)
    {
        return view('auth.locked', ['theme' => $this->theme($request), 'minutes' => (int) Settings::get('lockout_minutes')]);
    }

    public function logout(Request $request)
    {
        Sso::endFamily($request);
        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
