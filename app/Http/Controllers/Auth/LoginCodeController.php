<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Services\Audit;
use App\Services\Claims;
use App\Services\OneTimeCodes;
use App\Services\Settings;
use App\Services\Sms;
use Illuminate\Http\Request;

/**
 * Sign in with a one-time code by email or text instead of a password (SPEC-platform §7). Each channel is switched on
 * in Settings. The answer never tells whether an account exists. The code is the first factor: 2-step rules still
 * apply, and a text-message code can't also be the second factor.
 */
class LoginCodeController
{
    use Concerns;

    public static function available(): bool
    {
        return (bool) Settings::get('email_code_signin') || ((bool) Settings::get('sms_signin') && Sms::available());
    }

    private function channels(): array
    {
        return array_keys(array_filter(['email' => (bool) Settings::get('email_code_signin'), 'sms' => Settings::get('sms_signin') && Sms::available()]));
    }

    public function show(Request $request)
    {
        abort_unless(self::available(), 404);

        return view('auth.login-code', ['theme' => $this->theme($request), 'app' => $this->flowApp($request), 'channels' => $this->channels(),
            'sent' => $request->session()->get('login.code')]);
    }

    public function send(Request $request)
    {
        abort_unless(self::available(), 404);
        $data = $request->validate(['email' => 'required|email', 'via' => 'required|in:'.implode(',', $this->channels())]);
        $email = mb_strtolower(trim($data['email']));
        $user = User::where('email', $email)->first();
        $ok = $user && $user->status === 'active' && ! $user->isLocked() && ($data['via'] === 'email' || ($user->phone && $user->phone_verified_at));
        if ($ok) {
            try {
                $err = OneTimeCodes::send($data['via'] === 'sms' ? 'sms' : 'email', $data['via'] === 'sms' ? $user->phone : $user->email, 'signin', $request->ip());
            } catch (\Throwable $e) {
                report($e);
                $err = 'We couldn’t send the code. Try again, or sign in with your password.';
            }
            if ($err) {
                return back()->withErrors(['email' => $err])->withInput();
            }
        }
        $request->session()->put('login.code', ['email' => $email, 'via' => $data['via'], 'user_id' => $ok ? $user->id : null,
            'to' => $data['via'] === 'sms' ? Sms::mask($user?->phone) : $email]);
        $request->session()->put('login.remember', $request->boolean('remember'));

        return redirect()->route('login.code');
    }

    public function verify(Request $request)
    {
        abort_unless(self::available(), 404);
        $flow = $request->session()->get('login.code');
        if (! $flow) {
            return redirect()->route('login.code');
        }
        $tries = $request->session()->increment('login.code_tries');
        $user = $flow['user_id'] ? User::find($flow['user_id']) : null;
        $channel = $flow['via'] === 'sms' ? 'sms' : 'email';
        if ($tries > 5 || ! $user || ! OneTimeCodes::verify($channel, $channel === 'sms' ? (string) $user->phone : $user->email, 'signin', (string) $request->input('code'))) {
            if ($tries > 5) {
                $request->session()->forget(['login.code', 'login.code_tries']);

                return redirect()->route('login.code')->withErrors(['email' => 'Too many wrong codes. Ask for a new one.']);
            }

            return back()->withErrors(['code' => 'That code isn’t right, or it expired.']);
        }
        $request->session()->forget(['login.code', 'login.code_tries']);
        if ($user->status !== 'active' || $user->isLocked()) {
            return redirect()->route('login');
        }
        $amr = [$channel === 'sms' ? 'sms' : 'email'];
        Audit::log('signin.code', 'signin', $user, after: ['via' => $channel], actorId: $user->id, actorType: 'user');
        $remember = (bool) $request->session()->get('login.remember', false);
        $request->session()->regenerate();
        $request->session()->put(['login.user_id' => $user->id, 'login.remember' => $remember, 'login.amr' => $amr]);
        if (Claims::mfaRequired($user, $this->flowApp($request))) {
            $smsLeft = $user->hasSmsTwoStep() && $channel !== 'sms';
            if (! $user->hasTwoFactor() && ! $smsLeft) {
                return redirect()->route('login.mfa.setup');
            }
            if ($this->isTrustedDevice($request, $user)) {
                return $this->completeLogin($request, $user, $amr, true);
            }

            return redirect()->route('login.mfa');
        }

        return $this->completeLogin($request, $user, $amr, false);
    }

    public function restart(Request $request)
    {
        $request->session()->forget(['login.code', 'login.code_tries']);

        return redirect()->route('login.code');
    }
}
