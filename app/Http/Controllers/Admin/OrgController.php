<?php

namespace App\Http\Controllers\Admin;

use App\Models\Application;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit;
use App\Services\Installations;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Organizations (SPEC-organizations §4–§5). Clauth owners see and edit all of them; org owners/admins see their own,
 * manage which apps are installed, approve install requests, and (org owners) change people's org roles.
 */
class OrgController
{
    use Scope;

    public function index()
    {
        $me = auth()->user();

        return view('admin.orgs.index', [
            'orgs' => Organization::withCount('users')
                ->with(['apps.publisher', 'installRequests.publisher', 'users' => fn ($q) => $q->wherePivotIn('role', ['owner', 'admin', 'developer'])->orderBy('name')])
                ->when($this->orgIds() !== null, fn ($q) => $q->whereIn('id', $this->orgIds()))->orderBy('name')->get(),
            // Apps this person may add to an organization they run: everything for Clauth owners, else their own orgs' apps.
            'addable' => Application::with('publisher')->orderBy('name')
                ->when(! $me->isOwner(), fn ($q) => $q->whereIn('owner_organization_id', $me->devOrgIds()))->get(),
            'canEdit' => $me->isOwner(),
            'ownedOrgIds' => $me->ownedOrgIds(),
            'roles' => Organization::ROLES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'slug' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{1,60}$/', Rule::unique('organizations', 'slug')],
            'is_platform' => 'nullable|boolean']);
        $org = Organization::create(['name' => $data['name'], 'slug' => $data['slug'], 'is_platform' => $request->boolean('is_platform'),
            'verified_at' => $request->boolean('is_platform') ? now() : null]);
        Audit::log('org.created', 'admin', $org, after: $org->only('name', 'slug', 'is_platform'), orgId: $org->id);

        return back()->with('status', "{$org->name} created.");
    }

    /** Clauth owners: name, status and whether it is one of William's own (platform) organizations. */
    public function update(Request $request, Organization $org)
    {
        $before = $org->only('name', 'status', 'is_platform');
        $data = $request->validate(['name' => 'required|string|max:120', 'status' => 'required|in:active,disabled', 'is_platform' => 'nullable|boolean']);
        $org->update(['name' => $data['name'], 'status' => $data['status'], 'is_platform' => $request->boolean('is_platform'),
            'verified_at' => $request->boolean('is_platform') ? ($org->verified_at ?? now()) : $org->verified_at]);
        Audit::log('org.updated', 'admin', $org, $before, $org->only('name', 'status', 'is_platform'), orgId: $org->id);

        return back()->with('status', "{$org->name} saved.");
    }

    /** Set a member's role in the organization. Clauth owners and the organization's owners only. */
    public function role(Request $request, Organization $org)
    {
        $this->visibleOrg($org);
        $me = $request->user();
        abort_unless($me->isOwner() || in_array($org->id, $me->ownedOrgIds(), true), 404);
        $data = $request->validate(['email' => 'required|email', 'role' => ['required', Rule::in(array_keys(Organization::ROLES))]]);
        $user = User::where('email', mb_strtolower(trim($data['email'])))->first();
        $current = $user?->orgRole($org->id);
        if (! $current) {
            return back()->withErrors(['email' => 'That person isn’t a member of '.$org->name.'. Invite them first.']);
        }
        if ($current === 'owner' && $data['role'] !== 'owner' && $org->users()->wherePivot('role', 'owner')->count() === 1 && ! $me->isOwner()) {
            return back()->withErrors(['email' => 'An organization needs at least one owner. Make someone else owner first.']);
        }
        $user->organizations()->updateExistingPivot($org->id, ['role' => $data['role']]);
        Audit::log('org.role_changed', 'admin', $user, ['role' => $current], ['role' => $data['role'], 'org' => $org->slug], orgId: $org->id);

        return back()->with('status', "{$user->name} is now ".Organization::ROLES[$data['role']]." of {$org->name}.");
    }

    /** Make one of your own apps available in an organization you run. */
    public function addApp(Request $request, Organization $org)
    {
        $this->visibleOrg($org);
        $data = $request->validate(['app_id' => 'required|string']);
        $app = Application::findOrFail($data['app_id']);
        $me = $request->user();
        abort_unless($me->isOwner() || in_array($app->owner_organization_id, $me->devOrgIds(), true), 404);
        Installations::add($app, $org, $me);

        return back()->with('status', "{$app->name} can now be used in {$org->name}.");
    }

    /** Remove an app from an organization: its grants there are removed and those people signed out of it. */
    public function removeApp(Organization $org, Application $app)
    {
        $this->visibleOrg($org);
        abort_unless(Installations::status($app, $org), 404);
        Installations::remove($app, $org);

        return back()->with('status', "{$app->name} removed from {$org->name}. Access given through it is gone.");
    }

    public function approve(Organization $org, Application $app)
    {
        $this->visibleOrg($org);
        Installations::approve($app, $org, auth()->user());

        return back()->with('status', "{$app->name} can now be used in {$org->name}. Give people access on their user page.");
    }

    private function visibleOrg(Organization $org): void
    {
        abort_unless($this->canManageOrg($org->id), 404);
    }
}
