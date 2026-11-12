<?php

namespace App\Http\Controllers\Admin;

use App\Models\SigningKey;
use App\Models\User;
use App\Services\Audit;
use App\Services\Settings;
use App\Services\SigningKeys;
use Illuminate\Http\Request;

class SettingsController
{
    public function show()
    {
        return view('admin.settings', [
            's' => Settings::all(),
            'keys' => SigningKey::orderByDesc('id')->limit(10)->get(),
            'admins' => User::where('clauth_role', 'owner')->orWhereHas('organizations', fn ($q) => $q->whereIn('organization_user.role', ['owner', 'admin', 'developer']))
                ->with(['organizations' => fn ($q) => $q->wherePivotIn('role', ['owner', 'admin', 'developer'])])->orderBy('name')->get(),
            'mail' => ['mailer' => config('mail.default'), 'from' => config('mail.from.address')],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'require_mfa_everyone' => 'boolean',
            'password_min_length' => 'required|integer|min:8|max:64',
            'breached_password_check' => 'boolean',
            'lockout_attempts' => 'required|integer|min:3|max:20',
            'lockout_minutes' => 'required|integer|min:1|max:1440',
            'session_idle_hours' => 'required|integer|min:1|max:72',
            'trusted_device_days' => 'required|integer|min:0|max:90',
            'new_device_email' => 'boolean',
            'support_email' => 'required|email',
            'legal_reviewed' => 'boolean',
        ]);
        foreach (['require_mfa_everyone', 'breached_password_check', 'new_device_email', 'legal_reviewed'] as $b) {
            $data[$b] = $request->boolean($b);
        }
        $before = array_intersect_key(Settings::all(), $data);
        Settings::set($data);
        Audit::log('settings.updated', 'admin', null, $before, $data);

        return back()->with('status', 'Settings saved.');
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
