<?php

namespace App\Http\Controllers;

/** Serves the Clauth bar web component (resources/bar/v1.js) with a short cache so updates reach every app. */
class BarScriptController
{
    public function __invoke()
    {
        return response(file_get_contents(resource_path('bar/v1.js')), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
            'Access-Control-Allow-Origin' => '*',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
