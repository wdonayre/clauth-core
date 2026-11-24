<?php

namespace App\Services;

use App\Models\AccessProfile;
use App\Models\Application;
use App\Models\Connection;
use App\Models\Organization;
use App\Models\SigninPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in policies (SPEC-platform §8.1). The rules for a sign-in come from the app's own policy, else its publishing
 * organization's default, else the platform's, else DEFAULTS — the first that exists, whole (no field-by-field mixing,
 * so what an admin sees is what applies). Platform Settings still switch features on and off for everyone: a policy
 * can only narrow what Settings allow.
 */
class SigninPolicies
{
    public const DEFAULTS = [
        'password' => true,          // email + password
        'google' => true,            // Continue with Google (when configured)
        'connections' => 'all',      // 'all' or [connection keys] — sign-in connections offered
        'email_code' => true,        // one-time code by email (if Settings allow)
        'sms_code' => true,          // one-time code by text (if Settings allow)
        'two_step' => 'default',     // default (Settings + app's 2-step roles) | always
        'sms_second_step' => true,   // text messages may be the 2-step
        'registration' => 'invite',  // invite | open
        'open_domains' => [],        // open sign-up: only these email domains (empty = any)
        'open_profile' => null,      // open sign-up: access profile key given in the publisher organization
        'check' => null,             // key of an http connection asked before each sign-in
        'check_fail_open' => false,  // if that system can't be reached: false = refuse, true = allow
    ];

    public const METHODS = ['password' => 'Email and password', 'google' => 'Google', 'email_code' => 'One-time code by email', 'sms_code' => 'One-time code by text'];

    /** [rules, source] where source is app|org|platform|default. */
    public static function effective(?Application $app): array
    {
        $p = $app ? SigninPolicy::where('app_id', $app->id)->first() : null;
        $source = 'app';
        if (! $p && $app?->owner_organization_id) {
            $p = SigninPolicy::where('organization_id', $app->owner_organization_id)->first();
            $source = 'org';
        }
        if (! $p) {
            $p = SigninPolicy::whereNull('app_id')->whereNull('organization_id')->first();
            $source = 'platform';
        }

        return [array_merge(self::DEFAULTS, $p?->rules ?? []), $p ? $source : 'default'];
    }

    public static function rules(?Application $app): array
    {
        return self::effective($app)[0];
    }

    /** May someone who signed in "via" this way (password | google | email_code | sms_code | <connection key>) use the app? */
    public static function allowsVia(?Application $app, ?string $via): bool
    {
        if (! $app || ! $via) {
            return true;
        }
        $r = self::rules($app);
        if (array_key_exists($via, self::METHODS)) {
            return (bool) $r[$via];
        }

        return $r['connections'] === 'all' || in_array($via, (array) $r['connections'], true);
    }

    /** Does the policy itself require 2-step for this app? */
    public static function alwaysTwoStep(?Application $app): bool
    {
        return $app && self::rules($app)['two_step'] === 'always';
    }

    public static function find(?Application $app, ?Organization $org): ?SigninPolicy
    {
        return SigninPolicy::where('app_id', $app?->id)->where('organization_id', $app ? null : $org?->id)->first();
    }

    /** Validate and normalise rules for an app, an organization, or the platform (both null). */
    public static function validate(array $in, ?Application $app, ?Organization $org): array
    {
        $in = array_intersect_key($in, self::DEFAULTS);
        if (isset($in['open_domains']) && is_string($in['open_domains'])) {
            $in['open_domains'] = preg_split('/[\s,]+/', strtolower($in['open_domains']), -1, PREG_SPLIT_NO_EMPTY);
        }
        foreach (['password', 'google', 'email_code', 'sms_code', 'sms_second_step', 'check_fail_open'] as $b) {
            if (array_key_exists($b, $in)) {
                $in[$b] = filter_var($in[$b], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $publisher = $app?->publisher ?? $org;
        $connKeys = Connection::whereIn('type', ['oidc', 'oauth', 'meta'])->where(fn ($q) => $q->whereNull('organization_id')
            ->when($publisher, fn ($w) => $w->orWhere('organization_id', $publisher->id)))->pluck('key')->all();
        $httpKeys = Connection::where('type', 'http')->where(fn ($q) => $q->whereNull('organization_id')
            ->when($publisher, fn ($w) => $w->orWhere('organization_id', $publisher->id)))->pluck('key')->all();
        $v = validator($in, [
            'password' => 'boolean', 'google' => 'boolean', 'email_code' => 'boolean', 'sms_code' => 'boolean', 'sms_second_step' => 'boolean', 'check_fail_open' => 'boolean',
            'connections' => ['nullable', function ($a, $val, $fail) use ($connKeys) {
                if ($val !== 'all' && (! is_array($val) || array_diff($val, $connKeys))) {
                    $fail('connections must be "all" or a list of sign-in connection keys this app can use.');
                }
            }],
            'two_step' => 'in:default,always',
            'registration' => 'in:invite,open',
            'open_domains' => 'array', 'open_domains.*' => 'regex:/^([a-z0-9-]+\.)+[a-z]{2,}$/',
            'open_profile' => ['nullable', 'string'],
            'check' => ['nullable', Rule::in($httpKeys)],
        ]);
        $v->validate();
        $rules = array_merge(self::DEFAULTS, $in);
        if (! $rules['password'] && ! $rules['google'] && ! $rules['email_code'] && ! $rules['sms_code'] && $rules['connections'] !== 'all' && ! $rules['connections']) {
            throw ValidationException::withMessages(['password' => 'Leave at least one way to sign in.']);
        }
        if ($rules['registration'] === 'open') {
            if (! $app) {
                throw ValidationException::withMessages(['registration' => 'Open sign-up is set per app (people join the app’s organization).']);
            }
            if ($rules['open_profile']) {
                $p = AccessProfile::where('organization_id', $app->owner_organization_id)->where('key', $rules['open_profile'])->first();
                if (! $p) {
                    throw ValidationException::withMessages(['open_profile' => 'That access profile isn’t in the app’s organization.']);
                }
                if ($p->hasOwnerTier()) {
                    throw ValidationException::withMessages(['open_profile' => 'Self sign-up can’t give an Owner-tier role.']);
                }
            }
        }

        return $rules;
    }

    /** Save as a new version (history kept for restore). */
    public static function save(?Application $app, ?Organization $org, array $rules, ?User $actor, ?string $note = null): SigninPolicy
    {
        return DB::transaction(function () use ($app, $org, $rules, $actor, $note) {
            $p = self::find($app, $org);
            $before = $p?->rules;
            if ($p) {
                $p->update(['rules' => $rules, 'version' => $p->version + 1, 'updated_by' => $actor?->id]);
            } else {
                $p = SigninPolicy::create(['app_id' => $app?->id, 'organization_id' => $app ? null : $org?->id, 'rules' => $rules, 'version' => 1, 'updated_by' => $actor?->id]);
            }
            $p->versions()->create(['version' => $p->version, 'rules' => $rules, 'changed_by' => $actor?->id, 'note' => $note, 'created_at' => now()]);
            Audit::log('policy.changed', 'admin', $p, $before, $rules + ['version' => $p->version], appId: $app?->id, orgId: $org?->id ?? $app?->owner_organization_id, actorId: $actor?->id);

            return $p;
        });
    }

    public static function restore(SigninPolicy $p, int $version, ?User $actor): SigninPolicy
    {
        $v = $p->versions()->where('version', $version)->firstOrFail();

        return self::save($p->app, $p->organization, $v->rules, $actor, "Restored version {$version}");
    }

    /** Drop the app's (or organization's) own policy so the next level applies again. */
    public static function reset(?Application $app, ?Organization $org, ?User $actor): void
    {
        $p = self::find($app, $org);
        if ($p) {
            $p->delete();
            Audit::log('policy.reset', 'admin', $p, $p->rules, null, appId: $app?->id, orgId: $org?->id ?? $app?->owner_organization_id, actorId: $actor?->id);
        }
    }

    /**
     * Ask the organization's own system (an http connection) whether this sign-in may go ahead. Signed with HMAC-SHA256
     * of the body in X-Clauth-Signature. Returns null to allow, or the reason to refuse.
     */
    public static function check(Application $app, User $user): ?string
    {
        $key = self::rules($app)['check'];
        $c = $key ? Connection::where('key', $key)->where('type', 'http')->where('status', 'active')->first() : null;
        if (! $c) {
            return null;
        }
        $body = json_encode(['event' => 'signin', 'app' => $app->key, 'user' => ['id' => $user->sub(), 'email' => $user->email, 'name' => $user->name],
            'orgs' => array_keys(Claims::orgs($user, $app)), 'at' => now()->toIso8601String()]);
        try {
            $r = Http::withBody($body, 'application/json')->withHeaders(['X-Clauth-Signature' => 'sha256='.hash_hmac('sha256', $body, (string) $c->cfg('secret'))])
                ->timeout(5)->post($c->cfg('url'));
            $c->forceFill(['last_used_at' => now()])->save();
            if ($r->successful() && $r->json('allow') === true) {
                return null;
            }
            if ($r->successful() || $r->status() === 403) {
                return (string) ($r->json('message') ?: 'Your organization’s system didn’t allow this sign-in.');
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return self::rules($app)['check_fail_open'] ? null : 'We couldn’t confirm this sign-in with your organization’s system. Try again in a moment.';
    }
}
