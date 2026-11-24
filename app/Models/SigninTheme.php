<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A designed sign-in page look (SPEC-platform §8.4). See App\Services\Theme::SETTINGS for the fields. */
class SigninTheme extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function s(string $k, mixed $default = null): mixed
    {
        return $this->settings[$k] ?? $default;
    }
}
