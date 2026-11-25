<?php

namespace App\Observers;

use App\Models\Grant;
use App\Services\Webhooks;

/** Grants are what tokens read, so every change of access becomes an app webhook here (SPEC-provisioning E1). */
class GrantObserver
{
    public function saving(Grant $g): void
    {
        if (! $g->exists || $g->isDirty('app_role_id')) {
            $g->changed_at = now();
        }
    }

    public function created(Grant $g): void
    {
        Webhooks::grantChanged($g, 'granted');
    }

    public function updated(Grant $g): void
    {
        if ($g->wasChanged('app_role_id')) {
            Webhooks::grantChanged($g, 'changed', (int) $g->getOriginal('app_role_id'));
        }
    }

    public function deleted(Grant $g): void
    {
        Webhooks::grantChanged($g, 'removed', (int) $g->app_role_id);
    }
}
