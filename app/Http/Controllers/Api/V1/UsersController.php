<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiContext;
use App\Api\Etag;
use App\Api\Present;
use App\Services\Access;
use App\Models\User;
use App\Services\Audit;
use App\Services\Sessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

/** /api/v1/users (SPEC-platform §6.1). Same rules as Admin › Users; an org client sees its own members only. */
class UsersController
{
    use UsesApi;

    public function index(Request $request)
    {
        $request->validate(['status' => 'nullable|in:active,invited,disabled,locked', 'org' => 'nullable|string', 'app' => 'nullable|string', 'q' => 'nullable|string|max:120']);
        $q = $this->api()->scopeUsers(User::query()->with('organizations'));
        match ($request->query('status')) {
            'active', 'invited', 'disabled' => $q->where('status', $request->query('status')),
            'locked' => $q->where('locked_until', '>', now()),
            default => null,
        };
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%"));
        }
        if ($org = $request->query('org')) {
            $id = $this->api()->org($org)->id;
            $q->whereHas('organizations', fn ($o) => $o->where('organizations.id', $id));
        }
        if ($app = $request->query('app')) {
            $id = $this->api()->app($app)->id;
            $q->whereHas('grants', fn ($g) => $g->where('app_id', $id)->when($this->api()->orgIds() !== null, fn ($x) => $x->whereIn('organization_id', $this->api()->orgIds())));
        }

        return Present::page($q, fn (User $u) => Present::user($u, $this->api()->orgIds()), $request);
    }

    public function show(string $user)
    {
        $u = $this->api()->user($user)->load('organizations', 'socialIdentities');

        return Etag::attach(response()->json(Present::user($u, $this->api()->orgIds(), true)), $u);
    }

    /**
     * DELETE /users/{user} — platform only: deletes the account everywhere. Sessions end, memberships and access go,
     * and the email is freed (the row stays, anonymised, for the audit trail). Org clients remove people from
     * their organization with DELETE /orgs/{org}/members/{user} instead.
     */
    public function destroy(string $user)
    {
        abort_unless($this->api()->isPlatform(), 403, 'Only platform clients delete accounts. To take someone out of your organization: DELETE /orgs/{org}/members/{user}.');
        $u = $this->api()->user($user);
        abort_if($u->isOwner() || $u->id === $this->api()->actor->id, 422, 'Clauth owners and the caller’s own account cannot be deleted here.');
        Etag::check($u);
        Sessions::revokeAll($u);
        foreach ($u->organizations as $org) {
            Access::forgetMember($u, $org);
        }
        $u->organizations()->detach();
        $u->socialIdentities()->delete();
        $email = $u->email;
        $u->forceFill(['email' => 'deleted+'.$u->id.'@deleted.invalid', 'name' => 'Deleted user', 'phone' => null, 'password' => null,
            'two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'status' => 'disabled'])->save();
        $u->delete();
        Audit::log('user.deleted', 'admin', $u, before: ['email' => $email]);

        return response()->noContent();
    }

    public function update(Request $request, string $user)
    {
        $u = $this->api()->user($user);
        $data = $request->validate(['name' => 'sometimes|required|string|max:120', 'phone' => 'sometimes|nullable|string|max:40',
            'email' => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($u->id)]]);
        abort_if(isset($data['email']) && ! $this->api()->isPlatform(), 403, 'Only platform clients can change an email address.');
        abort_if($u->isOwner() && ! $this->api()->isPlatform(), 404, 'User not found.');
        Etag::check($u);
        $before = $u->only(array_keys($data));
        $u->update($data);
        Audit::log('user.updated', 'admin', $u, $before, $u->only(array_keys($data)));

        return $this->show($user);
    }

    /** POST /users/{user}/{action} — the console's user actions. */
    public function action(string $user, string $action)
    {
        $u = $this->api()->user($user);
        $me = $this->api()->actor;
        abort_if($u->isOwner() && ! $this->api()->isPlatform(), 404, 'User not found.');
        abort_if($u->id === $me->id && in_array($action, ['disable', 'reset-two-step', 'two-step-exempt'], true), 422, 'A client cannot do that to the account of the person who created it.');
        switch ($action) {
            case 'disable':
                $u->update(['status' => 'disabled']);
                Sessions::revokeAll($u);
                Audit::log('user.disabled', 'admin', $u);
                break;
            case 'enable':
                abort_unless($u->status === 'disabled', 409, 'The user is not disabled.');
                $u->update(['status' => $u->password ? 'active' : 'invited']);
                Audit::log('user.enabled', 'admin', $u);
                break;
            case 'unlock':
                $u->forceFill(['locked_until' => null, 'failed_logins' => 0])->save();
                Audit::log('user.unlocked', 'admin', $u);
                break;
            case 'reset-two-step':
                $u->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'sms_two_step' => false])->save();
                Sessions::revokeAll($u);
                Audit::log('security.mfa_reset', 'security', $u);
                break;
            case 'two-step-exempt':
                abort_if($u->isAdmin(), 422, 'Clauth admins always need 2-step verification.');
                $u->forceFill(['mfa_exempt' => true, 'two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'sms_two_step' => false])->save();
                Sessions::revokeAll($u);
                Audit::log('security.mfa_exempted', 'security', $u);
                break;
            case 'two-step-required':
                $u->forceFill(['mfa_exempt' => false])->save();
                Audit::log('security.mfa_exemption_removed', 'security', $u);
                break;
            case 'sign-out':
                Sessions::revokeAll($u);
                Audit::log('security.signout_everywhere', 'security', $u);
                break;
            case 'password-reset':
                abort_unless($u->status === 'active', 422, 'Only active users can reset a password.');
                Password::broker()->sendResetLink(['email' => $u->email]);
                Audit::log('security.password_reset_sent', 'security', $u);
                break;
            default:
                abort(404, 'Unknown action.');
        }

        return $this->show($user);
    }
}
