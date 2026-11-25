<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An outside service Clauth connects to (SPEC-platform §7): a sign-in provider (oidc, oauth, meta), an SMS gateway
 * or an organization's own email sender. `config` is encrypted; secret fields are write-only (see Connections).
 */
class Connection extends Model
{
    /** Connection types that are sign-in buttons. */
    public const SIGN_IN_TYPES = ['oidc', 'oauth', 'meta', 'apple'];

    protected $guarded = ['id'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return ['config' => 'encrypted:array', 'last_used_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function cfg(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function isSignIn(): bool
    {
        return in_array($this->type, self::SIGN_IN_TYPES, true);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
