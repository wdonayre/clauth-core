<?php

namespace App\Support;

/** Tiny user-agent summary for session lists ("Chrome on macOS"). */
class Agent
{
    public static function short(?string $ua): string
    {
        $ua = (string) $ua;
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            str_contains($ua, 'okhttp') || str_contains($ua, 'Expo') => 'Mobile app',
            $ua === '' => 'Unknown browser',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return $os ? "$browser on $os" : $browser;
    }
}
