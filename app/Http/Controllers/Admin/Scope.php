<?php

namespace App\Http\Controllers\Admin;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** What the signed-in admin may see: owners everything, org admins their own orgs only (others → 404). */
trait Scope
{
    protected function orgIds(): ?array
    {
        $u = auth()->user();

        return $u->isOwner() ? null : $u->adminOrgIds();
    }

    protected function orgs()
    {
        $ids = $this->orgIds();

        return Organization::orderBy('name')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->get();
    }

    protected function scopeUsers(Builder $q): Builder
    {
        $ids = $this->orgIds();

        return $ids === null ? $q : $q->whereHas('organizations', fn ($o) => $o->whereIn('organizations.id', $ids));
    }

    protected function visibleUser(string $id): User
    {
        return $this->scopeUsers(User::query())->findOrFail($id);
    }

    protected function canManageOrg(string $orgId): bool
    {
        $ids = $this->orgIds();

        return $ids === null || in_array($orgId, $ids, true);
    }
}
