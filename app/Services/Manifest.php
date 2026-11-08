<?php

namespace App\Services;

use App\Models\Application;
use App\Models\AppPermission;
use App\Models\AppRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Syncs an app's `/.well-known/clauth.json` into permissions + roles (SPEC §7). */
class Manifest
{
    public static function fetch(Application $app): array
    {
        if (! $app->manifest_url) {
            throw new RuntimeException('This app has no manifest URL.');
        }
        $res = Http::timeout(10)->acceptJson()->get($app->manifest_url);
        if (! $res->ok() || ! is_array($res->json())) {
            throw new RuntimeException("Manifest request failed (HTTP {$res->status()}).");
        }

        return $res->json();
    }

    /** Returns a summary: added/deprecated permissions, created roles, role diffs. */
    public static function apply(Application $app, array $manifest): array
    {
        if (($manifest['app'] ?? null) !== $app->key) {
            throw new RuntimeException('Manifest is for "'.($manifest['app'] ?? '?')."\", not \"{$app->key}\".");
        }
        $perms = collect($manifest['permissions'] ?? [])->filter(fn ($p) => is_array($p) && ! empty($p['key']))->keyBy('key');
        $summary = ['added' => [], 'deprecated' => [], 'roles_created' => [], 'roles_updated' => [], 'diffs' => []];

        DB::transaction(function () use ($app, $manifest, $perms, &$summary) {
            foreach ($perms as $key => $p) {
                $perm = AppPermission::firstOrNew(['app_id' => $app->id, 'key' => $key]);
                if (! $perm->exists || $perm->deprecated_at) {
                    $summary['added'][] = $key;
                }
                $perm->fill(['description' => $p['description'] ?? $perm->description, 'source' => 'manifest', 'deprecated_at' => null])->save();
            }
            foreach (AppPermission::where('app_id', $app->id)->where('source', 'manifest')->whereNull('deprecated_at')->get() as $perm) {
                if (! $perms->has($perm->key)) {
                    $perm->update(['deprecated_at' => now()]);
                    DB::table('app_role_permission')->where('app_permission_id', $perm->id)->delete();
                    $summary['deprecated'][] = $perm->key;
                }
            }

            $all = AppPermission::where('app_id', $app->id)->whereNull('deprecated_at')->pluck('id', 'key');
            foreach (array_values($manifest['roles'] ?? []) as $i => $r) {
                if (empty($r['key'])) {
                    continue;
                }
                $wanted = in_array('*', $r['permissions'] ?? [], true)
                    ? $all->values()->all()
                    : $all->only($r['permissions'] ?? [])->values()->all();
                $role = AppRole::where('app_id', $app->id)->where('key', $r['key'])->first();
                if (! $role) {
                    $role = AppRole::create(['app_id' => $app->id, 'key' => $r['key'], 'name' => $r['name'] ?? ucfirst($r['key']),
                        'description' => $r['description'] ?? null, 'source' => 'manifest', 'position' => $i]);
                    $role->permissions()->sync($wanted);
                    $summary['roles_created'][] = $r['key'];
                } elseif ($role->edited) {
                    $have = $role->permissions()->whereNull('deprecated_at')->pluck('key')->sort()->values()->all();
                    $want = $all->flip()->only($wanted)->values()->sort()->values()->all();
                    if ($have !== $want) {
                        $summary['diffs'][$r['key']] = ['manifest' => $want, 'clauth' => $have];
                    }
                } else {
                    $role->update(['name' => $r['name'] ?? $role->name, 'position' => $i]);
                    $role->permissions()->sync($wanted);
                    $summary['roles_updated'][] = $r['key'];
                }
            }

            $app->update([
                'manifest_synced_at' => now(),
                'manifest_hash' => hash('sha256', json_encode($manifest)),
                'manifest_diff' => $summary['diffs'] ?: null,
            ]);
        });

        Audit::log('app.manifest_synced', 'app', $app, after: $summary, appId: $app->id);

        return $summary;
    }
}
