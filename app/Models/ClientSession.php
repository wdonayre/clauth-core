<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A user's signed-in state at one OAuth client (amr, last use) — feeds My account › Sessions. */
class ClientSession extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amr' => 'array', 'auth_time' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(OAuthClient::class, 'client_id');
    }
}
