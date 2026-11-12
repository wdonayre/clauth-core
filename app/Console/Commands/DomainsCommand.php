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
 *   clauth:domains cert-result --ok | --failed="message"            (san mode: the shared certificate)
 *   clauth:domains cert-result <host> --ok | --failed="message"     (per-host mode: one domain's certificate)
 *   clauth:domains nginx-conf <host> [--tls]                        (per-host mode: the host's nginx server block)
 */
class DomainsCommand extends Command
{
    protected $signature = 'clauth:domains {action=list} {host?} {--all} {--ok} {--failed=} {--tls}';

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
                if ($host = $this->argument('host')) {
                    return $this->hostCertResult(strtolower($host));
                }
                $msg = $this->option('ok') ? null : 'Certificate request failed: '.Str::limit((string) $this->option('failed'), 300);
                AppDomain::where('status', 'reachable')->update(['last_error' => $msg ?? 'Certificate issued — checking HTTPS.']);
                if ($this->option('ok')) {
                    foreach (AppDomain::where('status', 'reachable')->get() as $d) {
                        Domains::check($d);
                    }
                }
                break;
            case 'nginx-conf':
                $host = strtolower((string) $this->argument('host'));
                if (! AppDomain::where('host', $host)->whereIn('status', AppDomain::IN_NGINX)->exists()) {
                    $this->error("$host is not a domain nginx should serve.");

                    return self::FAILURE;
                }
                $this->line(self::nginxConf($host, (bool) $this->option('tls')));
                break;
            default:
                $this->table(['host', 'org', 'apps', 'trusted', 'status', 'last error'], AppDomain::with(['apps', 'organization'])->get()
                    ->map(fn ($d) => [$d->host, $d->organization?->slug, $d->apps->pluck('key')->join(','), $d->isTrusted() ? 'yes' : 'no', $d->status, $d->last_error]));
        }

        return self::SUCCESS;
    }

    /** One domain's certificate result (per-host mode); a success moves it on to the strict HTTPS check. */
    private function hostCertResult(string $host): int
    {
        $d = AppDomain::where('host', $host)->first();
        if (! $d) {
            $this->error("Unknown domain $host");

            return self::FAILURE;
        }
        if ($this->option('ok')) {
            $d->update(['cert_status' => 'issued', 'cert_issued_at' => now(), 'cert_error' => null]);
            if (in_array($d->status, ['reachable', 'problem'], true)) {
                Domains::check($d);
            }
        } else {
            $err = Str::limit((string) $this->option('failed'), 300);
            $d->update(['cert_status' => 'failed', 'cert_error' => $err, 'last_error' => $d->isServed() ? $d->last_error : 'Certificate request failed: '.$err]);
        }
        $this->line("$host: ".($this->option('ok') ? 'certificate issued' : 'certificate failed'));

        return self::SUCCESS;
    }

    /**
     * nginx server block for one custom domain in per-host mode. Without --tls: port 80 only (ACME challenge,
     * Clauth's ownership proof, everything else redirected to HTTPS). With --tls: port 80 + 443 with the host's own
     * certificate, proxied to the same PHP app as the main domain.
     */
    public static function nginxConf(string $host, bool $tls): string
    {
        abort_unless((bool) preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $host), 422, 'bad host');
        $c = config('clauth.perhost');
        if (! empty($c['upstream'])) {
            $proxy = <<<NGX
                    proxy_pass {$c['upstream']};
                    proxy_set_header Host \$host;
                    proxy_set_header X-Forwarded-Host \$host;
                    proxy_set_header X-Real-IP \$remote_addr;
                    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
                    proxy_set_header X-Forwarded-Proto \$scheme;
            NGX;
            $http = <<<NGX
            # Clauth custom sign-in domain {$host} (generated by clauth:domains nginx-conf; do not edit)
            server {
                listen 80;
                listen [::]:80;
                server_name {$host};
                location ^~ /.well-known/acme-challenge/ { root "{$c['acme_root']}"; default_type text/plain; }
                location ~ "^/\\.well-known/clauth-domain/[a-z0-9]{32}\$" {
            {$proxy}
                }
                location / { return 301 https://\$host\$request_uri; }
            }

            NGX;
            if (! $tls) {
                return $http;
            }

            return $http.<<<NGX
            server {
                listen 443 ssl;
                listen [::]:443 ssl;
                http2 on;
                server_name {$host};
                ssl_certificate {$c['cert_dir']}/{$host}/fullchain.pem;
                ssl_certificate_key {$c['cert_dir']}/{$host}/privkey.pem;
                add_header Strict-Transport-Security "max-age=31536000" always;
                client_max_body_size 10m;
                location / {
            {$proxy}
                }
            }

            NGX;
        }
        $php = <<<NGX
            root "{$c['public_root']}";
            index index.php;
            location / { try_files \$uri \$uri/ /index.php?\$query_string; }
            location ~ \\.php\$ {
                include fastcgi_params;
                fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
                fastcgi_param HTTPS \$https if_not_empty;
                fastcgi_pass {$c['php_fpm']};
            }
        NGX;
        $http = <<<NGX
        # Clauth custom sign-in domain {$host} (generated by clauth:domains nginx-conf; do not edit)
        server {
            listen 80;
            listen [::]:80;
            server_name {$host};
            location ^~ /.well-known/acme-challenge/ { root "{$c['acme_root']}"; default_type text/plain; }
            location ~ "^/\\.well-known/clauth-domain/[a-z0-9]{32}\$" { include fastcgi_params; fastcgi_param SCRIPT_FILENAME "{$c['public_root']}/index.php"; fastcgi_pass {$c['php_fpm']}; }
            location / { return 301 https://\$host\$request_uri; }
        }

        NGX;
        if (! $tls) {
            return $http;
        }

        return $http.<<<NGX
        server {
            listen 443 ssl;
            listen [::]:443 ssl;
            http2 on;
            server_name {$host};
            ssl_certificate {$c['cert_dir']}/{$host}/fullchain.pem;
            ssl_certificate_key {$c['cert_dir']}/{$host}/privkey.pem;
            add_header Strict-Transport-Security "max-age=31536000" always;
        {$php}
        }

        NGX;
    }
}
