<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasUlids, SoftDeletes;

    protected $guarded = ['id'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('is_org_admin', 'joined_at');
    }

    public function apps(): BelongsToMany
    {
        return $this->belongsToMany(Application::class, 'app_organization', 'organization_id', 'app_id');
    }
}
