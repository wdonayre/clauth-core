<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** P5b: authorization details (RFC 9396), outside AuthZEN policy engines (SPEC-platform §8.3, §9.1). */
class PlatformP5bTest extends ClauthTestCase
{
    private User $admin;

    private User $maria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser('owner@cakesbygrace.ph', ['name' => 'Grace']);
        $this->admin->organizations()->attach($this->cbg->id, ['role' => 'owner', 'joined_at' => now()]);
        $this->maria = $this->makeUser();
        $this->grant($this->maria, $this->cbg, 'finance');
    }

    private function api(): \Closure
    {
        $c = new OAuthClient;
        $c->forceFill(['type' => 'api', 'name' => 's', 'secret' => 'api-secret-api-secret', 'organization_id' => $this->cbg->id, 'created_by' => $this->admin->id,
            'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'scopes' => ['apps:read', 'apps:write'], 'revoked' => false])->save();
        $t = $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $c->id, 'client_secret' => 'api-secret-api-secret', 'scope' => 'apps:read apps:write'])->json('access_token');

        return fn () => $this->flushHeaders()->withHeaders(['Authorization' => "Bearer $t", 'Accept' => 'application/json']);
    }

    private function authorize(array $details, string $verifier)
    {
        return $this->flushHeaders()->get('/oauth/authorize?'.http_build_query(['client_id' => $this->client->id, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code',
            'scope' => 'openid clauth', 'state' => 'st', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'authorization_details' => json_encode($details)]));
    }

    public function test_rich_authorization_request_end_to_end(): void
    {
        $this->api()()->putJson('/api/v1/apps/xpenses/authorization-detail-types/payment_initiation', ['description' => 'Pay a supplier', 'schema' => [
            'type' => 'object', 'required' => ['amount', 'currency', 'payee'], 'additionalProperties' => false,
            'properties' => ['type' => ['type' => 'string'], 'amount' => ['type' => 'number', 'minimum' => 1, 'maximum' => 100000],
                'currency' => ['type' => 'string', 'enum' => ['PHP']], 'payee' => ['type' => 'string', 'maxLength' => 80]]]])->assertOk();
        $this->getJson('/.well-known/openid-configuration')->assertJsonPath('authorization_details_types_supported', ['payment_initiation']);

        $this->actingAs($this->maria)->withSession(['auth.via' => 'password', 'auth.mfa' => true, 'auth.amr' => ['pwd']]);
        $rr = $this->authorize([['type' => 'payment_initiation', 'amount' => 5, 'currency' => 'USD', 'payee' => 'Ana']], 'v');
        $bad = (string) $rr->headers->get('Location');
        $this->assertStringContainsString('error=invalid_authorization_details', $bad);
        $this->assertStringContainsString('error=invalid_authorization_details', (string) $this->authorize([['type' => 'wire', 'amount' => 5]], 'v')->headers->get('Location'));

        $item = ['type' => 'payment_initiation', 'amount' => 1250, 'currency' => 'PHP', 'payee' => 'Ana Bakery'];
        $verifier = Str::random(64);
        $this->authorize([$item], $verifier)->assertOk()->assertSee('Pay a supplier')->assertSee('Ana Bakery')->assertSee('1250');
        $this->post('/oauth/details', ['decision' => 'approve'])->assertRedirect();
        $loc = (string) $this->authorize([$item], $verifier)->headers->get('Location');
        parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
        $tok = $this->post('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $this->client->id, 'client_secret' => $this->secret,
            'redirect_uri' => self::REDIRECT, 'code' => $q['code'], 'code_verifier' => $verifier])->assertOk();
        $this->assertSame([$item], $tok->json('authorization_details'));
        $claims = json_decode(base64_decode(strtr(explode('.', $tok->json('access_token'))[1], '-_', '+/')), true);
        $this->assertSame([$item], $claims['authorization_details']);

        // Refused, and asked again next time.
        $this->authorize([$item], $verifier)->assertOk();
        $this->post('/oauth/details', ['decision' => 'deny']);
        $this->assertStringContainsString('error=access_denied', (string) $this->authorize([$item], $verifier)->headers->get('Location'));
    }

    public function test_processor_can_fill_in_or_refuse(): void
    {
        DB::table('authorization_detail_types')->insert(['app_id' => $this->xp->id, 'type' => 'refund', 'description' => 'Refund', 'schema' => json_encode(['type' => 'object']),
            'processor_url' => 'https://xpenses.test/rar', 'processor_secret' => \Illuminate\Support\Facades\Crypt::encryptString('processor-secret-123'), 'created_at' => now(), 'updated_at' => now()]);
        $answer = (object) ['body' => ['approved' => true, 'authorization_details' => [['type' => 'refund', 'order' => 'A-1', 'limit' => 500]]]];
        Http::fake(['https://xpenses.test/rar' => fn () => Http::response($answer->body)]);
        $this->actingAs($this->maria)->withSession(['auth.via' => 'password', 'auth.mfa' => true]);
        $verifier = Str::random(64);
        $this->authorize([['type' => 'refund', 'order' => 'A-1']], $verifier)->assertOk();
        $this->post('/oauth/details', ['decision' => 'approve']);
        $loc = (string) $this->authorize([['type' => 'refund', 'order' => 'A-1']], $verifier)->headers->get('Location');
        parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
        $tok = $this->post('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $this->client->id, 'client_secret' => $this->secret,
            'redirect_uri' => self::REDIRECT, 'code' => $q['code'], 'code_verifier' => $verifier])->assertOk();
        $this->assertSame(500, $tok->json('authorization_details.0.limit'));
        Http::assertSent(fn ($r) => $r->hasHeader('X-Clauth-Signature', 'sha256='.hash_hmac('sha256', $r->body(), 'processor-secret-123')));

        $answer->body = ['approved' => false, 'message' => 'Order A-1 was already refunded.'];
        $this->authorize([['type' => 'refund', 'order' => 'A-1']], $verifier)->assertOk();
        $this->post('/oauth/details', ['decision' => 'approve']);
        $this->assertStringContainsString('already refunded', urldecode((string) $this->authorize([['type' => 'refund', 'order' => 'A-1']], $verifier)->headers->get('Location')));
    }

    public function test_outside_policy_engine_must_agree(): void
    {
        $m2m = new OAuthClient;
        $m2m->forceFill(['app_id' => $this->xp->id, 'type' => 'm2m', 'name' => 'svc', 'secret' => 's3cret-s3cret', 'redirect_uris' => [], 'grant_types' => ['client_credentials'], 'revoked' => false])->save();
        $az = $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $m2m->id, 'client_secret' => 's3cret-s3cret', 'scope' => 'authzen'])->json('access_token');
        $ask = fn () => $this->flushHeaders()->withHeaders(['Authorization' => "Bearer $az"])->postJson('/access/v1/evaluation', ['subject' => ['type' => 'user', 'id' => $this->maria->sub()],
            'action' => ['name' => 'expense.approve'], 'resource' => ['type' => 'app', 'id' => 'xpenses']]);
        $ask()->assertJsonPath('decision', true);

        Connection::create(['organization_id' => $this->cbg->id, 'type' => 'authzen-pdp', 'key' => 'cbg-pdp', 'name' => 'PDP', 'status' => 'active',
            'config' => ['url' => 'https://pdp.cakesbygrace.ph/access/v1/evaluation', 'token' => 'pdp-token', 'apps' => ['xpenses']]]);
        $answer = (object) ['decision' => false];
        Http::fake(['https://pdp.cakesbygrace.ph/*' => fn () => Http::response(['decision' => $answer->decision])]);
        $ask()->assertJsonPath('decision', false);
        $answer->decision = true;
        $ask()->assertJsonPath('decision', true);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer pdp-token') && $r['resource']['properties']['org'] === 'cakesbygrace');
    }

    public function test_openapi_still_matches_router(): void
    {
        $routes = array_keys(\App\Api\OpenApi::routes());
        sort($routes);
        $docs = array_keys(\App\Api\OpenApi::DOCS);
        sort($docs);
        $this->assertSame($docs, $routes);
    }
}
