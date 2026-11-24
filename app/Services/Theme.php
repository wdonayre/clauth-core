<?php

namespace App\Services;

use App\Models\Application;

/**
 * Sign-in page look (SPEC §6.1a, SPEC-platform §8.4). Built-in styles (classic | daylight | midnight, and auto = "Match
 * device": Daylight or Midnight following the visitor's light/dark setting), and
 * designed themes (SigninTheme) that start from one of them and set colors, font, corners, buttons and layout.
 * An app's designed theme wins over its built-in style; on a custom domain with no app, the domain's theme applies.
 */
class Theme
{
    public const NAMES = ['classic' => 'Clauth Classic', 'daylight' => 'Daylight', 'midnight' => 'Midnight', 'auto' => 'Match device'];

    /** Variables that differ between Daylight and Midnight: what "Match device" swaps under prefers-color-scheme: dark. */
    private const SCHEME_VARS = ['bg', 'card', 'border', 'text', 'muted', 'in-bg', 'in-border', 'sec', 'link', 'info-bg', 'info-border', 'shadow', 'danger', 'accent', 'on-accent', 'pri', 'on-pri'];

    /** Fonts a theme may use (Google Fonts; the page's CSP allows only those). */
    public const FONTS = ['Inter', 'Manrope', 'Poppins', 'Nunito Sans', 'DM Sans', 'Space Grotesk', 'IBM Plex Sans', 'Source Sans 3', 'Lora', 'Barlow'];

    /** Designed-theme settings: name => validation rule. */
    public const SETTINGS = [
        'base' => 'required|in:classic,daylight,midnight,auto',
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
        $base = ['app' => $app, 'fonts' => [], 'layout' => 'center', 'tagline' => null, 'auto' => false];
        if ($style === 'auto') {
            // Match device: Daylight by default, Midnight when the visitor's device is set to dark (both keep the app's accent).
            $dark = self::palette('midnight', $app?->accent_color);

            return ['style' => 'auto', 'dark' => false, 'auto' => true, 'vars' => self::palette('daylight', $app?->accent_color), 'dark_vars' => $dark,
                'logo' => $app?->logoUrl(false), 'logo_dark' => $app?->logo_dark_path ? $app->logoUrl(true) : null] + $base;
        }
        $dark = $style === 'midnight';

        return ['style' => $style, 'dark' => $dark, 'vars' => self::palette($style, $app?->accent_color), 'logo' => $app?->logoUrl($dark)] + $base;
    }

    /** The CSS variables of a built-in style (classic | daylight | midnight) with an app's accent. */
    public static function palette(string $style, ?string $appAccent): array
    {
        // Clauth Classic (the default) is the light "paper" look of the 2026-11 redesign: Ink buttons, Deep Signal (#0E7490)
        // for links and focus. Daylight is the same palette with the app's own accent; Midnight is the dark one.
        $accent = $style === 'classic' ? '#0E7490' : ($appAccent ?: ($style === 'daylight' ? '#0E7490' : '#22D3EE'));
        $onAccent = self::luminance($accent) > 0.35 ? '#111111' : '#FFFFFF';
        $vars = match ($style) {
            'midnight' => [
                'bg' => '#0F0F12', 'card' => '#18181C', 'border' => '#2A2A31', 'text' => '#F4F4F5', 'muted' => '#A1A1AA',
                'in-bg' => '#111114', 'in-border' => '#33333B', 'sec' => '#222228',
                'link' => self::luminance($accent) > 0.2 ? $accent : '#F4F4F5',
                'info-bg' => '#1E1E24', 'info-border' => '#2E2E36', 'shadow' => 'none', 'danger' => '#F87171',
            ],
            default => [
                'bg' => '#F7F8FA', 'card' => '#FFFFFF', 'border' => '#E6E8EC', 'text' => '#0B0D12', 'muted' => '#5B6475',
                'in-bg' => '#FFFFFF', 'in-border' => '#DDE1E6', 'sec' => '#FFFFFF',
                'link' => '#0E7490',
                'info-bg' => '#F2F4F6', 'info-border' => '#E6E8EC', 'shadow' => '0 10px 30px rgba(11,13,18,.05)', 'danger' => '#B91C1C',
            ],
        };
        $vars['accent'] = $accent;
        $vars['on-accent'] = $onAccent;
        // Primary button: Ink on light (brand rule) unless the app chose its own accent; otherwise the accent.
        $inkPrimary = $style === 'classic' || ($style === 'daylight' && ! $appAccent);
        $vars['pri'] = $inkPrimary ? '#0B0D12' : $accent;
        $vars['on-pri'] = $inkPrimary ? '#FFFFFF' : $onAccent;

        return $vars;
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
            $proxy->login_theme = 'daylight'; // classic keeps Clauth's own colors; a chosen accent needs a tintable base
        }
        $out = self::for($proxy);
        $out['app'] = $app;
        $out['logo'] = $app?->logoUrl($out['dark']);
        $out['style'] = 'designed';
        // On a "Match device" base a chosen colour belongs to the side it suits (a dark background to the dark side).
        $side = fn (string $hex) => $out['auto'] && self::luminance($hex) < 0.3 ? 'dark_vars' : 'vars';
        if ($bg = $t->s('background')) {
            $out[$side($bg)]['bg'] = $bg;
            if (! $out['auto']) {
                $out['dark'] = self::luminance($bg) < 0.3;
            }
        }
        if ($card = $t->s('card')) {
            $out[$side($card)]['card'] = $card;
            $out[$side($card)]['in-bg'] = $card;
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

    /**
     * "Match device": the dark-side variables as one CSS rule body (!important so they win over the inline light ones),
     * or null for any other style. Values are fixed palette entries or validated #rrggbb colours.
     */
    public static function darkCss(array $theme): ?string
    {
        if (empty($theme['auto']) || empty($theme['dark_vars'])) {
            return null;
        }
        $vars = array_intersect_key($theme['dark_vars'], array_flip(self::SCHEME_VARS));

        return collect($vars)->filter(fn ($v) => preg_match('/^[#\w\s(),.%-]+$/', (string) $v))
            ->map(fn ($v, $k) => "--$k:$v !important")->implode(';');
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
