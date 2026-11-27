<?php

namespace App\Http\Controllers\Auth;

use App\Models\AccessProfile;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Claims;
use App\Services\Limits;
use App\Services\OneTimeCodes;
use App\Services\Passwords;
use App\Services\Settings;
use App\Services\SigninPolicies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Self sign-up, two ways. The person always proves their email with a one-time code.
 * - App sign-up: the app's sign-in policy says registration: open (SPEC-platform §8.1). They join the app's
 *   organization with the policy's access profile; only email domains on the policy's list when it has one.
 * - Account sign-up (SPEC-signup.md): anywhere else, when Settings › account_signup is on. Just a Clauth account, in
 *   no organization: it opens no app until an organization invites them or their own organization is approved. From
 *   an invite-only app they then land on "ask for access / set up your organization".
 */
class SignupController
{
    use Concerns;

    /** Sign-ups per email domain per hour (on top of the per-IP throttle on the route). */
    private const PER_DOMAIN_HOUR = 20;

    /** [app or null, its rules or null, 'app'|'account'] */
    private function rules(Request $request): array
    {
        $app = $this->flowApp($request);
        $rules = $app ? SigninPolicies::rules($app) : null;
        if ($app && $app->publisher && $rules['registration'] === 'open' && ! $app->isSuspended()) {
            return [$app, $rules, 'app'];
        }
        abort_unless(Settings::get('account_signup'), 404);

        return [$app && ! $app->isSuspended() ? $app : null, null, 'account'];
    }

    public function show(Request $request)
    {
        [$app, $r, $mode] = $this->rules($request);

        return view('auth.signup', ['theme' => $this->theme($request), 'app' => $app, 'mode' => $mode, 'domains' => $r['open_domains'] ?? [],
            'pending' => $request->session()->get('signup.pending')]);
    }

    public function store(Request $request)
    {
        [$app, $r, $mode] = $this->rules($request);
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:190', 'password' => Passwords::rules(), 'terms' => 'accepted']);
        $email = mb_strtolower(trim($data['email']));
        if ($mode === 'app' && $r['open_domains'] && ! in_array(Str::after($email, '@'), $r['open_domains'], true)) {
            return back()->withInput()->withErrors(['email' => 'Sign-up is only open to addresses at '.implode(', ', $r['open_domains']).'.']);
        }
        if ($mode === 'app' && ($msg = Limits::exceeded($app->publisher, 'people'))) {
            return back()->withInput()->withErrors(['email' => $msg]);
        }
        $domainKey = 'signup-domain:'.Str::after($email, '@');
        if (RateLimiter::tooManyAttempts($domainKey, self::PER_DOMAIN_HOUR)) {
            return back()->withInput()->withErrors(['email' => 'Too many new accounts for this email domain right now. Try again in an hour.']);
        }
        RateLimiter::hit($domainKey, 3600);
        // Same answer whether or not the address already has an account: the code email says which.
        if (! User::where('email', $email)->exists()) {
            try {
                $err = OneTimeCodes::send('email', $email, 'signup', $request->ip());
            } catch (\Throwable $e) {
                report($e);
                $err = 'We couldn’t send the email. Try again.';
            }
            if ($err) {
                return back()->withInput()->withErrors(['email' => $err]);
            }
        }
        $request->session()->put('signup.pending', ['name' => $data['name'], 'email' => $email, 'hash' => Hash::make($data['password']), 'app' => $app?->key, 'mode' => $mode]);

        return redirect()->route('signup');
    }

    public function verify(Request $request)
    {
        [$app, $r, $mode] = $this->rules($request);
        $p = $request->session()->get('signup.pending');
        if (! $p || ($p['app'] ?? null) !== $app?->key || ($p['mode'] ?? 'app') !== $mode) {
            return redirect()->route('signup');
        }
        if (User::where('email', $p['email'])->exists()) {
            $request->session()->forget('signup.pending');

            return redirect()->route('login')->withErrors(['email' => 'You already have an account. Sign in instead.']);
        }
        if (! OneTimeCodes::verify('email', $p['email'], 'signup', (string) $request->input('code'))) {
            return back()->withErrors(['code' => 'That code isn’t right, or it expired.']);
        }
        $request->session()->forget('signup.pending');
        // The "hashed" cast keeps an already-hashed value as it is (the password never sat in the session in clear).
        $user = User::create(['email' => $p['email'], 'name' => $p['name'], 'status' => 'active', 'email_verified_at' => now(), 'password' => $p['hash']]);
        if ($mode === 'account') {
            Audit::log('user.signed_up', 'admin', $user, after: ['app' => $app?->key, 'account_only' => true], appId: $app?->id, actorId: $user->id, actorType: 'user');
            $request->session()->regenerate();
            $request->session()->put(['login.user_id' => $user->id, 'login.amr' => ['pwd']]);
            if (! $app && ! $request->session()->has('url.intended')) {
                $request->session()->put('url.intended', route('welcome'));
            }

            return $this->completeLogin($request, $user->fresh(), ['pwd'], false);
        }
        $org = $app->publisher;
        $user->organizations()->attach($org->id, ['joined_at' => now(), 'role' => 'member']);
        if ($r['open_profile'] && ($profile = AccessProfile::where('organization_id', $org->id)->where('key', $r['open_profile'])->first())) {
            $user->accessProfiles()->attach($profile->id, ['assigned_by' => null, 'assigned_at' => now()]);
        }
        Access::resolve($user, $org, 'signed up to '.$app->key, null);
        Audit::log('user.signed_up', 'admin', $user, after: ['app' => $app->key, 'profile' => $r['open_profile']], appId: $app->id, orgId: $org->id, actorId: $user->id, actorType: 'user');

        $request->session()->regenerate();
        $request->session()->put(['login.user_id' => $user->id, 'login.amr' => ['pwd']]);
        if (Claims::mfaRequired($user, $app)) {
            return redirect()->route('login.mfa.setup');
        }

        return $this->completeLogin($request, $user->fresh(), ['pwd'], false);
    }
}
