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
use App\Services\SigninPolicies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Self sign-up for an app whose sign-in policy says registration: open (SPEC-platform §8.1). The person proves their
 * email with a one-time code, joins the app's organization and gets the policy's access profile. Only for apps — Clauth
 * itself stays invite-only — and only email domains on the policy's list when it has one.
 */
class SignupController
{
    use Concerns;

    private function rules(Request $request): array
    {
        $app = $this->flowApp($request);
        abort_unless($app && $app->publisher && SigninPolicies::rules($app)['registration'] === 'open' && ! $app->isSuspended(), 404);

        return [$app, SigninPolicies::rules($app)];
    }

    public function show(Request $request)
    {
        [$app, $r] = $this->rules($request);

        return view('auth.signup', ['theme' => $this->theme($request), 'app' => $app, 'domains' => $r['open_domains'],
            'pending' => $request->session()->get('signup.pending')]);
    }

    public function store(Request $request)
    {
        [$app, $r] = $this->rules($request);
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:190', 'password' => Passwords::rules(), 'terms' => 'accepted']);
        $email = mb_strtolower(trim($data['email']));
        if ($r['open_domains'] && ! in_array(Str::after($email, '@'), $r['open_domains'], true)) {
            return back()->withInput()->withErrors(['email' => 'Sign-up is only open to addresses at '.implode(', ', $r['open_domains']).'.']);
        }
        if ($msg = Limits::exceeded($app->publisher, 'people')) {
            return back()->withInput()->withErrors(['email' => $msg]);
        }
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
        $request->session()->put('signup.pending', ['name' => $data['name'], 'email' => $email, 'hash' => Hash::make($data['password']), 'app' => $app->key]);

        return redirect()->route('signup');
    }

    public function verify(Request $request)
    {
        [$app, $r] = $this->rules($request);
        $p = $request->session()->get('signup.pending');
        if (! $p || $p['app'] !== $app->key) {
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
        $org = $app->publisher;
        // The "hashed" cast keeps an already-hashed value as it is (the password never sat in the session in clear).
        $user = User::create(['email' => $p['email'], 'name' => $p['name'], 'status' => 'active', 'email_verified_at' => now(), 'password' => $p['hash']]);
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
