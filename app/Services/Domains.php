<?php

namespace App\Services;

use App\Models\AppDomain;
use App\Models\Application;
use App\Support\Dns;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Custom sign-in domains: lookup, links, and the verification state machine. */
class Domains
{
    public static function mainHost(): string
    {
        return (string) parse_url(config('clauth.issuer'), PHP_URL_HOST);
    }

    public static function mainUrl(string $path = '/'): string
    {
        return rtrim(config('clauth.issuer'), '/').'/'.ltrim($path, '/');
    }

    public static function isMain(string $host): bool
    {
        return strtolower($host) === self::mainHost();
    }

    /** The active domain for a host (cached 60 s), or null. */
    public static function activeFor(string $host): ?AppDomain
    {
        $id = Cache::remember('clauth.domain.'.strtolower($host), 60, fn () => AppDomain::where('host', strtolower($host))->where('status', 'active')->value('id') ?? 0);

        return $id ? AppDomain::with('app')->find($id) : null;
    }

    public static function flush(string $host): void
    {
        Cache::forget('clauth.domain.'.strtolower($host));
    }

    /** Absolute URL for $path on the app's own sign-in domain when it has an active one, else on the main domain. */
    public static function urlFor(?Application $app, string $path): string
    {
        $d = $app ? AppDomain::where('app_id', $app->id)->where('status', 'active')->first() : null;

        return $d ? 'https://'.$d->host.'/'.ltrim($path, '/') : self::mainUrl($path);
    }

    /** One step of the lifecycle. Returns the domain (refreshed). */
    public static function check(AppDomain $d): AppDomain
    {
        $dns = app(Dns::class);
        $d->last_checked_at = now();
        $txtOk = in_array($d->txtValue(), array_map('trim', $dns->txt($d->txtName())), true);
        $d->dns_target = $dns->target($d->host, self::mainHost(), (array) config('clauth.server_ips'));

        switch ($d->status) {
            case 'pending':
                if ($txtOk) {
                    $d->fill(['status' => 'dns_ok', 'verified_at' => now(), 'last_error' => null, 'fail_count' => 0]);
                } else {
                    $d->last_error = 'TXT record '.$d->txtName().' not found yet (DNS changes can take a few minutes).';
                }
                break;
            case 'dns_ok':
                if (! $txtOk) {
                    $d->fill(['status' => 'pending', 'last_error' => 'The TXT record disappeared.']);
                } elseif (self::proofOk($d, false)) {
                    $d->fill(['status' => 'reachable', 'reachable_at' => now(), 'last_error' => null]);
                } else {
                    $d->last_error = $d->dns_target === 'none'
                        ? $d->host.' doesn’t resolve yet. Add the CNAME (or A) record.'
                        : $d->host.' doesn’t reach Clauth yet. Check the CNAME/A record; the server picks the domain up within a minute.';
                }
                break;
            case 'reachable':
                if (self::proofOk($d, true)) {
                    $d->fill(['status' => 'active', 'active_at' => now(), 'last_error' => null, 'fail_count' => 0]);
                } else {
                    $d->last_error = $d->last_error ?: 'Waiting for the HTTPS certificate.';
                }
                break;
            case 'active':
            case 'problem':
                if ($txtOk && self::proofOk($d, true)) {
                    $d->fill(['status' => 'active', 'fail_count' => 0, 'last_error' => null]);
                } else {
                    $d->fail_count++;
                    $d->last_error = ! $txtOk ? 'The TXT record is gone.' : 'HTTPS check failed (DNS moved away or the certificate is invalid).';
                    if ($d->fail_count >= 2 && $d->status === 'active') {
                        $d->status = 'problem';
                        Audit::log('app.domain_problem', 'app', $d, after: ['host' => $d->host, 'error' => $d->last_error], appId: $d->app_id, actorType: 'system');
                    }
                }
                break;
        }
        $was = $d->getOriginal('status');
        $d->save();
        if ($was !== $d->status) {
            self::flush($d->host);
            if ($d->status === 'active') {
                Audit::log('app.domain_active', 'app', $d, ['status' => $was], ['status' => 'active', 'host' => $d->host], $d->app_id, actorType: 'system');
            }
        }

        return $d->refresh();
    }

    /** Does https://host/<proof path> answer with our proof? $strict = the certificate must be valid. */
    public static function proofOk(AppDomain $d, bool $strict): bool
    {
        try {
            $req = Http::timeout(8)->withoutRedirecting();
            if (! $strict) {
                $req = $req->withoutVerifying();
            }
            $res = $req->get('https://'.$d->host.$d->proofPath());

            return $res->ok() && hash_equals($d->proofBody(), trim($res->body()));
        } catch (\Throwable) {
            return false;
        }
    }
}
