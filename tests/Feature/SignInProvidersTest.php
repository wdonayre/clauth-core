<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\ConnectionLoginController;
use App\Models\Connection;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/** Provider dropdown with setup guides, and Sign in with Apple (ES256 client secret, form_post callback). */
class SignInProvidersTest extends ClauthTestCase
{
    private const MAIN = 'https://auth.test';

    private const APPLE = 'https://appleid.apple.com';

    private ?string $nonce = null;

    private array $idClaims = [];

    private string $pem;

    protected function setUp(): void
    {
        parent::setUp();
        config(['clauth.issuer' => self::MAIN, 'app.url' => self::MAIN, 'services.google.client_id' => null]);
        $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($k, $pem);
        $this->pem = $pem;
        $b = fn ($a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
        Http::fake([
            self::APPLE.'/.well-known/openid-configuration' => Http::response(['issuer' => self::APPLE, 'authorization_endpoint' => self::APPLE.'/auth/authorize', 'token_endpoint' => self::APPLE.'/auth/token']),
            self::APPLE.'/auth/token' => fn () => Http::response(['access_token' => 'at', 'id_token' => $b(['alg' => 'RS256']).'.'.$b($this->idClaims + ['nonce' => $this->nonce]).'.sig']),
        ]);
    }

    private function apple(): Connection
    {
        return Connection::create(['organization_id' => null, 'type' => 'apple', 'key' => 'apple', 'name' => 'Apple', 'status' => 'active',
            'config' => ['client_id' => 'com.example.signin', 'team_id' => 'TEAM123456', 'key_id' => 'KEY1234567', 'private_key' => $this->pem, 'show_button' => true]]);
    }

    public function test_console_dialog_has_provider_dropdown_and_guides(): void
    {
        $owner = $this->makeUser('owner@cakesbygrace.ph', ['name' => 'Grace']);
        $owner->organizations()->attach($this->cbg->id, ['role' => 'owner', 'joined_at' => now()]);
        $owner->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => now()])->save();
        $as = fn () => $this->actingAs($owner)->withSession(['auth.mfa' => true]);
        $as()->get(self::MAIN."/admin/orgs/{$this->cbg->id}/connections")->assertOk()
            ->assertSee('data-provider-select', false)->assertSee('https://helloclauth.com/docs/sign-in-providers/#google', false)
            ->assertSee('How to set up Google sign-in')->assertSee('https://accounts.google.com', false)
            ->assertSee('/login/c/cakesbygrace-google/callback', false)->assertSee('#okta', false)->assertSee('#apple', false);

        // Apple from the same dialog: its own fields, validated.
        $as()->post(self::MAIN."/admin/orgs/{$this->cbg->id}/connections", ['type' => 'apple', 'preset' => 'apple', 'name' => 'Apple',
            'config' => ['client_id' => 'com.cbg.signin', 'team_id' => 'short', 'key_id' => 'KEY1234567', 'private_key' => 'nope', 'domains' => "cakesbygrace.ph"]])
            ->assertSessionHasErrors(['team_id', 'private_key']);
        $as()->post(self::MAIN."/admin/orgs/{$this->cbg->id}/connections", ['type' => 'apple', 'preset' => 'apple', 'name' => 'Apple',
            'config' => ['client_id' => 'com.cbg.signin', 'team_id' => 'TEAM123456', 'key_id' => 'KEY1234567', 'private_key' => $this->pem, 'domains' => "cakesbygrace.ph", 'show_button' => 1]])
            ->assertSessionHasNoErrors();
        $c = Connection::where('type', 'apple')->firstOrFail();
        $this->assertSame('cakesbygrace-apple', $c->key);
        $as()->post(self::MAIN."/admin/orgs/{$this->cbg->id}/connections/{$c->id}/test")->assertSessionHas('status', fn ($s) => str_starts_with($s, 'Key works'));
    }

    public function test_apple_secret_is_an_es256_jwt_apple_accepts(): void
    {
        $jwt = ConnectionLoginController::appleSecret($this->apple());
        [$h, $p, $sig] = explode('.', $jwt);
        $head = json_decode(base64_decode(strtr($h, '-_', '+/')), true);
        $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        $this->assertSame(['ES256', 'KEY1234567'], [$head['alg'], $head['kid']]);
        $this->assertSame(['TEAM123456', self::APPLE, 'com.example.signin'], [$claims['iss'], $claims['aud'], $claims['sub']]);
        $this->assertIsInt($claims['iat']);
        $this->assertLessThanOrEqual(300, $claims['exp'] - $claims['iat']);
        $this->assertSame(64, strlen(base64_decode(strtr($sig, '-_', '+/').'==')));
    }

    public function test_apple_sign_in_round_trip_with_form_post(): void
    {
        $this->apple();
        $maria = $this->makeUser('maria@cakesbygrace.ph', ['name' => 'Maria']);
        $loc = (string) $this->keep($this->get(self::MAIN.'/login/c/apple'))->headers->get('Location');
        parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
        $this->assertStringStartsWith(self::APPLE.'/auth/authorize?', $loc);
        $this->assertSame(['form_post', 'name email', 'com.example.signin'], [$q['response_mode'], $q['scope'], $q['client_id']]);
        $this->assertArrayNotHasKey('code_challenge', $q);
        $this->nonce = $q['nonce'];
        $this->idClaims = ['iss' => self::APPLE, 'aud' => 'com.example.signin', 'exp' => time() + 300, 'sub' => 'apple-1', 'email' => 'maria@cakesbygrace.ph', 'email_verified' => 'true'];

        // Apple POSTs back: the session-less route hands it to the GET callback.
        $post = $this->post(self::MAIN.'/login/c/apple/callback', ['code' => 'abc', 'state' => $q['state'], 'user' => '{"name":{"firstName":"Maria","lastName":"C"}}']);
        $post->assertStatus(303);
        $this->assertEmpty($post->headers->getCookies());
        $this->keep($this->get((string) $post->headers->get('Location')))->assertRedirect();
        $this->assertAuthenticatedAs($maria);
        $this->assertDatabaseHas('social_identities', ['user_id' => $maria->id, 'provider' => 'apple', 'provider_id' => 'apple-1']);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::APPLE.'/auth/token' && substr_count((string) $r['client_secret'], '.') === 2 && ! isset($r['code_verifier']));
    }
}
