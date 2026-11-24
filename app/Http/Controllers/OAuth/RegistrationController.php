<?php

namespace App\Http\Controllers\OAuth;

use App\Models\OAuthClient;
use App\Services\Audit;
use App\Services\ClientMetadata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dynamic client registration, RFC 7591 + 7592 (SPEC-platform §8.3). Off unless an app's publisher hands out an
 * initial access token (App › Integration or POST /api/v1/apps/{app}/registration-tokens): the new client belongs to
 * that app. The response's registration_access_token reads, changes or deletes the client later.
 */
class RegistrationController
{
    public function register(Request $request)
    {
        $token = (string) $request->bearerToken();
        $row = $token ? DB::table('registration_tokens')->where('token_hash', hash('sha256', $token))->first() : null;
        if (! $row || ($row->expires_at && now()->greaterThan($row->expires_at)) || ($row->uses_left !== null && $row->uses_left < 1)) {
            return $this->err('invalid_token', 'Registration needs an initial access token from the app’s publisher.', 401);
        }
        $app = \App\Models\Application::find($row->app_id);
        if (! $app || $app->isSuspended()) {
            return $this->err('invalid_token', 'The app is not available.', 401);
        }
        try {
            $m = ClientMetadata::validate($request->json()->all() ?: $request->all());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->err(str_contains(json_encode($e->errors()), 'redirect') ? 'invalid_redirect_uri' : 'invalid_client_metadata', collect($e->errors())->flatten()->first());
        }
        if ($row->uses_left !== null) {
            DB::table('registration_tokens')->where('id', $row->id)->decrement('uses_left');
        }
        [$c, $secret, $rat] = $this->create($app, $m);
        Audit::log('app.client_registered', 'app', $app, after: ['client' => $c->id, 'name' => $m['client_name'], 'token' => $row->name], appId: $app->id, actorType: 'app');

        return response()->json($this->json($c, $m) + array_filter(['client_secret' => $secret]) + ['registration_access_token' => $rat], 201, ['Cache-Control' => 'no-store']);
    }

    public function show(Request $request, string $clientId)
    {
        $c = $this->client($request, $clientId);

        return $c ? response()->json($this->json($c, $this->meta($c)), 200, ['Cache-Control' => 'no-store']) : $this->err('invalid_token', null, 401);
    }

    public function update(Request $request, string $clientId)
    {
        $c = $this->client($request, $clientId);
        if (! $c) {
            return $this->err('invalid_token', null, 401);
        }
        try {
            $m = ClientMetadata::validate(($request->json()->all() ?: $request->all()) + ['token_endpoint_auth_method' => $c->confidential() ? 'client_secret_basic' : 'none']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->err('invalid_client_metadata', collect($e->errors())->flatten()->first());
        }
        $c->forceFill(['name' => $m['client_name'], 'redirect_uris' => $m['redirect_uris']])->save();

        return response()->json($this->json($c, $m), 200, ['Cache-Control' => 'no-store']);
    }

    public function destroy(Request $request, string $clientId)
    {
        $c = $this->client($request, $clientId);
        if (! $c) {
            return $this->err('invalid_token', null, 401);
        }
        $c->forceFill(['revoked' => true, 'registration_token_hash' => null])->save();
        DB::table('oauth_access_tokens')->where('client_id', $c->id)->update(['revoked' => true]);
        Audit::log('app.client_deregistered', 'app', $c->app, after: ['client' => $c->id], appId: $c->app_id, actorType: 'app');

        return response()->noContent();
    }

    /** [client, secret|null, registration access token] */
    public static function create(\App\Models\Application $app, array $m): array
    {
        $public = $m['token_endpoint_auth_method'] === 'none';
        $secret = $public ? null : Str::random(48);
        $rat = Str::random(48);
        $c = new OAuthClient;
        $c->forceFill(['app_id' => $app->id, 'type' => $public ? 'public' : 'web', 'name' => $m['client_name'], 'secret' => $secret,
            'redirect_uris' => $m['redirect_uris'], 'post_logout_redirect_uris' => [], 'grant_types' => $m['grant_types'], 'revoked' => false,
            'registration_token_hash' => hash('sha256', $rat), 'secret_expires_at' => $secret ? now()->addDays(config('clauth.client_secret_days')) : null])->save();
        \Illuminate\Support\Facades\Cache::forget('oauth:cors-origins');

        return [$c, $secret, $rat];
    }

    private function client(Request $request, string $id): ?OAuthClient
    {
        $c = OAuthClient::with('app')->find($id);
        $t = (string) $request->bearerToken();

        return $c && ! $c->revoked && $c->registration_token_hash && $t && hash_equals($c->registration_token_hash, hash('sha256', $t)) ? $c : null;
    }

    private function meta(OAuthClient $c): array
    {
        return ['client_name' => $c->name, 'redirect_uris' => $c->redirect_uris ?? [], 'grant_types' => $c->grant_types,
            'token_endpoint_auth_method' => $c->confidential() ? 'client_secret_basic' : 'none'];
    }

    private function json(OAuthClient $c, array $m): array
    {
        return ['client_id' => $c->id, 'client_id_issued_at' => $c->created_at?->getTimestamp(),
            'client_secret_expires_at' => $c->secret_expires_at?->getTimestamp() ?? 0,
            'registration_client_uri' => \App\Services\Domains::mainUrl('oauth/register/'.$c->id)] + array_filter($m, fn ($v) => $v !== null);
    }

    private function err(string $code, ?string $desc, int $status = 400)
    {
        return response()->json(array_filter(['error' => $code, 'error_description' => $desc]), $status, ['Cache-Control' => 'no-store']);
    }
}
