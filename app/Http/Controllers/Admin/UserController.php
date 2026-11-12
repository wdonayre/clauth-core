<?php

namespace App\Http\Controllers\Admin;

use App\Models\Application;
use App\Models\AppRole;
use App\Models\AuditEvent;
use App\Models\Grant;
use App\Models\Invite;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit;
use App\Services\Claims;
use App\Services\Invites;
use App\Services\Sessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

class UserController
{
    use Scope;

    public function index(Request $request)
    {
        $status = $request->query('status', 'all');
        $q = $this->scopeUsers(User::query()->with(['organizations', 'grants.app', 'grants.role']));
        $counts = [
            'all' => (clone $q)->count(),
            'active' => (clone $q)->where('status', 'active')->count(),
            'invited' => (clone $q)->where('status', 'invited')->count(),
            'locked' => (clone $q)->where('locked_until', '>', now())->count(),
            'disabled' => (clone $q)->where('status', 'disabled')->count(),
        ];
        match ($status) {
            'active', 'invited', 'disabled' => $q->where('status', $status),
            'locked' => $q->where('locked_until', '>', now()),
            default => null,
        };
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%"));
        }
        if ($app = $request->query('app')) {
            $q->whereHas('grants.app', fn ($a) => $a->where('key', $app));
        }
        if ($org = $request->query('org')) {
            $q->whereHas('organizations', fn ($o) => $o->where('slug', $org));
        }

        return view('admin.users.index', [
            'users' => $q->orderBy('name')->paginate(25)->withQueryString(),
            'counts' => $counts, 'status' => $status,
            'orgs' => $this->orgs(),
            // Only apps installed in the organizations this admin runs (invite dialog).
            'apps' => Application::with(['roles', 'organizations' => fn ($q) => $q->when($this->orgIds() !== null, fn ($w) => $w->whereIn('organizations.id', $this->orgIds()))])
                ->when($this->orgIds() !== null, fn ($q) => $q->whereHas('organizations', fn ($o) => $o->whereIn('organizations.id', $this->orgIds())))->orderBy('name')->get(),
            'invited' => session('invite_link'),
        ]);
    }

    public function invite(Request $request)
    {
        $orgIds = collect($this->orgs())->pluck('id')->all();
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'organization_id' => ['required', Rule::in($orgIds)],
            'roles' => 'array',
            'roles.*' => 'nullable|integer',
            'require_mfa' => 'boolean',
            'is_org_admin' => 'boolean',
            'send' => 'boolean',
        ]);
        $existing = User::where('email', mb_strtolower($data['email']))->first();
        if ($existing && $existing->status === 'disabled') {
            return back()->withErrors(['email' => 'That person’s account is disabled. Turn it back on first.'])->withInput();
        }
        $grants = [];
        foreach ($data['roles'] ?? [] as $appId => $roleId) {
            if (! $roleId) {
                continue;
            }
            $role = AppRole::where('id', $roleId)->where('app_id', $appId)->firstOrFail();
            abort_unless($role->app->organizations->contains('id', $data['organization_id']), 422, 'App not enabled for this organization.');
            $grants[] = ['app_id' => $appId, 'role_id' => $role->id];
        }
        if (! empty($data['is_org_admin']) && ! auth()->user()->isOwner() && ! in_array($data['organization_id'], auth()->user()->ownedOrgIds(), true)) {
            $data['is_org_admin'] = false; // only Clauth owners and the organization's owners hand out admin
        }
        [$invite, $token] = Invites::create($data + ['grants' => $grants], auth()->user());
        $link = Invites::url($token, $invite);
        $sent = false;
        if ($request->boolean('send', true)) {
            $sent = rescue(fn () => Invites::send($invite, $token) ?? true, false, report: true);
        }

        return redirect()->route('admin.users.index', ['status' => 'invited'])
            ->with('invite_link', ['link' => $link, 'email' => $invite->email, 'sent' => (bool) $sent]);
    }

    public function show(string $id)
    {
        $user = $this->visibleUser($id);
        $orgs = $user->organizations()->orderBy('name')->get()
            ->when($this->orgIds() !== null, fn ($c) => $c->whereIn('id', $this->orgIds()));
        $org = $orgs->firstWhere('id', request('org')) ?? $orgs->first();
        $apps = $org ? $org->apps()->with(['roles.permissions'])->orderBy('name')->get() : collect();
        $grants = Grant::where('user_id', $user->id)->when($org, fn ($q) => $q->where('organization_id', $org->id))->get()->keyBy('app_id');
        $previewApp = $apps->first(fn ($a) => $grants->has($a->id)) ?? $apps->first();

        return view('admin.users.show', [
            'user' => $user, 'orgs' => $orgs, 'org' => $org, 'apps' => $apps, 'grants' => $grants,
            'allOrgs' => $this->orgs()->whereNotIn('id', $orgs->pluck('id')),
            'previewApp' => $previewApp,
            'preview' => $previewApp ? ['sub' => $user->sub(), 'aud' => $previewApp->key, 'email' => $user->email, 'name' => $user->name, 'orgs' => (object) Claims::orgs($user, $previewApp)] : null,
            'invites' => Invite::where('email', $user->email)->whereNull('accepted_at')->whereNull('revoked_at')->latest()->get(),
            'events' => AuditEvent::with('actor')->where('subject_type', 'user')->where('subject_id', $user->id)
                ->when($this->orgIds() !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('organization_id')->orWhereIn('organization_id', $this->orgIds())))
                ->latest('at')->limit(10)->get(),
            'orgRole' => $org?->pivot->role ?? 'member',
            'canSetRole' => $org && (auth()->user()->isOwner() || in_array($org->id, auth()->user()->ownedOrgIds(), true)),
            'roles' => Organization::ROLES,
        ]);
    }

    /** Save access + role for every app of one org. */
    public function grants(Request $request, string $id)
    {
        $user = $this->visibleUser($id);
        $orgId = $request->input('organization_id');
        abort_unless($orgId && $this->canManageOrg($orgId) && $user->organizations->contains('id', $orgId), 404);
        $org = $user->organizations->firstWhere('id', $orgId);
        foreach ($org->apps as $app) {
            $roleId = $request->input("roles.{$app->id}");
            $old = Grant::with('role')->where(['user_id' => $user->id, 'app_id' => $app->id, 'organization_id' => $orgId])->first();
            if (! $roleId) {
                if ($old) {
                    $old->delete();
                    Sessions::revokeApp($user, $app->id);
                    Audit::log('grant.removed', 'role', $user, ['app' => $app->key, 'role' => $old->role->key], null, $app->id, $orgId);
                }

                continue;
            }
            $role = AppRole::where('app_id', $app->id)->findOrFail($roleId);
            if ($old?->app_role_id == $role->id) {
                continue;
            }
            Grant::updateOrCreate(['user_id' => $user->id, 'app_id' => $app->id, 'organization_id' => $orgId],
                ['app_role_id' => $role->id, 'granted_by' => auth()->id(), 'granted_at' => now()]);
            Audit::log('grant.changed', 'role', $user, $old ? ['app' => $app->key, 'role' => $old->role->key] : null, ['app' => $app->key, 'role' => $role->key], $app->id, $orgId);
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

    public function addOrg(Request $request, string $id)
    {
        $user = $this->visibleUser($id);
        $orgId = $request->input('organization_id');
        abort_unless($orgId && $this->canManageOrg($orgId), 404);
        $user->organizations()->syncWithoutDetaching([$orgId => ['joined_at' => now()]]);
        Audit::log('user.updated', 'admin', $user, after: ['joined_org' => $orgId], orgId: $orgId);

        return redirect()->route('admin.users.show', [$user->id, 'org' => $orgId]);
    }

    public function removeOrg(Request $request, string $id)
    {
        $user = $this->visibleUser($id);
        $orgId = $request->input('organization_id');
        abort_unless($orgId && $this->canManageOrg($orgId), 404);
        foreach (Grant::where(['user_id' => $user->id, 'organization_id' => $orgId])->get() as $g) {
            $g->delete();
            Sessions::revokeApp($user, $g->app_id);
        }
        $user->organizations()->detach($orgId);
        Audit::log('user.updated', 'admin', $user, after: ['left_org' => $orgId], orgId: $orgId);

        return redirect()->route('admin.users.index')->with('status', "{$user->name} was removed from the organization.");
    }

    public function update(Request $request, string $id)
    {
        $user = $this->visibleUser($id);
        $before = $user->only('name', 'phone', 'email');
        $data = $request->validate(['name' => 'required|string|max:120', 'phone' => 'nullable|string|max:40',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)]]);
        if (! auth()->user()->isOwner()) {
            unset($data['email']);
        }
        $user->update($data);
        Audit::log('user.updated', 'admin', $user, $before, $user->only('name', 'phone', 'email'));

        return back()->with('status', 'Saved.');
    }

    public function action(Request $request, string $id, string $action)
    {
        $user = $this->visibleUser($id);
        abort_if($user->id === auth()->id() && in_array($action, ['disable', 'reset-2fa']), 422, 'You can’t do that to your own account here.');
        abort_if($user->isOwner() && ! auth()->user()->isOwner(), 404);
        switch ($action) {
            case 'disable':
                $user->update(['status' => 'disabled']);
                Sessions::revokeAll($user);
                Audit::log('user.disabled', 'admin', $user);
                $msg = "{$user->name} is disabled and signed out of every app.";
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
                $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
                Sessions::revokeAll($user);
                Audit::log('security.mfa_reset', 'security', $user);
                $msg = "2-step verification reset. {$user->name} sets it up again at next sign-in.";
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
                $last = Invite::where('email', $user->email)->latest()->first();
                abort_unless($last && $user->status === 'invited', 422);
                $last->update(['revoked_at' => now()]);
                [$invite, $token] = Invites::create([
                    'name' => $user->name, 'email' => $user->email, 'organization_id' => $last->organization_id,
                    'grants' => $last->grants, 'require_mfa' => $last->require_mfa, 'is_org_admin' => $last->is_org_admin, 'clauth_role' => $last->clauth_role,
                ], auth()->user());
                $sent = rescue(fn () => Invites::send($invite, $token) ?? true, false, report: true);

                return back()->with('invite_link', ['link' => Invites::url($token, $invite), 'email' => $user->email, 'sent' => (bool) $sent]);
            default:
                abort(404);
        }

        return back()->with('status', $msg);
    }
}
