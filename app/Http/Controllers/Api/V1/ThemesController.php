<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\Etag;
use App\Models\AppDomain;
use App\Models\SigninTheme;
use App\Services\Audit;
use App\Services\Theme;
use App\Services\Themes;
use Illuminate\Http\Request;

/** /api/v1/themes (SPEC-platform §8.4): built-in styles plus designed themes of the caller's organizations. */
class ThemesController
{
    use UsesApi;

    private function visible()
    {
        $ids = $this->api()->publisherOrgIds();

        return SigninTheme::with('organization')->where(fn ($q) => $q->whereNull('organization_id')->when($ids !== null, fn ($w) => $w->orWhereIn('organization_id', $ids), fn ($w) => $w->orWhereNotNull('organization_id')));
    }

    private function find(string $key, bool $write = false): SigninTheme
    {
        $t = $this->visible()->where('key', $key)->first();
        abort_unless($t, 404, 'Theme not found.');
        abort_if($write && ! $t->organization_id && ! $this->api()->isPlatform(), 403, 'Platform themes are changed by platform clients only.');

        return $t;
    }

    private function json(SigninTheme $t): array
    {
        return ['key' => $t->key, 'name' => $t->name, 'org' => $t->organization?->slug, 'built_in' => false, 'settings' => $t->settings,
            'usages' => Themes::usages($t)];
    }

    public function index()
    {
        $builtIn = collect(Theme::NAMES)->map(fn ($n, $k) => ['key' => $k, 'name' => $n, 'org' => null, 'built_in' => true])->values();

        return response()->json(['data' => $builtIn->concat($this->visible()->orderBy('name')->get()->map(fn ($t) => $this->json($t)))->values()]);
    }

    public function show(string $key)
    {
        $t = $this->find($key);

        return Etag::attach(response()->json($this->json($t)), $t);
    }

    /** {name, org?: slug (omit: platform; platform clients only), base, accent, background, card, font, heading_font, radius, buttons, layout, tagline} */
    public function store(Request $request)
    {
        $org = $request->filled('org') ? $this->api()->org((string) $request->input('org')) : null;
        abort_if(! $org && ! $this->api()->isPlatform(), 422, 'Say which organization the theme belongs to (org).');
        abort_if($org && $this->api()->publisherOrgIds() !== null && ! in_array($org->id, $this->api()->publisherOrgIds(), true), 404, 'Organization not found.');
        $request->validate(['name' => 'required|string|max:80']);
        $t = Themes::create($org, $request->input('name'), Themes::validate($request->except(['name', 'org'])), $this->api()->actor);

        return response()->json($this->json($t->load('organization')), 201);
    }

    public function update(Request $request, string $key)
    {
        $t = $this->find($key, true);
        Etag::check($t);
        $request->validate(['name' => 'sometimes|required|string|max:80']);
        $settings = array_merge($t->settings, Themes::validate($request->except(['name', 'org']), true));
        Themes::validate($settings);
        $before = $t->settings;
        $t->update(['settings' => $settings] + $request->only('name'));
        Audit::log('theme.updated', 'app', $t, $before, $settings, orgId: $t->organization_id);

        return Etag::attach(response()->json($this->json($t->fresh('organization'))), $t->fresh());
    }

    public function destroy(string $key)
    {
        $t = $this->find($key, true);
        Etag::check($t);
        $t->delete();
        Audit::log('theme.deleted', 'app', $t, before: ['key' => $t->key], orgId: $t->organization_id);

        return response()->noContent();
    }

    public function usages(string $key)
    {
        return response()->json(Themes::usages($this->find($key)));
    }

    /** GET /themes/resolve?app=&domain= — what a sign-in page there will look like (the computed variables). */
    public function resolve(Request $request)
    {
        $app = $request->query('app') ? $this->api()->app((string) $request->query('app')) : null;
        $domain = $request->query('domain') ? AppDomain::where('host', strtolower((string) $request->query('domain')))->first() : null;
        abort_if($domain && $app && ! $domain->serves($app), 422, 'That domain doesn’t serve that app.');
        $app ??= $domain?->brandApp();
        abort_if($app && ! $this->api()->canManageApp($app) && $this->api()->scopeApps(\App\Models\Application::query())->whereKey($app->id)->doesntExist(), 404, 'App not found.');
        $designed = ! $app?->signin_theme_id && $domain?->signin_theme_id ? SigninTheme::find($domain->signin_theme_id) : null;
        $t = Theme::for($app, $designed);

        return response()->json(['style' => $t['style'], 'theme' => $t['designed']->key ?? null, 'dark' => $t['dark'], 'layout' => $t['layout'] ?? 'center',
            'fonts' => $t['fonts'] ?? [], 'vars' => $t['vars'], 'logo' => $t['logo']]);
    }
}
