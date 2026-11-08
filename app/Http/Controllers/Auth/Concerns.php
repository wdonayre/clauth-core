<?php

namespace App\Http\Controllers\Auth;

use App\Mail\NewDeviceMail;
use App\Models\Application;
use App\Models\OAuthClient;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Settings;
use App\Services\Theme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** Shared sign-in helpers: which app is this flow for, pending user, finishing the sign-in. */
trait Concerns
{
    protected function flowApp(Request $request): ?Application
    {
        if ($id = $request->session()->get('login.client_id')) {
            return OAuthClient::with('app')->find($id)?->app;
        }
        if ($key = $request->session()->get('login.app_key')) {
            return Application::where('key', $key)->first();
        }

        return null;
    }

    protected function theme(Request $request): array
    {
        return Theme::for($this->flowApp($request));
    }

    /** The user who passed the password step but not yet 2-step (or a signed-in user stepping up). */
    protected function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('login.user_id');

        return $id ? User::find($id) : $request->user();
    }

    protected function deviceToken(Request $request): ?string
    {
        return $request->cookie('clauth_dev');
    }

    protected function deviceRow(Request $request, User $user): ?UserDevice
    {
        $t = $this->deviceToken($request);

        return $t ? UserDevice::where('user_id', $user->id)->where('cookie_hash', hash('sha256', $t))->first() : null;
    }

    protected function isTrustedDevice(Request $request, User $user): bool
    {
        $d = $this->deviceRow($request, $user);

        return $d && $d->trusted_until && $d->trusted_until->isFuture() && ! $user->isAdmin();
    }

    /** Sign the user in for real. $mfa = second factor verified (or trusted device). */
    protected function completeLogin(Request $request, User $user, array $amr, bool $mfa, bool $trustDevice = false)
    {
        $remember = (bool) $request->session()->pull('login.remember', false);
        $intended = $request->session()->get('url.intended');
        $clientId = $request->session()->get('login.client_id');
        $appKey = $request->session()->get('login.app_key');

        Auth::login($user, $remember);
        $request->session()->regenerate();
        $request->session()->forget(['login.user_id', 'login.mfa_attempts', 'login.mfa_secret', 'login.recovery_codes']);
        $request->session()->put(['auth.amr' => $amr, 'auth.time' => time(), 'auth.mfa' => $mfa]);
        if ($clientId) {
            $request->session()->put('login.client_id', $clientId);
        }
        if ($appKey) {
            $request->session()->put('login.app_key', $appKey);
        }
        if ($intended) {
            $request->session()->put('url.intended', $intended);
        }
        $user->forceFill(['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();

        // Device bookkeeping: new-device email + optional "trust this device".
        $token = $this->deviceToken($request);
        $device = $token ? $this->deviceRow($request, $user) : null;
        if (! $device) {
            $token = Str::random(40);
            $isFirst = ! $user->devices()->exists();
            $device = $user->devices()->create(['cookie_hash' => hash('sha256', $token)]);
            if (! $isFirst && Settings::get('new_device_email')) {
                rescue(fn () => Mail::to($user->email)->send(new NewDeviceMail($user, (string) $request->userAgent(), (string) $request->ip())), report: true);
            }
        }
        $device->forceFill([
            'user_agent' => substr((string) $request->userAgent(), 0, 500), 'ip' => $request->ip(), 'last_seen_at' => now(),
            'trusted_until' => $trustDevice ? now()->addDays((int) Settings::get('trusted_device_days')) : $device->trusted_until,
        ])->save();
        Cookie::queue(Cookie::make('clauth_dev', $token, 60 * 24 * 400, null, null, null, true, false, 'lax'));

        return redirect()->intended(route('account'));
    }
}
