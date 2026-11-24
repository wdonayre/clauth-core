<?php

namespace App\Http\Controllers\Auth;

use App\Models\Application;
use App\Models\Connection;
use App\Services\Connections;
use App\Services\Domains;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Continue with <connection>" for OIDC, OAuth 2 and Facebook connections (SPEC-platform §7). Same shape as Google:
 * the callback is always on the main domain (/login/c/{key}/callback); a sign-in started on an app's own domain hops
 * to main and comes back through SSO. Authorization code flow with state, PKCE (OIDC/OAuth) and nonce (OIDC).
 * The ID token comes straight from the provider's token endpoint over TLS, so it is checked for issuer, audience,
 * expiry and nonce (OIDC Core §3.1.3.7) rather than by signature.
 */
class ConnectionLoginController
{
    use Federated;

    private const META = 'https://www.facebook.com/v19.0/dialog/oauth';

    private const META_TOKEN = 'https://graph.facebook.com/v19.0/oauth/access_token';

    private const META_ME = 'https://graph.facebook.com/v19.0/me';

    private function connection(string $key): Connection
    {
        $c = Connection::with('organization')->where('key', $key)->first();
        abort_unless($c && $c->isSignIn() && $c->isActive() && ! $c->organization?->isSuspended(), 404);

        return $c;
    }

    public function redirect(Request $request, string $key)
    {
        $c = $this->connection($key);
        if ($domain = $request->attributes->get('clauth.domain')) {
            return redirect()->away(Domains::mainUrl("login/c/$key?".http_build_query(array_filter([
                'back' => $domain->host, 'app' => $this->flowApp($request)?->key, 'remember' => $request->boolean('remember') ? 1 : null,
                'login_hint' => $request->query('login_hint'),
            ]))));
        }
        $back = strtolower((string) $request->query('back'));
        $backDomain = $back ? Domains::activeFor($back) : null;
        $request->session()->put('login.remember', $request->boolean('remember') || $request->session()->get('login.remember', false));
        if (($appKey = (string) $request->query('app')) && ! $request->session()->has('login.client_id')) {
            $app = Application::where('key', $appKey)->first();
            if ($app && (! $backDomain || $backDomain->serves($app))) {
                $request->session()->put('login.app_key', $app->key);
            }
        }
        $state = Str::random(40);
        $verifier = Str::random(64);
        $nonce = Str::random(32);
        $request->session()->put("fed.$state", ['key' => $c->key, 'verifier' => $verifier, 'nonce' => $nonce, 'back' => $backDomain?->host, 'at' => time()]);
        $params = ['client_id' => $c->cfg('client_id'), 'redirect_uri' => $this->callbackUrl($c), 'response_type' => 'code', 'state' => $state];

        try {
            if ($c->type === 'meta') {
                return redirect()->away(self::META.'?'.http_build_query($params + ['scope' => 'email,public_profile']));
            }
            $params += ['code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'];
            if ($c->type === 'oidc') {
                $params += ['scope' => $c->cfg('scopes') ?: 'openid email profile', 'nonce' => $nonce, 'prompt' => 'select_account']
                    + array_filter(['login_hint' => $request->query('login_hint')]);

                return redirect()->away($this->discovery($c)['authorization_endpoint'].'?'.http_build_query($params));
            }

            return redirect()->away($c->cfg('authorize_url').(str_contains($c->cfg('authorize_url'), '?') ? '&' : '?').http_build_query($params + array_filter(['scope' => $c->cfg('scopes')])));
        } catch (Throwable $e) {
            report($e);

            return $this->federatedFail($backDomain?->host, 'failed');
        }
    }

    public function callback(Request $request, string $key)
    {
        abort_unless(Domains::isMain($request->getHost()) || in_array(strtolower($request->getHost()), Domains::mainAliases(), true), 404);
        $c = $this->connection($key);
        $flow = $request->session()->pull('fed.'.$request->query('state'));
        if (! $flow || $flow['key'] !== $c->key || time() - $flow['at'] > 900) {
            return $this->federatedFail(null, 'failed');
        }
        $back = $flow['back'];
        if ($request->has('error') || ! $request->filled('code')) {
            return $this->federatedFail($back, 'cancelled');
        }
        try {
            [$id, $email, $verified, $name] = match ($c->type) {
                'oidc' => $this->oidc($c, (string) $request->query('code'), $flow),
                'oauth' => $this->oauth($c, (string) $request->query('code'), $flow),
                'meta' => $this->meta($c, (string) $request->query('code')),
            };
        } catch (Throwable $e) {
            report($e);

            return $this->federatedFail($back, 'failed');
        }
        if ($id === '') {
            return $this->federatedFail($back, 'failed');
        }

        return $this->federatedFinish($request, $c->key, $id, $email, $verified, $c, $name, $back);
    }

    /** @return array{0:string,1:?string,2:bool,3:?string} [id, email, email verified, name] */
    private function oidc(Connection $c, string $code, array $flow): array
    {
        $d = $this->discovery($c);
        $tok = Http::asForm()->acceptJson()->timeout(15)->post($d['token_endpoint'], [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->callbackUrl($c),
            'client_id' => $c->cfg('client_id'), 'client_secret' => $c->cfg('client_secret'), 'code_verifier' => $flow['verifier'],
        ])->throw()->json();
        $claims = $this->jwtClaims((string) ($tok['id_token'] ?? ''));
        $iss = rtrim((string) ($claims['iss'] ?? ''), '/');
        $aud = (array) ($claims['aud'] ?? []);
        $expected = rtrim($d['issuer'] ?? $c->cfg('issuer'), '/');
        // Microsoft's "common"/"organizations" issuers put the tenant id in the token's iss.
        $issOk = $iss === $expected || (str_contains($expected, '{tenantid}') && preg_match('#^'.str_replace('\{tenantid\}', '[0-9a-f-]{36}', preg_quote($expected, '#')).'$#', $iss));
        if (! $issOk || ! in_array($c->cfg('client_id'), $aud, true) || ($claims['exp'] ?? 0) < time() - 60 || ($claims['nonce'] ?? null) !== $flow['nonce']) {
            throw new \RuntimeException("ID token from {$c->key} failed checks (iss/aud/exp/nonce).");
        }
        if (empty($claims['email']) && ! empty($d['userinfo_endpoint']) && ! empty($tok['access_token'])) {
            $claims += Http::withToken($tok['access_token'])->acceptJson()->timeout(15)->get($d['userinfo_endpoint'])->throw()->json() ?? [];
        }
        $email = $claims['email'] ?? $claims['preferred_username'] ?? null;
        $email = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;

        return [(string) ($claims['sub'] ?? ''), $email, filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN), $claims['name'] ?? null];
    }

    private function oauth(Connection $c, string $code, array $flow): array
    {
        $tok = Http::asForm()->acceptJson()->timeout(15)->post($c->cfg('token_url'), [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->callbackUrl($c),
            'client_id' => $c->cfg('client_id'), 'client_secret' => $c->cfg('client_secret'), 'code_verifier' => $flow['verifier'],
        ])->throw()->json();
        $u = Http::withToken((string) ($tok['access_token'] ?? ''))->acceptJson()->timeout(15)->get($c->cfg('userinfo_url'))->throw()->json() ?? [];
        $email = data_get($u, $c->cfg('email_field') ?: 'email');

        return [(string) data_get($u, $c->cfg('id_field') ?: 'id', ''), is_string($email) ? $email : null,
            filter_var(data_get($u, 'email_verified', data_get($u, 'verified_email', false)), FILTER_VALIDATE_BOOLEAN), data_get($u, $c->cfg('name_field') ?: 'name')];
    }

    private function meta(Connection $c, string $code): array
    {
        $tok = Http::acceptJson()->timeout(15)->get(self::META_TOKEN, ['client_id' => $c->cfg('client_id'), 'client_secret' => $c->cfg('client_secret'),
            'redirect_uri' => $this->callbackUrl($c), 'code' => $code])->throw()->json();
        $access = (string) ($tok['access_token'] ?? '');
        $u = Http::acceptJson()->timeout(15)->get(self::META_ME, ['fields' => 'id,name,email', 'access_token' => $access,
            'appsecret_proof' => hash_hmac('sha256', $access, (string) $c->cfg('client_secret'))])->throw()->json() ?? [];

        // Facebook doesn't say whether an email was confirmed: never link by email unless the connection trusts it.
        return [(string) ($u['id'] ?? ''), $u['email'] ?? null, (bool) $c->cfg('trust_email', false), $u['name'] ?? null];
    }

    private function discovery(Connection $c): array
    {
        return Cache::remember('oidc-discovery:'.$c->id.':'.md5($c->cfg('issuer')), 3600, function () use ($c) {
            $d = Http::acceptJson()->timeout(10)->get(rtrim($c->cfg('issuer'), '/').'/.well-known/openid-configuration')->throw()->json();
            if (empty($d['authorization_endpoint']) || empty($d['token_endpoint'])) {
                throw new \RuntimeException("{$c->cfg('issuer')} has no usable discovery document.");
            }

            return $d;
        });
    }

    private function jwtClaims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('No ID token.');
        }

        return json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) ?: throw new \RuntimeException('Bad ID token.');
    }

    private function callbackUrl(Connection $c): string
    {
        return Domains::mainUrl("login/c/{$c->key}/callback");
    }
}
