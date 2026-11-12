<?php

namespace App\Console\Commands;

use App\Models\AppDomain;
use App\Services\Domains;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Custom sign-in domains. Used by the root server job (deploy/domains.sh) and the scheduler.
 *   clauth:domains hosts         hosts nginx must answer for (one per line)
 *   clauth:domains cert-hosts    hosts the certificate must cover
 *   clauth:domains check [--all] run the next verification step (--all also re-checks active domains)
 *   clauth:domains cert-result --ok | --failed="message"
 */
class DomainsCommand extends Command
{
    protected $signature = 'clauth:domains {action=list} {--all} {--ok} {--failed=}';

    protected $description = 'Custom sign-in domains: nginx/cert host lists and verification checks';

    public function handle(): int
    {
        switch ($this->argument('action')) {
            case 'hosts':
                AppDomain::whereIn('status', AppDomain::IN_NGINX)->orderBy('host')->pluck('host')->each(fn ($h) => $this->line($h));
                break;
            case 'cert-hosts':
                // A domain broken for over a week leaves the certificate, so it can't block renewing the main domain's.
                AppDomain::whereIn('status', AppDomain::IN_CERT)
                    ->where(fn ($q) => $q->where('status', '!=', 'problem')->orWhere('fail_count', '<', 9))
                    ->orderBy('host')->pluck('host')->each(fn ($h) => $this->line($h));
                break;
            case 'check':
                $q = AppDomain::query()->when(! $this->option('all'), fn ($w) => $w->whereNotIn('status', ['active', 'problem'])
                    ->where(fn ($x) => $x->where('status', '!=', 'pending')->orWhere('created_at', '>', now()->subDays(7))));
                foreach ($q->get() as $d) {
                    $d = Domains::check($d);
                    $this->line("{$d->host}: {$d->status}".($d->last_error ? " — {$d->last_error}" : ''));
                }
                break;
            case 'cert-result':
                $msg = $this->option('ok') ? null : 'Certificate request failed: '.Str::limit((string) $this->option('failed'), 300);
                AppDomain::where('status', 'reachable')->update(['last_error' => $msg ?? 'Certificate issued — checking HTTPS.']);
                if ($this->option('ok')) {
                    foreach (AppDomain::where('status', 'reachable')->get() as $d) {
                        Domains::check($d);
                    }
                }
                break;
            default:
                $this->table(['host', 'app', 'status', 'last error'], AppDomain::with('app')->get()
                    ->map(fn ($d) => [$d->host, $d->app?->key, $d->status, $d->last_error]));
        }

        return self::SUCCESS;
    }
}
