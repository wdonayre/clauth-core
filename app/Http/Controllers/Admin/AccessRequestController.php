<?php

namespace App\Http\Controllers\Admin;

use App\Mail\AccessRequestMail;
use App\Models\AccessRequest;
use App\Models\AppRole;
use App\Models\Organization;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Limits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/** Organization owners/admins: people who asked for access to one of the organization's apps (SPEC-signup.md). */
class AccessRequestController
{
    use Scope;

    public function approve(Request $request, Organization $org, AccessRequest $accessRequest)
    {
        $ar = $this->open($org, $accessRequest);
        $data = $request->validate(['role' => 'required|integer']);
        $role = AppRole::where('app_id', $ar->app_id)->where('assignable', true)->findOrFail($data['role']);
        $user = $ar->user;
        if (! $user->organizations()->whereKey($org->id)->exists()) {
            if ($msg = Limits::exceeded($org, 'people')) {
                return back()->withErrors(['access' => $msg]);
            }
            $user->organizations()->attach($org->id, ['joined_at' => now(), 'role' => 'member']);
        }
        Access::setOverride($user, $org, $ar->app_id, (string) $role->id, 'Asked for access', $request->user());
        Access::resolve($user, $org, 'access request approved', $request->user());
        $ar->update(['status' => 'approved', 'decided_by' => $request->user()->id, 'decided_at' => now()]);
        Audit::log('access.request_approved', 'admin', $user, after: ['app' => $ar->app->key, 'role' => $role->key], appId: $ar->app_id, orgId: $org->id);
        rescue(fn () => Mail::to($user->email)->send(new AccessRequestMail($ar, 'approved', $ar->app->home_url)), report: true);

        return back()->with('status', "{$user->name} can now use {$ar->app->name} as {$role->name}.");
    }

    public function decline(Request $request, Organization $org, AccessRequest $accessRequest)
    {
        $ar = $this->open($org, $accessRequest);
        $ar->update(['status' => 'declined', 'decided_by' => $request->user()->id, 'decided_at' => now()]);
        Audit::log('access.request_declined', 'admin', $ar->user, after: ['app' => $ar->app->key], appId: $ar->app_id, orgId: $org->id);
        rescue(fn () => Mail::to($ar->user->email)->send(new AccessRequestMail($ar, 'declined')), report: true);

        return back()->with('status', "Request from {$ar->user->name} declined.");
    }

    private function open(Organization $org, AccessRequest $ar): AccessRequest
    {
        abort_unless($this->canManageOrg($org->id) && $ar->organization_id === $org->id && $ar->status === 'pending', 404);
        abort_unless(in_array(auth()->user()->orgRole($org->id), ['owner', 'admin'], true) || auth()->user()->isOwner(), 403);

        return $ar->loadMissing(['user', 'app']);
    }
}
