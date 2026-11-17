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
            'mfa_exempt' => 'boolean',
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
        return $this->belongsToMany(Organization::class)->withPivot('role', 'joined_at');
    }

    public function grants(): HasMany
    {
        return $this->hasMany(Grant::class);
    }

    public function socialIdentities(): HasMany
    {
        return $this->hasMany(SocialIdentity::class);
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

    /** Can open the console at all: Clauth owners, org owners/admins/developers. */
    public function isAdmin(): bool
    {
        return $this->isOwner() || $this->devOrgIds() !== [];
    }

    /** Role in one organization (owner|admin|developer|member), or null when not a member. */
    public function orgRole(?string $orgId): ?string
    {
        return $orgId ? $this->organizations()->where('organizations.id', $orgId)->value('organization_user.role') : null;
    }

    /** Org ids where this user manages people (org owners and admins; Clauth owners see everything). */
    public function adminOrgIds(): array
    {
        return $this->organizations()->wherePivotIn('role', ['owner', 'admin'])->pluck('organizations.id')->all();
    }

    /** Org ids whose apps this user manages (owners, admins and developers). */
    public function devOrgIds(): array
    {
        return $this->organizations()->wherePivotIn('role', ['owner', 'admin', 'developer'])->pluck('organizations.id')->all();
    }

    /** Org ids this user owns (can change org roles). */
    public function ownedOrgIds(): array
    {
        return $this->organizations()->wherePivot('role', 'owner')->pluck('organizations.id')->all();
    }

    /** May manage this app (clients, roles, branding, domain): Clauth owner, or owner/admin/developer of its publisher. */
    public function canManageApp(Application $app): bool
    {
        return $this->isOwner() || ($app->owner_organization_id && in_array($app->owner_organization_id, $this->devOrgIds(), true));
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
