<?php

namespace App\Services;

use App\Models\AccessProfile;
use App\Models\Application;
use App\Models\AppPermission;
use App\Models\AppRole;
use App\Models\Connection;
use App\Models\Organization;
use App\Models\OrgAttribute;
use App\Models\SigninTheme;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Config as code (SPEC-platform §9.4): export an organization's setup as one JSON document — teams, profile fields,
 * access profiles (with their role per app), sign-in rules, the apps it publishes (roles, hand-made permissions,
 * sign-in rules, look), themes, messages and connections — and import it into the same or another Clauth (staging →
 * production). Secrets never leave: they export as ${secret:NAME} and import from the target's secrets store.
 * Apps must already exist on the target (they carry clients and domains); everything else is created or updated by key.
 * People, grants and agents are not part of config.
 */
class ConfigTransfer
{
    public const VERSION = 1;

    public static function export(Organization $org): array
    {
        $apps = Application::where('owner_organization_id', $org->id)->orderBy('key')->get();
        $installed = $org->apps()->pluck('apps.key', 'apps.id');
        $secretName = fn (Connection $c, string $f) => strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $c->name.'_'.$f));

        return [
            'clauth_export' => self::VERSION, 'org' => $org->slug, 'exported_at' => now()->toIso8601String(),
            'teams' => $org->teams()->get()->map(fn ($t) => $t->only('key', 'name', 'description'))->values()->all(),
            'fields' => $org->attributeDefs()->get()->map(fn ($a) => $a->only('key', 'label', 'type', 'options', 'required', 'editable_by', 'in_token'))->values()->all(),
            'profiles' => $org->accessProfiles()->get()->map(function (AccessProfile $p) use ($installed) {
                $roles = AppRole::whereIn('id', array_filter($p->mapping()))->pluck('key', 'id');

                return $p->only('key', 'name', 'description') + ['apps' => (object) collect($p->mapping())
                    ->mapWithKeys(fn ($roleId, $appId) => [$installed[$appId] ?? $appId => $roleId ? ($roles[$roleId] ?? null) : 'none'])->all()];
            })->values()->all(),
            'policy' => SigninPolicies::find(null, $org)?->rules,
            'apps' => $apps->map(fn (Application $a) => [
                'key' => $a->key, 'name' => $a->name, 'home_url' => $a->home_url, 'manifest_url' => $a->manifest_url, 'color' => $a->color,
                'visibility' => $a->visibility, 'require_mfa_roles' => $a->require_mfa_roles ?? [],
                'look' => ['login_theme' => $a->login_theme, 'theme' => SigninTheme::find($a->signin_theme_id)?->name, 'accent_color' => $a->accent_color],
                'policy' => SigninPolicies::find($a, null)?->rules,
                'permissions' => AppPermission::where('app_id', $a->id)->where('source', 'manual')->whereNull('deprecated_at')->orderBy('key')->get(['key', 'description'])->toArray(),
                'roles' => $a->roles()->with('permissions')->orderBy('position')->get()->map(fn (AppRole $r) => $r->only('key', 'name', 'description', 'tier', 'assignable', 'aliases', 'source')
                    + ['permissions' => $r->permissionKeys()])->values()->all(),
            ])->values()->all(),
            'themes' => SigninTheme::where('organization_id', $org->id)->orderBy('name')->get()->map(fn ($t) => ['name' => $t->name, 'settings' => $t->settings])->values()->all(),
            'templates' => DB::table('message_templates')->where('organization_id', $org->id)->orderBy('key')->get(['key', 'locale', 'subject', 'body'])->map(fn ($r) => (array) $r)->values()->all(),
            'connections' => Connection::where('organization_id', $org->id)->orderBy('name')->get()->map(function (Connection $c) use ($secretName) {
                $cfg = $c->config ?? [];
                foreach (Connections::secretFields($c->type) as $f) {
                    if (isset($cfg[$f])) {
                        $cfg[$f] = '${secret:'.$secretName($c, $f).'}';
                    }
                }

                return ['type' => $c->type, 'name' => $c->name, 'status' => $c->status, 'config' => $cfg];
            })->values()->all(),
        ];
    }

    /**
     * Plan (and with $apply, carry out) importing $doc into $org. Returns ['changes' => [[op, kind, key, note?]], 'errors' => [..]].
     * Nothing is written when there are errors.
     */
    public static function import(Organization $org, array $doc, bool $apply, User $actor): array
    {
        $changes = [];
        $errors = [];
        if (($doc['clauth_export'] ?? null) !== self::VERSION) {
            return ['changes' => [], 'errors' => ['This is not a Clauth export (clauth_export: 1).']];
        }
        $resolve = function ($v) use ($org, &$errors) {
            if (! is_string($v) || ! preg_match('/^\$\{(secret|var):([A-Z0-9_]+)\}$/', $v, $m)) {
                return $v;
            }
            $row = DB::table('config_values')->where('name', $m[2])->where('secret', $m[1] === 'secret')
                ->where(fn ($q) => $q->where('organization_id', $org->id)->orWhereNull('organization_id'))->orderByRaw('organization_id is null')->first();
            if (! $row) {
                $errors[] = "Missing {$m[1]} {$m[2]} on this Clauth (add it under /api/v1/".($m[1] === 'secret' ? 'secrets' : 'variables').').';

                return null;
            }

            return $row->secret ? Crypt::decryptString($row->value) : $row->value;
        };

        // Validate first: apps must exist here and belong to this organization.
        $apps = [];
        foreach ($doc['apps'] ?? [] as $a) {
            $app = Application::where('key', $a['key'] ?? '')->first();
            if (! $app || $app->owner_organization_id !== $org->id) {
                $errors[] = "App {$a['key']} doesn’t exist here (or isn’t {$org->slug}’s): create it first, then import again.";

                continue;
            }
            $apps[] = [$app, $a];
        }
        foreach ($doc['connections'] ?? [] as $i => $c) {
            $doc['connections'][$i]['config'] = collect($c['config'] ?? [])->map($resolve)->all();
        }
        foreach ($doc['fields'] ?? [] as $f) {
            if (! isset(OrgAttribute::TYPES[$f['type'] ?? ''])) {
                $errors[] = "Field {$f['key']} has an unknown type.";
            }
        }
        if ($errors) {
            return ['changes' => [], 'errors' => array_values(array_unique($errors))];
        }

        $run = function () use ($org, $doc, $apps, $actor, &$changes, $apply) {
            $note = function (string $kind, string $key, bool $exists, bool $same) use (&$changes) {
                $changes[] = ['op' => $same ? 'unchanged' : ($exists ? 'update' : 'create'), 'kind' => $kind, 'key' => $key];

                return ! $same;
            };
            foreach ($doc['teams'] ?? [] as $t) {
                $row = Team::where('organization_id', $org->id)->where('key', $t['key'])->first();
                if ($note('team', $t['key'], (bool) $row, $row && $row->name === $t['name'] && $row->description === ($t['description'] ?? null)) && $apply) {
                    Team::updateOrCreate(['organization_id' => $org->id, 'key' => $t['key']], ['name' => $t['name'], 'description' => $t['description'] ?? null]);
                }
            }
            foreach ($doc['fields'] ?? [] as $f) {
                $row = OrgAttribute::where('organization_id', $org->id)->where('key', $f['key'])->first();
                $vals = array_intersect_key($f, array_flip(['label', 'type', 'options', 'required', 'editable_by', 'in_token']));
                if ($note('field', $f['key'], (bool) $row, $row && collect($vals)->every(fn ($v, $k) => $row->{$k} == $v)) && $apply) {
                    OrgAttribute::updateOrCreate(['organization_id' => $org->id, 'key' => $f['key']], $vals);
                }
            }
            foreach ($apps as [$app, $a]) {
                foreach ($a['permissions'] ?? [] as $p) {
                    $row = AppPermission::where('app_id', $app->id)->where('key', $p['key'])->first();
                    if ($note('permission', "{$app->key}:{$p['key']}", (bool) $row, (bool) $row) && $apply) {
                        AppPermission::create(['app_id' => $app->id, 'key' => $p['key'], 'description' => $p['description'] ?? null, 'source' => 'manual']);
                    }
                }
                foreach ($a['roles'] ?? [] as $i => $r) {
                    $row = AppRole::where('app_id', $app->id)->where('key', $r['key'])->first();
                    $same = $row && $row->tier === ($r['tier'] ?? null) && ($row->source === 'manifest' || ($row->name === $r['name'] && $row->permissionKeys() == ($r['permissions'] ?? [])));
                    if ($note('role', "{$app->key}:{$r['key']}", (bool) $row, $same) && $apply) {
                        if ($row?->source === 'manifest') {
                            $row->update(['tier' => $r['tier'] ?? null, 'tier_source' => ($r['tier'] ?? null) ? 'manual' : null]); // the manifest owns the rest
                        } else {
                            $role = AppRole::updateOrCreate(['app_id' => $app->id, 'key' => $r['key']], ['name' => $r['name'], 'description' => $r['description'] ?? null,
                                'tier' => $r['tier'] ?? null, 'assignable' => $r['assignable'] ?? true, 'aliases' => $r['aliases'] ?? null, 'source' => 'manual', 'position' => $i]);
                            $role->permissions()->sync(AppPermission::where('app_id', $app->id)->whereIn('key', $r['permissions'] ?? [])->pluck('id'));
                        }
                    }
                }
                $look = $a['look'] ?? [];
                $themeId = ! empty($look['theme']) ? SigninTheme::where('organization_id', $org->id)->where('name', $look['theme'])->value('id') : null;
                $sameLook = $app->login_theme === ($look['login_theme'] ?? 'classic') && $app->accent_color === ($look['accent_color'] ?? null) && (empty($look['theme']) || $app->signin_theme_id === $themeId);
                if ($note('app look', $app->key, true, $sameLook) && $apply) {
                    $app->update(['login_theme' => $look['login_theme'] ?? 'classic', 'accent_color' => $look['accent_color'] ?? null]);
                    // the theme is linked after themes are imported (below)
                }
                $p = SigninPolicies::find($app, null);
                if (($a['policy'] ?? null) !== null && $note('app sign-in rules', $app->key, (bool) $p, $p && $p->rules == $a['policy']) && $apply) {
                    SigninPolicies::save($app, null, SigninPolicies::validate($a['policy'], $app, null), $actor, 'Imported');
                }
            }
            foreach ($doc['profiles'] ?? [] as $pd) {
                $row = AccessProfile::where('organization_id', $org->id)->where('key', $pd['key'])->first();
                $want = collect((array) ($pd['apps'] ?? []))->mapWithKeys(function ($roleKey, $appKey) use ($org) {
                    $app = $org->apps()->where('apps.key', $appKey)->first();

                    return $app ? [$app->id => $roleKey === 'none' ? null : AppRole::where('app_id', $app->id)->where('key', $roleKey)->value('id')] : [];
                })->all();
                $same = $row && $row->name === $pd['name'] && $row->mapping() == $want;
                if ($note('profile', $pd['key'], (bool) $row, $same) && $apply) {
                    $p = AccessProfile::updateOrCreate(['organization_id' => $org->id, 'key' => $pd['key']], ['name' => $pd['name'], 'description' => $pd['description'] ?? null]);
                    DB::table('access_profile_roles')->where('access_profile_id', $p->id)->delete();
                    foreach ($want as $appId => $roleId) {
                        DB::table('access_profile_roles')->insert(['access_profile_id' => $p->id, 'app_id' => $appId, 'app_role_id' => $roleId, 'updated_by' => $actor->id, 'updated_at' => now()]);
                    }
                    Access::resolveHolders($p, 'imported config', $actor);
                }
            }
            if (($doc['policy'] ?? null) !== null) {
                $p = SigninPolicies::find(null, $org);
                if ($note('organization sign-in rules', $org->slug, (bool) $p, $p && $p->rules == $doc['policy']) && $apply) {
                    SigninPolicies::save(null, $org, SigninPolicies::validate($doc['policy'], null, $org), $actor, 'Imported');
                }
            }
            foreach ($doc['themes'] ?? [] as $t) {
                $row = SigninTheme::where('organization_id', $org->id)->where('name', $t['name'])->first();
                if ($note('theme', $t['name'], (bool) $row, $row && $row->settings == $t['settings']) && $apply) {
                    $row ? $row->update(['settings' => Themes::validate($t['settings'])]) : Themes::create($org, $t['name'], Themes::validate($t['settings']), $actor);
                }
            }
            if ($apply) {
                foreach ($apps as [$app, $a]) {
                    if (! empty($a['look']['theme']) && ($tid = SigninTheme::where('organization_id', $org->id)->where('name', $a['look']['theme'])->value('id'))) {
                        $app->update(['signin_theme_id' => $tid]);
                    }
                }
            }
            foreach ($doc['templates'] ?? [] as $t) {
                $row = DB::table('message_templates')->where(['organization_id' => $org->id, 'key' => $t['key'], 'locale' => $t['locale']])->first();
                if ($note('message', "{$t['key']}:{$t['locale']}", (bool) $row, $row && $row->subject === $t['subject'] && $row->body === $t['body']) && $apply) {
                    DB::table('message_templates')->updateOrInsert(['organization_id' => $org->id, 'key' => $t['key'], 'locale' => $t['locale']],
                        ['subject' => $t['subject'], 'body' => $t['body'], 'updated_by' => $actor->id, 'updated_at' => now(), 'created_at' => now()]);
                }
            }
            foreach ($doc['connections'] ?? [] as $c) {
                $row = Connection::where('organization_id', $org->id)->where('type', $c['type'])->where('name', $c['name'])->first();
                if ($note('connection', $c['name'], (bool) $row, $row && $row->config == $c['config'] && $row->status === ($c['status'] ?? 'active')) && $apply) {
                    $cfg = Connections::config($c['type'], true, $c['config'], $row);
                    $row ? $row->update(['config' => $cfg, 'status' => $c['status'] ?? 'active'])
                        : Connection::create(['organization_id' => $org->id, 'type' => $c['type'], 'key' => Connections::newKey($c['name'], $org), 'name' => $c['name'],
                            'config' => $cfg, 'status' => $c['status'] ?? 'active', 'created_by' => $actor->id]);
                }
            }
        };
        $apply ? DB::transaction($run) : $run();
        if ($apply) {
            Audit::log('config.imported', 'admin', $org, after: ['changes' => count(array_filter($changes, fn ($c) => $c['op'] !== 'unchanged'))], orgId: $org->id, actorId: $actor->id);
        }

        return ['changes' => $changes, 'errors' => []];
    }
}
