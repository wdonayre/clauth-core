<?php

namespace App\Services;

use App\Models\Application;
use App\Models\OAuthClient;
use App\Models\Organization;

/**
 * Branded accounts (SPEC-branding). Whose name and logo a page or email carries: Clauth, an organization co-branded
 * ("CakesbyGrace Account · Secured by Clauth") or white-labelled (no Clauth at all, only on the organization's own
 * sign-in domain). Clauth owners set how far each organization may go (branding_allowed); the organization picks up
 * to that (branding_mode).
 */
class Brand
{
    public const LEVELS = ['clauth' => 'Clauth', 'cobrand' => 'Co-branded', 'whitelabel' => 'White-label'];

    public const SETTINGS = [
        'branding_mode' => 'sometimes|in:clauth,cobrand,whitelabel',
        'account_name' => 'nullable|string|max:60',
        'brand_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        'support_email' => 'nullable|email|max:190',
        'privacy_url' => 'nullable|url:https|max:300',
        'terms_url' => 'nullable|url:https|max:300',
    ];

    /** While an email about an organization is being built (OrgMail), its brand wins over the page's. */
    private static ?Organization $mailOrg = null;

    public static function rank(?string $level): int
    {
        return (int) array_search($level ?? 'clauth', array_keys(self::LEVELS), true);
    }

    /** The lower of what Clauth allows and what the organization chose; white-label only on its own sign-in domain. */
    public static function level(?Organization $org, bool $onOwnDomain): string
    {
        if (! $org) {
            return 'clauth';
        }
        $level = array_keys(self::LEVELS)[min(self::rank($org->branding_allowed), self::rank($org->branding_mode))];

        return $level === 'whitelabel' && ! $onOwnDomain ? 'cobrand' : $level;
    }

    public static function clauth(): array
    {
        return ['level' => 'clauth', 'org' => null, 'name' => 'Clauth', 'account' => __('Clauth account'), 'logo' => null, 'color' => null,
            'support_email' => null, 'privacy_url' => null, 'terms_url' => null, 'secured_by' => false];
    }

    public static function of(?Organization $org, bool $onOwnDomain = false): array
    {
        $level = self::level($org, $onOwnDomain);
        if ($level === 'clauth') {
            return self::clauth();
        }

        return ['level' => $level, 'org' => $org, 'name' => $org->name,
            'account' => $org->account_name ?: __(':org Account', ['org' => $org->name]),
            'logo' => self::logoUrl($org), 'color' => $org->brand_color, 'support_email' => $org->support_email,
            'privacy_url' => $level === 'whitelabel' ? $org->privacy_url : null, 'terms_url' => $level === 'whitelabel' ? $org->terms_url : null,
            'secured_by' => $level === 'cobrand'];
    }

    /** Public logo address (absolute, for emails too); only served while the organization is branded. */
    public static function logoUrl(Organization $org): ?string
    {
        return $org->logo_path ? route('brand.logo', [$org->slug, 'v' => substr(md5($org->logo_path), 0, 8)]) : null;
    }

    /** Is the organization's logo public right now (some page could show its brand)? */
    public static function logoIsPublic(Organization $org): bool
    {
        return self::rank($org->branding_allowed) > 0 && self::rank($org->branding_mode) > 0;
    }

    /**
     * The brand of this page: an email's organization while one is built; on a custom sign-in domain, the domain's
     * organization; in an app's sign-in on the main address, the app's publisher (never white-label there).
     */
    public static function current(?Application $app = null): array
    {
        if (self::$mailOrg) {
            return self::of(self::$mailOrg, self::hasOwnDomain(self::$mailOrg));
        }
        if (! app()->bound('request')) {
            return self::clauth();
        }
        $req = request();
        $memo = 'clauth.brand.'.($app?->id ?? '-');
        if ($req->attributes->has($memo)) {
            return $req->attributes->get($memo);
        }
        if ($domain = $req->attributes->get('clauth.domain')) {
            $req->attributes->set($memo, $b = self::of($domain->organization, true));

            return $b;
        }
        if (! $app && $req->hasSession()) {
            $s = $req->session();
            $app = ($id = $s->get('login.client_id')) ? OAuthClient::with('app')->find($id)?->app
                : (($key = $s->get('login.app_key')) ? Application::where('key', $key)->first() : null);
        }

        $req->attributes->set($memo, $b = self::of($app?->publisher, false));

        return $b;
    }

    /**
     * The brand an app should show (SPEC-branding B3): its active sign-in domain's organization (white-label allowed
     * there), else its publisher on the main address. Adds the sign-in and account portal addresses.
     */
    public static function forApp(Application $app): array
    {
        $domain = \App\Models\AppDomain::where('status', 'active')->whereHas('apps', fn ($q) => $q->where('apps.id', $app->id))->with('organization')->first();
        $b = $domain ? self::of($domain->organization, true) : self::of($app->publisher, false);
        $base = $domain ? 'https://'.$domain->host : rtrim((string) config('clauth.issuer'), '/');

        return $b + ['login_url' => $domain ? $base : null,
            'account_url' => $base.'/account',
            'button' => $b['level'] === 'clauth' ? __('Sign in with Clauth') : __('Continue with :account', ['account' => $b['account']])];
    }

    /** What apps may show publicly: no models, no internal fields. */
    public static function publicJson(Application $app): array
    {
        $b = self::forApp($app);

        return ['app' => $app->key, 'level' => $b['level'], 'name' => $b['name'], 'account_name' => $b['account'], 'button' => $b['button'],
            'logo' => $b['logo'], 'color' => $b['color'], 'secured_by_clauth' => $b['secured_by'], 'login_url' => $b['login_url'],
            'account_url' => $b['account_url'], 'support_email' => $b['support_email'], 'privacy_url' => $b['privacy_url'], 'terms_url' => $b['terms_url']];
    }

    /** Run $fn (build and send an email) with $org's brand. */
    public static function forMail(?Organization $org, callable $fn): mixed
    {
        $before = self::$mailOrg;
        self::$mailOrg = $org && self::rank($org->branding_allowed) > 0 ? $org : $before;
        try {
            return $fn();
        } finally {
            self::$mailOrg = $before;
        }
    }

    /** Emails may be white-label once the organization has an active sign-in domain of its own. */
    private static function hasOwnDomain(Organization $org): bool
    {
        return \App\Models\AppDomain::where('organization_id', $org->id)->where('status', 'active')->exists();
    }
}
