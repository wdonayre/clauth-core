<?php

namespace App\Http\Controllers\Auth;

use App\Mail\LockoutMail;
use App\Models\User;
use App\Services\Audit;
use App\Services\Claims;
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

        $socialError = SocialController::ERRORS[(string) $request->query('social_error')] ?? null;

        return view('auth.login', ['theme' => $this->theme($request), 'app' => $this->flowApp($request),
            'google' => SocialController::enabled('google'), 'socialError' => $socialError]);
    }

    public function store(Request $request)
    {
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
                rescue(fn () => Mail::to($user->email)->send(new LockoutMail($user)), report: true);

                return redirect()->route('login.locked');
            }

            return $fail('That email and password don’t match.');
        }

        $request->session()->regenerate();
        $request->session()->put(['login.user_id' => $user->id, 'login.remember' => $request->boolean('remember')]);
        $request->session()->forget('login.amr'); // first factor = password
        $app = $this->flowApp($request);

        if (Claims::mfaRequired($user, $app)) {
            if (! $user->hasTwoFactor()) {
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
