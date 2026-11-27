<?php

namespace App\Http\Controllers\Admin;

use App\Mail\OrgRequestMail;
use App\Models\Organization;
use App\Models\OrganizationRequest;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Brand;
use App\Services\Invites;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Clauth owners: organization requests (SPEC-organizations §8, SPEC-signup.md). Approve (creates the organization and
 * makes the person its Owner), ask for more information, or decline with a note the person sees.
 */
class OrgRequestController
{
    public const TABS = [
        'waiting' => 'Waiting', 'info' => 'More info asked', 'approved' => 'Approved', 'declined' => 'Declined or closed',
    ];

    /** Free email providers: an address there says nothing about the organization. */
    private const FREE_MAIL = ['gmail.com', 'googlemail.com', 'yahoo.com', 'ymail.com', 'outlook.com', 'hotmail.com', 'live.com',
        'msn.com', 'icloud.com', 'me.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'mail.com', 'zoho.com'];

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'waiting';
        $q = fn (string $t) => match ($t) {
            'waiting' => OrganizationRequest::waiting(),
            'info' => OrganizationRequest::where('status', 'info_requested'),
            'approved' => OrganizationRequest::where('status', 'approved'),
            'declined' => OrganizationRequest::whereIn('status', ['rejected', 'cancelled', 'expired']),
        };

        return view('admin.orgs.requests', [
            'tab' => $tab,
            'counts' => collect(array_keys(self::TABS))->mapWithKeys(fn ($t) => [$t => $q($t)->count()]),
            'requests' => $q($tab)->with(['user', 'sourceApp'])->latest('updated_at')->paginate(50)->withQueryString(),
            'checksFor' => fn (OrganizationRequest $r) => $this->checks($r),
        ]);
    }

    public function show(OrganizationRequest $orgRequest)
    {
        return view('admin.orgs.request', [
            'r' => $orgRequest->loadMissing(['user', 'sourceApp']),
            'checks' => $this->checks($orgRequest),
            'org' => $orgRequest->organization_id ? Organization::find($orgRequest->organization_id) : null,
            'limits' => config('clauth.limits.unverified'),
        ]);
    }

    public function approve(Request $request, OrganizationRequest $orgRequest)
    {
        abort_unless($orgRequest->status === 'pending' && $orgRequest->email_verified_at, 404);
        $data = $request->validate([
            'people_limit' => 'nullable|integer|min:1|max:100000',
            'apps_limit' => 'nullable|integer|min:0|max:1000',
            'branding_allowed' => ['nullable', Rule::in(array_keys(Brand::LEVELS))],
        ]);
        if (Organization::where('slug', $orgRequest->slug)->exists()) {
            return back()->withErrors(['request' => "The short name {$orgRequest->slug} is taken now. Ask them to pick another one."]);
        }
        $person = $orgRequest->user_id ? User::where('id', $orgRequest->user_id)->where('status', 'active')->first() : null;
        if ($orgRequest->user_id && ! $person) {
            return back()->withErrors(['request' => 'The account that sent this request is no longer active. Decline it instead.']);
        }
        $me = $request->user();
        $limits = array_filter(['people' => $data['people_limit'] ?? null, 'apps' => $data['apps_limit'] ?? null], fn ($v) => $v !== null);

        $org = DB::transaction(function () use ($orgRequest, $person, $me, $limits, $data) {
            $org = Organization::create(['name' => $orgRequest->org_name, 'slug' => $orgRequest->slug, 'is_platform' => false,
                'website' => $orgRequest->website, 'terms_accepted_at' => now(), 'terms_version' => config('clauth.terms_version'),
                'limits' => $limits ?: null, 'branding_allowed' => $data['branding_allowed'] ?? 'clauth']);
            [$ownerProfile] = Access::starterProfiles($org, $me);
            if ($person) {
                // The request came from a signed-in account: that person is the Owner now, no invite email needed.
                $person->organizations()->syncWithoutDetaching([$org->id => ['joined_at' => now(), 'role' => 'owner']]);
                $person->accessProfiles()->syncWithoutDetaching([$ownerProfile->id => ['assigned_by' => $me->id, 'assigned_at' => now()]]);
                Access::resolve($person, $org, 'organization approved', $me);
            } else {
                [$invite, $token] = Invites::create(['name' => $orgRequest->contact_name, 'email' => $orgRequest->contact_email,
                    'organization_id' => $org->id, 'org_role' => 'owner', 'require_mfa' => true, 'grants' => [], 'profiles' => [$ownerProfile->id]], $me);
                rescue(fn () => Invites::send($invite, $token), report: true);
            }
            $orgRequest->update(['status' => 'approved', 'decided_by' => $me->id, 'decided_at' => now(), 'organization_id' => $org->id]);

            return $org;
        });
        if ($person) {
            rescue(fn () => Mail::to($person->email)->send(new OrgRequestMail($orgRequest, 'approved', route('admin.orgs.show', $org->id))), report: true);
        }
        Audit::log('org.request_approved', 'admin', $org, after: ['slug' => $org->slug, 'contact' => $orgRequest->contact_email,
            'limits' => $limits, 'branding_allowed' => $org->branding_allowed], orgId: $org->id);

        return redirect()->route('admin.org-requests.show', $orgRequest->id)->with('status', $person
            ? "{$org->name} created. {$person->email} is its Owner and has been emailed."
            : "{$org->name} created. {$orgRequest->contact_email} was invited as its owner.");
    }

    /** Ask the person something before deciding. Their answer sends the request back to Waiting. */
    public function askInfo(Request $request, OrganizationRequest $orgRequest)
    {
        abort_unless($orgRequest->status === 'pending' && $orgRequest->user_id, 404);
        $data = $request->validate(['message' => 'required|string|min:5|max:1000'], ['message.required' => 'Write the question you want answered.']);
        $orgRequest->update(['status' => 'info_requested', 'info_message' => $data['message'], 'info_requested_at' => now()]);
        rescue(fn () => Mail::to($orgRequest->contact_email)->send(new OrgRequestMail($orgRequest, 'info', route('org-request.edit'))), report: true);
        Audit::log('org.request_info_asked', 'admin', $orgRequest, after: ['org' => $orgRequest->org_name, 'message' => $data['message']]);

        return redirect()->route('admin.org-requests.index')->with('status', "Question sent to {$orgRequest->contact_email}.");
    }

    public function reject(Request $request, OrganizationRequest $orgRequest)
    {
        abort_unless($orgRequest->isOpen(), 404);
        $data = $request->validate(['reason' => 'nullable|string|max:500']);
        $orgRequest->update(['status' => 'rejected', 'reject_reason' => $data['reason'] ?? null, 'decided_by' => $request->user()->id, 'decided_at' => now()]);
        rescue(fn () => Mail::to($orgRequest->contact_email)->send(new OrgRequestMail($orgRequest, 'rejected')), report: true);
        Audit::log('org.request_rejected', 'admin', $orgRequest, after: ['org' => $orgRequest->org_name, 'contact' => $orgRequest->contact_email]);

        return redirect()->route('admin.org-requests.index')->with('status', "Request from {$orgRequest->org_name} declined.");
    }

    /** Quick checks shown on the list and the review page. Null = can't tell. */
    private function checks(OrganizationRequest $r): array
    {
        $host = fn (?string $u) => $u ? preg_replace('/^www\./', '', mb_strtolower((string) parse_url($u, PHP_URL_HOST))) : null;
        $site = $host($r->website);
        $mail = mb_strtolower(substr(strrchr($r->contact_email, '@') ?: '', 1));
        $free = in_array($mail, self::FREE_MAIL, true);

        return [
            'email' => (bool) $r->email_verified_at,
            'free' => $free,
            'match' => $site && ! $free ? ($mail === $site || str_ends_with($mail, '.'.$site)) : null,
            'slug' => ! Organization::where('slug', $r->slug)->exists(),
            'reserved' => collect(config('clauth.reserved_key_prefixes'))->contains(fn ($p) => str_starts_with($r->slug, $p)),
            'first' => $r->user_id ? OrganizationRequest::where('user_id', $r->user_id)->whereKeyNot($r->id)->doesntExist() : null,
            'owns' => $r->user_id ? DB::table('organization_user')->where('user_id', $r->user_id)->where('role', 'owner')->count() : null,
        ];
    }
}
