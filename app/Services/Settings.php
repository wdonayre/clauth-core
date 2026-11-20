<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/** Global sign-in and security policy (SPEC §10), editable in Admin › Settings. */
class Settings
{
    public const DEFAULTS = [
        'require_mfa_everyone' => false,
        // Anyone holding an owner- or admin-tier role in any app must use 2-step (SPEC-roles §10.2).
        'require_mfa_admin_tiers' => false,
        'password_min_length' => 12,
        'breached_password_check' => true,
        'lockout_attempts' => 5,
        'lockout_minutes' => 15,
        'session_idle_hours' => 8,
        'trusted_device_days' => 30,
        'new_device_email' => true,
        'support_email' => null, // from config('clauth.support_email') unless set in Admin › Settings
        // Outside organizations can only request to join once a lawyer has reviewed the Terms, Privacy Policy and
        // Data Processing Agreement and William ticks this (SPEC-organizations §10.5).
        'legal_reviewed' => false,
    ];

    public static function all(): array
    {
        $stored = Cache::remember('clauth.settings', 300, fn () => Setting::pluck('value', 'key')->all());

        return array_merge(self::DEFAULTS, ['support_email' => config('clauth.support_email')], $stored);
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    public static function set(array $values): void
    {
        foreach ($values as $key => $value) {
            if (array_key_exists($key, self::DEFAULTS)) {
                Setting::updateOrCreate(['key' => $key], ['value' => $value]);
            }
        }
        Cache::forget('clauth.settings');
    }
}
