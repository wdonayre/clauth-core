<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person's OK for an app outside their own organizations to receive their details (SPEC-organizations §6). */
class Consent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'granted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
