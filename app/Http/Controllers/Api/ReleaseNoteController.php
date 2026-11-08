<?php

namespace App\Http\Controllers\Api;

use App\Models\OAuthClient;
use App\Models\ReleaseNote;
use App\Services\Audit;
use App\Services\SigningKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/release-notes — an app posts its own "What's new" (e.g. from its deploy script).
 * Server-to-server token of a client of that app, scope `updates:write`. Same version twice = updated, not duplicated.
 */
class ReleaseNoteController
{
    public function store(Request $request)
    {
        $t = SigningKeys::verify((string) $request->bearerToken());
        $c = $t?->claims();
        if (! $t || ! in_array('updates:write', explode(' ', (string) $c->get('scope')), true)
            || DB::table('oauth_access_tokens')->where('id', $c->get('jti'))->where('revoked', false)->doesntExist()) {
            return response()->json(['error' => 'invalid_token'], 401);
        }
        $app = OAuthClient::with('app')->find($c->get('client_id'))?->app;
        if (! $app) {
            return response()->json(['error' => 'unknown_app'], 403);
        }
        $data = $request->validate([
            'title' => 'required|string|max:160',
            'version' => 'nullable|string|max:40',
            'body' => 'nullable|string|max:4000',
            'link' => 'nullable|url|max:255',
        ]);
        $note = $data['version'] ?? null
            ? ReleaseNote::updateOrCreate(['app_id' => $app->id, 'version' => $data['version']], $data + ['published_at' => now(), 'source' => 'api'])
            : ReleaseNote::create($data + ['app_id' => $app->id, 'published_at' => now(), 'source' => 'api']);
        Audit::log('app.release_note', 'app', $note, after: $note->only('title', 'version') + ['via' => 'api'], appId: $app->id, actorType: 'app');

        return response()->json(['id' => $note->id, 'app' => $app->key], $note->wasRecentlyCreated ? 201 : 200);
    }
}
