<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A per-person, per-app role (or No access when app_role_id is null) that wins over profiles (SPEC-roles §4). */
class GrantOverride extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['set_at' => 'datetime'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(AppRole::class, 'app_role_id');
    }
}
