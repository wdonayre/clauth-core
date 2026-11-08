<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasUlids, Notifiable, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = mb_strtolower(trim($value));
    }

    /** Public id used in tokens and APIs (`sub`). */
    public function sub(): string
    {
        return 'usr_'.$this->id;
    }

    public static function findBySub(string $sub): ?self
    {
        return static::find(str_starts_with($sub, 'usr_') ? substr($sub, 4) : $sub);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)->withPivot('is_org_admin', 'joined_at');
    }

    public function grants(): HasMany
    {
        return $this->hasMany(Grant::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function clientSessions(): HasMany
    {
        return $this->hasMany(ClientSession::class);
    }

    public function isOwner(): bool
    {
        return $this->clauth_role === 'owner';
    }

    public function isAdmin(): bool
    {
        return $this->isOwner() || $this->adminOrgIds() !== [];
    }

    /** Org ids this user administers (org admins only; owners see everything). */
    public function adminOrgIds(): array
    {
        return $this->organizations()->wherePivot('is_org_admin', true)->pluck('organizations.id')->all();
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last) ?: '?';
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
