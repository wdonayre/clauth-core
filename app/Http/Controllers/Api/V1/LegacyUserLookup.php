<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\UserController as AppLookup;
use App\Http\Middleware\ApiClient;
use App\Models\OAuthClient;
use App\Services\SigningKeys;
use Illuminate\Http\Request;

/**
 * GET /api/v1/users/{id} serves two callers: an app's m2m client (users:read, the original lookup, SPEC §9)
 * and an API client (the platform API). The token's client type decides.
 */
class LegacyUserLookup
{
    public function __invoke(Request $request, string $user)
    {
        $t = SigningKeys::verify((string) $request->bearerToken());
        $client = $t ? OAuthClient::find($t->claims()->get('client_id')) : null;
        if (! $client?->isApi()) {
            return app(AppLookup::class)->show($request, $user);
        }

        return app(ApiClient::class)->handle($request, fn () => app(UsersController::class)->show($user), 'users:read');
    }
}
