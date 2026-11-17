<?php

namespace App\Http\Controllers\Auth;

use App\Models\Application;
use App\Models\SocialIdentity;
use App\Models\User;
use App\Services\Audit;
use App\Services\Claims;
use App\Services\Domains;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * "Continue with Google". Clauth stays invite-only: Google only signs in people who already have an account —
 * matched by a linked Google account, or by Google-verified email (then linked). Strangers are turned away.
 * 2-step rules apply exactly as after a password. Google's callback is always on the MAIN domain (one redirect URI
 * to register); a sign-in started on an app's own domain hops to main and comes back through the usual SSO check.
 */
class SocialController
{
    use Concerns;

    public const PROVIDERS = ['google'];

    public const ERRORS = [
        'no_account' => 'There’s no Clauth account for that Google account. Ask your admin for an invite.',
        'unverified' => 'Google hasn’t verified that email address, so we can’t use it to sign you in.',
        'invited' => 'Your account isn’t set up yet. Use the link in your invite email first.',
        'disabled' => 'This account is turned off. Ask your admin.',
        'failed' => 'Google sign-in didn’t finish. Please try again.',
        'cancelled' => 'Google sign-in was cancelled.',
    ];

    public static function enabled(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true) && filled(config("services.$provider.client_id")) && filled(config("services.$provider.client_secret"));
    }

    /** Start: GET /login/google (from the sign-in page; on an app domain it hops to the main domain first). */
    public function redirect(Request $request, string $provider)
    {
        abort_unless(self::enabled($provider), 404);
        if ($domain = $request->attributes->get('clauth.domain')) {
            return redirect()->away(Domains::mainUrl("login/$provider?".http_build_query(array_filter([
                'back' => $domain->host, 'app' => $this->flowApp($request)?->key, 'remember' => $request->boolean('remember') ? 1 : null,
            ]))));
        }

        $back = strtolower((string) $request->query('back'));
        $backDomain = $back ? Domains::activeFor($back) : null;
        $request->session()->put('social.back', $backDomain?->host);
        $request->session()->put('login.remember', $request->boolean('remember'));
        if (($key = (string) $request->query('app')) && ! $request->session()->has('login.client_id')) {
            $app = Application::where('key', $key)->first();
            if ($app && (! $backDomain || $backDomain->serves($app))) {
                $request->session()->put('login.app_key', $app->key); // right look + the app's 2-step rules
            }
        }
        $request->session()->forget('social.link');

        return $this->toProvider($provider);
    }

    /** My account › Link Google (signed in, main domain). */
    public function link(Request $request, string $provider)
    {
        abort_unless(self::enabled($provider), 404);
        if (! Domains::isMain($request->getHost())) {
            return redirect()->away(Domains::mainUrl("account/link/$provider"));
        }
        $request->session()->put('social.link', $request->user()->id);
        $request->session()->forget('social.back');

        return $this->toProvider($provider);
    }

    public function unlink(Request $request, string $provider)
    {
        $identity = $request->user()->socialIdentities()->where('provider', $provider)->firstOrFail();
        $identity->delete();
        Audit::log('security.social_unlinked', 'security', $request->user(), before: ['provider' => $provider, 'email' => $identity->email], actorType: 'user');

        return back()->with('status', ucfirst($provider).' is no longer linked. Sign in with your password instead.');
    }

    /** MAIN: Google sends the person back here. */
    public function callback(Request $request, string $provider)
    {
        abort_unless(self::enabled($provider) && Domains::isMain($request->getHost()), 404);
        $linkFor = $request->session()->pull('social.link');
        $back = $request->session()->pull('social.back');

        if ($request->has('error')) {
            return $linkFor ? redirect()->route('account')->withErrors(['social' => self::ERRORS['cancelled']]) : $this->fail($back, 'cancelled');
        }
        try {
            $g = Socialite::driver($provider)->redirectUrl($this->callbackUrl($provider))->user();
        } catch (Throwable $e) {
            report($e);

            return $linkFor ? redirect()->route('account')->withErrors(['social' => self::ERRORS['failed']]) : $this->fail($back, 'failed');
        }
        $id = (string) $g->getId();
        $email = mb_strtolower(trim((string) $g->getEmail()));
        $verified = filter_var($g->user['email_verified'] ?? $g->user['verified_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $identity = SocialIdentity::where('provider', $provider)->where('provider_id', $id)->first();

        // ---- Linking from My account ----
        if ($linkFor) {
            $user = $request->user();
            if (! $user || $user->id !== $linkFor) {
                return redirect()->route('login');
            }
            if ($identity && $identity->user_id !== $user->id) {
                return redirect()->route('account')->withErrors(['social' => 'That Google account is already linked to another Clauth account.']);
            }
            $user->socialIdentities()->where('provider', $provider)->where('provider_id', '!=', $id)->delete();
            $user->socialIdentities()->updateOrCreate(['provider' => $provider], ['provider_id' => $id, 'email' => $email ?: null]);
            Audit::log('security.social_linked', 'security', $user, after: ['provider' => $provider, 'email' => $email], actorType: 'user');

            return redirect()->route('account')->with('status', 'Google linked'.($email ? " ($email)" : '').'. You can now use “Continue with Google”.');
        }

        // ---- Signing in ----
        $user = $identity?->user;
        if (! $user) {
            if (! $email) {
                return $this->fail($back, 'no_account');
            }
            $match = User::where('email', $email)->first();
            if (! $match) {
                return $this->fail($back, 'no_account');
            }
            if (! $verified) {
                return $this->fail($back, 'unverified');
            }
            $user = $match;
        }
        if ($user->status === 'invited' || ! $user->password) {
            return $this->fail($back, 'invited');
        }
        if ($user->status === 'disabled') {
            return $this->fail($back, 'disabled');
        }
        if ($user->isLocked()) {
            return redirect()->route('login.locked');
        }
        if (! $identity) {
            $identity = $user->socialIdentities()->updateOrCreate(['provider' => $provider], ['provider_id' => $id, 'email' => $email]);
            Audit::log('security.social_linked', 'security', $user, after: ['provider' => $provider, 'email' => $email, 'via' => 'verified email'], actorId: $user->id, actorType: 'user');
        }
        $identity->forceFill(['last_used_at' => now(), 'email' => $email ?: $identity->email])->save();

        $remember = (bool) $request->session()->get('login.remember', false);
        $request->session()->regenerate();
        $request->session()->put(['login.user_id' => $user->id, 'login.remember' => $remember]);
        $request->session()->put('login.amr', ['fed']); // first factor = Google (2-step adds 'otp')
        if ($back) {
            // Finish on main (incl. 2-step), then return to the app's domain, which picks the sign-in up via SSO.
            $request->session()->put('url.intended', "https://$back/login?sso=1");
        }

        if (Claims::mfaRequired($user, $this->flowApp($request))) {
            if (! $user->hasTwoFactor()) {
                return redirect()->route('login.mfa.setup');
            }
            if ($this->isTrustedDevice($request, $user)) {
                return $this->completeLogin($request, $user, ['fed'], true);
            }

            return redirect()->route('login.mfa');
        }

        return $this->completeLogin($request, $user, ['fed'], false);
    }

    private function toProvider(string $provider)
    {
        return Socialite::driver($provider)->redirectUrl($this->callbackUrl($provider))
            ->scopes(['openid', 'email', 'profile'])->with(['prompt' => 'select_account'])->redirect();
    }

    private function callbackUrl(string $provider): string
    {
        return Domains::mainUrl("login/$provider/callback");
    }

    /** Back to the sign-in page the person started on, with a fixed message (never text from outside). */
    private function fail(?string $back, string $code)
    {
        $path = 'login?'.http_build_query(['social_error' => $code]);

        return redirect()->away($back ? "https://$back/$path" : Domains::mainUrl($path));
    }
}
