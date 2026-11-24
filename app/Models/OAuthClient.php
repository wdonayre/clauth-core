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
        'last_used_at' => 'datetime',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** An API client (SPEC-platform §5.1): calls /api/v1 with management scopes. */
    public function isApi(): bool
    {
        return $this->type === 'api';
    }

    /** API client of the platform (all organizations) rather than of one organization. */
    public function isPlatformApi(): bool
    {
        return $this->type === 'api' && $this->organization_id === null;
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
