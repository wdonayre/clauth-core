<?php

namespace App\Support;

/** Thin DNS wrapper so tests can fake lookups. */
class Dns
{
    /** @return string[] TXT strings for $name */
    public function txt(string $name): array
    {
        $rows = @dns_get_record($name, DNS_TXT) ?: [];

        return array_values(array_filter(array_map(fn ($r) => $r['txt'] ?? (isset($r['entries']) ? implode('', $r['entries']) : null), $rows)));
    }

    /** cname|a|proxied|other|none — how $host points at us (informational; reachability is checked over HTTPS). */
    public function target(string $host, string $mainHost, array $serverIps): string
    {
        $cname = @dns_get_record($host, DNS_CNAME) ?: [];
        if ($cname && rtrim(strtolower($cname[0]['target'] ?? ''), '.') === $mainHost) {
            return 'cname';
        }
        $ips = array_column(@dns_get_record($host, DNS_A) ?: [], 'ip');
        if (! $ips) {
            return 'none';
        }
        if (array_intersect($ips, $serverIps)) {
            return 'a';
        }
        $cf = ['104.16.', '104.17.', '104.18.', '104.19.', '104.20.', '104.21.', '172.64.', '172.65.', '172.66.', '172.67.', '188.114.'];
        foreach ($ips as $ip) {
            foreach ($cf as $p) {
                if (str_starts_with($ip, $p)) {
                    return 'proxied';
                }
            }
        }

        return 'other';
    }
}
