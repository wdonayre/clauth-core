<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/** An organization's own job role that maps to one role (or No access) per installed app (SPEC-roles §4). */
class AccessProfile extends Model
{
    protected $guarded = ['id'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'access_profile_user')->withPivot('assigned_by', 'assigned_at');
    }

    /** [app_id => app_role_id|null]; apps missing from the array are not mapped yet. */
    public function mapping(): array
    {
        return DB::table('access_profile_roles')->where('access_profile_id', $this->id)->pluck('app_role_id', 'app_id')->all();
    }

    /** Does any mapping of this profile point at an owner-tier role? (only org owners may touch those) */
    public function hasOwnerTier(): bool
    {
        return DB::table('access_profile_roles')->join('app_roles', 'app_roles.id', '=', 'access_profile_roles.app_role_id')
            ->where('access_profile_id', $this->id)->where('app_roles.tier', 'owner')->exists();
    }
}
