<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Report this page" from a sign-in page (SPEC-organizations §10.3). Reviewed by Clauth owners. */
class Report extends Model
{
    public const REASONS = ['phishing' => 'It’s trying to steal passwords', 'impersonation' => 'It pretends to be another company',
        'spam' => 'Spam or unwanted invites', 'other' => 'Something else'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'app_id');
    }
}
