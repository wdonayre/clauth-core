<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/** An app people sign in to (table `apps`). One app can have several OAuth clients. */
class Application extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'apps';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'require_mfa_roles' => 'array',
            'manifest_diff' => 'array',
            'manifest_synced_at' => 'datetime',
            'last_token_at' => 'datetime',
            'bar_seen_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /** Organizations where the app can be used (active installations). */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'app_installations', 'app_id', 'organization_id')
            ->withPivotValue('status', 'active')->withTimestamps();
    }

    /** Every installation row, including requests waiting for the other organization. */
    public function installations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'app_installations', 'app_id', 'organization_id')
            ->withPivot('status', 'requested_by', 'approved_by', 'approved_at')->withTimestamps();
    }

    /** The organization that owns (publishes) the app. */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'owner_organization_id');
    }

    /** First-party for sign-in purposes: published by one of William's own (platform) organizations. */
    public function isFirstParty(): bool
    {
        return $this->owner_organization_id === null || (bool) $this->publisher?->is_platform;
    }

    public function clients(): HasMany
    {
        return $this->hasMany(OAuthClient::class, 'app_id');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(AppPermission::class, 'app_id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(AppRole::class, 'app_id')->orderBy('position')->orderBy('id');
    }

    /** Sign-in domains this app uses (at most one; see signInDomain()). */
    public function domains(): BelongsToMany
    {
        return $this->belongsToMany(AppDomain::class, 'app_domain_app', 'app_id', 'app_domain_id')->withTimestamps();
    }

    public function signInDomain(): ?AppDomain
    {
        return $this->domains()->first();
    }

    public function grants(): HasMany
    {
        return $this->hasMany(Grant::class, 'app_id');
    }

    public function letter(): string
    {
        return $this->icon_letter ?: mb_strtoupper(mb_substr($this->name, 0, 1));
    }

    public function logoUrl(bool $dark = false): ?string
    {
        $path = ($dark && $this->logo_dark_path) ? $this->logo_dark_path : $this->logo_path;

        return $path ? route('app-logo', ['app' => $this->key, 'v' => substr(md5($path), 0, 8)]).($dark ? '&dark=1' : '') : null;
    }

    public function logoFile(bool $dark = false): ?string
    {
        $path = ($dark && $this->logo_dark_path) ? $this->logo_dark_path : $this->logo_path;

        return $path && Storage::disk('local')->exists($path) ? Storage::disk('local')->path($path) : null;
    }
}
