<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A profile field an organization defines for its people (SPEC-platform §6.4). Values live in user_attributes. */
class OrgAttribute extends Model
{
    public const TYPES = ['string' => 'Text', 'number' => 'Number', 'bool' => 'Yes / no', 'date' => 'Date', 'enum' => 'Choice'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['options' => 'array', 'required' => 'bool', 'in_token' => 'bool'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
