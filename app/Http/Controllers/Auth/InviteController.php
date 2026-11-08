<?php

namespace App\Http\Controllers\Auth;

use App\Models\Application;
use App\Models\AppRole;
use App\Models\Invite;
use App\Models\User;
use App\Services\Claims;
use App\Services\Invites;
use App\Services\Passwords;
use App\Services\Theme;
use Illuminate\Http\Request;

/** /invite/{token} — the only registration page (SPEC §6.5). */
class InviteController
{
    use Concerns;

    private function load(string $token): array
    {
        $invite = Invite::findByToken($token);
        $app = $invite?->primaryApp();
        $role = $invite && ($id = $invite->grants[0]['role_id'] ?? null) ? AppRole::find($id) : null;

        return [$invite, $app, $role];
    }

    public function show(Request $request, string $token)
    {
        [$invite, $app, $role] = $this->load($token);
        $theme = Theme::for($app);
        if (! $invite || ! $invite->isUsable()) {
            return response()->view('auth.invite-expired', ['theme' => $theme, 'inviter' => $invite?->inviter?->name], 410);
        }
        $user = User::where('email', $invite->email)->first();
        if ($user && $user->password) {
            if ($request->user()?->id === $user->id) {
                return $this->acceptExisting($request, $invite, $app);
            }
            $request->session()->put('url.intended', route('invite.show', $token));
            if ($app) {
                $request->session()->put('login.app_key', $app->key);
            }

            return view('auth.invite-existing', ['theme' => $theme, 'invite' => $invite, 'app' => $app, 'role' => $role]);
        }

        return view('auth.invite', ['theme' => $theme, 'invite' => $invite, 'app' => $app, 'role' => $role, 'token' => $token]);
    }

    public function store(Request $request, string $token)
    {
        [$invite, $app] = $this->load($token);
        if (! $invite || ! $invite->isUsable()) {
            return redirect()->route('invite.show', $token);
        }
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'password' => Passwords::rules(),
            'terms' => 'accepted',
        ], ['terms.accepted' => 'Please accept the Terms and Privacy policy.']);

        $user = User::firstOrNew(['email' => $invite->email]);
        if ($user->password) {
            return redirect()->route('invite.show', $token);
        }
        $user->forceFill(['name' => $data['name'], 'password' => $data['password'], 'status' => 'active', 'email_verified_at' => now()])->save();
        Invites::apply($invite, $user);
        $user->refresh();

        $request->session()->regenerate();
        $request->session()->put(['login.user_id' => $user->id, 'invite.flow' => true]);
        if ($app) {
            $request->session()->put('login.app_key', $app->key);
        }
        $request->session()->put('url.intended', $app?->home_url ?: route('account'));
        if ($invite->require_mfa) {
            $request->session()->put('invite_require_mfa', true);
        }

        if (Claims::mfaRequired($user, $app)) {
            return redirect()->route('login.mfa.setup');
        }
        $request->session()->forget('invite.flow');

        return $this->completeLogin($request, $user, ['pwd'], false);
    }

    private function acceptExisting(Request $request, Invite $invite, ?Application $app)
    {
        Invites::apply($invite, $request->user());

        return $app?->home_url ? redirect()->away($app->home_url) : redirect()->route('account')->with('status', 'Invite accepted.');
    }
}
