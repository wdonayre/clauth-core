<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Single sign-on across Clauth's domains (main + apps' own sign-in domains). Browsers keep a separate cookie per
 * domain, so sessions are linked by top-level redirects through the main domain carrying one-time tickets
 * (90 s, single use, bound to the target host). Linked sessions share a "family"; signing out ends the family.
 */
class Sso
{
    private const TTL = 90;

    public static function family(Request $request): string
    {
        $f = $request->session()->get('sso.family');
        if (! $f) {
            $f = Str::random(40);
            $request->session()->put('sso.family', $f);
        }

        return $f;
    }

    /** Record this browser session as part of its sign-in family (call after the session id is final). */
    public static function register(Request $request, User $user): void
    {
        $family = self::family($request);
        DB::table('sso_sessions')->updateOrInsert(
            ['family' => $family, 'session_id' => $request->session()->getId()],
            ['user_id' => $user->id, 'host' => strtolower($request->getHost()), 'created_at' => now()],
        );
    }

    public static function issue(array $data): string
    {
        $token = Str::random(48);
        Cache::put('sso:'.hash('sha256', $token), $data, self::TTL);

        return $token;
    }

    public static function take(?string $token): ?array
    {
        return $token ? Cache::pull('sso:'.hash('sha256', $token)) : null;
    }

    /** What a ticket carries about the signed-in person. */
    public static function snapshot(Request $request, User $user): array
    {
        return [
            'user' => $user->id,
            'amr' => $request->session()->get('auth.amr', ['pwd']),
            'time' => $request->session()->get('auth.time', time()),
            'mfa' => (bool) $request->session()->get('auth.mfa', false),
            'family' => self::family($request),
        ];
    }

    /** Sign in from a ticket on this domain's session. */
    public static function adopt(Request $request, User $user, array $t): void
    {
        auth()->login($user);
        $request->session()->regenerate();
        $request->session()->put(['auth.amr' => $t['amr'] ?? ['pwd'], 'auth.time' => $t['time'] ?? time(), 'auth.mfa' => (bool) ($t['mfa'] ?? false), 'sso.family' => $t['family'] ?? Str::random(40)]);
        self::register($request, $user);
    }

    /** End every browser session in this session's family (all Clauth domains), except the current one. */
    public static function endFamily(Request $request): void
    {
        $family = $request->hasSession() ? $request->session()->get('sso.family') : null;
        if (! $family) {
            return;
        }
        $ids = DB::table('sso_sessions')->where('family', $family)->pluck('session_id');
        DB::table('sessions')->whereIn('id', $ids)->where('id', '!=', $request->session()->getId())->delete();
        DB::table('sso_sessions')->where('family', $family)->delete();
    }
}
