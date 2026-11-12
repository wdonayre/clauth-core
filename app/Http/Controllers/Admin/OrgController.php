<?php

namespace App\Http\Controllers\Admin;

use App\Mail\OrgRequestMail;
use App\Models\Application;
use App\Models\Organization;
use App\Models\OrganizationRequest;
use App\Models\User;
use App\Services\Audit;
use App\Services\Installations;
use App\Services\Invites;
use App\Services\OrgLifecycle;
use App\Services\Suspensions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
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
            'requests' => $me->isOwner() ? OrganizationRequest::where('status', 'pending')->whereNotNull('email_verified_at')->latest()->get() : collect(),
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
        $before = $org->only('name', 'status', 'is_platform', 'verified_at', 'limits');
        $keys = array_keys(config('clauth.limits.verified'));
        $data = $request->validate(['name' => 'required|string|max:120', 'status' => 'required|in:active,disabled', 'is_platform' => 'nullable|boolean',
            'verified' => 'nullable|boolean', 'limits' => 'array', 'limits.*' => 'nullable|integer|min:0|max:1000000']);
        $verified = $request->boolean('is_platform') || $request->boolean('verified');
        $limits = collect($data['limits'] ?? [])->only($keys)->reject(fn ($v) => $v === null || $v === '')->map(fn ($v) => (int) $v)->all();
        $org->update(['name' => $data['name'], 'status' => $data['status'], 'is_platform' => $request->boolean('is_platform'),
            'verified_at' => $verified ? ($org->verified_at ?? now()) : null, 'limits' => $limits ?: null]);
        Audit::log('org.updated', 'admin', $org, $before, $org->only('name', 'status', 'is_platform', 'verified_at', 'limits'), orgId: $org->id);

        return back()->with('status', "{$org->name} saved.");
    }

    /**
     * Approve an outside organization's request: the organization is created (unverified, low limits) and the contact
     * is invited as its owner, with 2-step verification required.
     */
    public function approveRequest(OrganizationRequest $orgRequest)
    {
        abort_unless($orgRequest->status === 'pending' && $orgRequest->email_verified_at, 404);
        if (Organization::where('slug', $orgRequest->slug)->exists()) {
            return back()->withErrors(['request' => "The short name {$orgRequest->slug} is taken now. Reject with a note, or rename it."]);
        }
        $org = Organization::create(['name' => $orgRequest->org_name, 'slug' => $orgRequest->slug, 'is_platform' => false,
            'terms_accepted_at' => now(), 'terms_version' => config('clauth.terms_version')]);
        [$invite, $token] = Invites::create(['name' => $orgRequest->contact_name, 'email' => $orgRequest->contact_email,
            'organization_id' => $org->id, 'org_role' => 'owner', 'require_mfa' => true, 'grants' => []], auth()->user());
        rescue(fn () => Invites::send($invite, $token), report: true);
        $orgRequest->update(['status' => 'approved', 'decided_by' => auth()->id(), 'decided_at' => now(), 'organization_id' => $org->id]);
        Audit::log('org.request_approved', 'admin', $org, after: ['slug' => $org->slug, 'contact' => $orgRequest->contact_email], orgId: $org->id);

        return back()->with('status', "{$org->name} created. {$orgRequest->contact_email} was invited as its owner.");
    }

    public function rejectRequest(Request $request, OrganizationRequest $orgRequest)
    {
        abort_unless($orgRequest->status === 'pending', 404);
        $data = $request->validate(['reason' => 'nullable|string|max:500']);
        $orgRequest->update(['status' => 'rejected', 'reject_reason' => $data['reason'] ?? null, 'decided_by' => auth()->id(), 'decided_at' => now()]);
        rescue(fn () => Mail::to($orgRequest->contact_email)->send(new OrgRequestMail($orgRequest, 'rejected')), report: true);
        Audit::log('org.request_rejected', 'admin', $orgRequest, after: ['org' => $orgRequest->org_name, 'contact' => $orgRequest->contact_email]);

        return back()->with('status', "Request from {$orgRequest->org_name} rejected.");
    }

    /** Clauth owners: suspend (with a reason shown to the organization's staff) or restore. */
    public function suspend(Request $request, Organization $org)
    {
        abort_if($org->is_platform, 422, 'William’s own organizations can’t be suspended here.');
        $data = $request->validate(['reason' => 'required|string|max:500']);
        Suspensions::suspendOrg($org, $data['reason']);

        return back()->with('status', "{$org->name} is suspended. Its apps can’t sign anyone in.");
    }

    public function restore(Organization $org)
    {
        Suspensions::restoreOrg($org);

        return back()->with('status', "{$org->name} is active again.");
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

    /** The organization's own domain (proved with a TXT record) — part of getting verified. Owners and admins. */
    public function domain(Request $request, Organization $org)
    {
        $this->visibleOrg($org);
        $data = $request->validate(['domain' => ['required', 'max:190', 'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i']],
            ['domain.regex' => 'Enter a domain like example.com.']);
        $parent = collect(config('clauth.reserved_parent_domains'))->first(fn ($p) => strtolower($data['domain']) === $p || str_ends_with(strtolower($data['domain']), '.'.$p));
        if ($parent && ! $org->is_platform) {
            return back()->withErrors(['domain' => "{$parent} is reserved."]);
        }
        OrgLifecycle::setDomain($org, $data['domain']);

        return back()->with('status', 'Add the TXT record shown, then check it.');
    }

    public function checkDomain(Organization $org)
    {
        $this->visibleOrg($org);
        $ok = OrgLifecycle::checkDomain($org);

        return back()->with('status', $ok ? "{$org->domain} is verified. Clauth will review {$org->name} for the Verified badge." : 'The TXT record isn’t visible yet. DNS changes can take a few minutes.');
    }

    /** An outside organization's owner accepts the current Terms + Data Processing Agreement. */
    public function acceptTerms(Request $request, Organization $org)
    {
        $this->visibleOrg($org);
        abort_unless($request->user()->isOwner() || in_array($org->id, $request->user()->ownedOrgIds(), true), 404);
        $request->validate(['agree' => 'accepted'], ['agree.accepted' => 'Tick the box to accept.']);
        $org->update(['terms_accepted_at' => now(), 'terms_version' => config('clauth.terms_version'), 'terms_accepted_by' => $request->user()->id]);
        Audit::log('org.terms_accepted', 'admin', $org, after: ['version' => $org->terms_version], orgId: $org->id);

        return back()->with('status', 'Thanks. The terms are accepted for '.$org->name.'.');
    }

    /** Download everything Clauth holds about the organization (JSON). Owners and admins. */
    public function export(Organization $org)
    {
        $this->visibleOrg($org);
        Audit::log('org.exported', 'admin', $org, orgId: $org->id);

        return response()->json(OrgLifecycle::export($org), 200, [
            'Content-Disposition' => 'attachment; filename="clauth-'.$org->slug.'-'.now()->format('Y-m-d').'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Ask for the organization to be deleted after a 30-day grace period. Owners only; never William's own. */
    public function requestDeletion(Request $request, Organization $org)
    {
        $this->visibleOrg($org);
        abort_if($org->is_platform, 422, 'William’s own organizations can’t be deleted here.');
        abort_unless($request->user()->isOwner() || in_array($org->id, $request->user()->ownedOrgIds(), true), 404);
        $request->validate(['confirm' => ['required', Rule::in([$org->slug])]], ['confirm.in' => 'Type the organization’s short name to confirm.']);
        OrgLifecycle::requestDeletion($org, $request->user());

        return back()->with('status', "{$org->name} will be deleted on ".now()->addDays(OrgLifecycle::GRACE_DAYS)->format('j M Y').'. You can cancel until then.');
    }

    public function cancelDeletion(Request $request, Organization $org)
    {
        $this->visibleOrg($org);
        abort_unless($request->user()->isOwner() || in_array($org->id, $request->user()->ownedOrgIds(), true), 404);
        OrgLifecycle::cancelDeletion($org);

        return back()->with('status', "Deletion of {$org->name} cancelled.");
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
