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
                // Tier, aliases and "assignable" (SPEC-roles §7). An unknown tier is ignored (role shows "No tier").
                $tier = isset($r['tier']) && array_key_exists($r['tier'], AppRole::TIERS) ? $r['tier'] : null;
                $extras = ['aliases' => AppRole::cleanAliases($r['aliases'] ?? [], $r['key']) ?: null,
                    'assignable' => ! array_key_exists('assignable', $r) || (bool) $r['assignable']];
                $role = AppRole::where('app_id', $app->id)->where('key', $r['key'])->first();
                if (! $role) {
                    $role = AppRole::create(['app_id' => $app->id, 'key' => $r['key'], 'name' => $r['name'] ?? ucfirst($r['key']),
                        'description' => $r['description'] ?? null, 'source' => 'manifest', 'position' => $i,
                        'tier' => $tier, 'tier_source' => $tier ? 'manifest' : null] + $extras);
                    $role->permissions()->sync($wanted);
                    $summary['roles_created'][] = $r['key'];
                } elseif ($role->edited) {
                    $have = $role->permissions()->whereNull('deprecated_at')->pluck('key')->sort()->values()->all();
                    $want = $all->flip()->only($wanted)->values()->sort()->values()->all();
                    if ($have !== $want) {
                        $summary['diffs'][$r['key']] = ['manifest' => $want, 'clauth' => $have];
                    }
                    $role->update($extras);
                } else {
                    $role->update(['name' => $r['name'] ?? $role->name, 'position' => $i] + $extras);
                    $role->permissions()->sync($wanted);
                    $summary['roles_updated'][] = $r['key'];
                }
                // A tier set by hand in Clauth wins; the sync only reports the difference.
                if ($tier && $role->tier_source === 'manual' && $role->tier !== $tier) {
                    $summary['diffs'][$r['key']]['tier'] = ['manifest' => $tier, 'clauth' => $role->tier];
                } elseif ($tier && $role->tier_source !== 'manual' && $role->tier !== $tier) {
                    $role->update(['tier' => $tier, 'tier_source' => 'manifest']);
                }
            }

            // An app with no roles at all gets one "Access" role, so profiles can simply turn it on or off (SPEC-roles §7).
            if (array_key_exists('roles', $manifest) && $manifest['roles'] === [] && ! AppRole::where('app_id', $app->id)->exists()) {
                self::ensureAccessRole($app);
                $summary['roles_created'][] = 'access';
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

    /** The single role of an app that has none of its own: tier member, no permissions, source system. */
    public static function ensureAccessRole(Application $app): AppRole
    {
        return AppRole::firstOrCreate(['app_id' => $app->id, 'key' => 'access'], [
            'name' => 'Access', 'description' => 'Can use the app (it has no roles of its own).',
            'source' => 'system', 'tier' => 'member', 'tier_source' => 'manual', 'position' => 0,
        ]);
    }
}
