<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrgAttribute;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Teams and profile fields of an organization (SPEC-platform §6.3, §6.4). The only writer of team_user and
 * user_attributes, so console, API, invites and SCIM follow the same rules and audit trail.
 */
class OrgData
{
    /** Set the person's teams in $org to exactly $keys (other organizations untouched). Returns true when changed. */
    public static function setTeams(User $user, Organization $org, array $keys, ?User $actor): bool
    {
        $teams = Team::where('organization_id', $org->id)->get()->keyBy('key');
        $unknown = array_diff($keys, $teams->keys()->all());
        if ($unknown) {
            throw ValidationException::withMessages(['teams' => 'Unknown teams: '.implode(', ', $unknown)]);
        }
        $want = collect($keys)->unique()->map(fn ($k) => $teams[$k]->id);
        $have = $user->teams()->where('organization_id', $org->id)->pluck('teams.id');
        $add = $want->diff($have);
        $remove = $have->diff($want);
        foreach ($add as $id) {
            $user->teams()->attach($id, ['added_by' => $actor?->id, 'added_at' => now()]);
        }
        $user->teams()->detach($remove->all());
        if ($add->isEmpty() && $remove->isEmpty()) {
            return false;
        }
        $keyOf = $teams->pluck('key', 'id');
        Audit::log('team.membership_changed', 'role', $user, ['teams' => $have->map(fn ($id) => $keyOf[$id] ?? $id)->values()->all()],
            ['teams' => $want->map(fn ($id) => $keyOf[$id])->values()->all()], orgId: $org->id, actorId: $actor?->id);

        return true;
    }

    public static function teamKeys(User $user, Organization $org): array
    {
        return $user->teams()->where('organization_id', $org->id)->orderBy('key')->pluck('key')->all();
    }

    /**
     * Save field values ({key: value|null}; null clears). Unknown keys and wrong types are a validation error.
     * $asSelf: the person editing their own profile — only fields marked editable by self.
     */
    public static function setValues(User $user, Organization $org, array $values, ?User $actor, bool $asSelf = false): array
    {
        $defs = OrgAttribute::where('organization_id', $org->id)->get()->keyBy('key');
        $errors = [];
        $clean = [];
        foreach ($values as $key => $value) {
            $def = $defs->get($key);
            if (! $def) {
                $errors["fields.$key"] = "{$org->name} has no field called {$key}.";
            } elseif ($asSelf && $def->editable_by !== 'self') {
                $errors["fields.$key"] = "{$def->label} can only be changed by an admin.";
            } elseif (($msg = self::invalid($def, $value)) !== null) {
                $errors["fields.$key"] = $msg;
            } else {
                $clean[$key] = self::normalise($def, $value);
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $before = self::values($user, $org);
        foreach ($clean as $key => $value) {
            if ($value === null) {
                DB::table('user_attributes')->where(['user_id' => $user->id, 'organization_id' => $org->id, 'key' => $key])->delete();
            } else {
                DB::table('user_attributes')->updateOrInsert(['user_id' => $user->id, 'organization_id' => $org->id, 'key' => $key],
                    ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
            }
        }
        $after = self::values($user, $org);
        if ($after != $before) {
            Audit::log('user.fields_changed', $asSelf ? 'security' : 'admin', $user, array_diff_assoc($before, $after) ?: null, array_diff_assoc($after, $before) ?: null,
                orgId: $org->id, actorId: $actor?->id);
        }

        return $after;
    }

    /** {key: typed value} for the person in $org; $tokenOnly = only fields sent to apps. */
    public static function values(User $user, Organization $org, bool $tokenOnly = false): array
    {
        $defs = OrgAttribute::where('organization_id', $org->id)->when($tokenOnly, fn ($q) => $q->where('in_token', true))->get()->keyBy('key');
        $out = [];
        foreach (DB::table('user_attributes')->where('user_id', $user->id)->where('organization_id', $org->id)->whereIn('key', $defs->keys())->get() as $r) {
            $out[$r->key] = self::typed($defs[$r->key], $r->value);
        }
        ksort($out);

        return $out;
    }

    /** Required fields the person has no value for. */
    public static function missing(User $user, Organization $org): array
    {
        $have = array_keys(self::values($user, $org));

        return OrgAttribute::where('organization_id', $org->id)->where('required', true)->whereNotIn('key', $have)->pluck('key')->all();
    }

    public static function invalid(OrgAttribute $def, mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return $def->required ? "{$def->label} is required." : null;
        }

        return match ($def->type) {
            'number' => is_numeric($v) ? null : "{$def->label} must be a number.",
            'bool' => in_array($v, [true, false, 0, 1, '0', '1', 'true', 'false'], true) ? null : "{$def->label} must be true or false.",
            'date' => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? null : "{$def->label} must be a date (YYYY-MM-DD).",
            'enum' => in_array((string) $v, $def->options ?? [], true) ? null : "{$def->label} must be one of: ".implode(', ', $def->options ?? []).'.',
            default => is_scalar($v) && mb_strlen((string) $v) <= 255 ? null : "{$def->label} must be text up to 255 characters.",
        };
    }

    private static function normalise(OrgAttribute $def, mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return match ($def->type) {
            'bool' => in_array($v, [true, 1, '1', 'true'], true) ? '1' : '0',
            default => trim((string) $v),
        };
    }

    public static function typed(OrgAttribute $def, ?string $v): mixed
    {
        return match ($def->type) {
            'number' => $v === null ? null : $v + 0,
            'bool' => $v === '1',
            default => $v,
        };
    }
}
