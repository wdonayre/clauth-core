<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AppRole extends Model
{
    /** Clauth's fixed scale every app role sits on, highest first (SPEC-roles §5). */
    public const TIERS = ['owner' => 'Owner', 'admin' => 'Admin', 'member' => 'Member', 'viewer' => 'Viewer'];

    /** Tiers that require 2-step verification when the tier rule is on (SPEC-roles §10.2). */
    public const MFA_TIERS = ['owner', 'admin'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['edited' => 'bool', 'aliases' => 'array', 'assignable' => 'bool'];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(AppPermission::class, 'app_role_permission', 'app_role_id', 'app_permission_id');
    }

    /** Active (non-deprecated) permission keys. */
    public function permissionKeys(): array
    {
        return $this->permissions->whereNull('deprecated_at')->pluck('key')->sort()->values()->all();
    }

    /** Keys sent in the token's `roles` claim: the role's key, then any old keys it replaced (SPEC-roles §7). */
    public function tokenKeys(): array
    {
        return array_values(array_unique(array_merge([$this->key], $this->aliases ?? [])));
    }

    /** 0 = owner … 3 = viewer; roles without a tier sort last. */
    public function tierRank(): int
    {
        $i = array_search($this->tier, array_keys(self::TIERS), true);

        return $i === false ? 9 : $i;
    }

    /** Clean a list of alias keys from a manifest or form (same shape as role keys, never the role's own key). */
    public static function cleanAliases(mixed $aliases, string $ownKey): array
    {
        return collect(is_array($aliases) ? $aliases : preg_split('/[\s,]+/', (string) $aliases))
            ->map(fn ($a) => strtolower(trim((string) $a)))
            ->filter(fn ($a) => $a !== '' && $a !== $ownKey && preg_match('/^[a-z][a-z0-9_-]{0,40}$/', $a))
            ->unique()->values()->all();
    }
}
