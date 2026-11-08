<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['at' => 'datetime', 'before' => 'array', 'after' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'app_id')->withTrashed();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class)->withTrashed();
    }
}
