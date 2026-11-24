<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Sign-in rules of one app, one organization (default for its apps) or the platform (both null). */
class SigninPolicy extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SigninPolicyVersion::class)->orderByDesc('version');
    }
}
