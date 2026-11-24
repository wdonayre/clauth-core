<?php

namespace App\Http\Controllers\Auth;

use App\Models\Connection;
use App\Models\Invite;
use App\Models\SocialIdentity;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Claims;
use App\Services\Connections;
use App\Services\Domains;
use App\Services\Invites;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Finishing a sign-in through an outside provider (Google, or a connection: OIDC, OAuth, Facebook). Clauth stays
 * invite-only: a provider signs in people who already have an account (linked, or matched by email under the rules in
 * Connections::mayLinkByEmail), or — for an organization's own provider with "create accounts" on — new people from
 * that organization's email domains. 2-step rules apply exactly as after a password.
 */
trait Federated
{
    use Concerns;

    public static array $federatedErrors = [
        'no_account' => 'There’s no Clauth account for that sign-in. Ask your admin for an invite.',
        'unverified' => 'That provider hasn’t verified your email address, so we can’t use it to sign you in.',
        'link_first' => 'Sign in with your password first, then link this account under My account.',
        'invited' => 'Your account isn’t set up yet. Use the link in your invite email first.',
        'disabled' => 'This account is turned off. Ask your admin.',
        'failed' => 'Sign-in with that provider didn’t finish. Please try again.',
        'cancelled' => 'Sign-in was cancelled.',
    ];

    protected function federatedFinish(Request $request, string $provider, string $id, ?string $email, bool $verified, ?Connection $conn, ?string $name, ?string $back)
    {
        $email = $email ? mb_strtolower(trim($email)) : null;
        $identity = SocialIdentity::where('provider', $provider)->where('provider_id', $id)->first();
        $user = $identity?->user;
        if (! $user) {
            if (! $email) {
                return $this->federatedFail($back, 'no_account');
            }
            $match = User::where('email', $email)->first();
            if ($match) {
                if (! ($conn ? Connections::mayLinkByEmail($conn, $match, $verified) : $verified)) {
                    return $this->federatedFail($back, $conn ? 'link_first' : 'unverified');
                }
                $user = $match;
            } elseif ($conn?->organization_id && $conn->cfg('jit') && in_array(strtolower(Str::after($email, '@')), $conn->cfg('domains', []), true)) {
                // Just-in-time: the organization's own provider vouches for its domain.
                $org = $conn->organization;
                $user = User::create(['email' => $email, 'name' => $name ?: Str::before($email, '@'), 'status' => 'active', 'email_verified_at' => now()]);
                $user->organizations()->attach($org->id, ['joined_at' => now(), 'role' => 'member']);
                Access::resolve($user, $org, 'first sign-in through '.$conn->name, null);
                Audit::log('user.created_on_signin', 'admin', $user, after: ['connection' => $conn->key], orgId: $org->id, actorId: $user->id, actorType: 'system');
            } else {
                return $this->federatedFail($back, 'no_account');
            }
        }
        if ($user->status === 'invited') {
            $invite = $conn?->organization_id ? Invite::where('email', $user->email)->where('organization_id', $conn->organization_id)
                ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->latest()->first() : null;
            if (! $invite) {
                return $this->federatedFail($back, 'invited');
            }
            $user->forceFill(['status' => 'active', 'email_verified_at' => now()])->save();
            Invites::apply($invite, $user);
        }
        if ($user->status === 'disabled') {
            return $this->federatedFail($back, 'disabled');
        }
        if ($user->isLocked()) {
            return redirect()->route('login.locked');
        }
        if (! $identity) {
            $identity = $user->socialIdentities()->updateOrCreate(['provider' => $provider], ['provider_id' => $id, 'email' => $email]);
            Audit::log('security.social_linked', 'security', $user, after: ['provider' => $provider, 'email' => $email, 'via' => 'sign-in'], actorId: $user->id, actorType: 'user');
        }
        $identity->forceFill(['last_used_at' => now(), 'email' => $email ?: $identity->email])->save();
        $conn?->forceFill(['last_used_at' => now()])->save();

        $remember = (bool) $request->session()->get('login.remember', false);
        $request->session()->regenerate();
        $request->session()->put(['login.user_id' => $user->id, 'login.remember' => $remember]);
        $request->session()->put('login.amr', ['fed']);
        if ($back) {
            $request->session()->put('url.intended', "https://$back/login?sso=1");
        }
        if (Claims::mfaRequired($user, $this->flowApp($request))) {
            if (! $user->hasSecondFactor()) {
                return redirect()->route('login.mfa.setup');
            }
            if ($this->isTrustedDevice($request, $user)) {
                return $this->completeLogin($request, $user, ['fed'], true);
            }

            return redirect()->route('login.mfa');
        }

        return $this->completeLogin($request, $user, ['fed'], false);
    }

    /** Back to the sign-in page the person started on, with a fixed message (never text from outside). */
    protected function federatedFail(?string $back, string $code)
    {
        $path = 'login?'.http_build_query(['social_error' => $code]);

        return redirect()->away($back ? "https://$back/$path" : Domains::mainUrl($path));
    }
}
