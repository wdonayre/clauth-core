<?php

namespace App\Services;

use App\Models\ClientSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Revokes refresh/access tokens and Clauth web sessions. */
class Sessions
{
    public static function revokeClient(User $user, string $clientId): void
    {
        $tokenIds = DB::table('oauth_access_tokens')->where('user_id', $user->id)->where('client_id', $clientId)->pluck('id');
        DB::table('oauth_access_tokens')->whereIn('id', $tokenIds)->update(['revoked' => true]);
        DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
        ClientSession::where('user_id', $user->id)->where('client_id', $clientId)->delete();
    }

    public static function revokeApp(User $user, string $appId): void
    {
        foreach (DB::table('oauth_clients')->where('app_id', $appId)->pluck('id') as $clientId) {
            self::revokeClient($user, $clientId);
        }
    }

    /** "Sign out everywhere": every app token + every Clauth browser session (optionally keeping one). */
    public static function revokeAll(User $user, ?string $keepSessionId = null): void
    {
        $tokenIds = DB::table('oauth_access_tokens')->where('user_id', $user->id)->pluck('id');
        DB::table('oauth_access_tokens')->whereIn('id', $tokenIds)->update(['revoked' => true]);
        DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
        ClientSession::where('user_id', $user->id)->delete();
        DB::table('sessions')->where('user_id', $user->id)
            ->when($keepSessionId, fn ($q) => $q->where('id', '!=', $keepSessionId))->delete();
        $user->forceFill(['remember_token' => null])->save();
    }
}
