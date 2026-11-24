<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\OpenApi;

/** GET /api/v1/openapi.json — public, built from the router (SPEC-platform §5). */
class OpenApiController
{
    public function __invoke()
    {
        return response()->json(OpenApi::document(), 200, ['Cache-Control' => 'public, max-age=300', 'Access-Control-Allow-Origin' => '*'],
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
