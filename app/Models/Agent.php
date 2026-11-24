<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A non-human identity (SPEC-platform §9.3): an AI assistant, bot or script. An organization's agent gets its own roles
 * per app (agent_grants); a person's own agent acts for that person and never gets more than they have. Tokens carry
 * sub = agt_<id> and act = the owner. An agent stops working when it is suspended, or its owner can no longer sign in.
 */
class Agent extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function sub(): string
    {
        return 'agt_'.$this->id;
    }

    public static function findBySub(string $sub): ?self
    {
        return str_starts_with($sub, 'agt_') ? static::find(substr($sub, 4)) : null;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function isPersonal(): bool
    {
        return $this->owner_user_id !== null;
    }

    /** Usable right now: active, organization not suspended, and (personal) the owner can still sign in and is still a member. */
    public function usable(): bool
    {
        if ($this->status !== 'active' || ! $this->organization || $this->organization->isSuspended()) {
            return false;
        }
        if ($this->isPersonal()) {
            $o = $this->owner;

            return $o && $o->status === 'active' && $o->organizations()->where('organizations.id', $this->organization_id)->wherePivotNull('deactivated_at')->exists();
        }

        return true;
    }
}
