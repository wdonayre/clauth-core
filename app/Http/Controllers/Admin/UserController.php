<?php

namespace App\Http\Controllers\Admin;

use App\Models\AccessProfile;
use App\Models\Application;
use App\Models\AppRole;
use App\Models\AuditEvent;
use App\Models\ClientSession;
use App\Models\Grant;
use App\Models\GrantOverride;
use App\Models\Invite;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Claims;
use App\Services\Invites;
use App\Services\Limits;
use App\Services\OrgData;
use App\Services\Sessions;
use App\Support\Agent;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController
{
    use Scope;

    public const TABS = ['access' => 'Access', 'profile' => 'Profile', 'security' => 'Security & sessions', 'activity' => 'Activity'];

    public function index(Request $request)
    {
        $status = $request->query('status', 'all');
        $base = $this->scopeUsers(User::query());
        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('status', 'active')->count(),
            'invited' => (clone $base)->where('status', 'invited')->count(),
            'locked' => (clone $base)->where('locked_until', '>', now())->count(),
            'disabled' => (clone $base)->where('status', 'disabled')->count(),
        ];
        $q = (clone $base)->with([
            'organizations' => fn ($o) => $o->when($this->orgIds() !== null, fn ($w) => $w->whereIn('organizations.id', $this->orgIds()))->orderBy('name'),
            'grants' => fn ($g) => $g->when($this->orgIds() !== null, fn ($w) => $w->whereIn('organization_id', $this->orgIds())),
            'grants.app', 'grants.role',
            'accessProfiles' => fn ($p) => $p->when($this->orgIds() !== null, fn ($w) => $w->whereIn('access_profiles.organization_id', $this->orgIds())),
        ]);
        match ($status) {
            'active', 'invited', 'disabled' => $q->where('status', $status),
            'locked' => $q->where('locked_until', '>', now()),
            default => null,
        };
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%"));
        }
        if ($app = $request->query('app')) {
            $q->whereHas('grants', fn ($g) => $g->whereHas('app', fn ($a) => $a->where('key', $app))
                ->when($this->orgIds() !== null, fn ($w) => $w->whereIn('organization_id', $this->orgIds())));
        }
        if ($org = $request->query('org')) {
            // ?org= takes an organization slug or id (the organization's People tab links by id).
            $q->whereHas('organizations', fn ($o) => $o->where(fn ($w) => $w->where('slug', $org)->orWhere('organizations.id', $org))
                ->when($this->orgIds() !== null, fn ($w) => $w->whereIn('organizations.id', $this->orgIds())));
        }
        $q->orderBy('name');

        if ($request->query('export') === 'csv') {
            return $this->exportCsv($q->get());
        }

        $users = $q->paginate(25)->withQueryString();
        $orgs = $this->orgs();
        $apps = Application::with(['roles', 'organizations' => fn ($q) => $q->when($this->orgIds() !== null, fn ($w) => $w->whereIn('organizations.id', $this->orgIds()))])
            ->when($this->orgIds() !== null, fn ($q) => $q->whereHas('organizations', fn ($o) => $o->whereIn('organizations.id', $this->orgIds())))->orderBy('name')->get();
        $profiles = AccessProfile::whereIn('organization_id', $orgs->pluck('id'))->orderBy('name')->get();

        $orgParam = (string) $request->query('org');
        $currentOrg = $orgParam !== '' ? $orgs->first(fn ($o) => $o->slug === $orgParam || $o->id === $orgParam) : null;

        return view('admin.users.index', [
            'users' => $users,
            'currentOrg' => $currentOrg,
            'rows' => $users->getCollection()->map(fn (User $u) => $this->rowFor($u))->all(),
            'pending' => $this->pendingInvites($users->getCollection()),
            'counts' => $counts, 'status' => $status,
            'orgs' => $orgs,
            // Only apps installed in the organizations this admin runs (invite dialog).
            'apps' => $apps,
            'invited' => session('invite_links') ?? (session('invite_link') ? [session('invite_link')] : []),
            'profiles' => $profiles->groupBy('organization_id'),
            'gives' => $this->profileGives($profiles),
        ]);
    }

    /** One people-list row: organizations, access summary, 2-step state, last active. */
    private function rowFor(User $u): array
    {
        $grants = $u->grants->filter(fn ($g) => $g->app && $g->role);
        $appCount = $grants->unique('app_id')->count();
        $badges = $u->accessProfiles->pluck('name')->unique()->take(2)->map(fn ($n) => ['text' => $n, 'tone' => 'info'])->values()->all();
        if (! $badges) {
            $badges = $grants->map(fn ($g) => $g->role->name)->unique()->take(2)->map(fn ($n) => ['text' => $n, 'tone' => 'muted'])->values()->all();
        }
        if ($u->hasSecondFactor()) {
            $mfa = ['text' => 'On', 'tone' => 'ok'];
        } elseif ($u->mfa_exempt) {
            $mfa = ['text' => 'Skipped', 'tone' => 'muted'];
        } elseif ($u->status !== 'invited' && Claims::policyRequiresMfa($u)) {
            $mfa = ['text' => 'Off — required', 'tone' => 'warn'];
        } else {
            $mfa = ['text' => 'Off', 'tone' => 'muted'];
        }

        return [
            'user' => $u,
            'orgs' => $u->organizations->pluck('name')->implode(', '),
            'badges' => $badges,
            'apps' => $appCount,
            'appNames' => $grants->pluck('app.name')->unique()->values()->all(),
            'mfa' => $mfa,
        ];
    }

    /** Latest open invite for each invited person on this page (shown as a pending row). */
    private function pendingInvites(Collection $users): array
    {
        $emails = $users->where('status', 'invited')->pluck('email');
        if ($emails->isEmpty()) {
            return [];
        }
        $invites = Invite::with(['organization', 'inviter'])->whereIn('email', $emails)->whereNull('accepted_at')->whereNull('revoked_at')
            ->when($this->orgIds() !== null, fn ($q) => $q->whereIn('organization_id', $this->orgIds()))
            ->latest()->get()->unique('email')->keyBy('email');
        $roleNames = AppRole::with('app')->whereIn('id', $invites->flatMap(fn ($i) => collect($i->grants ?? [])->pluck('role_id')))->get()->keyBy('id');
        $profileNames = AccessProfile::whereIn('id', $invites->flatMap(fn ($i) => $i->profiles ?? []))->pluck('name', 'id');

        return $invites->map(function (Invite $i) use ($roleNames, $profileNames) {
            $roles = collect($i->grants ?? [])->map(fn ($g) => $roleNames->get($g['role_id']))->filter();

            return [
                'invite' => $i,
                'expired' => $i->expires_at->isPast(),
                'badges' => collect($i->profiles ?? [])->map(fn ($p) => $profileNames->get($p))->filter()
                    ->merge($roles->pluck('name'))->unique()->take(2)->values()->all(),
                'apps' => $roles->map(fn ($r) => $r->app?->name)->filter()->unique()->values()->all(),
            ];
        })->all();
    }

    /** [profile id => "Staff in Alab ERP, Finance in Xpenses"] for the invite summary. */
    private function profileGives(Collection $profiles): array
    {
        if ($profiles->isEmpty()) {
            return [];
        }

        return DB::table('access_profile_roles')
            ->join('app_roles', 'app_roles.id', '=', 'access_profile_roles.app_role_id')
            ->join('apps', 'apps.id', '=', 'access_profile_roles.app_id')
            ->whereIn('access_profile_id', $profiles->pluck('id'))
            ->orderBy('apps.name')
            ->get(['access_profile_id', 'app_roles.name as role', 'apps.name as app'])
            ->groupBy('access_profile_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => "{$r->role} in {$r->app}")->implode(', '))
            ->all();
    }

    private function exportCsv(Collection $users)
    {
        return response()->streamDownload(function () use ($users) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['name', 'email', 'status', 'organizations', 'access', 'two_step', 'last_active_utc']);
            foreach ($users as $u) {
                $r = $this->rowFor($u);
                $access = $u->grants->filter(fn ($g) => $g->app && $g->role && ($this->orgIds() === null || in_array($g->organization_id, $this->orgIds(), true)))
                    ->map(fn ($g) => "{$g->app->name}: {$g->role->name}")->unique()->implode('; ');
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, [
                    $u->name, $u->email, $u->isLocked() ? 'locked' : $u->status, $r['orgs'], $access,
                    $r['mfa']['text'], $u->last_login_at?->utc()->toDateTimeString() ?? '',
                ]));
            }
            fclose($out);
        }, 'people-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function invite(Request $request)
    {
        $orgIds = collect($this->orgs())->pluck('id')->all();
        $data = $request->validate([
            'name' => 'nullable|string|max:120',
            'email' => 'required_without:emails|nullable|email|max:190',
            'emails' => 'required_without:email|nullable|string|max:5000',
            'organization_id' => ['required', Rule::in($orgIds)],
            'roles' => 'array',
            'roles.*' => 'nullable|integer',
            'profiles' => 'array',
            'profiles.*' => 'nullable|integer',
            'require_mfa' => 'boolean',
            'is_org_admin' => 'boolean',
            'send' => 'boolean',
            'note' => 'nullable|string|max:500',
        ], ['note.max' => 'Keep the note under 500 characters.', 'emails.required_without' => 'Add at least one email address.', 'email.required_without' => 'Add at least one email address.']);

        // Several addresses (comma, space or new line separated) or the single name + email of older clients.
        $people = [];
        if (! empty($data['emails'])) {
            $list = collect(preg_split('/[\s,;]+/', $data['emails']))->map(fn ($e) => mb_strtolower(trim($e, " \t\n\r<>\"'")))->filter()->unique()->values();
            $bad = $list->reject(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) && mb_strlen($e) <= 190);
            if ($bad->isNotEmpty()) {
                return back()->withErrors(['emails' => 'These don’t look like email addresses: '.$bad->implode(', ')])->withInput();
            }
            if ($list->count() > 50) {
                return back()->withErrors(['emails' => 'Invite up to 50 people at a time.'])->withInput();
            }
            foreach ($list as $e) {
                $people[] = ['email' => $e, 'name' => $list->count() === 1 && ! empty($data['name']) ? $data['name'] : $this->nameFromEmail($e)];
            }
        }
        if (! $people && ! empty($data['email'])) {
            $people[] = ['email' => mb_strtolower($data['email']), 'name' => $data['name'] ?: $this->nameFromEmail($data['email'])];
        }
        if (! $people) {
            return back()->withErrors(['emails' => 'Add at least one email address.'])->withInput();
        }

        $org = Organization::find($data['organization_id']);
        $grants = [];
        foreach ($data['roles'] ?? [] as $appId => $roleId) {
            if (! $roleId) {
                continue;
            }
            $role = AppRole::where('id', $roleId)->where('app_id', $appId)->firstOrFail();
            abort_unless($role->app->organizations->contains('id', $data['organization_id']), 422, 'App not enabled for this organization.');
            $grants[] = ['app_id' => $appId, 'role_id' => $role->id];
        }
        // Profiles of the chosen organization only; one with an Owner-tier role needs an owner of it (SPEC-roles §10.1).
        $profiles = AccessProfile::where('organization_id', $org->id)->whereIn('id', array_filter($data['profiles'] ?? []))->get();
        foreach ($profiles as $p) {
            abort_if($p->hasOwnerTier() && ! Access::canTouchOwnerTier(auth()->user(), $org->id), 403, "Only an owner of {$org->name} can invite someone as {$p->name}.");
        }
        foreach ($grants as $g) {
            abort_if(AppRole::find($g['role_id'])?->tier === 'owner' && ! Access::canTouchOwnerTier(auth()->user(), $org->id), 403, "Only an owner of {$org->name} can give an Owner-tier role.");
        }
        $common = [
            'organization_id' => $org->id,
            'grants' => $grants,
            'profiles' => $profiles->pluck('id')->all(),
            'require_mfa' => $data['require_mfa'] ?? false,
            'is_org_admin' => $data['is_org_admin'] ?? false,
        ];
        if (! empty($common['is_org_admin']) && ! auth()->user()->isOwner() && ! in_array($org->id, auth()->user()->ownedOrgIds(), true)) {
            $common['is_org_admin'] = false; // only Clauth owners and the organization's owners hand out admin
        }

        $links = [];
        $errors = [];
        foreach ($people as $p) {
            $existing = User::where('email', $p['email'])->first();
            if ($existing && $existing->status === 'disabled') {
                $errors[] = "{$p['email']} is turned off. Turn it back on first.";

                continue;
            }
            $isMember = $existing && $existing->organizations()->where('organizations.id', $org->id)->exists();
            if ($msg = Limits::exceeded($org, 'invites_per_day') ?? ($isMember ? null : Limits::exceeded($org, 'people'))) {
                $errors[] = $msg;

                break;
            }
            [$invite, $token] = Invites::create($p + $common, auth()->user());
            if (filled($data['note'] ?? null)) {
                $invite->forceFill(['note' => trim($data['note'])])->save();
            }
            $sent = false;
            if ($request->boolean('send', true)) {
                $sent = rescue(fn () => Invites::send($invite, $token) ?? true, false, report: true);
            }
            $links[] = ['link' => Invites::url($token, $invite), 'email' => $invite->email, 'sent' => (bool) $sent];
        }

        if (! $links) {
            return back()->withErrors([! empty($data['emails']) ? 'emails' : 'email' => $errors[0] ?? 'No invites were sent.'])->withInput();
        }
        $redirect = redirect()->route('admin.users.index', ['status' => 'invited'])
            ->with('invite_link', $links[0])->with('invite_links', $links);
        if ($errors) {
            $redirect->withErrors(['emails' => 'Some invites weren’t sent: '.implode(' ', array_unique($errors))]);
        }

        return $redirect;
    }

    private function nameFromEmail(string $email): string
    {
        $local = Str::before($email, '@');

        return Str::limit(Str::title(trim(preg_replace('/[._\-+0-9]+/', ' ', $local))) ?: $local, 120, '');
    }

    public function show(Request $request, string $id)
    {
        $user = $this->visibleUser($id);
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? $request->query('tab') : 'access';
        $orgs = $user->organizations()->orderBy('name')->get()
            ->when($this->orgIds() !== null, fn ($c) => $c->whereIn('id', $this->orgIds()))->values();
        $me = auth()->user();

        $data = [
            'user' => $user, 'tab' => $tab, 'tabs' => self::TABS, 'orgs' => $orgs,
            'allOrgs' => $this->orgs()->whereNotIn('id', $orgs->pluck('id'))->values(),
            'roles' => Organization::ROLES,
            'invites' => Invite::with('organization')->where('email', $user->email)->whereNull('accepted_at')->whereNull('revoked_at')
                ->when($this->orgIds() !== null, fn ($q) => $q->whereIn('organization_id', $this->orgIds()))->latest()->get(),
            'isSelf' => $user->id === $me->id,
            'canTouch' => ! ($user->isOwner() && ! $me->isOwner()),
            'mfaRequired' => ! $user->hasSecondFactor() && ! $user->mfa_exempt && $user->status !== 'invited' && Claims::policyRequiresMfa($user),
        ];

        return view('admin.users.show', $data + match ($tab) {
            'profile' => $this->profileTab($user, $orgs),
            'security' => $this->securityTab($user),
            'activity' => $this->activityTab($user),
            default => $this->accessTab($user, $orgs, $request),
        });
    }

    private function accessTab(User $user, Collection $orgs, Request $request): array
    {
        $me = auth()->user();
        $cards = $orgs->map(function (Organization $org) use ($user, $me) {
            $profiles = $org->accessProfiles()->get();

            return [
                'org' => $org,
                'apps' => $org->apps()->with(['roles.permissions'])->orderBy('name')->get(),
                'access' => Access::explain($user, $org),
                'profiles' => $profiles,
                'held' => $user->accessProfiles()->where('organization_id', $org->id)->pluck('access_profiles.id')->all(),
                'gives' => $this->profileGives($profiles),
                'canOwner' => Access::canTouchOwnerTier($me, $org->id),
                'orgRole' => $org->pivot->role ?? 'member',
                'canSetRole' => $me->isOwner() || in_array($org->id, $me->ownedOrgIds(), true),
            ];
        });

        // "What <app> can see": one app the person uses (or ?preview=<app id>), with the permissions their role carries.
        $grants = Grant::with(['app', 'role.permissions'])->where('user_id', $user->id)->whereIn('organization_id', $orgs->pluck('id'))->get();
        $previewApps = $grants->pluck('app')->filter()->unique('id')->values();
        if ($previewApps->isEmpty()) {
            $previewApps = $cards->flatMap(fn ($c) => $c['apps'])->unique('id')->values();
        }
        $previewApp = $previewApps->firstWhere('id', $request->query('preview')) ?? $previewApps->first();

        return [
            'cards' => $cards,
            'previewApps' => $previewApps,
            'previewApp' => $previewApp,
            'previewPerms' => $previewApp ? $grants->where('app_id', $previewApp->id)->flatMap(fn ($g) => $g->role?->permissions ?? [])->unique('id')
                ->map(fn ($p) => $p->description ?: $p->key)->values()->all() : [],
            'previewRoles' => $previewApp ? $grants->where('app_id', $previewApp->id)->map(fn ($g) => $g->role?->name)->filter()->unique()->values()->all() : [],
            'preview' => $previewApp ? ['sub' => $user->sub(), 'aud' => $previewApp->key, 'email' => $user->email, 'name' => $user->name, 'orgs' => (object) Claims::orgs($user, $previewApp)] : null,
            'appSessions' => $user->clientSessions()->with('app')->latest('last_used_at')->limit(4)->get(),
        ];
    }

    private function profileTab(User $user, Collection $orgs): array
    {
        return [
            'orgData' => $orgs->map(fn (Organization $org) => [
                'org' => $org,
                'teams' => $org->teams()->get(),
                'heldTeams' => OrgData::teamKeys($user, $org),
                'fieldDefs' => $org->attributeDefs()->get(),
                'fieldValues' => OrgData::values($user, $org),
            ]),
        ];
    }

    private function securityTab(User $user): array
    {
        $scoped = fn ($q) => $q->when($this->orgIds() !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('organization_id')->orWhereIn('organization_id', $this->orgIds())));

        return [
            'lastOwner' => $user->isOwner() && ! User::where('clauth_role', 'owner')->where('id', '!=', $user->id)->exists(),
            'passwordChanged' => AuditEvent::where('subject_type', 'user')->where('subject_id', $user->id)
                ->whereIn('event', ['security.password_changed', 'security.password_reset'])->latest('at')->value('at'),
            'recoveryLeft' => $user->hasTwoFactor() ? count((array) $user->two_factor_recovery_codes) : null,
            'identities' => $user->socialIdentities()->orderBy('provider')->get(),
            'appSessions' => $user->clientSessions()->with('app')->latest('last_used_at')->get(),
            'webSessions' => DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->get(),
            'devices' => $user->devices()->latest('last_seen_at')->get(),
            'signins' => AuditEvent::with('app')->where('subject_type', 'user')->where('subject_id', $user->id)
                ->where(fn ($q) => $q->where('event', 'like', 'signin.%')->orWhere('event', 'security.recovery_code_used'))
                ->tap($scoped)->latest('at')->limit(10)->get(),
        ];
    }

    private function activityTab(User $user): array
    {
        $events = AuditEvent::with(['actor', 'app', 'organization'])->where('subject_type', 'user')->where('subject_id', $user->id)
            ->when($this->orgIds() !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('organization_id')->orWhereIn('organization_id', $this->orgIds())))
            ->latest('at')->latest('id')->paginate(30, ['*'], 'page')->withQueryString();

        return [
            'events' => $events,
            'days' => $events->getCollection()->map(fn (AuditEvent $e) => $this->sentence($e, $user) + ['day' => $this->dayLabel($e->at)])->groupBy('day'),
        ];
    }

    private function dayLabel(Carbon $at): string
    {
        $at = $at->copy()->timezone(config('app.timezone'));

        return match (true) {
            $at->isToday() => 'Today',
            $at->isYesterday() => 'Yesterday',
            $at->greaterThanOrEqualTo(now()->startOfWeek()) => 'Earlier this week',
            $at->greaterThanOrEqualTo(now()->subWeek()->startOfWeek()) => 'Last week',
            $at->isSameYear(now()) => $at->format('F'),
            default => $at->format('F Y'),
        };
    }

    /**
     * One audit event as a short sentence: ['parts' => [['t' => text] | ['b' => bold] | ['badge' => text]], 'sub' => ?string, 'tone' => css var].
     * The view escapes every part.
     */
    private function sentence(AuditEvent $e, User $user): array
    {
        $after = (array) ($e->after ?? []);
        $before = (array) ($e->before ?? []);
        $self = $e->actor_user_id === $user->id;
        $actor = match (true) {
            $self => null,
            $e->actor_user_id && $e->actor_user_id === auth()->id() => 'You',
            (bool) $e->actor => $e->actor->name,
            $e->actor_type === 'api' => 'An API client',
            default => 'Clauth',
        };
        $app = $e->app?->name ?? ($after['app'] ?? null);
        $org = $e->organization?->name;
        $roleName = function (?string $key) use ($e) {
            if (! $key || $key === 'none') {
                return 'No access';
            }

            return ($e->app_id ? AppRole::where('app_id', $e->app_id)->where('key', $key)->value('name') : null) ?? Str::headline($key);
        };
        $where = collect([$org, $e->user_agent ? Agent::short($e->user_agent) : null, $e->ip])->filter()->implode(' · ');

        // [verb phrase parts, sub line]
        [$parts, $sub] = match ($e->event) {
            'signin.success' => [[['t' => 'signed in to '], ['b' => $app ?? 'Clauth']], collect([isset($after['via']) ? 'via '.$after['via'] : null, $where])->filter()->implode(' · ')],
            'signin.failed' => [[['t' => 'failed to sign in']], collect([isset($after['attempts']) ? "attempt {$after['attempts']}" : null, $where])->filter()->implode(' · ')],
            'signin.denied' => [[['t' => 'was turned away from '], ['b' => $app ?? 'an app'], ['t' => ' (no access)']], $where],
            'signin.locked' => [[['t' => 'was locked out after too many tries']], $where],
            'signin.code' => [[['t' => 'signed in with a one-time code']], $where],
            'consent.granted' => [[['t' => 'allowed '], ['b' => $app ?? 'an app'], ['t' => ' to use their account']], $where],
            'consent.revoked' => [[['t' => 'removed access for '], ['b' => $app ?? 'an app']], null],
            'security.mfa_enabled' => [[['t' => 'turned on 2-step verification']], null],
            'security.mfa_reset' => [[['t' => 'reset 2-step verification']], 'They set it up again at next sign-in'],
            'security.mfa_exempted' => [[['t' => 'turned off 2-step verification for this person']], null],
            'security.mfa_exemption_removed' => [[['t' => 'made 2-step follow the normal rules again']], null],
            'security.mfa_skipped' => [[['t' => 'skipped setting up 2-step for now']], null],
            'security.sms_two_step_enabled' => [[['t' => 'turned on 2-step by text message']], null],
            'security.phone_verified' => [[['t' => 'confirmed their phone number']], null],
            'security.recovery_code_used' => [[['t' => 'used a recovery code']], $where],
            'security.password_changed' => [[['t' => 'changed their password']], null],
            'security.password_reset' => [[['t' => 'reset their password']], null],
            'security.password_reset_sent' => [[['t' => 'sent a password reset email']], null],
            'security.signout_everywhere' => [[['t' => 'signed out everywhere']], null],
            'security.session_ended' => [[['t' => 'ended a session']], null],
            'security.refresh_reuse' => [[['t' => 'was signed out of '], ['b' => $app ?? 'an app'], ['t' => ' (token reused)']], null],
            'security.social_linked' => [[['t' => 'linked a '], ['b' => Str::headline($after['provider'] ?? 'outside')], ['t' => ' account']], $after['email'] ?? null],
            'security.social_unlinked' => [[['t' => 'unlinked a '], ['b' => Str::headline($after['provider'] ?? $before['provider'] ?? 'outside')], ['t' => ' account']], null],
            'grant.resolved' => [($after['role'] ?? null)
                ? [['t' => 'now has '], ['badge' => $roleName($after['role'])], ['t' => ' in '], ['b' => $app ?? 'an app']]
                : [['t' => 'lost access to '], ['b' => $app ?? 'an app']],
                collect([$org, isset($before['role']) ? 'was '.$roleName($before['role']) : null, $after['cause'] ?? null])->filter()->implode(' · ')],
            'grant.override_set' => [[['t' => 'set '], ['badge' => $roleName($after['role'] ?? null)], ['t' => ' in '], ['b' => $app ?? 'an app'], ['t' => ' by hand']],
                collect([$org, isset($before['role']) ? 'was '.$roleName($before['role']) : null, $after['reason'] ?? null])->filter()->implode(' · ')],
            'grant.override_cleared' => [[['t' => 'put '], ['b' => $app ?? 'an app'], ['t' => ' back on the job role']], $org],
            'profile.assigned' => [[['t' => 'gave the job role '], ['badge' => $this->profileName($after['profile'] ?? null, $e->organization_id)]], $org],
            'profile.unassigned' => [[['t' => 'removed the job role '], ['badge' => $this->profileName($before['profile'] ?? null, $e->organization_id)]], $org],
            'team.membership_changed' => [[['t' => 'changed teams']], collect([$org, isset($after['teams']) ? 'now '.(implode(', ', (array) $after['teams']) ?: 'none') : null])->filter()->implode(' · ')],
            'user.invited' => [[['t' => 'sent an invite'], ...($org ? [['t' => ' to '], ['b' => $org]] : [])], $after['email'] ?? null],
            'user.invite_accepted' => [[['t' => 'accepted the invite'], ...($org ? [['t' => ' to '], ['b' => $org]] : [])], null],
            'user.invite_revoked' => [[['t' => 'cancelled an invite']], $org],
            'user.signed_up' => [[['t' => 'signed up']], $where],
            'user.created_on_signin' => [[['t' => 'was created on first sign-in']], $where],
            'user.updated' => [[['t' => isset($after['joined_org']) ? 'added them to ' : (isset($after['left_org']) ? 'removed them from ' : 'updated the profile')],
                ...((isset($after['joined_org']) || isset($after['left_org'])) ? [['b' => $org ?? 'an organization']] : [])],
                ($before && ! isset($after['joined_org']) && ! isset($after['left_org'])) ? collect($after)->map(fn ($v, $k) => ($before[$k] ?? null) !== $v ? Str::headline($k) : null)->filter()->implode(', ') ?: null : null],
            'user.fields_changed' => [[['t' => 'changed profile fields']], collect([$org, collect($after)->keys()->map(fn ($k) => Str::headline($k))->implode(', ')])->filter()->implode(' · ')],
            'user.disabled' => [[['t' => 'turned off this account']], null],
            'user.enabled' => [[['t' => 'turned this account back on']], null],
            'user.unlocked' => [[['t' => 'unlocked this account']], null],
            'user.deleted' => [[['t' => 'deleted this account']], null],
            'org.role_changed' => [[['t' => 'made them '], ['badge' => Organization::ROLES[$after['role'] ?? ''] ?? Str::headline($after['role'] ?? 'member')], ...($org ? [['t' => ' of '], ['b' => $org]] : [])],
                isset($before['role']) ? 'was '.(Organization::ROLES[$before['role']] ?? $before['role']) : null],
            'scim.user_provisioned' => [[['t' => 'was added by directory sync']], $org],
            'scim.user_removed' => [[['t' => 'was switched off by directory sync']], $org],
            default => [[['t' => Str::lcfirst(Audit::label($e->event))]], $org],
        };
        // Passive "was …" phrases read about the person, not the actor.
        $passive = str_starts_with($parts[0]['t'] ?? '', 'was ') || str_starts_with($parts[0]['t'] ?? '', 'now has') || str_starts_with($parts[0]['t'] ?? '', 'lost ');
        if ($actor && ! $passive) {
            array_unshift($parts, ['b' => $actor], ['t' => ' ']);
        } else {
            $parts[0] = isset($parts[0]['t']) ? ['t' => Str::ucfirst($parts[0]['t'])] : $parts[0];
            if ($passive && $actor && $actor !== 'Clauth') {
                $sub = trim(($sub ? $sub.' · ' : '').'by '.$actor);
            }
        }

        return [
            'e' => $e,
            'parts' => $parts,
            'sub' => $sub ?: null,
            'tone' => match ($e->category) {
                'signin' => str_ends_with($e->event, 'success') || $e->event === 'signin.code' ? 'var(--info)' : 'var(--warn)',
                'security' => in_array($e->event, ['security.refresh_reuse', 'signin.locked', 'security.mfa_reset', 'security.mfa_exempted'], true) ? 'var(--bad)' : 'var(--warn)',
                'role' => 'var(--ok)',
                default => 'var(--muted-2)',
            },
        ];
    }

    private function profileName(?string $key, ?string $orgId): string
    {
        if (! $key) {
            return 'a job role';
        }

        return AccessProfile::where('key', $key)->when($orgId, fn ($q) => $q->where('organization_id', $orgId))->value('name') ?? Str::headline($key);
    }

    /** Save profiles, per-app exceptions and the org role for one org, then work out the grants (SPEC-roles §6, §9.3). */
    public function grants(Request $request, string $id)
    {
        $user = $this->visibleUser($id);
        $orgId = $request->input('organization_id');
        abort_unless($orgId && $this->canManageOrg($orgId) && $user->organizations->contains('id', $orgId), 404);
        $org = $user->organizations->firstWhere('id', $orgId);
        $request->validate(['profiles' => 'array', 'roles' => 'array', 'reason' => 'nullable|string|max:190']);
        $me = $request->user();
        Access::setProfiles($user, $org, $request->has('profiles_shown') ? (array) $request->input('profiles', []) : $user->accessProfiles()->where('organization_id', $org->id)->pluck('access_profiles.id')->all(), $me);
        $this->saveOrgData($user, $org, $request->has('teams_shown') ? (array) $request->input('teams', []) : null, $request->input('fields'), $me);
        $changed = false;
        foreach ($org->apps as $app) {
            $choice = $request->input("roles.{$app->id}");
            if ($choice !== null) {
                $changed = Access::setOverride($user, $org, $app->id, (string) $choice, $request->input('reason'), $me) || $changed;
            }
        }
        if ($changed) {
            Access::resolve($user, $org, 'exceptions changed', $me);
        }
        $newRole = $request->input('org_role');
        if ($newRole && isset(Organization::ROLES[$newRole]) && (auth()->user()->isOwner() || in_array($orgId, auth()->user()->ownedOrgIds(), true))) {
            $was = $org->pivot->role;
            if ($was !== $newRole) {
                $user->organizations()->updateExistingPivot($orgId, ['role' => $newRole]);
                Audit::log('org.role_changed', 'admin', $user, ['role' => $was], ['role' => $newRole, 'org' => $org->slug], orgId: $orgId);
            }
        }

        return back()->with('status', 'Access saved. It applies the next time the app refreshes its token (within 15 minutes).');
    }

    /** Teams (null = not shown, leave alone) and profile fields (only what changed) for one organization. */
    private function saveOrgData(User $user, Organization $org, ?array $teams, mixed $fields, User $me): void
    {
        if ($teams !== null) {
            OrgData::setTeams($user, $org, $teams, $me);
        }
        if (is_array($fields)) {
            $defs = $org->attributeDefs()->get()->keyBy('key');
            $vals = collect($fields)->only($defs->keys())
                ->map(fn ($v, $k) => $defs[$k]->type === 'bool' ? ($v === '1' ? true : ($v === '0' ? false : null)) : $v);
            $now = OrgData::values($user, $org);
            // Only what changed: an empty required field nobody touched doesn't block saving the rest.
            $vals = $vals->filter(fn ($v, $k) => (string) json_encode($v === '' ? null : $v) !== (string) json_encode($now[$k] ?? null)
                && ! (is_numeric($v) && isset($now[$k]) && $v + 0 == $now[$k]))->all();
            OrgData::setValues($user, $org, $vals, $me);
        }
    }

    public function addOrg(Request $request, string $id)
    {
        $user = $this->visibleUser($id);
        $orgId = $request->input('organization_id');
        abort_unless($orgId && $this->canManageOrg($orgId), 404);
        if (! $user->organizations()->where('organizations.id', $orgId)->exists() && ($msg = Limits::exceeded(Organization::find($orgId), 'people'))) {
            return back()->withErrors(['organization_id' => $msg]);
        }
        $user->organizations()->syncWithoutDetaching([$orgId => ['joined_at' => now()]]);
        Audit::log('user.updated', 'admin', $user, after: ['joined_org' => $orgId], orgId: $orgId);

        return redirect()->route('admin.users.show', [$user->id, 'org' => $orgId, 'tab' => $request->input('tab') === 'profile' ? 'profile' : 'access'])
            ->with('status', "{$user->name} was added to the organization.");
    }

    public function removeOrg(Request $request, string $id)
    {
        $user = $this->visibleUser($id);
        $orgId = $request->input('organization_id');
        abort_unless($orgId && $this->canManageOrg($orgId), 404);
        Access::forgetMember($user, Organization::findOrFail($orgId));
        $user->organizations()->detach($orgId);
        Audit::log('user.updated', 'admin', $user, after: ['left_org' => $orgId], orgId: $orgId);

        return redirect()->route('admin.users.index')->with('status', "{$user->name} was removed from the organization.");
    }

    public function update(Request $request, string $id)
    {
        $user = $this->visibleUser($id);
        $before = $user->only('name', 'phone', 'email');
        $data = $request->validate(['name' => 'required|string|max:120', 'phone' => 'nullable|string|max:40',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)], 'orgdata' => 'array']);
        $orgdata = $data['orgdata'] ?? [];
        unset($data['orgdata']);
        if (! auth()->user()->isOwner()) {
            unset($data['email']);
        }
        $user->update($data);
        Audit::log('user.updated', 'admin', $user, $before, $user->only('name', 'phone', 'email'));
        // Profile tab: teams and fields per organization (only ones this admin runs and the person belongs to).
        foreach ($orgdata as $orgId => $d) {
            $org = $user->organizations->firstWhere('id', (string) $orgId);
            abort_unless($org && $this->canManageOrg((string) $orgId), 404);
            $this->saveOrgData($user, $org, isset($d['teams_shown']) ? (array) ($d['teams'] ?? []) : null, $d['fields'] ?? null, $request->user());
        }

        return back()->with('status', 'Saved.');
    }

    public function action(Request $request, string $id, string $action)
    {
        $user = $this->visibleUser($id);
        abort_if($user->id === auth()->id() && in_array($action, ['disable', 'reset-2fa', 'mfa-off'], true), 422, 'You can’t do that to your own account here.');
        abort_if($user->isOwner() && ! auth()->user()->isOwner(), 404);
        switch ($action) {
            case 'disable':
                $user->update(['status' => 'disabled']);
                Sessions::revokeAll($user);
                Audit::log('user.disabled', 'admin', $user);
                $msg = "{$user->name} is turned off and signed out of every app.";
                break;
            case 'enable':
                $user->update(['status' => $user->password ? 'active' : 'invited']);
                Audit::log('user.enabled', 'admin', $user);
                $msg = "{$user->name} is turned back on.";
                break;
            case 'unlock':
                $user->forceFill(['locked_until' => null, 'failed_logins' => 0])->save();
                Audit::log('user.unlocked', 'admin', $user);
                $msg = "{$user->name} can sign in again.";
                break;
            case 'reset-2fa':
                $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'sms_two_step' => false])->save();
                Sessions::revokeAll($user);
                Audit::log('security.mfa_reset', 'security', $user);
                $msg = "2-step verification reset. {$user->name} sets it up again at next sign-in.";
                break;
            case 'mfa-off':
                abort_if($user->isAdmin(), 422, 'Clauth admins always need 2-step verification.');
                $user->forceFill(['mfa_exempt' => true, 'two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'sms_two_step' => false])->save();
                Sessions::revokeAll($user);
                Audit::log('security.mfa_exempted', 'security', $user);
                $msg = "2-step verification is off for {$user->name}. They sign in with just their password.";
                break;
            case 'mfa-on':
                $user->forceFill(['mfa_exempt' => false])->save();
                Audit::log('security.mfa_exemption_removed', 'security', $user);
                $msg = "2-step verification follows the normal rules again for {$user->name}.";
                break;
            case 'signout':
                Sessions::revokeAll($user);
                Audit::log('security.signout_everywhere', 'security', $user);
                $msg = "{$user->name} was signed out everywhere.";
                break;
            case 'reset-password':
                abort_unless($user->status === 'active', 422, 'Only active users can reset a password.');
                Password::broker()->sendResetLink(['email' => $user->email]);
                $msg = "Password reset email sent to {$user->email}.";
                break;
            case 'reinvite':
                $link = $this->resendInvite($user);
                abort_unless($link, 422);

                return back()->with('invite_link', $link);
            default:
                abort(404);
        }

        return back()->with('status', $msg);
    }

    /** Revokes the person's latest invite (in an organization this admin runs) and sends a fresh link. Null when there is none. */
    private function resendInvite(User $user): ?array
    {
        $last = Invite::where('email', $user->email)
            ->when($this->orgIds() !== null, fn ($q) => $q->whereIn('organization_id', $this->orgIds()))->latest()->first();
        if (! $last || $user->status !== 'invited') {
            return null;
        }
        $last->update(['revoked_at' => now()]);
        [$invite, $token] = Invites::create([
            'name' => $user->name, 'email' => $user->email, 'organization_id' => $last->organization_id,
            'grants' => $last->grants, 'profiles' => $last->profiles, 'require_mfa' => $last->require_mfa, 'is_org_admin' => $last->is_org_admin, 'clauth_role' => $last->clauth_role,
        ], auth()->user());
        if ($last->note) {
            $invite->forceFill(['note' => $last->note])->save();
        }
        $sent = rescue(fn () => Invites::send($invite, $token) ?? true, false, report: true);

        return ['link' => Invites::url($token, $invite), 'email' => $user->email, 'sent' => (bool) $sent];
    }

    public const BULK = ['disable' => 'Turn off', 'enable' => 'Turn back on', 'reinvite' => 'Resend invites', 'add-profile' => 'Add job role', 'remove-org' => 'Remove from organization'];

    /**
     * People list bulk bar. Every id must be someone this admin can see — one outside their organizations and
     * nothing changes (404). Each change is audited like the single actions; people it can't apply to are skipped.
     */
    public function bulk(Request $request)
    {
        $ids = collect((array) $request->input('ids', []))->filter(fn ($v) => is_string($v) && $v !== '')->unique()->values();
        $users = $ids->isEmpty() || $ids->count() > 200 ? collect() : $this->scopeUsers(User::query())->whereIn('id', $ids)->get();
        abort_if($ids->isEmpty() || $users->count() !== $ids->count(), 404);
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(self::BULK))],
            'profile_id' => 'nullable|integer',
            'organization_id' => 'nullable|string',
        ], ['action.required' => 'Choose what to do with the selected people.']);
        $me = $request->user();
        $done = 0;
        $skipped = [];
        // Same limits as the single actions: never yourself, and only Clauth owners touch Clauth owners.
        $blocked = fn (User $u) => $u->id === $me->id ? 'you' : ($u->isOwner() && ! $me->isOwner() ? $u->name : null);

        switch ($data['action']) {
            case 'disable':
            case 'enable':
                foreach ($users as $u) {
                    if ($who = $blocked($u)) {
                        $skipped[] = $who;

                        continue;
                    }
                    if ($data['action'] === 'disable' && $u->status !== 'disabled') {
                        $u->update(['status' => 'disabled']);
                        Sessions::revokeAll($u);
                        Audit::log('user.disabled', 'admin', $u);
                        $done++;
                    } elseif ($data['action'] === 'enable' && $u->status === 'disabled') {
                        $u->update(['status' => $u->password ? 'active' : 'invited']);
                        Audit::log('user.enabled', 'admin', $u);
                        $done++;
                    }
                }
                $msg = $data['action'] === 'disable' ? 'Turned off :n and signed them out of every app.' : 'Turned :n back on.';
                break;
            case 'reinvite':
                $links = [];
                foreach ($users as $u) {
                    if ($u->status !== 'invited') {
                        continue;
                    }
                    ($l = $this->resendInvite($u)) ? $links[] = $l : $skipped[] = $u->email;
                }
                if ($links) {
                    return redirect()->route('admin.users.index', ['status' => 'invited'])->with('invite_link', $links[0])->with('invite_links', $links)
                        ->with('status', 'Sent '.count($links).' new invite '.Str::plural('link', count($links)).'.'.($skipped ? ' Skipped: '.implode(', ', $skipped).'.' : ''));
                }
                $msg = 'No one selected is waiting on an invite.';
                break;
            case 'add-profile':
                if (empty($data['profile_id'])) {
                    return back()->withErrors(['bulk' => 'Choose a job role to add.']);
                }
                $profile = AccessProfile::find($data['profile_id'] ?? 0);
                abort_unless($profile && $this->canManageOrg($profile->organization_id), 404);
                $org = Organization::findOrFail($profile->organization_id);
                abort_if($profile->hasOwnerTier() && ! Access::canTouchOwnerTier($me, $org->id), 403, "Only an owner of {$org->name} can give the {$profile->name} job role.");
                foreach ($users as $u) {
                    if ($u->isOwner() && ! $me->isOwner()) {
                        $skipped[] = $u->name;

                        continue;
                    }
                    if (! $u->organizations()->where('organizations.id', $org->id)->exists()) {
                        $skipped[] = $u->status === 'invited' ? $u->email : $u->name;

                        continue;
                    }
                    $held = $u->accessProfiles()->where('organization_id', $org->id)->pluck('access_profiles.id')->all();
                    if (! in_array($profile->id, $held, true)) {
                        Access::setProfiles($u, $org, [...$held, $profile->id], $me);
                        $done++;
                    }
                }
                $msg = "Gave :n the {$profile->name} job role in {$org->name}.";
                break;
            case 'remove-org':
                $orgId = (string) ($data['organization_id'] ?? '');
                if ($orgId === '') {
                    return back()->withErrors(['bulk' => 'Choose the organization to remove them from.']);
                }
                abort_unless($this->canManageOrg($orgId), 404);
                $org = Organization::findOrFail($orgId);
                foreach ($users as $u) {
                    if ($who = $blocked($u)) {
                        $skipped[] = $who;

                        continue;
                    }
                    if (! $u->organizations()->where('organizations.id', $org->id)->exists()) {
                        continue;
                    }
                    Access::forgetMember($u, $org);
                    $u->organizations()->detach($org->id);
                    Audit::log('user.updated', 'admin', $u, after: ['left_org' => $org->id], orgId: $org->id);
                    $done++;
                }
                $msg = "Removed :n from {$org->name}.";
                break;
        }

        $msg = str_replace(':n', $done === 1 ? '1 person' : "$done people", $msg);
        if ($skipped) {
            $msg .= ' Skipped '.implode(', ', array_unique($skipped)).'.';
        }

        return back()->with('status', $msg);
    }

    /** Opaque handle for a Clauth browser session in URLs (the raw session id never reaches the page). */
    public static function sessionHandle(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 32);
    }

    /** Security tab: sign the person out of one Clauth browser session (web) or one app (app). Same storage as Account › Sessions. */
    public function revokeSession(string $id, string $kind, string $session)
    {
        $user = $this->visibleUser($id);
        abort_if($user->isOwner() && ! auth()->user()->isOwner(), 404);
        if ($kind === 'web') {
            $row = DB::table('sessions')->where('user_id', $user->id)->get(['id', 'user_agent'])
                ->first(fn ($s) => hash_equals(self::sessionHandle($s->id), $session));
            abort_unless($row, 404);
            DB::table('sessions')->where('user_id', $user->id)->where('id', $row->id)->delete();
            Audit::log('security.session_ended', 'security', $user, after: ['session' => 'clauth', 'device' => Agent::short($row->user_agent)]);
            $msg = "{$user->name} was signed out of that browser.";
        } else {
            abort_unless(ctype_digit($session), 404);
            $cs = ClientSession::with('app')->where('user_id', $user->id)->findOrFail((int) $session);
            Sessions::revokeClient($user, $cs->client_id);
            Audit::log('security.session_ended', 'security', $user, after: ['session' => 'app', 'device' => Agent::short($cs->user_agent)], appId: $cs->app_id);
            $msg = "{$user->name} was signed out of ".($cs->app?->name ?? 'the app').'.';
        }

        return back()->with('status', $msg);
    }

    /** Security tab: remove a linked sign-in (Google or a connection), only while another way to sign in remains. */
    public function unlink(string $id, string $provider)
    {
        $user = $this->visibleUser($id);
        abort_if($user->isOwner() && ! auth()->user()->isOwner(), 404);
        $identity = $user->socialIdentities()->where('provider', $provider)->firstOrFail();
        $name = Str::headline($provider);
        if (! $user->password && ! $user->socialIdentities()->where('id', '!=', $identity->id)->exists()) {
            return back()->withErrors(['unlink' => "{$name} is the only way {$user->name} signs in. Send them a password reset first, then unlink it."]);
        }
        $identity->delete();
        Audit::log('security.social_unlinked', 'security', $user, before: ['provider' => $provider, 'email' => $identity->email]);

        return back()->with('status', "{$name} is no longer linked to {$user->name}.");
    }

    /**
     * Delete an account (Clauth owners only; organization admins remove people from their organization instead).
     * Never yourself or the last owner; confirmed by typing the email. Sessions, tokens, memberships and access go,
     * open invites are cancelled, and the row is anonymised then soft-deleted so the audit trail keeps pointing at it.
     */
    public function destroy(Request $request, string $id)
    {
        $user = $this->visibleUser($id);
        $me = $request->user();
        abort_unless($me->isOwner(), 403, 'Only Clauth owners can delete accounts. Remove them from your organization instead.');
        abort_if($user->id === $me->id, 422, 'You can’t delete your own account here.');
        abort_if($user->isOwner() && ! User::where('clauth_role', 'owner')->where('id', '!=', $user->id)->exists(), 422, 'Clauth needs at least one owner.');
        $typed = mb_strtolower(trim((string) $request->input('confirm_email')));
        if ($typed === '' || ! hash_equals(mb_strtolower($user->email), $typed)) {
            return back()->withErrors(['confirm_email' => 'Type their email address exactly to confirm.']);
        }

        $email = $user->email;
        $name = $user->name;
        DB::transaction(function () use ($user, $email) {
            Sessions::revokeAll($user);
            foreach ($user->organizations as $org) {
                Access::forgetMember($user, $org);
            }
            Grant::where('user_id', $user->id)->delete();
            GrantOverride::where('user_id', $user->id)->delete();
            $user->accessProfiles()->detach();
            $user->teams()->detach();
            DB::table('user_attributes')->where('user_id', $user->id)->delete();
            $user->organizations()->detach();
            $user->socialIdentities()->delete();
            $user->devices()->delete();
            Invite::where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $user->forceFill(['email' => 'deleted+'.$user->id.'@deleted.invalid', 'name' => 'Deleted user', 'phone' => null, 'password' => null,
                'two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'status' => 'disabled'])->save();
            $user->delete();
        });
        Audit::log('user.deleted', 'admin', $user, before: ['email' => $email]);

        return redirect()->route('admin.users.index')->with('status', "{$name}’s account was deleted.");
    }
}
