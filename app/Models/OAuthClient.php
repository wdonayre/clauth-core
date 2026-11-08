<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Client;

/** Passport client tied to a Clauth app. All clients are first-party: no consent screen. */
class OAuthClient extends Client
{
    protected $casts = [
        'grant_types' => 'array',
        'scopes' => 'array',
        'redirect_uris' => 'array',
        'post_logout_redirect_uris' => 'array',
        'revoked' => 'bool',
        'secret_expires_at' => 'datetime',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return true;
    }

    public function firstParty(): bool
    {
        return true;
    }
}
