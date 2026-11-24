<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiContext;

/** The caller of this request, set by the api.client middleware (resolved lazily: controllers are built before middleware runs). */
trait UsesApi
{
    protected function api(): ApiContext
    {
        return app(ApiContext::class);
    }
}
