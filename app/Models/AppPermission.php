<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppPermission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['deprecated_at' => 'datetime'];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'app_id');
    }
}
