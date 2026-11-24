<?php

namespace App\Http\Controllers\Admin;

use App\Models\Application;
use App\Models\Connection;
use App\Models\Organization;
use App\Services\SigninPolicies;
use Illuminate\Http\Request;

/**
 * Sign-in rules (SPEC-platform §8.1) for an app (App detail › Sign-in rules), an organization's default for its apps,
 * or the platform (Clauth owners). Every save is a version that can be restored.
 */
class SigninPolicyController
{
    use Scope;

    /** [app, org] for the scope in the URL; the platform is [null, null]. */
    private function target(string $kind, ?string $ref): array
    {
        $me = auth()->user();

        return match ($kind) {
            'app' => tap([Application::where('key', $ref)->firstOrFail(), null], fn ($t) => abort_unless($me->canManageApp($t[0]), 404)),
            'org' => tap([null, Organization::findOrFail($ref)], fn ($t) => abort_unless($this->canManageOrg($t[1]->id), 404)),
            default => tap([null, null], fn () => abort_unless($me->isOwner(), 404)),
        };
    }

    public function show(string $kind, ?string $ref = null)
    {
        [$app, $org] = $this->target($kind, $ref);
        $own = $app ? SigninPolicies::find($app, null) : SigninPolicies::find(null, $org);
        [$rules, $source] = $app ? SigninPolicies::effective($app) : [array_merge(SigninPolicies::DEFAULTS, $own?->rules ?? []), $own ? ($org ? 'org' : 'platform') : 'default'];
        $publisher = $app?->publisher ?? $org;

        return view('admin.signin-policy', [
            'kind' => $kind, 'ref' => $ref, 'app' => $app, 'org' => $org, 'own' => $own, 'rules' => $rules, 'source' => $source,
            'connections' => Connection::whereIn('type', ['oidc', 'oauth', 'meta'])->where(fn ($q) => $q->whereNull('organization_id')->when($publisher, fn ($w) => $w->orWhere('organization_id', $publisher->id)))->orderBy('name')->get(),
            'checks' => Connection::where('type', 'http')->where(fn ($q) => $q->whereNull('organization_id')->when($publisher, fn ($w) => $w->orWhere('organization_id', $publisher->id)))->orderBy('name')->get(),
            'profiles' => $app?->publisher?->accessProfiles()->get() ?? collect(),
            'versions' => $own?->versions()->with('changer')->limit(20)->get() ?? collect(),
            // Whether Settings / the server have each method switched on at all (rules can only narrow them).
            'available' => [
                'password' => true,
                'google' => \App\Http\Controllers\Auth\SocialController::enabled('google'),
                'email_code' => (bool) \App\Services\Settings::get('email_code_signin'),
                'sms_code' => \App\Services\Settings::get('sms_signin') && \App\Services\Sms::available(),
            ],
        ]);
    }

    public function update(Request $request, string $kind, ?string $ref = null)
    {
        [$app, $org] = $this->target($kind, $ref);
        $in = $request->except(['_token', '_method']);
        foreach (['password', 'google', 'email_code', 'sms_code', 'sms_second_step', 'check_fail_open'] as $b) {
            $in[$b] = $request->boolean($b);
        }
        $in['connections'] = $request->input('connections_mode') === 'some' ? array_values((array) $request->input('connections', [])) : 'all';
        $in['open_profile'] = $request->input('open_profile') ?: null;
        $in['check'] = $request->input('check') ?: null;
        $rules = SigninPolicies::validate($in, $app, $org);
        $p = SigninPolicies::save($app, $org, $rules, $request->user());

        return back()->with('status', "Sign-in rules saved (version {$p->version}). They apply to the next sign-in.");
    }

    public function restore(Request $request, string $kind, ?string $ref = null)
    {
        [$app, $org] = $this->target($kind, $ref);
        $own = $app ? SigninPolicies::find($app, null) : SigninPolicies::find(null, $org);
        abort_unless($own, 404);
        $p = SigninPolicies::restore($own, (int) $request->input('version'), $request->user());

        return back()->with('status', "Version {$request->input('version')} restored as version {$p->version}.");
    }

    public function reset(Request $request, string $kind, ?string $ref = null)
    {
        [$app, $org] = $this->target($kind, $ref);
        SigninPolicies::reset($app, $org, $request->user());

        return back()->with('status', 'Own rules removed: the '.($app ? 'organization’s or platform’s' : 'platform’s').' rules apply now.');
    }

    // Route entry points (no closures in routes: route:cache).
    public function showApp(string $app)
    {
        return $this->show('app', $app);
    }

    public function updateApp(Request $r, string $app)
    {
        return $this->update($r, 'app', $app);
    }

    public function restoreApp(Request $r, string $app)
    {
        return $this->restore($r, 'app', $app);
    }

    public function resetApp(Request $r, string $app)
    {
        return $this->reset($r, 'app', $app);
    }

    public function showOrg(string $org)
    {
        return $this->show('org', $org);
    }

    public function updateOrg(Request $r, string $org)
    {
        return $this->update($r, 'org', $org);
    }

    public function restoreOrg(Request $r, string $org)
    {
        return $this->restore($r, 'org', $org);
    }

    public function resetOrg(Request $r, string $org)
    {
        return $this->reset($r, 'org', $org);
    }

    public function showPlatform()
    {
        return $this->show('platform');
    }

    public function updatePlatform(Request $r)
    {
        return $this->update($r, 'platform');
    }

    public function restorePlatform(Request $r)
    {
        return $this->restore($r, 'platform');
    }

    public function resetPlatform(Request $r)
    {
        return $this->reset($r, 'platform');
    }
}
