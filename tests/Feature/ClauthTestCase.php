<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AppPermission;
use App\Models\AppRole;
use App\Models\Grant;
use App\Models\OAuthClient;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Tests\TestCase;

abstract class ClauthTestCase extends TestCase
{
    use RefreshDatabase;

    protected const PASSWORD = 'correct horse battery staple';

    protected const REDIRECT = 'https://xpenses.test/auth/callback';

    protected Organization $cbg;

    protected Organization $alab;

    protected Application $xp;

    protected OAuthClient $client;

    protected string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        if (! file_exists(storage_path('oauth-private.key'))) {
            $this->artisan('passport:keys');
        }
        $this->cbg = Organization::create(['slug' => 'cakesbygrace', 'name' => 'CakesbyGrace']);
        $this->alab = Organization::create(['slug' => 'alab-apparel', 'name' => 'Alab Apparel']);
        $this->xp = Application::create(['key' => 'xpenses', 'name' => 'Xpenses', 'status' => 'live', 'home_url' => 'https://xpenses.test']);
        $this->xp->organizations()->sync([$this->cbg->id, $this->alab->id]);
        $perms = collect(['expense.submit', 'expense.approve', 'expense.view_all', 'report.export'])
            ->mapWithKeys(fn ($k) => [$k => AppPermission::create(['app_id' => $this->xp->id, 'key' => $k, 'source' => 'manifest'])->id]);
        foreach (['member' => ['expense.submit'], 'finance' => ['expense.view_all', 'expense.approve', 'report.export']] as $key => $p) {
            AppRole::create(['app_id' => $this->xp->id, 'key' => $key, 'name' => ucfirst($key)])->permissions()->sync($perms->only($p)->values());
        }
        $this->secret = Str::random(40);
        $this->client = new OAuthClient;
        $this->client->forceFill(['app_id' => $this->xp->id, 'type' => 'web', 'name' => 'Xpenses web', 'secret' => $this->secret,
            'redirect_uris' => [self::REDIRECT], 'post_logout_redirect_uris' => ['https://xpenses.test/'],
            'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false])->save();
    }

    protected function role(string $key): AppRole
    {
        return AppRole::where('app_id', $this->xp->id)->where('key', $key)->firstOrFail();
    }

    protected function makeUser(string $email = 'maria@cakesbygrace.ph', array $attrs = []): User
    {
        return User::create(array_merge(['email' => $email, 'name' => 'Maria Santos', 'password' => self::PASSWORD, 'status' => 'active', 'email_verified_at' => now()], $attrs));
    }

    protected function grant(User $user, Organization $org, string $role): Grant
    {
        $user->organizations()->syncWithoutDetaching([$org->id]);

        return Grant::updateOrCreate(['user_id' => $user->id, 'app_id' => $this->xp->id, 'organization_id' => $org->id], ['app_role_id' => $this->role($role)->id]);
    }

    /** Carry cookies between requests like a browser. */
    protected function keep(TestResponse $r): TestResponse
    {
        foreach ($r->headers->getCookies() as $c) {
            if ($c->getValue() === null || $c->isCleared()) {
                continue;
            }
            $val = $r->getCookie($c->getName(), true)?->getValue();
            if ($val !== null) {
                $this->withCookie($c->getName(), $val);
            }
        }

        return $r;
    }

    /** Runs browser sign-in + authorize; returns [code, state, verifier] or the final response on failure. */
    protected function authorizeCode(?User $user = null, string $scope = 'openid profile email clauth', array $extra = [])
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = Str::random(16);
        $query = http_build_query(array_merge([
            'client_id' => $this->client->id, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code',
            'scope' => $scope, 'state' => $state, 'nonce' => 'n-0S6_WzA2Mj', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ], $extra));
        $r = $this->keep($this->get('/oauth/authorize?'.$query));
        if ($user && str_ends_with((string) $r->headers->get('Location'), '/login')) {
            $this->keep($this->get('/login'))->assertOk();
            $r = $this->keep($this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]));
            $r = $this->keep($this->get($r->headers->get('Location')));
        }
        $loc = (string) $r->headers->get('Location');
        if (! str_starts_with($loc, self::REDIRECT)) {
            return $r;
        }
        parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);

        return [$q['code'] ?? null, $q['state'] ?? null, $verifier, $q];
    }

    protected function exchange(string $code, string $verifier): TestResponse
    {
        return $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $this->client->id, 'client_secret' => $this->secret,
            'redirect_uri' => self::REDIRECT, 'code' => $code, 'code_verifier' => $verifier,
        ]);
    }

    protected function refresh(string $refreshToken): TestResponse
    {
        return $this->post('/oauth/token', [
            'grant_type' => 'refresh_token', 'client_id' => $this->client->id, 'client_secret' => $this->secret, 'refresh_token' => $refreshToken,
        ]);
    }

    /** Verify a JWT exactly like an app would: only with the public JWKS document. */
    protected function verifyOffline(string $jwt, array $jwks, string $aud): Plain
    {
        $token = (new Parser(new JoseEncoder))->parse($jwt);
        $kid = $token->headers()->get('kid');
        $jwk = collect($jwks['keys'])->firstWhere('kid', $kid);
        $this->assertNotNull($jwk, "kid $kid not in JWKS");
        $ok = (new Validator)->validate($token, new SignedWith(new Sha256, InMemory::plainText(self::jwkToPem($jwk))), new PermittedFor($aud));
        $this->assertTrue($ok, 'JWT signature/audience did not validate offline');

        return $token;
    }

    protected static function jwkToPem(array $jwk): string
    {
        $b64 = fn ($s) => base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4));
        $len = function (int $l) {
            if ($l < 128) {
                return chr($l);
            }
            $b = ltrim(pack('N', $l), "\0");

            return chr(0x80 | strlen($b)).$b;
        };
        $int = function (string $x) use ($len) {
            if (ord($x[0]) > 0x7F) {
                $x = "\0".$x;
            }

            return "\x02".$len(strlen($x)).$x;
        };
        $seq = fn (string $x) => "\x30".$len(strlen($x)).$x;
        $rsa = $seq($int($b64($jwk['n'])).$int($b64($jwk['e'])));
        $algo = $seq("\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00");
        $bit = "\x03".$len(strlen($rsa) + 1)."\x00".$rsa;
        $der = $seq($algo.$bit);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }
}
