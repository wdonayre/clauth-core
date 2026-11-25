<?php

namespace App\Http\Controllers\OAuth;

use App\Models\OAuthClient;
use App\Services\AppJoin;
use Illuminate\Http\Request;

/** POST /oauth/join — the answer on the "Join :app" screen; goes back to /oauth/authorize either way. */
class JoinController
{
    public function __invoke(Request $request)
    {
        $pending = $request->session()->pull('join.request');
        $user = $request->user();
        abort_unless($pending && $user, 419);
        $client = OAuthClient::with('app')->findOrFail($pending['client']);
        if ($request->input('decision') === 'join' && AppJoin::join($user, $client->app)) {
            // Joining shows the same list the consent screen would, so it counts as the answer there too.
            $request->session()->put('consent.answer.'.$client->id, 'yes');
        } else {
            $request->session()->put('join.answer.'.$client->id, 'no');
        }

        return redirect()->to($pending['url']);
    }
}
