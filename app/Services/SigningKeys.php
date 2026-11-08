<?php

namespace App\Services;

use App\Models\SigningKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * RS256 signing keys kept in the DB (private part encrypted with APP_KEY) — SPEC §10.1.
 * Rotation: stage (published in JWKS) → activate (signs new tokens) → retire (dropped from JWKS).
 */
class SigningKeys
{
    public static function generate(string $status = 'staged'): SigningKey
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (! $res) {
            throw new RuntimeException('Could not generate an RSA key: '.openssl_error_string());
        }
        openssl_pkey_export($res, $privatePem);
        $details = openssl_pkey_get_details($res);
        $kid = now()->format('Ymd').'-'.Str::lower(Str::random(8));

        $key = SigningKey::create([
            'kid' => $kid,
            'alg' => 'RS256',
            'public_pem' => $details['key'],
            'private_pem' => $privatePem,
            'public_jwk' => [
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => $kid,
                'n' => self::b64u($details['rsa']['n']),
                'e' => self::b64u($details['rsa']['e']),
            ],
            'status' => $status,
            'activates_at' => $status === 'active' ? now() : now()->addDays(7),
        ]);
        self::flush();

        return $key;
    }

    public static function active(): SigningKey
    {
        $key = SigningKey::where('status', 'active')->latest('activates_at')->first();

        return $key ?? self::generate('active');
    }

    public static function activate(SigningKey $key): void
    {
        SigningKey::where('status', 'active')->where('id', '!=', $key->id)
            ->update(['status' => 'retiring', 'retires_at' => now()->addDays(31)]);
        $key->update(['status' => 'active', 'activates_at' => now()]);
        self::flush();
    }

    public static function retire(SigningKey $key): void
    {
        $key->update(['status' => 'retired', 'retires_at' => now()]);
        self::flush();
    }

    /** Scheduled: activate staged keys whose date passed, retire old keys once tokens signed by them expired. */
    public static function tick(): void
    {
        foreach (SigningKey::where('status', 'staged')->where('activates_at', '<=', now())->get() as $key) {
            self::activate($key);
            Audit::log('security.key_activated', 'security', $key, after: ['kid' => $key->kid]);
        }
        foreach (SigningKey::where('status', 'retiring')->where('retires_at', '<=', now())->get() as $key) {
            self::retire($key);
            Audit::log('security.key_retired', 'security', $key, after: ['kid' => $key->kid]);
        }
    }

    /** Keys published in JWKS: active, staged and still-retiring ones. */
    public static function jwks(): array
    {
        self::active(); // first boot: make sure a key exists

        return Cache::remember('clauth.jwks', 300, fn () => [
            'keys' => SigningKey::whereIn('status', ['active', 'staged', 'retiring'])->orderByDesc('id')->get()
                ->map(fn ($k) => $k->public_jwk)->values()->all(),
        ]);
    }

    public static function config(?SigningKey $key = null): Configuration
    {
        $key ??= self::active();

        return Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($key->private_pem),
            InMemory::plainText($key->public_pem),
        );
    }

    /** Verify a token's signature against our published keys. Returns the token or null. */
    public static function verify(string $jwt, bool $checkExpiry = true): ?Plain
    {
        try {
            $parser = new Parser(new JoseEncoder);
            $token = $parser->parse($jwt);
            if (! $token instanceof Plain) {
                return null;
            }
            $kid = $token->headers()->get('kid');
            $key = SigningKey::where('kid', $kid)->whereIn('status', ['active', 'staged', 'retiring'])->first();
            if (! $key) {
                return null;
            }
            $validator = new Validator;
            $constraints = [
                new SignedWith(new Sha256, InMemory::plainText($key->public_pem)),
                new IssuedBy(config('clauth.issuer')),
            ];
            if ($checkExpiry) {
                $constraints[] = new LooseValidAt(new class implements ClockInterface
                {
                    public function now(): \DateTimeImmutable
                    {
                        return new \DateTimeImmutable;
                    }
                }, new \DateInterval('PT30S'));
            }

            $validator->assert($token, ...$constraints);

            return $token;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function flush(): void
    {
        Cache::forget('clauth.jwks');
    }

    private static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
