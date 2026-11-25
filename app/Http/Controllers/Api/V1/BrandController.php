<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Application;
use App\Models\OAuthClient;
use App\Services\Brand;
use Illuminate\Http\Request;

/**
 * GET /api/v1/brand?app=<key> | ?client_id=<id> — public: how an app should name the sign-in ("Continue with
 * CakesbyGrace Account"), with logo, color and addresses (SPEC-branding B3). Kits and SDKs read this.
 */
class BrandController
{
    public function __invoke(Request $request)
    {
        $app = $request->query('app') ? Application::where('key', (string) $request->query('app'))->first()
            : ($request->query('client_id') ? OAuthClient::with('app')->find((string) $request->query('client_id'))?->app : null);
        abort_unless($app, 404, 'Unknown app. Pass ?app=<key> or ?client_id=<id>.');

        return response()->json(Brand::publicJson($app))->header('Cache-Control', 'public, max-age=300')->header('Access-Control-Allow-Origin', '*');
    }
}
