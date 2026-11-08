<?php

namespace App\Http\Controllers\Auth;

use App\Services\Audit;
use App\Services\TwoFactor;
use Illuminate\Http\Request;

/** 2-step code at sign-in, and forced enrolment (also step 2–3 of invite setup). */
class TwoFactorController
{
    use Concerns;

    public function challenge(Request $request)
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('login');
        }
        if (! $user->hasTwoFactor()) {
            return redirect()->route('login.mfa.setup');
        }

        return view('auth.mfa', ['theme' => $this->theme($request), 'user' => $user, 'recovery' => $request->boolean('recovery'), 'canTrust' => ! $user->isAdmin()]);
    }

    public function verify(Request $request)
    {
        $user = $this->pendingUser($request);
        if (! $user || ! $user->hasTwoFactor()) {
            return redirect()->route('login');
        }
        $attempts = $request->session()->increment('login.mfa_attempts');
        if ($attempts > 5) {
            $request->session()->forget(['login.user_id', 'login.mfa_attempts']);

            return redirect()->route('login')->withErrors(['email' => 'Too many wrong codes. Sign in again.']);
        }

        if ($request->filled('recovery_code')) {
            if (! TwoFactor::useRecoveryCode($user, $request->input('recovery_code'))) {
                return back()->withErrors(['recovery_code' => 'That recovery code isn’t valid.']);
            }
            Audit::log('security.recovery_code_used', 'security', $user, after: ['left' => count($user->two_factor_recovery_codes ?? [])], actorId: $user->id, actorType: 'user');

            return $this->completeLogin($request, $user, ['pwd', 'otp'], true);
        }

        if (! TwoFactor::verify($user->two_factor_secret, (string) $request->input('code'), $user->id)) {
            return back()->withErrors(['code' => 'That code isn’t right. Check your authenticator app and try again.']);
        }

        return $this->completeLogin($request, $user, ['pwd', 'otp'], true, $request->boolean('trust') && ! $user->isAdmin());
    }

    public function setup(Request $request)
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('login');
        }
        $secret = $request->session()->get('login.mfa_secret') ?? tap(TwoFactor::newSecret(), fn ($s) => $request->session()->put('login.mfa_secret', $s));

        return view('auth.mfa-setup', [
            'theme' => $this->theme($request), 'user' => $user, 'secret' => $secret,
            'qr' => TwoFactor::qrSvg($user, $secret), 'invite' => $request->session()->get('invite.flow'),
        ]);
    }

    public function confirm(Request $request)
    {
        $user = $this->pendingUser($request);
        $secret = $request->session()->get('login.mfa_secret');
        if (! $user || ! $secret) {
            return redirect()->route('login');
        }
        if (! TwoFactor::verify($secret, (string) $request->input('code'))) {
            return back()->withErrors(['code' => 'That code isn’t right. Make sure your phone’s time is correct and try again.']);
        }
        $codes = TwoFactor::recoveryCodes();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();
        Audit::log('security.mfa_enabled', 'security', $user, actorId: $user->id, actorType: 'user');
        $request->session()->forget('login.mfa_secret');
        $request->session()->put('login.recovery_codes', $codes);

        return redirect()->route('login.mfa.codes');
    }

    public function codes(Request $request)
    {
        $user = $this->pendingUser($request);
        $codes = $request->session()->get('login.recovery_codes');
        if (! $user || ! $codes) {
            return redirect()->route('login');
        }

        return view('auth.recovery-codes', ['theme' => $this->theme($request), 'codes' => $codes, 'invite' => $request->session()->get('invite.flow')]);
    }

    public function finish(Request $request)
    {
        $request->validate(['saved' => 'accepted'], ['saved.accepted' => 'Tick the box once you’ve saved your codes.']);
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('login');
        }
        $request->session()->forget(['invite.flow', 'invite_require_mfa']);

        return $this->completeLogin($request, $user, ['pwd', 'otp'], true);
    }
}
