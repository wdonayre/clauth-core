<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Audit;
use App\Services\ConfigTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Config as code (SPEC-platform §9.4): /export, /import, /variables, /secrets. */
class ConfigController
{
    use UsesApi;

    public function export(Request $request)
    {
        $request->validate(['org' => 'required|string']);
        $org = $this->api()->org((string) $request->query('org'));
        Audit::log('config.exported', 'admin', $org, orgId: $org->id);

        return response()->json(ConfigTransfer::export($org), 200, ['Content-Disposition' => 'attachment; filename="clauth-'.$org->slug.'-'.now()->format('Ymd-His').'.json"'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** POST {org, document, dry_run: true by default} — shows what would change; send dry_run=false to apply. */
    public function import(Request $request)
    {
        $data = $request->validate(['org' => 'required|string', 'document' => 'required|array', 'dry_run' => 'boolean']);
        $org = $this->api()->org($data['org']);
        $apply = array_key_exists('dry_run', $data) && $data['dry_run'] === false;
        $out = ConfigTransfer::import($org, $data['document'], $apply, $this->api()->actor);

        return response()->json(['applied' => $apply && ! $out['errors'], 'errors' => $out['errors'],
            'summary' => collect($out['changes'])->countBy('op'), 'changes' => $out['changes']], $out['errors'] ? 422 : 200);
    }

    private function scope(Request $request): ?string
    {
        if ($request->filled('org')) {
            return $this->api()->org((string) $request->input('org'))->id;
        }
        abort_unless($this->api()->isPlatform(), 422, 'Say which organization (org); platform values are for platform clients.');

        return null;
    }

    public function list(Request $request, string $kind)
    {
        $orgId = $request->filled('org') ? $this->api()->org((string) $request->query('org'))->id : ($this->api()->isPlatform() ? null : abort(422, 'Say which organization (org).'));
        $rows = DB::table('config_values')->where('secret', $kind === 'secrets')->where('organization_id', $orgId)->orderBy('name')->get();

        return response()->json(['data' => $rows->map(fn ($r) => ['name' => $r->name] + ($r->secret ? ['set' => true] : ['value' => $r->value]) + ['updated_at' => $r->updated_at])->values()]);
    }

    /** PUT /variables/{name} or /secrets/{name} {value, org?}. Secrets are stored encrypted and never returned. */
    public function put(Request $request, string $kind, string $name)
    {
        abort_unless(preg_match('/^[A-Z0-9_]{1,80}$/', $name), 422, 'Names are CAPITALS, digits and underscores.');
        $request->validate(['value' => 'required|string|max:5000']);
        $orgId = $this->scope($request);
        $secret = $kind === 'secrets';
        DB::table('config_values')->updateOrInsert(['organization_id' => $orgId, 'name' => $name],
            ['secret' => $secret, 'value' => $secret ? Crypt::encryptString($request->input('value')) : $request->input('value'), 'updated_at' => now(), 'created_at' => now()]);
        Audit::log($secret ? 'config.secret_set' : 'config.variable_set', 'admin', null, after: ['name' => $name], orgId: $orgId);

        return response()->json(['name' => $name] + ($secret ? ['set' => true] : ['value' => $request->input('value')]));
    }

    public function delete(Request $request, string $kind, string $name)
    {
        $orgId = $this->scope($request);
        DB::table('config_values')->where(['organization_id' => $orgId, 'name' => $name, 'secret' => $kind === 'secrets'])->delete();

        return response()->noContent();
    }

    public function listVariables(Request $r)
    {
        return $this->list($r, 'variables');
    }

    public function listSecrets(Request $r)
    {
        return $this->list($r, 'secrets');
    }

    public function putVariable(Request $r, string $name)
    {
        return $this->put($r, 'variables', $name);
    }

    public function putSecret(Request $r, string $name)
    {
        return $this->put($r, 'secrets', $name);
    }

    public function deleteVariable(Request $r, string $name)
    {
        return $this->delete($r, 'variables', $name);
    }

    public function deleteSecret(Request $r, string $name)
    {
        return $this->delete($r, 'secrets', $name);
    }
}
