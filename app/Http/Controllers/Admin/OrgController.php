<?php

namespace App\Http\Controllers\Admin;

use App\Mail\OrgRequestMail;
use App\Models\AppDomain;
use App\Models\Application;
use App\Models\Grant;
use App\Models\Organization;
use App\Models\OrganizationRequest;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Installations;
use App\Services\Invites;
use App\Services\OrgLifecycle;
use App\Services\Suspensions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Organizations (SPEC-organizations §4–§5). Clauth owners see and edit all of them; org owners/admins see their own,
 * manage which apps are installed, approve install requests, and (org owners) change people's org roles.
 */
class OrgController
{
    use Scope;

    /** Organizations list: people + apps counts, admins, status; pending organization requests for Clauth owners. */
    public function index()
    {
        $me = auth()->user();

        return view('admin.orgs.index', [
            'orgs' => Organization::withCount(['users', 'apps'])
                ->with(['apps:id,name', 'publishedApps:id,name,owner_organization_id', 'installRequests.publisher',
                    'users' => fn ($q) => $q->wherePivotIn('role', ['owner', 'admin'])->orderBy('name')])
                ->when($this->orgIds() !== null, fn ($q) => $q->whereIn('id', $this->orgIds()))->orderBy('name')->get(),
            'canEdit' => $me->isOwner(),
            'requests' => $me->isOwner() ? OrganizationRequest::where('status', 'pending')->whereNotNull('email_verified_at')->latest()->get() : collect(),
        ]);
    }

    /** People tab: everyone in the organization, their org role, job roles and how many apps they can use here. */
    public function show(Request $request, Organization $org)
    {
        $this->visibleOrg($org);
        $q = trim((string) $request->query('q', ''));
        $role = $request->query('role');
        $people = $org->users()
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('users.name', 'like', "%{$q}%")->orWhere('users.email', 'like', '%'.mb_strtolower($q).'%')))
            ->when(isset(Organization::ROLES[$role]), fn ($w) => $w->wherePivot('role', $role))
            ->orderBy('users.name')->paginate(50)->withQueryString();
        $ids = $people->getCollection()->pluck('id');
        $jobRoles = DB::table('access_profile_user')->join('access_profiles', 'access_profiles.id', '=', 'access_profile_user.access_profile_id')
            ->where('access_profiles.organization_id', $org->id)->whereIn('access_profile_user.user_id', $ids)
            ->orderBy('access_profiles.name')->get(['access_profile_user.user_id', 'access_profiles.name'])->groupBy('user_id');
        $appCounts = Grant::where('organization_id', $org->id)->whereIn('user_id', $ids)
            ->selectRaw('user_id, count(distinct app_id) as n')->groupBy('user_id')->pluck('n', 'user_id');
        $profiles = $org->accessProfiles()->withCount('users')->get();
        $appsPer = DB::table('access_profile_roles')->whereIn('access_profile_id', $profiles->pluck('id'))
            ->selectRaw('access_profile_id, count(*) as n')->groupBy('access_profile_id')->pluck('n', 'access_profile_id');

        $suggestions = Access::suggestions($org, $request->user());

        return view('admin.orgs.show', $this->orgHead($org) + [
            'suggestion' => $suggestions[0] ?? null, 'suggestionCount' => count($suggestions),
            'people' => $people, 'jobRoles' => $jobRoles, 'appCounts' => $appCounts, 'profiles' => $profiles, 'appsPer' => $appsPer,
            'q' => $q, 'role' => isset(Organization::ROLES[$role]) ? $role : null,
            'canRoles' => $this->canOwn($org), 'roles' => Organization::ROLES,
        ]);
    }

    /** Apps tab: apps people here can use, install requests waiting, and adding one. */
    public function apps(Request $request, Organization $org)
    {
        $this->visibleOrg($org);
        $me = $request->user();
        $apps = $org->apps()->with('publisher')->orderBy('name')->get();
        $people = Grant::where('organization_id', $org->id)->selectRaw('app_id, count(distinct user_id) as n')->groupBy('app_id')->pluck('n', 'app_id');
        $addable = Application::with('publisher')->orderBy('name')
            ->when(! $me->isOwner(), fn ($q) => $q->whereIn('owner_organization_id', $me->devOrgIds()))
            ->whereNotIn('id', $apps->pluck('id'))->get();

        return view('admin.orgs.apps', $this->orgHead($org) + [
            'apps' => $apps, 'people' => $people, 'addable' => $addable,
            'requests' => $org->installRequests()->with('publisher')->get(),
            'canBuild' => $me->isOwner() || in_array($org->id, $me->devOrgIds(), true),
        ]);
    }

    /** Sign-in domain tab: the organization's own sign-in addresses (set up on an app's page) and its proven domain. */
    public function domainPage(Organization $org)
    {
        $this->visibleOrg($org);

        return view('admin.orgs.domain', $this->orgHead($org) + [
            'domains' => AppDomain::where('organization_id', $org->id)->with('apps')->orderBy('host')->get(),
            'manageable' => $org->publishedApps()->orderBy('name')->get()->filter(fn ($a) => auth()->user()->canManageApp($a))->values(),
        ]);
    }

    /** Settings tab: general details, status & limits, agreements, export, suspension and deletion. */
    public function settings(Request $request, Organization $org)
    {
        $this->visibleOrg($org);

        return view('admin.orgs.settings', $this->orgHead($org) + [
            'canEdit' => $request->user()->isOwner(), 'canOwn' => $this->canOwn($org),
        ]);
    }

    /** Organization owners (and Clauth owners): name and website. Status, verification and limits stay with Clauth owners. */
    public function general(Request $request, Organization $org)
    {
        $this->visibleOrg($org);
        abort_unless($this->canOwn($org), 404);
        $data = $request->validate(['name' => 'required|string|max:120', 'website' => 'nullable|url:http,https|max:190']);
        $before = $org->only('name', 'website');
        $org->update(['name' => $data['name'], 'website' => $data['website'] ?? null]);
        Audit::log('org.updated', 'admin', $org, $before, $org->only('name', 'website'), orgId: $org->id);

        return back()->with('status', "{$org->name} saved.");
    }

    /**
     * Branding (SPEC-branding B1): the level the organization wants (up to what Clauth allows it), account name,
     * color, support email and legal links. Owners/admins of the organization and Clauth owners.
     */
    public function branding(Request $request, Organization $org)
    {
        $this->visibleOrg($org);
        $data = $request->validate(\App\Services\Brand::SETTINGS);
        $msg = self::saveBranding($org, $data);

        return $msg ? back()->withErrors(['branding_mode' => $msg])->withInput() : back()->with('status', "{$org->name} branding saved.");
    }

    /** Shared by the console and the API; returns an error message, or null when saved. */
    public static function saveBranding(Organization $org, array $data): ?string
    {
        if (isset($data['branding_mode']) && \App\Services\Brand::rank($data['branding_mode']) > \App\Services\Brand::rank($org->branding_allowed)) {
            return 'Clauth allows '.$org->name.' up to “'.\App\Services\Brand::LEVELS[$org->branding_allowed ?? 'clauth'].'”. Ask Clauth to allow more.';
        }
        $keys = array_keys(\App\Services\Brand::SETTINGS);
        $before = $org->only($keys);
        $org->update(array_intersect_key($data, array_flip($keys)));
        Audit::log('org.branding_updated', 'admin', $org, $before, $org->only($keys), orgId: $org->id);

        return null;
    }

    /** Upload the organization's logo (PNG or SVG, 512 KB; same rules and disk as app logos). Owners/admins of it, Clauth owners. */
    public function logo(Request $request, Organization $org)
    {
        $this->visibleOrg($org);
        $request->validate(['logo' => 'required|file|mimes:png,svg|max:512'], ['logo.mimes' => 'Upload a PNG or SVG.', 'logo.max' => 'The logo can be up to 512 KB.']);
        $file = $request->file('logo');
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext === 'svg' && preg_match('/<script|on\w+=|javascript:/i', (string) file_get_contents($file->getRealPath()))) {
            return back()->withErrors(['logo' => 'That SVG contains scripts. Upload a clean SVG or a PNG.']);
        }
        $before = $org->logo_path;
        $org->update(['logo_path' => $file->storeAs('logos', 'org-'.$org->slug.'-'.Str::random(6).'.'.($ext === 'svg' ? 'svg' : 'png'), 'local')]);
        if ($before && $before !== $org->logo_path) {
            Storage::disk('local')->delete($before);
        }
        Audit::log('org.updated', 'admin', $org, ['logo' => $before], ['logo' => $org->logo_path], orgId: $org->id);

        return back()->with('status', "{$org->name}’s logo saved.");
    }

    public function removeLogo(Organization $org)
    {
        $this->visibleOrg($org);
        abort_unless($org->logo_path, 404);
        $before = $org->logo_path;
        $org->update(['logo_path' => null]);
        Storage::disk('local')->delete($before);
        Audit::log('org.updated', 'admin', $org, ['logo' => $before], ['logo' => null], orgId: $org->id);

        return back()->with('status', "{$org->name}’s logo removed.");
    }

    /** Serves the uploaded logo from Clauth's own domain, locked down like app logos. */
    public function showLogo(Organization $org)
    {
        $this->visibleOrg($org);
        $path = $org->logo_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, max-age=86400',
            'Content-Type' => str_ends_with($path, '.svg') ? 'image/svg+xml' : 'image/png',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'", 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Logo URL for an organization, or null (show initials). Works with partly-selected models (the sidebar switcher
     * loads only id/name/slug): those are looked up once per request.
     */
    public static function logoUrl(Organization $o): ?string
    {
        if (array_key_exists('logo_path', $o->getAttributes())) {
            $path = $o->logo_path;
        } else {
            $bag = request()->attributes;
            if (! $bag->has('clauth.org_logos')) {
                $bag->set('clauth.org_logos', Organization::whereNotNull('logo_path')->pluck('logo_path', 'id')->all());
            }
            $path = $bag->get('clauth.org_logos')[$o->id] ?? null;
        }

        return $path ? route('admin.orgs.logo.show', [$o->id, 'v' => substr(md5($path), 0, 8)]) : null;
    }

    /** Review one organization request (Clauth owners). */
    public function showRequest(OrganizationRequest $orgRequest)
    {
        $host = fn (?string $u) => $u ? preg_replace('/^www\./', '', mb_strtolower((string) parse_url($u, PHP_URL_HOST))) : null;
        $site = $host($orgRequest->website);
        $mail = mb_strtolower(substr(strrchr($orgRequest->contact_email, '@') ?: '', 1));

        return view('admin.orgs.request', [
            'r' => $orgRequest,
            'checks' => [
                'email' => (bool) $orgRequest->email_verified_at,
                'match' => $site ? ($mail === $site || str_ends_with($mail, '.'.$site)) : null,
                'slug' => ! Organization::where('slug', $orgRequest->slug)->exists(),
            ],
            'org' => $orgRequest->organization_id ? Organization::find($orgRequest->organization_id) : null,
        ]);
    }

    /** Shared numbers for the organization header. */
    private function orgHead(Organization $org): array
    {
        return ['org' => $org, 'peopleCount' => $org->users()->count(), 'appsCount' => $org->apps()->count(),
            'signInHost' => AppDomain::where('organization_id', $org->id)->where('status', 'active')->orderBy('host')->value('host')];
    }

    /** Clauth owner or an owner of this organization. */
    private function canOwn(Organization $org): bool
    {
        $me = auth()->user();

        return $me->isOwner() || in_array($org->id, $me->ownedOrgIds(), true);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'slug' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{1,60}$/', Rule::unique('organizations', 'slug')],
            'is_platform' => 'nullable|boolean']);
        $org = Organization::create(['name' => $data['name'], 'slug' => $data['slug'], 'is_platform' => $request->boolean('is_platform'),
            'verified_at' => $request->boolean('is_platform') ? now() : null]);
        Audit::log('org.created', 'admin', $org, after: $org->only('name', 'slug', 'is_platform'), orgId: $org->id);
        Access::starterProfiles($org, auth()->user());

        return back()->with('status', "{$org->name} created.");
    }

    /** Clauth owners: name, status and whether it is one of William's own (platform) organizations. */
    public function update(Request $request, Organization $org)
    {
        $before = $org->only('name', 'status', 'is_platform', 'verified_at', 'limits', 'branding_allowed');
        $keys = array_keys(config('clauth.limits.verified'));
        $data = $request->validate(['name' => 'required|string|max:120', 'status' => 'required|in:active,disabled', 'is_platform' => 'nullable|boolean',
            'verified' => 'nullable|boolean', 'limits' => 'array', 'limits.*' => 'nullable|integer|min:0|max:1000000',
            'branding_allowed' => 'nullable|in:clauth,cobrand,whitelabel']);
        $verified = $request->boolean('is_platform') || $request->boolean('verified');
        $limits = collect($data['limits'] ?? [])->only($keys)->reject(fn ($v) => $v === null || $v === '')->map(fn ($v) => (int) $v)->all();
        $org->update(['name' => $data['name'], 'status' => $data['status'], 'is_platform' => $request->boolean('is_platform'),
            'verified_at' => $verified ? ($org->verified_at ?? now()) : null, 'limits' => $limits ?: null]
            + (isset($data['branding_allowed']) ? ['branding_allowed' => $data['branding_allowed']] : []));
        // Lowering what's allowed lowers the organization's choice with it.
        if (\App\Services\Brand::rank($org->branding_mode) > \App\Services\Brand::rank($org->branding_allowed)) {
            $org->update(['branding_mode' => $org->branding_allowed]);
        }
        Audit::log('org.updated', 'admin', $org, $before, $org->only('name', 'status', 'is_platform', 'verified_at', 'limits', 'branding_allowed'), orgId: $org->id);

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
        $org = Organization::create(['name' => $orgRequest->org_name, 'slug' => $orgRequest->slug, 'is_platform' => false, 'website' => $orgRequest->website,
            'terms_accepted_at' => now(), 'terms_version' => config('clauth.terms_version')]);
        [$ownerProfile] = Access::starterProfiles($org, auth()->user());
        [$invite, $token] = Invites::create(['name' => $orgRequest->contact_name, 'email' => $orgRequest->contact_email,
            'organization_id' => $org->id, 'org_role' => 'owner', 'require_mfa' => true, 'grants' => [], 'profiles' => [$ownerProfile->id]], auth()->user());
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
