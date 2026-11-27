<?php

namespace App\Http\Controllers\OAuth;

use App\Mail\AccessRequestMail;
use App\Models\AccessRequest;
use App\Models\OAuthClient;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * POST /oauth/access-request — on the "no access yet" screen of an invite-only app: ask the app's organization for
 * access (SPEC-signup.md). Its owners and admins are emailed; they approve with a role, or decline.
 */
class AccessRequestController
{
    public function store(Request $request)
    {
        $pending = $request->session()->get('join.request');
        $user = $request->user();
        abort_unless($pending && $user, 419);
        $app = OAuthClient::with('app.publisher')->findOrFail($pending['client'])->app;
        abort_unless($app && $app->publisher, 404);
        $data = $request->validate(['note' => 'nullable|string|max:500']);

        $ar = AccessRequest::firstOrCreate(['user_id' => $user->id, 'app_id' => $app->id, 'status' => 'pending'],
            ['organization_id' => $app->owner_organization_id, 'note' => $data['note'] ?? null]);
        if ($ar->wasRecentlyCreated) {
            Audit::log('access.requested', 'admin', $user, after: ['app' => $app->key], appId: $app->id, orgId: $app->owner_organization_id, actorId: $user->id, actorType: 'user');
            $admins = DB::table('organization_user')->join('users', 'users.id', '=', 'organization_user.user_id')
                ->where('organization_user.organization_id', $app->owner_organization_id)->whereIn('organization_user.role', ['owner', 'admin'])
                ->where('users.status', 'active')->pluck('users.email');
            foreach ($admins as $email) {
                rescue(fn () => Mail::to($email)->send(new AccessRequestMail($ar, 'new', route('admin.orgs.show', $app->owner_organization_id))), report: true);
            }
        }

        return redirect()->to($pending['url']);
    }
}
