<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An outside organization asking to use Clauth (SPEC-organizations §8). William approves or rejects it. */
class OrganizationRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'decided_at' => 'datetime'];
    }
}
