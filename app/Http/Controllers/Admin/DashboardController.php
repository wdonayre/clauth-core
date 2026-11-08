<?php

namespace App\Http\Controllers\Admin;

use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Invite;
use App\Models\OAuthClient;
use App\Models\User;

class DashboardController
{
    use Scope;

    public function __invoke()
    {
        $ids = $this->orgIds();
        $users = $this->scopeUsers(User::query());
        $attention = [];
        if ($ids === null) {
            foreach (OAuthClient::with('app')->whereNotNull('secret_expires_at')->where('revoked', false)->where('secret_expires_at', '<', now()->addDays(14))->get() as $c) {
                $attention[] = ['tone' => 'warn', 'text' => "{$c->app?->name} · {$c->name}: client secret ".($c->secret_expires_at->isPast() ? 'expired' : 'expires '.$c->secret_expires_at->diffForHumans()), 'href' => route('admin.apps.show', [$c->app?->key ?? '-', 'tab' => 'settings'])];
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
                'Apps' => Application::count(),
                'Pending invites' => Invite::whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
                    ->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))->count(),
            ],
            'apps' => Application::withCount(['grants'])->orderBy('name')->get(),
            'attention' => $attention,
            'events' => AuditEvent::with(['actor', 'app'])->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))
                ->latest('at')->limit(8)->get(),
        ]);
    }
}
