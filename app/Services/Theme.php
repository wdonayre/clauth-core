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
        // Brand v2: Signal (#22D3EE) on dark, Deep Signal (#0E7490) on light.
        $accent = $style === 'classic' ? '#22D3EE' : ($app?->accent_color ?: ($style === 'daylight' ? '#0E7490' : '#22D3EE'));
        $onAccent = self::luminance($accent) > 0.35 ? '#111111' : '#FFFFFF';
        $dark = $style !== 'daylight';
        $vars = match ($style) {
            'daylight' => [
                'bg' => '#F7F8FA', 'card' => '#FFFFFF', 'border' => '#DDE2EA', 'text' => '#0B0D12', 'muted' => '#5B6475',
                'in-bg' => '#FFFFFF', 'in-border' => '#C9D0DB', 'sec' => '#FFFFFF', 'link' => '#0E7490',
                'info-bg' => '#ECFEFF', 'info-border' => '#A5E4EF', 'shadow' => '0 1px 2px rgba(11,13,18,.06), 0 8px 24px rgba(11,13,18,.06)', 'danger' => '#B91C1C',
            ],
            'midnight' => [
                'bg' => '#0F0F12', 'card' => '#18181C', 'border' => '#2A2A31', 'text' => '#F4F4F5', 'muted' => '#A1A1AA',
                'in-bg' => '#111114', 'in-border' => '#33333B', 'sec' => '#222228',
                'link' => self::luminance($accent) > 0.2 ? $accent : '#F4F4F5',
                'info-bg' => '#1E1E24', 'info-border' => '#2E2E36', 'shadow' => 'none', 'danger' => '#F87171',
            ],
            default => [
                'bg' => '#0B0D12', 'card' => '#12161E', 'border' => '#232A36', 'text' => '#F2F5F9', 'muted' => '#AAB2C2',
                'in-bg' => '#0B0D12', 'in-border' => '#2E3644', 'sec' => '#161B25', 'link' => '#22D3EE',
                'info-bg' => '#0E2A31', 'info-border' => '#164E5A', 'shadow' => 'none', 'danger' => '#F87171',
            ],
        };
        $vars['accent'] = $accent;
        $vars['on-accent'] = $onAccent;
        // Primary button: Ink on light (brand rule) unless the app chose its own accent; otherwise the accent.
        $inkPrimary = $style === 'daylight' && ! $app?->accent_color;
        $vars['pri'] = $inkPrimary ? '#0B0D12' : $accent;
        $vars['on-pri'] = $inkPrimary ? '#FFFFFF' : $onAccent;

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
