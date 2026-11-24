<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Application;
use App\Models\Organization;
use App\Services\SigninPolicies;
use Illuminate\Http\Request;

/** Sign-in rules over the API (SPEC-platform §8.1): /apps/{app}/policy, /orgs/{org}/policy, /policy (platform). */
class PolicyController
{
    use UsesApi;

    private function target(string $kind, ?string $ref): array
    {
        $api = $this->api();
        if ($kind === 'app') {
            $a = $api->app($ref);
            abort_unless($api->canManageApp($a) || request()->isMethod('GET'), 403, 'Only the organization that publishes this app can change its sign-in rules.');

            return [$a, null];
        }
        if ($kind === 'org') {
            return [null, $api->org($ref)];
        }
        abort_unless($api->isPlatform(), 403, 'Platform sign-in rules are for platform clients only.');

        return [null, null];
    }

    private function json(?Application $app, ?Organization $org): array
    {
        $own = $app ? SigninPolicies::find($app, null) : SigninPolicies::find(null, $org);
        [$rules, $source] = $app ? SigninPolicies::effective($app) : [array_merge(SigninPolicies::DEFAULTS, $own?->rules ?? []), $own ? ($org ? 'org' : 'platform') : 'default'];

        return ['rules' => $rules, 'source' => $source, 'version' => $own?->version, 'own' => (bool) $own];
    }

    public function show(string $kind, ?string $ref = null)
    {
        [$app, $org] = $this->target($kind, $ref);

        return response()->json($this->json($app, $org));
    }

    /** PUT {rules…} — the whole rule set; missing keys take the built-in defaults. */
    public function update(Request $request, string $kind, ?string $ref = null)
    {
        [$app, $org] = $this->target($kind, $ref);
        $rules = SigninPolicies::validate($request->json()->all() ?: $request->all(), $app, $org);
        SigninPolicies::save($app, $org, $rules, $this->api()->actor);

        return response()->json($this->json($app, $org));
    }

    public function versions(string $kind, ?string $ref = null)
    {
        [$app, $org] = $this->target($kind, $ref);
        $own = $app ? SigninPolicies::find($app, null) : SigninPolicies::find(null, $org);

        return response()->json(['data' => ($own?->versions()->with('changer')->get() ?? collect())->map(fn ($v) => [
            'version' => $v->version, 'rules' => $v->rules, 'by' => $v->changer?->sub(), 'note' => $v->note, 'at' => $v->created_at?->toIso8601String()])->values()]);
    }

    public function restore(Request $request, string $kind, ?string $ref = null)
    {
        [$app, $org] = $this->target($kind, $ref);
        $own = $app ? SigninPolicies::find($app, null) : SigninPolicies::find(null, $org);
        abort_unless($own, 404, 'There are no saved rules here.');
        $request->validate(['version' => 'required|integer']);
        SigninPolicies::restore($own, (int) $request->input('version'), $this->api()->actor);

        return response()->json($this->json($app, $org));
    }

    public function destroy(string $kind, ?string $ref = null)
    {
        [$app, $org] = $this->target($kind, $ref);
        SigninPolicies::reset($app, $org, $this->api()->actor);

        return response()->noContent();
    }

    // Route entry points
    public function showApp(string $app)
    {
        return $this->show('app', $app);
    }

    public function updateApp(Request $r, string $app)
    {
        return $this->update($r, 'app', $app);
    }

    public function versionsApp(string $app)
    {
        return $this->versions('app', $app);
    }

    public function restoreApp(Request $r, string $app)
    {
        return $this->restore($r, 'app', $app);
    }

    public function destroyApp(string $app)
    {
        return $this->destroy('app', $app);
    }

    public function showOrg(string $org)
    {
        return $this->show('org', $org);
    }

    public function updateOrg(Request $r, string $org)
    {
        return $this->update($r, 'org', $org);
    }

    public function versionsOrg(string $org)
    {
        return $this->versions('org', $org);
    }

    public function restoreOrg(Request $r, string $org)
    {
        return $this->restore($r, 'org', $org);
    }

    public function destroyOrg(string $org)
    {
        return $this->destroy('org', $org);
    }

    public function showPlatform()
    {
        return $this->show('platform');
    }

    public function updatePlatform(Request $r)
    {
        return $this->update($r, 'platform');
    }

    public function versionsPlatform()
    {
        return $this->versions('platform');
    }

    public function restorePlatform(Request $r)
    {
        return $this->restore($r, 'platform');
    }

    public function destroyPlatform()
    {
        return $this->destroy('platform');
    }
}
