<?php

namespace App\Services;

use App\Mail\LoginCodeMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 6-digit one-time codes by SMS or email (SPEC-platform §7): valid 5 minutes, one use, 5 tries, and limits per
 * destination (5 an hour), per IP (20 an hour) and — for SMS — a daily cap for the whole platform (Settings).
 * Codes are kept hashed in the cache, keyed by purpose + destination.
 */
class OneTimeCodes
{
    public const TTL = 300;

    /** Returns null when sent, or the reason it wasn't (a limit). Throws when the provider fails. */
    public static function send(string $channel, string $to, string $purpose, ?string $ip): ?string
    {
        $dest = 'otp-dest:'.hash('sha256', $channel.$to);
        if (RateLimiter::tooManyAttempts($dest, 5)) {
            return 'Too many codes for this '.($channel === 'sms' ? 'number' : 'address').'. Try again in an hour.';
        }
        if ($ip && RateLimiter::tooManyAttempts('otp-ip:'.$ip, 20)) {
            return 'Too many codes from this network. Try again later.';
        }
        if ($channel === 'sms') {
            $today = 'otp-sms-day:'.now()->format('Ymd');
            if ((int) Cache::get($today, 0) >= (int) Settings::get('sms_daily_cap')) {
                Audit::log('limit.reached', 'security', null, after: ['limit' => 'sms_daily_cap'], actorType: 'system');

                return 'Text-message codes are paused for today. Use another way to sign in.';
            }
        }
        $code = (string) random_int(100000, 999999);
        if ($channel === 'sms') {
            $b = \App\Services\Brand::current();
            Sms::send($to, Templates::text('sms_code', $b['org'], ['code' => $code], __(':code is your :brand code. It expires in 5 minutes. Never share it.', ['code' => $code, 'brand' => $b['name']])));
            Cache::add($today, 0, now()->endOfDay());
            Cache::increment($today);
        } else {
            Mail::to($to)->send(Templates::mail('login_code', \App\Services\Brand::current()['org'], ['code' => $code]) ?? new LoginCodeMail($code));
        }
        RateLimiter::hit($dest, 3600);
        if ($ip) {
            RateLimiter::hit('otp-ip:'.$ip, 3600);
        }
        Cache::put(self::key($purpose, $channel, $to), ['hash' => hash('sha256', $code), 'tries' => 0], self::TTL);

        return null;
    }

    public static function verify(string $channel, string $to, string $purpose, string $code): bool
    {
        $k = self::key($purpose, $channel, $to);
        $row = Cache::get($k);
        if (! $row) {
            return false;
        }
        if (++$row['tries'] > 5) {
            Cache::forget($k);

            return false;
        }
        if (! hash_equals($row['hash'], hash('sha256', preg_replace('/\D/', '', $code)))) {
            Cache::put($k, $row, self::TTL);

            return false;
        }
        Cache::forget($k);

        return true;
    }

    private static function key(string $purpose, string $channel, string $to): string
    {
        return 'otp:'.$purpose.':'.hash('sha256', $channel.'|'.$to);
    }
}
