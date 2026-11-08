<?php

namespace App\Http\Controllers\Api;

use App\Models\OAuthClient;
use App\Models\User;
use App\Services\Claims;
use App\Services\SigningKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** GET /api/v1/users/{sub} — m2m clients with scope users:read (SPEC §9). */
class UserController
{
    public function show(Request $request, string $sub)
    {
        $t = SigningKeys::verify((string) $request->bearerToken());
        if (! $t || ! in_array('users:read', explode(' ', (string) $t->claims()->get('scope')), true)
            || DB::table('oauth_access_tokens')->where('id', $t->claims()->get('jti'))->where('revoked', false)->doesntExist()) {
            return response()->json(['error' => 'invalid_token'], 401);
        }
        $user = User::findBySub($sub);
        if (! $user) {
            return response()->json(['error' => 'not_found'], 404);
        }
        $client = OAuthClient::with('app')->find($t->claims()->get('client_id'));

        return response()->json([
            'sub' => $user->sub(),
            'email' => $user->email,
            'name' => $user->name,
            'status' => $user->status,
            'orgs' => $client?->app ? (object) Claims::orgs($user, $client->app) : (object) [],
        ]);
    }
}
