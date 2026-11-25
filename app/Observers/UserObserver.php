<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Webhooks;

/** Name/email changes and switching an account off or on reach the apps the person uses (SPEC-provisioning E1). */
class UserObserver
{
    public function updated(User $u): void
    {
        if ($u->wasChanged('status') && in_array($u->status, ['disabled', 'active'], true) && $u->getOriginal('status') !== 'invited') {
            Webhooks::forUser($u, $u->status === 'disabled' ? 'user.disabled' : 'user.enabled');
        } elseif ($u->wasChanged(['name', 'email'])) {
            Webhooks::forUser($u, 'user.updated');
        }
    }
}
