<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\Sessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** /signout-everywhere — linked from the Clauth bar. Confirm, then end every app session and Clauth session. */
class SignOutEverywhereController
{
    public function show(Request $request)
    {
        return view('auth.signout-everywhere', ['user' => $request->user()]);
    }

    public function store(Request $request)
    {
        if ($user = $request->user()) {
            Sessions::revokeAll($user);
            Audit::log('security.signout_everywhere', 'security', $user, actorId: $user->id, actorType: 'user');
            Auth::logout();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return view('auth.signed-out', ['everywhere' => true]);
    }
}
