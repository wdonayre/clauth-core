<?php

namespace App\Http\Controllers\Admin;

use App\Models\AppDomain;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Invite;
use App\Models\OAuthClient;
use App\Models\Organization;
use App\Models\OrganizationRequest;
use App\Models\Report;
use App\Models\User;
use App\Services\Domains;

class DashboardController
{
    use Scope;

    public function __invoke()
    {
        $ids = $this->orgIds();
        $me = auth()->user();
        $users = $this->scopeUsers(User::query());
        // Apps this person may see: their organizations' own apps and the apps installed where they're admin.
        $apps = Application::query()->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('owner_organization_id', $me->devOrgIds())
            ->orWhereHas('organizations', fn ($o) => $o->whereIn('organizations.id', $ids))));
        $attention = [];
        if ($ids !== null) {
            foreach (Organization::with('installRequests')->whereIn('id', $ids)->get() as $o) {
                foreach ($o->installRequests as $a) {
                    $attention[] = ['tone' => 'info', 'text' => "{$a->name} wants to be available to {$o->name} — approve or decline", 'href' => route('admin.orgs.index')];
                }
            }
        }
        if ($ids === null) {
            foreach (OAuthClient::with('app')->whereNotNull('secret_expires_at')->where('revoked', false)->where('secret_expires_at', '<', now()->addDays(14))->get() as $c) {
                $attention[] = ['tone' => 'warn', 'text' => "{$c->app?->name} · {$c->name}: client secret ".($c->secret_expires_at->isPast() ? 'expired' : 'expires '.$c->secret_expires_at->diffForHumans()), 'href' => route('admin.apps.show', [$c->app?->key ?? '-', 'tab' => 'settings'])];
            }
            foreach (AppDomain::with('apps')->get() as $d) {
                $names = $d->apps->pluck('name')->join(', ') ?: 'No app';
                $href = route('admin.apps.show', [$d->apps->first()?->key ?? '-', 'tab' => 'brand']);
                if ($d->status === 'problem') {
                    $attention[] = ['tone' => 'danger', 'text' => "{$names}: sign-in domain {$d->host} has a problem (sign-in falls back to ".Domains::mainHost().") — {$d->last_error}", 'href' => $href];
                } elseif (! $d->isServed() && $d->created_at->lt(now()->subDay())) {
                    $attention[] = ['tone' => 'warn', 'text' => "{$names}: sign-in domain {$d->host} isn’t live yet — {$d->label()}", 'href' => $href];
                }
            }
            if ($n = OrganizationRequest::where('status', 'pending')->whereNotNull('email_verified_at')->count()) {
                $attention[] = ['tone' => 'info', 'text' => "$n organization request(s) to review", 'href' => route('admin.orgs.index')];
            }
            if ($n = Report::where('status', 'open')->count()) {
                $attention[] = ['tone' => 'danger', 'text' => "$n sign-in page report(s) to review", 'href' => route('admin.reports')];
            }
            foreach (Application::whereNotNull('manifest_diff')->get() as $a) {
                $attention[] = ['tone' => 'info', 'text' => "{$a->name}: manifest wants role changes you edited in Clauth", 'href' => route('admin.apps.show', $a->key)];
            }
        }
        foreach ((clone $users)->whereNotNull('locked_until')->where('locked_until', '>', now())->get() as $u) {
            $attention[] = ['tone' => 'danger', 'text' => "{$u->name} is locked out after failed sign-ins", 'href' => route('admin.users.show', $u->id)];
        }
        $expiredInvites = Invite::whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '<', now())
            ->where('expires_at', '>', now()->subDays(14))->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))->count();
        if ($expiredInvites) {
            $attention[] = ['tone' => 'info', 'text' => "$expiredInvites invite(s) expired without being accepted", 'href' => route('admin.users.index', ['status' => 'invited'])];
        }

        return view('admin.dashboard', [
            'stats' => [
                'Users' => (clone $users)->count(),
                'Active today' => (clone $users)->where('last_login_at', '>=', now()->startOfDay())->count(),
                'Apps' => (clone $apps)->count(),
                'Pending invites' => Invite::whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
                    ->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))->count(),
            ],
            'apps' => (clone $apps)->withCount(['grants' => fn ($g) => $g->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))])->orderBy('name')->get(),
            'attention' => $attention,
            'events' => AuditEvent::with(['actor', 'app'])->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))
                ->latest('at')->limit(8)->get(),
        ]);
    }
}
