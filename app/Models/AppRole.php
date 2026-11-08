<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AppRole extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['edited' => 'bool'];
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
}
