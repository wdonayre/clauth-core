<?php

namespace App\Models;

use App\Mail\OrgRequestMail;
use App\Services\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Mail;

/**
 * Someone asking to set up an organization on Clauth (SPEC-organizations §8, SPEC-signup.md). A Clauth owner approves,
 * asks for more information, or declines. Newer requests belong to a signed-in account (user_id); older ones were
 * sent from a public form with a contact email.
 *
 * Status: pending → (info_requested → pending) → approved | rejected | cancelled | expired.
 */
class OrganizationRequest extends Model
{
    public const OPEN = ['pending', 'info_requested'];

    public const SIZES = ['1-10' => '1–10', '11-50' => '11–50', '51-200' => '51–200', '200+' => 'More than 200'];

    /** Days a request may wait for an answer (from either side) before it closes by itself. */
    public const EXPIRES_DAYS = 30;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'decided_at' => 'datetime', 'info_requested_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceApp(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'source_app_id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', self::OPEN);
    }

    /** Waiting for a Clauth owner (confirmed email, not waiting on the person). */
    public function scopeWaiting(Builder $q): Builder
    {
        return $q->where('status', 'pending')->whereNotNull('email_verified_at');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** Close open requests nobody answered for EXPIRES_DAYS, and tell the person. Returns how many closed. */
    public static function expireDue(): int
    {
        $n = 0;
        foreach (self::open()->where('updated_at', '<', now()->subDays(self::EXPIRES_DAYS))->get() as $r) {
            $r->update(['status' => 'expired', 'decided_at' => now()]);
            Audit::log('org.request_expired', 'admin', $r, after: ['org' => $r->org_name]);
            rescue(fn () => Mail::to($r->contact_email)->send(new OrgRequestMail($r, 'expired')), report: true);
            $n++;
        }

        return $n;
    }

    public function sizeLabel(): ?string
    {
        return self::SIZES[$this->team_size] ?? $this->team_size;
    }
}
