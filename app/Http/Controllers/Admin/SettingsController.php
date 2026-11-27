<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Auth\SocialController;
use App\Models\Connection;
use App\Models\SigningKey;
use App\Models\User;
use App\Services\Audit;
use App\Services\Claims;
use App\Services\Connections;
use App\Services\Domains;
use App\Services\Settings;
use App\Services\SigningKeys;
use App\Services\Sms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class SettingsController
{
    public function show()
    {
        return view('admin.settings', [
            's' => Settings::all(),
            // Who would newly need 2-step if the tier rule were turned on (shown before turning it on).
            'tierPreview' => Settings::get('require_mfa_admin_tiers') ? collect() : Claims::tierRulePreview(),
            'keys' => SigningKey::orderByDesc('id')->limit(10)->get(),
            'admins' => User::where('clauth_role', 'owner')->orWhereHas('organizations', fn ($q) => $q->whereIn('organization_user.role', ['owner', 'admin', 'developer']))
                ->with(['organizations' => fn ($q) => $q->wherePivotIn('role', ['owner', 'admin', 'developer'])])->orderBy('name')->get(),
            'mail' => ['mailer' => config('mail.default'), 'from' => config('mail.from.address')],
            // Sign-in methods section: Google comes from the server's .env; the rest are platform connections.
            'google' => SocialController::enabled('google'),
            'googleCallback' => Domains::mainUrl('login/google/callback'),
            'providers' => Connection::whereNull('organization_id')->whereIn('type', Connection::SIGN_IN_TYPES)->orderBy('name')->get(),
            'sms' => Connections::sms(),
            'smsAvailable' => Sms::available(),
            'types' => Connections::TYPES,
        ]);
    }

    public function update(Request $request)
    {
        // "Send a test" in Settings › Email posts here with only send_test (no new route needed).
        if ($request->boolean('send_test')) {
            return $this->sendTest($request);
        }
        $data = $request->validate([
            'require_mfa_everyone' => 'boolean',
            'require_mfa_admin_tiers' => 'boolean',
            'password_min_length' => 'required|integer|min:8|max:64',
            'breached_password_check' => 'boolean',
            'lockout_attempts' => 'required|integer|min:3|max:20',
            'lockout_minutes' => 'required|integer|min:1|max:1440',
            'session_idle_hours' => 'required|integer|min:1|max:72',
            'trusted_device_days' => 'required|integer|min:0|max:90',
            'new_device_email' => 'boolean',
            'email_code_signin' => 'boolean',
            'sms_signin' => 'boolean',
            'sms_daily_cap' => 'required|integer|min:0|max:100000',
            'support_email' => 'required|email',
            'legal_reviewed' => 'boolean',
            'account_signup' => 'boolean',
        ]);
        foreach (['require_mfa_everyone', 'require_mfa_admin_tiers', 'breached_password_check', 'new_device_email', 'legal_reviewed', 'account_signup', 'email_code_signin', 'sms_signin'] as $b) {
            $data[$b] = $request->boolean($b);
        }
        $before = array_intersect_key(Settings::all(), $data);
        Settings::set($data);
        Audit::log('settings.updated', 'admin', null, $before, $data);

        return back()->with('status', 'Settings saved.');
    }

    /** Email a short test message to the signed-in owner, so they can see the mail settings work. */
    private function sendTest(Request $request)
    {
        $to = $request->user()->email;
        try {
            Mail::raw("This is a test email from Clauth. If you can read it, sending works.\n\nSent from Settings › Email.", fn ($m) => $m->to($to)->subject('Clauth test email'));
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['send_test' => 'The test email couldn’t be sent: '.$e->getMessage()]);
        }
        Audit::log('settings.test_email', 'admin', null, after: ['to' => $to]);

        return back()->with('status', "Test email sent to {$to}.");
    }

    public function stageKey()
    {
        $key = SigningKeys::generate('staged');
        Audit::log('security.key_staged', 'security', $key, after: ['kid' => $key->kid, 'activates_at' => $key->activates_at->toIso8601String()]);

        return back()->with('status', "New key {$key->kid} published. It starts signing tokens on {$key->activates_at->format('j M Y')}.");
    }

    public function activateKey(SigningKey $key)
    {
        abort_unless($key->status === 'staged', 422);
        SigningKeys::activate($key);
        Audit::log('security.key_activated', 'security', $key, after: ['kid' => $key->kid]);

        return back()->with('status', "Key {$key->kid} now signs new tokens. The old key stays published until its tokens expire.");
    }
}
