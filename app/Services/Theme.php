<?php

namespace App\Services;

use App\Models\Application;

/**
 * Sign-in page look (SPEC §6.1a, SPEC-platform §8.4). Three built-in styles (classic | daylight | midnight), and
 * designed themes (SigninTheme) that start from one of them and set colors, font, corners, buttons and layout.
 * An app's designed theme wins over its built-in style; on a custom domain with no app, the domain's theme applies.
 */
class Theme
{
    public const NAMES = ['classic' => 'Clauth Classic', 'daylight' => 'Daylight', 'midnight' => 'Midnight'];

    /** Fonts a theme may use (Google Fonts; the page's CSP allows only those). */
    public const FONTS = ['Inter', 'Manrope', 'Poppins', 'Nunito Sans', 'DM Sans', 'Space Grotesk', 'IBM Plex Sans', 'Source Sans 3', 'Lora', 'Barlow'];

    /** Designed-theme settings: name => validation rule. */
    public const SETTINGS = [
        'base' => 'required|in:classic,daylight,midnight',
        'accent' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        'background' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        'card' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        'font' => 'nullable|in:Inter,Manrope,Poppins,Nunito Sans,DM Sans,Space Grotesk,IBM Plex Sans,Source Sans 3,Lora,Barlow',
        'heading_font' => 'nullable|in:Inter,Manrope,Poppins,Nunito Sans,DM Sans,Space Grotesk,IBM Plex Sans,Source Sans 3,Lora,Barlow',
        'radius' => 'nullable|integer|min:0|max:28',
        'buttons' => 'nullable|in:rounded,pill,square',
        'layout' => 'nullable|in:center,split',
        'tagline' => 'nullable|string|max:120',
    ];

    public static function for(?Application $app, ?\App\Models\SigninTheme $designed = null): array
    {
        $designed ??= $app?->signin_theme_id ? \App\Models\SigninTheme::find($app->signin_theme_id) : null;
        if ($designed) {
            return self::designed($designed, $app);
        }
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

        return ['style' => $style, 'dark' => $dark, 'vars' => $vars, 'app' => $app, 'logo' => $app?->logoUrl($dark), 'fonts' => [], 'layout' => 'center', 'tagline' => null];
    }

    /** A designed theme: its base style's palette, then its own colors, fonts, corners, buttons and layout. */
    public static function designed(\App\Models\SigninTheme $t, ?Application $app): array
    {
        $proxy = $app ? $app->replicate() : new Application;
        $proxy->key = $app?->key;
        $proxy->signin_theme_id = null;
        $proxy->login_theme = $t->s('base', 'classic');
        $proxy->accent_color = $t->s('accent') ?: $app?->accent_color;
        if ($proxy->login_theme === 'classic' && $t->s('accent')) {
            $proxy->login_theme = 'midnight'; // classic keeps Clauth cyan; a chosen accent needs a tintable base
        }
        $out = self::for($proxy);
        $out['app'] = $app;
        $out['logo'] = $app?->logoUrl($out['dark']);
        $out['style'] = 'designed';
        if ($bg = $t->s('background')) {
            $out['vars']['bg'] = $bg;
            $out['dark'] = self::luminance($bg) < 0.3;
        }
        if ($card = $t->s('card')) {
            $out['vars']['card'] = $card;
            $out['vars']['in-bg'] = $card;
        }
        $font = $t->s('font');
        $heading = $t->s('heading_font') ?: $font;
        if ($font) {
            $out['vars']['cl-font-body'] = "\"$font\",system-ui,sans-serif";
        }
        if ($heading) {
            $out['vars']['cl-font-display'] = "\"$heading\",system-ui,sans-serif";
        }
        $out['fonts'] = array_values(array_unique(array_filter([$font, $heading])));
        if (($r = $t->s('radius')) !== null) {
            $r = (int) $r;
            $out['vars'] += ['cl-radius-sm' => max(0, $r - 4).'px', 'cl-radius-md' => $r.'px', 'cl-radius-xl' => min(32, $r * 2).'px'];
        }
        $out['vars']['btn-radius'] = match ($t->s('buttons')) {
            'pill' => '999px', 'square' => '2px', default => 'var(--cl-radius-md)',
        };
        $out['layout'] = $t->s('layout', 'center');
        $out['tagline'] = $t->s('tagline');
        $out['designed'] = $t;

        return $out;
    }

    /** Google Fonts URL for the theme's fonts (null when it only uses the defaults). */
    public static function fontsUrl(array $theme): ?string
    {
        $extra = array_diff($theme['fonts'] ?? [], ['Inter', 'Space Grotesk']);
        if (! $extra) {
            return null;
        }

        return 'https://fonts.googleapis.com/css2?'.implode('&', array_map(fn ($f) => 'family='.str_replace(' ', '+', $f).':wght@400;500;600;700', $extra)).'&display=swap';
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
