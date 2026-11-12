<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\OAuthClient;
use App\Models\Report;
use App\Services\Theme;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Public "Report this page" (SPEC-organizations §10.3): anyone can flag a sign-in page; Clauth owners review it. */
class ReportController
{
    private function flowApp(Request $request): ?Application
    {
        $id = $request->session()->get('login.client_id');

        return $id ? OAuthClient::with('app.publisher')->find($id)?->app : $request->attributes->get('clauth.domain')?->brandApp();
    }

    public function show(Request $request)
    {
        $app = $this->flowApp($request);

        return view('pages.report', ['app' => $app, 'theme' => Theme::for($app), 'reasons' => Report::REASONS, 'from' => url()->previous()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['reason' => ['required', Rule::in(array_keys(Report::REASONS))], 'details' => 'nullable|string|max:2000', 'url' => 'nullable|string|max:1000']);
        Report::create([
            'host' => strtolower($request->getHost()), 'url' => $data['url'] ?? null, 'app_id' => $this->flowApp($request)?->id,
            'reason' => $data['reason'], 'details' => $data['details'] ?? null, 'reporter_user_id' => $request->user()?->id,
            'reporter_ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
        ]);

        return view('auth.error', ['title' => 'Thanks for telling us', 'message' => 'Clauth will look at this page. Don’t enter your password on a page you don’t trust.', 'theme' => Theme::for(null)]);
    }
}
