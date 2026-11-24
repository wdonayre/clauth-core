<?php

namespace App\Http\Controllers\Admin;

use App\Models\Organization;
use App\Models\SigninTheme;
use App\Services\Audit;
use App\Services\Theme;
use App\Services\Themes;
use Illuminate\Http\Request;

/** Organization › Themes (SPEC-platform §8.4): design sign-in page looks for the organization's apps and domains. */
class ThemeController
{
    private function org(string $org): ?Organization
    {
        $me = auth()->user();
        if ($org === 'platform') {
            abort_unless($me->isOwner(), 404);

            return null;
        }
        abort_unless($me->isOwner() || in_array($org, $me->devOrgIds(), true), 404);

        return Organization::findOrFail($org);
    }

    private function find(?Organization $o, int $id): SigninTheme
    {
        return SigninTheme::where('organization_id', $o?->id)->findOrFail($id);
    }

    public function index(Request $request, string $org)
    {
        $o = $this->org($org);
        $themes = SigninTheme::where('organization_id', $o?->id)->orderBy('name')->get();
        $edit = $request->query('edit') ? $themes->firstWhere('id', (int) $request->query('edit')) : null;

        return view('admin.themes', ['org' => $o, 'orgKey' => $o?->id ?? 'platform', 'themes' => $themes, 'edit' => $edit,
            'fonts' => Theme::FONTS, 'bases' => Theme::NAMES,
            'preview' => $edit ? Theme::designed($edit, null) : null]);
    }

    private function input(Request $request): array
    {
        $in = $request->only(array_keys(Theme::SETTINGS));

        return array_filter($in, fn ($v) => $v !== null && $v !== '');
    }

    public function store(Request $request, string $org)
    {
        $o = $this->org($org);
        $request->validate(['name' => 'required|string|max:80']);
        $t = Themes::create($o, $request->input('name'), Themes::validate($this->input($request)), $request->user());

        return redirect()->route('admin.themes.index', [$o?->id ?? 'platform', 'edit' => $t->id])->with('status', "Theme “{$t->name}” created.");
    }

    public function update(Request $request, string $org, int $theme)
    {
        $o = $this->org($org);
        $t = $this->find($o, $theme);
        $request->validate(['name' => 'required|string|max:80']);
        $settings = Themes::validate($this->input($request));
        $before = $t->settings;
        $t->update(['name' => $request->input('name'), 'settings' => $settings]);
        Audit::log('theme.updated', 'app', $t, $before, $settings, orgId: $o?->id);

        return back()->with('status', 'Theme saved. Apps and domains using it show it from the next page load.');
    }

    public function destroy(string $org, int $theme)
    {
        $o = $this->org($org);
        $t = $this->find($o, $theme);
        $t->delete(); // apps/domains fall back to their built-in style (FK nullOnDelete)
        Audit::log('theme.deleted', 'app', $t, before: ['key' => $t->key], orgId: $o?->id);

        return redirect()->route('admin.themes.index', $o?->id ?? 'platform')->with('status', "Theme “{$t->name}” deleted.");
    }
}
