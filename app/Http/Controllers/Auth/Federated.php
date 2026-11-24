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
        'invite_email' => 'That account uses a different email. Continue with the account for the email your invite was sent to.',
    ];

    /**
     * Accepting an invite through a provider ("Continue with Google" on the invite page). The invite page keeps its
     * token in the session (invite.token) and its buttons add ?invite=1; a hop from an app's own domain carries the
     * token in the query to the main domain, the same way it carries the app. Returns [invite, token] or [null, null].
     */
    protected function inviteForFlow(Request $request): array
    {
        $q = (string) $request->query('invite');
        $token = $q === '1' ? (string) $request->session()->get('invite.token') : $q;
        $invite = $token !== '' && strlen($token) <= 100 ? Invite::findByToken($token) : null;

        return $invite?->isUsable() ? [$invite, $token] : [null, null];
    }

    /** Main domain, before leaving for the provider: remember (or forget) the invite this sign-in accepts. */
    protected function stashInvite(Request $request, ?Invite $invite, ?string $token): void
    {
        if (! $invite) {
            $request->session()->forget('social.invite');

            return;
        }
        $request->session()->put('social.invite', $token);
        if (($app = $invite->primaryApp()) && ! $request->session()->has('login.client_id')) {
            $request->session()->put('login.app_key', $app->key);
        }
    }

    protected function federatedFinish(Request $request, string $provider, string $id, ?string $email, bool $verified, ?Connection $conn, ?string $name, ?string $back)
    {
        $email = $email ? mb_strtolower(trim($email)) : null;
        // Started from an invite page whose account isn't set up yet: this sign-in sets it up (invited email only).
        $inviteToken = $request->session()->get('social.invite');
        $invite = $inviteToken ? Invite::findByToken((string) $inviteToken) : null;
        $invitee = $invite?->isUsable() ? User::where('email', $invite->email)->first() : null;
        if ($invite?->isUsable() && (! $invitee || ($invitee->status === 'invited' && ! $invitee->password))) {
            return $this->federatedInvite($request, $invite, $invitee, $provider, $id, $email, $verified, $conn, $name, $back);
        }
        $request->session()->forget('social.invite');
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
            return $this->toLocked($request, $user);
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
        $request->session()->put('login.via', $provider);
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

    /**
     * Set up an invited account through a provider. Only the invited email may accept: the provider must report exactly
     * that address and vouch for it (Google: verified; a connection: as Connections::mayLinkByEmail would for a new
     * member — the inviting organization's own provider for its domains, or a platform provider trusted for email).
     */
    private function federatedInvite(Request $request, Invite $invite, ?User $user, string $provider, string $id, ?string $email, bool $verified, ?Connection $conn, ?string $name, ?string $back)
    {
        $identity = SocialIdentity::where('provider', $provider)->where('provider_id', $id)->first();
        if (! $email || ! hash_equals($invite->email, $email) || ($identity && $identity->user?->email !== $invite->email)) {
            return $this->federatedFail($back, 'invite_email');
        }
        $vouched = ! $conn ? $verified : ($conn->organization_id
            ? $conn->organization_id === $invite->organization_id && in_array(strtolower(Str::after($email, '@')), $conn->cfg('domains', []), true)
            : $conn->cfg('trust_email', $conn->type === 'oidc') && $verified);
        if (! $vouched) {
            return $this->federatedFail($back, 'unverified');
        }
        $request->session()->forget('social.invite');
        $user ??= new User(['email' => $invite->email]);
        $user->forceFill(['name' => $user->name ?: ($invite->name ?: ($name ?: Str::before($email, '@'))), 'status' => 'active', 'email_verified_at' => now()])->save();
        Invites::apply($invite, $user);
        $user->refresh();
        $identity = $user->socialIdentities()->updateOrCreate(['provider' => $provider], ['provider_id' => $id, 'email' => $email]);
        $identity->forceFill(['last_used_at' => now()])->save();
        $conn?->forceFill(['last_used_at' => now()])->save();
        Audit::log('security.social_linked', 'security', $user, after: ['provider' => $provider, 'email' => $email, 'via' => 'invite'], actorId: $user->id, actorType: 'user');

        // From here on it's the invite's onboarding, exactly as after "Create account" (InviteController::store).
        $app = $invite->primaryApp();
        $request->session()->regenerate();
        $request->session()->put(['login.user_id' => $user->id, 'login.amr' => ['fed'], 'login.via' => $provider, 'invite.flow' => true]);
        if ($app && ! $request->session()->has('login.client_id')) {
            $request->session()->put('login.app_key', $app->key);
        }
        $request->session()->put('url.intended', $back ? "https://$back/login?sso=1" : ($app?->home_url ?: route('account')));
        if ($invite->require_mfa) {
            $request->session()->put('invite_require_mfa', true);
        }
        if (! $user->mfa_exempt) {
            return redirect()->route('login.mfa.setup');
        }
        $request->session()->forget(['invite.flow', 'invite_require_mfa']);

        return $this->completeLogin($request, $user, ['fed'], false);
    }

    /** Back to the page the person started on (sign-in, or their invite), with a fixed message (never text from outside). */
    protected function federatedFail(?string $back, string $code)
    {
        $invite = session()->pull('social.invite');
        $path = $invite ? 'invite/'.rawurlencode((string) $invite).'?'.http_build_query(['social_error' => $code]) : 'login?'.http_build_query(['social_error' => $code]);

        return redirect()->away($back ? "https://$back/$path" : Domains::mainUrl($path));
    }
}
