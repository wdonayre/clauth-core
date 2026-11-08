<?php

namespace App\Services;

use App\Models\Application;

/** Per-app sign-in page style (SPEC §6.1a): classic | daylight | midnight. */
class Theme
{
    public const NAMES = ['classic' => 'Clauth Classic', 'daylight' => 'Daylight', 'midnight' => 'Midnight'];

    public static function for(?Application $app): array
    {
        $style = $app?->login_theme && isset(self::NAMES[$app->login_theme]) ? $app->login_theme : 'classic';
        $accent = $style === 'classic' ? '#22D3EE' : ($app?->accent_color ?: '#22D3EE');
        $onAccent = self::luminance($accent) > 0.35 ? '#111111' : '#FFFFFF';
        $dark = $style !== 'daylight';
        $vars = match ($style) {
            'daylight' => [
                'bg' => '#F3F5F8', 'card' => '#FFFFFF', 'border' => '#E2E7EE', 'text' => '#0F172A', 'muted' => '#475569',
                'in-bg' => '#FFFFFF', 'in-border' => '#CBD5E1', 'sec' => '#FFFFFF', 'link' => '#0F172A',
                'info-bg' => '#F1F5F9', 'info-border' => '#E2E8F0', 'shadow' => '0 10px 30px rgba(15,23,42,.08)', 'danger' => '#B91C1C',
            ],
            'midnight' => [
                'bg' => '#0F0F12', 'card' => '#18181C', 'border' => '#2A2A31', 'text' => '#F4F4F5', 'muted' => '#A1A1AA',
                'in-bg' => '#111114', 'in-border' => '#33333B', 'sec' => '#222228',
                'link' => self::luminance($accent) > 0.2 ? $accent : '#F4F4F5',
                'info-bg' => '#1E1E24', 'info-border' => '#2E2E36', 'shadow' => 'none', 'danger' => '#F87171',
            ],
            default => [
                'bg' => '#0A0C10', 'card' => '#12161C', 'border' => '#232A34', 'text' => '#E8EDF2', 'muted' => '#AEB8C5',
                'in-bg' => '#0D1014', 'in-border' => '#2A323D', 'sec' => '#161B22', 'link' => '#22D3EE',
                'info-bg' => '#0E2A31', 'info-border' => '#164E5A', 'shadow' => 'none', 'danger' => '#F87171',
            ],
        };
        $vars['accent'] = $accent;
        $vars['on-accent'] = $onAccent;

        return ['style' => $style, 'dark' => $dark, 'vars' => $vars, 'app' => $app, 'logo' => $app?->logoUrl($dark)];
    }

    public static function css(array $theme): string
    {
        return collect($theme['vars'])->map(fn ($v, $k) => "--$k:$v")->implode(';');
    }

    public static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        [$r, $g, $b] = array_map(fn ($c) => hexdec($c) / 255, str_split(substr($hex.'000000', 0, 6), 2));
        $f = fn ($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    }

    /** Best contrast ratio of black or white text on the accent; reject accents below 4.5:1. */
    public static function contrastOk(string $hex): bool
    {
        $l = self::luminance($hex);
        $black = ($l + 0.05) / 0.05;
        $white = 1.05 / ($l + 0.05);

        return max($black, $white) >= 4.5;
    }
}
