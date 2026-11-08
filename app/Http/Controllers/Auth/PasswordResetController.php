<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Services\Audit;
use App\Services\Passwords;
use App\Services\Sessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class PasswordResetController
{
    use Concerns;

    public function request(Request $request)
    {
        return view('auth.forgot', ['theme' => $this->theme($request)]);
    }

    public function email(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $user = User::where('email', mb_strtolower(trim($request->input('email'))))->where('status', 'active')->first();
        if ($user) {
            Password::broker()->sendResetLink(['email' => $user->email]);
        }

        return back()->with('status', 'If that email has a Clauth account, a reset link is on its way. It works for 60 minutes.');
    }

    public function reset(Request $request, string $token)
    {
        return view('auth.reset', ['theme' => $this->theme($request), 'token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request)
    {
        $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => Passwords::rules()]);
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'failed_logins' => 0, 'locked_until' => null])->save();
                Sessions::revokeAll($user);
                Audit::log('security.password_reset', 'security', $user, actorId: $user->id, actorType: 'user');
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', 'Password changed. Sign in with your new password.')
            : back()->withErrors(['email' => 'This reset link is invalid or has expired. Ask for a new one.']);
    }
}
