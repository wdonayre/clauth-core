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
use Illuminate\Support\Str;

class DashboardController
{
    use Scope;

    public function __invoke()
    {
        $ids = $this->orgIds();
        $me = auth()->user();
        $canPeople = $me->isOwner() || $me->adminOrgIds() !== [];
        $canApps = $me->isOwner() || $me->devOrgIds() !== [];
        $users = $this->scopeUsers(User::query());
        // Apps this person may see: their organizations' own apps and the apps installed where they're admin.
        $apps = Application::query()->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('owner_organization_id', $me->devOrgIds())
            ->orWhereHas('organizations', fn ($o) => $o->whereIn('organizations.id', $ids))));

        // Needs your attention. Each item: tone (danger|warn|info), icon glyph, title, optional sub line, where to act, button label.
        $attention = [];
        $add = function (string $tone, string $glyph, string $title, ?string $sub, string $href, string $action) use (&$attention) {
            $attention[] = compact('tone', 'glyph', 'title', 'sub', 'href', 'action');
        };
        if ($ids !== null) {
            foreach (Organization::with('installRequests')->whereIn('id', $ids)->get() as $o) {
                foreach ($o->installRequests as $a) {
                    $add('info', '+', "{$a->name} wants to be available to {$o->name}", 'Approve or decline it in the organization.', route('admin.orgs.index'), 'Review');
                }
            }
        }
        if ($ids === null) {
            foreach (OAuthClient::with('app')->whereNotNull('secret_expires_at')->where('revoked', false)->where('secret_expires_at', '<', now()->addDays(14))->get() as $c) {
                $add($c->secret_expires_at->isPast() ? 'danger' : 'warn', '!', "{$c->app?->name} · {$c->name}: client secret ".($c->secret_expires_at->isPast() ? 'expired' : 'expires '.$c->secret_expires_at->diffForHumans()),
                    'Make a new secret and update the app before it stops signing people in.', route('admin.apps.show', [$c->app?->key ?? '-', 'tab' => 'settings']), 'Rotate');
            }
            foreach (AppDomain::with('apps')->get() as $d) {
                $names = $d->apps->pluck('name')->join(', ') ?: 'No app';
                $href = route('admin.apps.show', [$d->apps->first()?->key ?? '-', 'tab' => 'brand']);
                if ($d->status === 'problem') {
                    $add('danger', '!', "{$names}: sign-in domain {$d->host} has a problem", 'Sign-in falls back to '.Domains::mainHost().". {$d->last_error}", $href, 'Fix');
                } elseif (! $d->isServed() && $d->created_at->lt(now()->subDay())) {
                    $add('warn', '◷', "{$names}: sign-in domain {$d->host} isn’t live yet", $d->label(), $href, 'Check');
                }
            }
            if ($n = OrganizationRequest::waiting()->count()) {
                $oldest = OrganizationRequest::waiting()->oldest('updated_at')->first();
                $add('info', '+', $n === 1 ? '1 organization request is waiting' : "$n organization requests are waiting",
                    OrganizationRequest::waiting()->latest()->limit(3)->pluck('org_name')->join(', ').'. The oldest is from '.$oldest->updated_at->diffForHumans().'.',
                    route('admin.org-requests.index'), 'Review');
            }
            if ($n = Report::where('status', 'open')->count()) {
                $add('danger', '!', "$n sign-in page report(s) to review", 'Someone flagged a sign-in page as suspicious.', route('admin.reports'), 'Review');
            }
            foreach (Application::whereNotNull('manifest_diff')->get() as $a) {
                $add('info', '↻', "{$a->name}: manifest wants role changes you edited in Clauth", $a->manifest_synced_at ? 'Last synced '.$a->manifest_synced_at->diffForHumans() : null,
                    route('admin.apps.show', $a->key), 'Sync');
            }
        }
        foreach ((clone $users)->whereNotNull('locked_until')->where('locked_until', '>', now())->get() as $u) {
            $add('danger', '!', "{$u->name} is locked out after failed sign-ins", 'Unlocks '.$u->locked_until->diffForHumans().', or unlock them now.', route('admin.users.show', $u->id), 'Review');
        }
        $expiredInvites = Invite::whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '<', now())
            ->where('expires_at', '>', now()->subDays(14))->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))->count();
        if ($expiredInvites) {
            $add('info', '@', "$expiredInvites invite(s) expired without being accepted", 'Send a new invite if they still need access.', route('admin.users.index', ['status' => 'invited']), 'Review');
        }

        // Key numbers.
        $pending = Invite::whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids));
        $oldInvites = (clone $pending)->where('created_at', '<', now()->subWeek())->count();
        $newPeople = (clone $users)->where('created_at', '>=', now()->subWeek())->count();
        $appList = (clone $apps)->withCount(['grants' => fn ($g) => $g->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))])->orderBy('name')->get();
        $signins = AuditEvent::where('event', 'signin.success')->where('at', '>=', now()->startOfDay())->whereIn('app_id', $appList->pluck('id'))
            ->when($ids !== null, fn ($q) => $q->whereIn('actor_user_id', (clone $users)->select('users.id')))
            ->selectRaw('app_id, count(distinct actor_user_id) as n')->groupBy('app_id')->pluck('n', 'app_id');
        $notConnected = $appList->where('status', 'not_connected')->count();
        $paused = $appList->where('status', 'paused')->count();

        $stats = [
            ['label' => 'People', 'n' => (clone $users)->count(), 'href' => $canPeople ? route('admin.users.index') : null,
                'sub' => $newPeople ? "+$newPeople this week" : 'No one new this week', 'tone' => $newPeople ? 'ok' : ''],
            ['label' => 'Signed in today', 'n' => (clone $users)->where('last_login_at', '>=', now()->startOfDay())->count(),
                'href' => $canPeople ? route('admin.audit', ['cat' => 'signin', 'range' => 'today']) : null,
                'sub' => 'across '.$signins->count().' '.Str::plural('app', $signins->count()), 'tone' => ''],
            ['label' => 'Apps', 'n' => $appList->count(), 'href' => $canApps ? route('admin.apps.index') : null,
                'sub' => match (true) {
                    $appList->isEmpty() => 'None yet',
                    $notConnected > 0 => "$notConnected not connected yet",
                    $paused > 0 => "$paused paused",
                    default => 'All connected',
                }, 'tone' => $appList->isEmpty() ? '' : ($notConnected || $paused ? 'warn' : 'ok')],
            ['label' => 'Waiting on invites', 'n' => (clone $pending)->count(), 'href' => $canPeople ? route('admin.users.index', ['status' => 'invited']) : null,
                'sub' => $oldInvites ? "$oldInvites sent over a week ago" : 'None overdue', 'tone' => $oldInvites ? 'warn' : ''],
        ];

        $events = AuditEvent::with(['actor', 'app', 'organization'])->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))
            ->latest('at')->limit(8)->get();
        AuditController::prime($events);

        $hour = (int) now()->format('G');

        return view('admin.dashboard', [
            'greeting' => ($hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening')).', '.Str::before(trim($me->name ?: 'there'), ' '),
            'stats' => $stats,
            'apps' => $appList,
            'signins' => $signins,
            'attention' => $attention,
            'events' => $events,
            'canPeople' => $canPeople,
            'canApps' => $canApps,
        ]);
    }
}
