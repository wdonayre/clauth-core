<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Client metadata (SPEC-platform §8.3): validation shared by dynamic client registration (RFC 7591) and client ID
 * metadata documents (an https URL that is the client_id and serves the client's metadata as JSON).
 */
class ClientMetadata
{
    /** Host → IPs (tests swap this). */
    public static ?\Closure $resolve = null;

    /** https URLs, custom app schemes (com.example.app:/cb) and http://localhost / 127.0.0.1 for development. */
    public static function redirectOk(string $u): bool
    {
        if (preg_match('#^https://[^\s/]+#i', $u) && filter_var($u, FILTER_VALIDATE_URL)) {
            return true;
        }
        if (preg_match('#^http://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?(/|$)#i', $u)) {
            return true;
        }

        return (bool) preg_match('#^[a-z][a-z0-9+.-]*\.[a-z0-9+.-]+:/[^\s]*$#i', $u); // reverse-DNS custom scheme
    }

    /** Normalised metadata or a validation error. */
    public static function validate(array $m): array
    {
        $errors = [];
        $uris = array_values(array_filter((array) ($m['redirect_uris'] ?? []), 'is_string'));
        if (! $uris || count($uris) > 10) {
            $errors['redirect_uris'] = 'Send 1–10 redirect_uris.';
        }
        foreach ($uris as $u) {
            if (! self::redirectOk($u)) {
                $errors['redirect_uris'] = "Not allowed as a redirect: {$u}";
            }
        }
        $auth = $m['token_endpoint_auth_method'] ?? 'client_secret_basic';
        if (! in_array($auth, ['none', 'client_secret_basic', 'client_secret_post'], true)) {
            $errors['token_endpoint_auth_method'] = 'Use none, client_secret_basic or client_secret_post.';
        }
        $grants = (array) ($m['grant_types'] ?? ['authorization_code', 'refresh_token']);
        if (array_diff($grants, ['authorization_code', 'refresh_token'])) {
            $errors['grant_types'] = 'Only authorization_code and refresh_token can be registered.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['client_name' => mb_substr(trim((string) ($m['client_name'] ?? 'Registered client')), 0, 80) ?: 'Registered client',
            'redirect_uris' => $uris, 'token_endpoint_auth_method' => $auth, 'grant_types' => ['authorization_code', 'refresh_token'],
            'logo_uri' => is_string($m['logo_uri'] ?? null) && str_starts_with($m['logo_uri'], 'https://') ? $m['logo_uri'] : null];
    }

    /** Fetch a client ID metadata document. Only https, public addresses, ≤ 64 KB, and its client_id must be its own URL. */
    public static function fetch(string $url): array
    {
        if (! preg_match('#^https://([^/:\s]+)(:\d+)?/#i', $url, $m)) {
            throw ValidationException::withMessages(['url' => 'The client_id must be an https URL with a path.']);
        }
        $ips = (self::$resolve ?? fn ($h) => gethostbynamel($h) ?: [])($m[1]);
        if (! $ips || collect($ips)->contains(fn ($ip) => ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            throw ValidationException::withMessages(['url' => 'That address is not reachable from the internet.']);
        }
        $r = Http::timeout(5)->withOptions(['allow_redirects' => false])->accept('application/json')->get($url);
        if (! $r->successful() || strlen($r->body()) > 65536 || ! is_array($doc = $r->json())) {
            throw ValidationException::withMessages(['url' => 'No metadata document there (expect JSON, ≤ 64 KB).']);
        }
        if (($doc['client_id'] ?? null) !== $url) {
            throw ValidationException::withMessages(['url' => 'The document’s client_id must equal its URL.']);
        }

        return ['client_id' => $url] + self::validate($doc + ['token_endpoint_auth_method' => 'none']);
    }
}
