<?php

namespace App\Services;

use App\Models\Application;
use App\Models\AppDomain;
use App\Models\Organization;
use App\Models\SigninTheme;
use App\Models\User;
use Illuminate\Support\Str;

/** Designed sign-in themes (SPEC-platform §8.4): who may use which, validation, assignment, usages. */
class Themes
{
    /** Themes an app may use: its publisher's and the platform's. */
    public static function forApp(Application $app)
    {
        return SigninTheme::where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $app->owner_organization_id))->orderBy('name')->get();
    }

    public static function validate(array $in, bool $partial = false): array
    {
        $rules = Theme::SETTINGS;
        if ($partial) {
            $rules['base'] = 'sometimes|in:classic,daylight,midnight';
        }
        $data = validator($in, $rules)->validate();
        if (! empty($data['accent']) && ! Theme::contrastOk($data['accent'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['accent' => 'Neither black nor white text is readable on that accent color.']);
        }

        return array_intersect_key($data, Theme::SETTINGS);
    }

    public static function create(?Organization $org, string $name, array $settings, ?User $by): SigninTheme
    {
        $base = Str::limit(Str::slug(($org ? $org->slug.'-' : '').$name) ?: 'theme', 50, '');
        $key = $base;
        for ($i = 2; SigninTheme::where('key', $key)->exists() || isset(Theme::NAMES[$key]); $i++) {
            $key = "$base-$i";
        }
        $t = SigninTheme::create(['organization_id' => $org?->id, 'key' => $key, 'name' => $name, 'settings' => $settings, 'created_by' => $by?->id]);
        Audit::log('theme.created', 'app', $t, after: ['key' => $key] + $settings, orgId: $org?->id, actorId: $by?->id);

        return $t;
    }

    public static function usages(SigninTheme $t): array
    {
        return ['apps' => Application::where('signin_theme_id', $t->id)->orderBy('key')->pluck('key')->all(),
            'domains' => AppDomain::where('signin_theme_id', $t->id)->orderBy('host')->pluck('host')->all()];
    }

    /** Set an app's look: a built-in style key, or "designed:<key>". Returns null on success, else the reason. */
    public static function assign(Application $app, string $value): ?string
    {
        if (str_starts_with($value, 'designed:')) {
            $t = self::forApp($app)->firstWhere('key', substr($value, 9));
            if (! $t) {
                return 'That theme isn’t available to this app.';
            }
            $app->update(['signin_theme_id' => $t->id]);

            return null;
        }
        if (! isset(Theme::NAMES[$value])) {
            return 'Unknown style.';
        }
        $app->update(['login_theme' => $value, 'signin_theme_id' => null]);

        return null;
    }
}
