<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\OneTimeCodes;
use App\Services\Sms;
use Illuminate\Http\Request;

/** My account › phone for text-message codes (SPEC-platform §7): verify a number, then use it for 2-step / sign-in codes. */
class AccountPhoneController
{
    public function send(Request $request)
    {
        abort_unless(Sms::available(), 404);
        $phone = Sms::normalise((string) $request->input('phone'));
        if (! $phone) {
            return back()->withErrors(['phone' => 'Enter a mobile number, e.g. 0917 123 4567 or +63 917 123 4567.']);
        }
        try {
            $err = OneTimeCodes::send('sms', $phone, 'phone:'.$request->user()->id, $request->ip());
        } catch (\Throwable $e) {
            report($e);
            $err = 'We couldn’t send the text. Check the number and try again.';
        }
        if ($err) {
            return back()->withErrors(['phone' => $err]);
        }
        $request->session()->put('account.phone', $phone);

        return back()->with('status', 'Code sent to '.Sms::mask($phone).'.');
    }

    public function verify(Request $request)
    {
        $phone = $request->session()->get('account.phone');
        abort_unless($phone, 404);
        $u = $request->user();
        if (! OneTimeCodes::verify('sms', $phone, 'phone:'.$u->id, (string) $request->input('code'))) {
            return back()->withErrors(['phone_code' => 'That code isn’t right, or it expired.']);
        }
        $u->forceFill(['phone' => $phone, 'phone_verified_at' => now()])->save();
        $request->session()->forget('account.phone');
        Audit::log('security.phone_verified', 'security', $u, after: ['phone' => Sms::mask($phone)], actorType: 'user');

        return back()->with('status', 'Phone number verified.');
    }

    public function twoStep(Request $request)
    {
        $u = $request->user();
        $on = $request->boolean('on');
        abort_if($on && ($u->isAdmin() || ! $u->phone_verified_at || ! Sms::available()), 422, 'Text-message 2-step needs a verified phone, and isn’t available to admins.');
        $u->forceFill(['sms_two_step' => $on])->save();
        Audit::log($on ? 'security.sms_two_step_enabled' : 'security.sms_two_step_disabled', 'security', $u, actorType: 'user');

        return back()->with('status', $on ? 'Text-message codes are on for 2-step.' : 'Text-message codes are off for 2-step.');
    }
}
