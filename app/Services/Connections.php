<?php

namespace App\Services;

use App\Models\Application;
use App\Models\AppDomain;
use App\Models\Connection;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Connections (SPEC-platform §7): the schema of each type, validation, masking of secrets, and which sign-in
 * buttons a page shows. Google stays configured on the server (.env) and is not a row here.
 *
 *   oidc        any OpenID Connect provider (Microsoft Entra, Okta, Google Workspace, another Clauth) — platform or org
 *   oauth       a plain OAuth 2 provider with a userinfo URL — platform or org
 *   meta        Facebook — platform only
 *   sms         SMS gateway for one-time codes (Semaphore, Twilio, Vonage) — platform only
 *   email-smtp  an organization's own email sender — org only
 */
class Connections
{
    public const TYPES = [
        'oidc' => 'OpenID Connect (Microsoft, Okta, Google Workspace…)',
        'oauth' => 'OAuth 2 provider',
        'meta' => 'Facebook',
        'sms' => 'SMS gateway',
        'email-smtp' => 'Email sender (SMTP)',
    ];

    public const PLATFORM_ONLY = ['meta', 'sms'];

    public const ORG_ONLY = ['email-smtp'];

    public const SMS_DRIVERS = ['semaphore' => 'Semaphore (Philippines)', 'twilio' => 'Twilio', 'vonage' => 'Vonage'];

    /** Fields per type: name => [label, rule, secret?] */
    public static function fields(string $type, bool $org): array
    {
        $signIn = [
            'show_button' => ['Show a button on the sign-in page', 'boolean'],
            'trust_email' => ['Link to an existing Clauth account with the same email', 'boolean'],
        ];
        $orgSignIn = $org ? [
            'domains' => ['Email domains of your people (one per line)', 'array'],
            'jit' => ['Create accounts for new people from these domains on first sign-in', 'boolean'],
            'require' => ['People with these email domains must sign in this way', 'boolean'],
        ] : [];

        return match ($type) {
            'oidc' => [
                'issuer' => ['Issuer URL', 'required|url|starts_with:https://'],
                'client_id' => ['Client ID', 'required|string|max:255'],
                'client_secret' => ['Client secret', 'required|string|max:500', true],
                'scopes' => ['Scopes', 'nullable|string|max:255'],
            ] + $signIn + $orgSignIn,
            'oauth' => [
                'authorize_url' => ['Authorize URL', 'required|url|starts_with:https://'],
                'token_url' => ['Token URL', 'required|url|starts_with:https://'],
                'userinfo_url' => ['User info URL', 'required|url|starts_with:https://'],
                'client_id' => ['Client ID', 'required|string|max:255'],
                'client_secret' => ['Client secret', 'required|string|max:500', true],
                'scopes' => ['Scopes', 'nullable|string|max:255'],
                'id_field' => ['User id field', 'nullable|string|max:60'],
                'email_field' => ['Email field', 'nullable|string|max:60'],
                'name_field' => ['Name field', 'nullable|string|max:60'],
            ] + $signIn + $orgSignIn,
            'meta' => [
                'client_id' => ['App ID', 'required|string|max:100'],
                'client_secret' => ['App secret', 'required|string|max:200', true],
                'show_button' => ['Show a button on the sign-in page', 'boolean'],
            ],
            'sms' => [
                'driver' => ['Provider', 'required|in:'.implode(',', array_keys(self::SMS_DRIVERS))],
                'api_key' => ['API key / Account SID', 'required|string|max:255', true],
                'api_secret' => ['API secret / Auth token (Twilio, Vonage)', 'nullable|string|max:255', true],
                'sender' => ['Sender name or number', 'nullable|string|max:20'],
            ],
            'email-smtp' => [
                'host' => ['SMTP host', 'required|string|max:190'],
                'port' => ['Port', 'required|integer|between:1,65535'],
                'encryption' => ['Encryption', 'nullable|in:tls,ssl'],
                'username' => ['Username', 'nullable|string|max:190'],
                'password' => ['Password', 'nullable|string|max:255', true],
                'from_address' => ['From address', 'required|email'],
                'from_name' => ['From name', 'nullable|string|max:80'],
            ],
            default => [],
        };
    }

    public static function secretFields(string $type): array
    {
        return array_keys(array_filter(self::fields($type, true), fn ($f) => $f[2] ?? false));
    }

    /**
     * Validate $input for a new or changed connection and return the config to store. Secrets left empty on an
     * update keep their old value (they are write-only).
     */
    public static function config(string $type, bool $org, array $input, ?Connection $existing = null): array
    {
        $fields = self::fields($type, $org);
        $old = $existing?->config ?? [];
        $data = [];
        $rules = [];
        foreach ($fields as $name => [$label, $rule]) {
            $secret = $fields[$name][2] ?? false;
            $v = $input[$name] ?? null;
            if ($name === 'domains' && is_string($v)) {
                $v = collect(preg_split('/[\s,]+/', $v))->map(fn ($d) => strtolower(trim($d, " @.\t")))->filter()->unique()->values()->all();
            }
            if ($secret && ($v === null || $v === '') && isset($old[$name])) {
                $v = $old[$name];
            }
            if (str_contains($rule, 'boolean')) {
                $v = filter_var($v ?? ($old[$name] ?? false), FILTER_VALIDATE_BOOLEAN);
            }
            $data[$name] = $v;
            $rules[$name] = $rule;
        }
        $rules['domains.*'] = 'string|max:190|regex:/^([a-z0-9-]+\.)+[a-z]{2,}$/';
        $v = validator($data, $rules, [], collect($fields)->map(fn ($f) => $f[0])->all());
        $v->validate();
        if ($org && in_array($type, ['oidc', 'oauth'], true) && empty($data['domains'])) {
            throw ValidationException::withMessages(['domains' => 'List the email domains of your people (e.g. acme.com).']);
        }

        return array_filter($data, fn ($x) => $x !== null && $x !== '');
    }

    /** Config with secrets replaced by "set". */
    public static function masked(Connection $c): array
    {
        $cfg = $c->config ?? [];
        foreach (self::secretFields($c->type) as $s) {
            if (isset($cfg[$s])) {
                $cfg[$s] = '••••••';
            }
        }

        return $cfg;
    }

    public static function newKey(string $name, ?Organization $org): string
    {
        $base = Str::limit(Str::slug(($org ? $org->slug.'-' : '').$name) ?: 'connection', 32, '');
        $key = $base;
        for ($i = 2; Connection::where('key', $key)->exists() || in_array($key, ['google', 'code', 'locked', '2fa'], true); $i++) {
            $key = "$base-$i";
        }

        return $key;
    }

    /** Sign-in buttons for this page: platform ones, plus the connections of organizations the app or domain belongs to. */
    public static function buttons(?Application $app, ?AppDomain $domain)
    {
        $orgIds = collect();
        if ($app) {
            $orgIds = $orgIds->merge($app->organizations()->pluck('organizations.id'));
        }
        if ($domain?->organization_id) {
            $orgIds->push($domain->organization_id);
        }

        return Connection::whereIn('type', ['oidc', 'oauth', 'meta'])->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $orgIds->unique()->all()))
            ->orderByRaw('organization_id is null')->orderBy('name')->get()
            ->filter(fn (Connection $c) => $c->cfg('show_button', true));
    }

    /** An organization connection that people with this email must use (home-realm discovery), or null. */
    public static function required(string $email): ?Connection
    {
        $domain = strtolower(Str::after($email, '@'));

        return Connection::whereIn('type', ['oidc', 'oauth'])->where('status', 'active')->whereNotNull('organization_id')->get()
            ->first(fn (Connection $c) => $c->cfg('require') && in_array($domain, $c->cfg('domains', []), true));
    }

    public static function sms(): ?Connection
    {
        return Connection::where('type', 'sms')->whereNull('organization_id')->where('status', 'active')->first();
    }

    public static function mailerFor(?Organization $org): ?Connection
    {
        return $org ? Connection::where('type', 'email-smtp')->where('organization_id', $org->id)->where('status', 'active')->first() : null;
    }

    /** Validation for the API/console "type" field given who owns it. */
    public static function typeRule(bool $org): array
    {
        return ['required', Rule::in(array_keys(array_diff_key(self::TYPES, array_flip($org ? self::PLATFORM_ONLY : self::ORG_ONLY))))];
    }

    public static function json(Connection $c): array
    {
        return ['key' => $c->key, 'type' => $c->type, 'name' => $c->name, 'org' => $c->organization?->slug, 'status' => $c->status,
            'config' => self::masked($c), 'callback_url' => $c->isSignIn() ? Domains::mainUrl("login/c/{$c->key}/callback") : null,
            'last_used_at' => $c->last_used_at?->toIso8601String(), 'created_at' => $c->created_at?->toIso8601String()];
    }

    /** May a person signed in through $c be linked to $user by email? (SPEC-platform §7, takeover rules) */
    public static function mayLinkByEmail(Connection $c, User $user, bool $emailVerified): bool
    {
        if ($c->organization_id) {
            // An organization's provider only vouches for people who belong to that organization alone — it must not
            // be able to take over someone who also works for another organization.
            $orgs = $user->organizations()->pluck('organizations.id');

            return $orgs->count() === 1 && $orgs->first() === $c->organization_id && ! $user->isOwner()
                && in_array(strtolower(Str::after($user->email, '@')), $c->cfg('domains', []), true);
        }

        return $c->cfg('trust_email', $c->type === 'oidc') && $emailVerified;
    }
}
