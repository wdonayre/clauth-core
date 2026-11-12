<?php

namespace App\Http\Controllers\OAuth;

use App\Models\OAuthClient;
use App\Services\Consents;
use Illuminate\Http\Request;

/** POST /oauth/consent — the person's answer on the consent screen; goes back to /oauth/authorize. */
class ConsentController
{
    public function __invoke(Request $request)
    {
        $pending = $request->session()->pull('consent.request');
        $user = $request->user();
        abort_unless($pending && $user, 419);
        $client = OAuthClient::with('app')->findOrFail($pending['client']);
        if ($request->input('decision') === 'allow') {
            Consents::grant($user, $client->app, $pending['scopes'] ?: ['openid', 'profile', 'email', 'clauth']);
            $request->session()->put('consent.answer.'.$client->id, 'yes');
        } else {
            $request->session()->put('consent.answer.'.$client->id, 'no');
        }

        return redirect()->to($pending['url']);
    }
}
