<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Services\Audit;
use App\Services\Claims;
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
        if (! $user->hasTwoFactor() && ! $this->smsUsable($request, $user)) {
            return redirect()->route('login.mfa.setup');
        }

        return view('auth.mfa', ['theme' => $this->theme($request), 'user' => $user, 'recovery' => $request->boolean('recovery'), 'canTrust' => ! $user->isAdmin(),
            'totp' => $user->hasTwoFactor(), 'sms' => $this->smsUsable($request, $user), 'smsSent' => (bool) $request->session()->get('login.sms_sent'),
            'phone' => \App\Services\Sms::mask($user->phone)]);
    }

    /** Text-message 2-step works for this person now (not when the first factor already was a text). */
    private function smsUsable(Request $request, User $user): bool
    {
        return $user->hasSmsTwoStep() && ! in_array('sms', $request->session()->get('login.amr', []), true)
            && \App\Services\SigninPolicies::rules($this->flowApp($request))['sms_second_step'];
    }

    /** POST /login/2fa/sms — text the code. */
    public function sendSms(Request $request)
    {
        $user = $this->pendingUser($request);
        if (! $user || ! $this->smsUsable($request, $user)) {
            return redirect()->route('login');
        }
        try {
            $err = \App\Services\OneTimeCodes::send('sms', $user->phone, 'mfa', $request->ip());
        } catch (\Throwable $e) {
            report($e);
            $err = 'We couldn’t send the text. Try again, or use another way.';
        }
        if ($err) {
            return back()->withErrors(['code' => $err]);
        }
        $request->session()->put('login.sms_sent', true);

        return back()->with('status', 'Code sent to '.\App\Services\Sms::mask($user->phone).'.');
    }

    public function verify(Request $request)
    {
        $user = $this->pendingUser($request);
        if (! $user || ! $user->hasSecondFactor()) {
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

            return $this->completeLogin($request, $user, [...$request->session()->get('login.amr', ['pwd']), 'otp'], true);
        }

        if ($request->session()->get('login.sms_sent') && $this->smsUsable($request, $user)
            && \App\Services\OneTimeCodes::verify('sms', $user->phone, 'mfa', (string) $request->input('code'))) {
            $request->session()->forget('login.sms_sent');

            return $this->completeLogin($request, $user, [...$request->session()->get('login.amr', ['pwd']), 'sms'], true, $request->boolean('trust') && ! $user->isAdmin());
        }
        if (! $user->hasTwoFactor() || ! TwoFactor::verify($user->two_factor_secret, (string) $request->input('code'), $user->id)) {
            return back()->withErrors(['code' => 'That code isn’t right. Check your authenticator app and try again.']);
        }

        return $this->completeLogin($request, $user, [...$request->session()->get('login.amr', ['pwd']), 'otp'], true, $request->boolean('trust') && ! $user->isAdmin());
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
            'canSkip' => $this->canSkipSetup($request, $user),
            'smsSetup' => \App\Services\Sms::available() && ! $user->isAdmin() && ! in_array('sms', $request->session()->get('login.amr', []), true),
        ]);
    }

    /** GET /login/2fa/sms-setup — "No authenticator app? Use text messages" (not for admins). */
    public function smsSetup(Request $request)
    {
        $user = $this->pendingUser($request);
        if (! $user || $user->isAdmin() || ! \App\Services\Sms::available()) {
            return redirect()->route('login');
        }

        return view('auth.sms-setup', ['theme' => $this->theme($request), 'phone' => $request->session()->get('login.sms_phone')]);
    }

    public function smsSetupSend(Request $request)
    {
        $user = $this->pendingUser($request);
        if (! $user || $user->isAdmin() || ! \App\Services\Sms::available()) {
            return redirect()->route('login');
        }
        $phone = \App\Services\Sms::normalise((string) $request->input('phone'));
        if (! $phone) {
            return back()->withErrors(['phone' => 'Enter a mobile number, e.g. 0917 123 4567 or +63 917 123 4567.'])->withInput();
        }
        try {
            $err = \App\Services\OneTimeCodes::send('sms', $phone, 'phone:'.$user->id, $request->ip());
        } catch (\Throwable $e) {
            report($e);
            $err = 'We couldn’t send the text. Check the number and try again.';
        }
        if ($err) {
            return back()->withErrors(['phone' => $err])->withInput();
        }
        $request->session()->put('login.sms_phone', $phone);

        return redirect()->route('login.mfa.sms-setup');
    }

    public function smsSetupConfirm(Request $request)
    {
        $user = $this->pendingUser($request);
        $phone = $request->session()->get('login.sms_phone');
        if (! $user || ! $phone || $user->isAdmin()) {
            return redirect()->route('login');
        }
        if (! \App\Services\OneTimeCodes::verify('sms', $phone, 'phone:'.$user->id, (string) $request->input('code'))) {
            return back()->withErrors(['code' => 'That code isn’t right, or it expired. Send a new one.']);
        }
        $user->forceFill(['phone' => $phone, 'phone_verified_at' => now(), 'sms_two_step' => true])->save();
        Audit::log('security.sms_two_step_enabled', 'security', $user, after: ['phone' => \App\Services\Sms::mask($phone)], actorId: $user->id, actorType: 'user');
        $request->session()->forget(['login.sms_phone', 'invite.flow', 'invite_require_mfa', 'login.mfa_secret']);

        return $this->completeLogin($request, $user, [...$request->session()->get('login.amr', ['pwd']), 'sms'], true);
    }

    /** Onboarding only: skip 2-step setup when no rule requires it (they can turn it on later in My account). */
    public function skip(Request $request)
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('login');
        }
        if (! $this->canSkipSetup($request, $user)) {
            return redirect()->route('login.mfa.setup')->withErrors(['code' => '2-step verification is required for your account.']);
        }
        Audit::log('security.mfa_skipped', 'security', $user, actorId: $user->id, actorType: 'user');
        $request->session()->forget(['invite.flow', 'invite_require_mfa', 'login.mfa_secret']);

        return $this->completeLogin($request, $user, $request->session()->get('login.amr', ['pwd']), false);
    }

    private function canSkipSetup(Request $request, User $user): bool
    {
        return $request->session()->get('invite.flow') && ! $user->hasSecondFactor() && ! Claims::mfaRequired($user, $this->flowApp($request));
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

        return $this->completeLogin($request, $user, [...$request->session()->get('login.amr', ['pwd']), 'otp'], true);
    }
}
