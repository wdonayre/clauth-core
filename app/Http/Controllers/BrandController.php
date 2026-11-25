<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Brand;
use Illuminate\Support\Facades\Storage;

/** Public copy of an organization's logo for branded sign-in pages and emails (SPEC-branding §3). */
class BrandController
{
    public function logo(string $org)
    {
        $o = Organization::where('slug', $org)->first();
        abort_unless($o && $o->logo_path && Brand::logoIsPublic($o) && Storage::disk('local')->exists($o->logo_path), 404);

        return response()->file(Storage::disk('local')->path($o->logo_path), ['Cache-Control' => 'public, max-age=86400',
            'Content-Type' => str_ends_with($o->logo_path, '.svg') ? 'image/svg+xml' : 'image/png',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'", 'X-Content-Type-Options' => 'nosniff']);
    }
}
