<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasUlids, SoftDeletes;

    /** Roles a person can hold in an organization (SPEC-organizations §4). */
    public const ROLES = ['owner' => 'Owner', 'admin' => 'Admin', 'developer' => 'Developer', 'member' => 'Member'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_platform' => 'boolean', 'verified_at' => 'datetime', 'suspended_at' => 'datetime', 'limits' => 'array',
            'domain_verified_at' => 'datetime', 'terms_accepted_at' => 'datetime', 'deletion_requested_at' => 'datetime'];
    }

    /** Outside organizations must accept the current Terms + Data Processing Agreement before publishing apps. */
    public function needsTerms(): bool
    {
        return ! $this->is_platform && $this->terms_version !== config('clauth.terms_version');
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /** Effective limits (SPEC-organizations §10.1); null = no limit (platform organizations). */
    public function limit(string $key): ?int
    {
        if ($this->is_platform) {
            return null;
        }
        $over = $this->limits[$key] ?? null;

        return $over !== null ? (int) $over : (int) config('clauth.limits.'.($this->verified_at ? 'verified' : 'unverified').'.'.$key);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role', 'joined_at');
    }

    public function accessProfiles(): HasMany
    {
        return $this->hasMany(AccessProfile::class)->orderBy('position')->orderBy('name');
    }

    /** Apps usable in this organization (active installations). */
    public function apps(): BelongsToMany
    {
        return $this->belongsToMany(Application::class, 'app_installations', 'organization_id', 'app_id')
            ->withPivotValue('status', 'active')->withTimestamps();
    }

    /** Install requests from other organizations' apps, waiting for this organization's admin. */
    public function installRequests(): BelongsToMany
    {
        return $this->belongsToMany(Application::class, 'app_installations', 'organization_id', 'app_id')
            ->wherePivot('status', 'requested')->withPivot('requested_by', 'created_at');
    }

    /** Apps this organization publishes. */
    public function publishedApps(): HasMany
    {
        return $this->hasMany(Application::class, 'owner_organization_id');
    }
}
