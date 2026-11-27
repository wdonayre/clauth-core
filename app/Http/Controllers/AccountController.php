<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\SocialController;
use App\Models\Application;
use App\Models\ClientSession;
use App\Models\Connection;
use App\Models\Consent;
use App\Models\Grant;
use App\Models\OrganizationRequest;
use App\Services\Audit;
use App\Services\Brand;
use App\Services\Claims;
use App\Services\Consents;
use App\Services\Passwords;
use App\Services\Sessions;
use App\Services\Settings;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** /account — "My account": app launcher, security, sessions. */
class AccountController
{
    public function show(Request $request)
    {
        $user = $request->user();
        // On an organization's own domain this is its account portal: its brand, and only its apps (SPEC-branding B2).
        $domain = $request->attributes->get('clauth.domain');
        $grants = Grant::with(['app', 'organization', 'role'])->where('user_id', $user->id)
            ->when($domain, fn ($q) => $q->where('organization_id', $domain->organization_id))->get()
            ->filter(fn ($g) => $g->app && $g->app->status !== 'paused' && ! $g->app->isSuspended() && $g->app->organizations->contains('id', $g->organization_id));
        $apps = $grants->groupBy('app_id')->map(fn ($gs) => [
            'app' => $gs->first()->app,
            'roles' => $gs->map(fn ($g) => $g->role->name.' · '.$g->organization->name)->all(),
            'last' => ClientSession::where('user_id', $user->id)->where('app_id', $gs->first()->app_id)->max('last_used_at'),
        ])->sortByDesc('last')->values();

        $web = DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->get();
        $appSessions = ClientSession::with('app')->where('user_id', $user->id)->orderByDesc('last_used_at')->get();

        return view('account.show', [
            'portal' => (bool) $domain, 'brand' => $domain ? Brand::of($domain->organization, true) : Brand::clauth(),
            'user' => $user, 'apps' => $apps, 'web' => $web, 'appSessions' => $appSessions,
            'currentSession' => $request->session()->getId(), 'mfaRequired' => Claims::policyRequiresMfa($user),
            'google' => SocialController::enabled('google') ? ['identity' => $user->socialIdentities()->where('provider', 'google')->first()] : null,
            // Other sign-in providers this person can link: platform ones and their organizations' (SPEC-platform §7).
            'linkable' => Connection::whereIn('type', Connection::SIGN_IN_TYPES)->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $user->organizations()->pluck('organizations.id')))
                ->orderBy('name')->get()->map(fn ($c) => ['c' => $c, 'identity' => $user->socialIdentities()->where('provider', $c->key)->first()]),
            'codesLeft' => count($user->two_factor_recovery_codes ?? []),
            'newCodes' => $request->session()->get('new_codes'),
            // Apps outside your own organizations that you've allowed to receive your details (SPEC-organizations §6.1).
            // Setting up an organization (SPEC-signup.md): the open request, or one decided in the last 30 days.
            'orgRequest' => $domain ? null : OrganizationRequest::where('user_id', $user->id)
                ->where(fn ($q) => $q->whereIn('status', OrganizationRequest::OPEN)->orWhere('decided_at', '>=', now()->subDays(30)))
                ->whereNotIn('status', ['cancelled'])->latest('id')->first(),
            'orgsOpen' => (bool) Settings::get('legal_reviewed'),
            'ownsOrg' => $user->organizations()->wherePivot('role', 'owner')->exists(),
            'consents' => Consent::with('app.publisher')->where('user_id', $user->id)->whereNull('revoked_at')->latest('granted_at')->get()->filter(fn ($c) => $c->app),
        ]);
    }

    /** "Remove access": the app loses your consent and you're signed out of it there. */
    public function revokeConsent(Request $request, Application $app)
    {
        abort_unless(Consent::where('user_id', $request->user()->id)->where('app_id', $app->id)->whereNull('revoked_at')->exists(), 404);
        Consents::revoke($request->user(), $app);

        return back()->with('status', "{$app->name} no longer has access to your details. It will ask again if you sign in to it.");
    }

    public function profile(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120']);
        $request->user()->update($data);

        return back()->with('status', 'Name saved.');
    }

    public function password(Request $request)
    {
        $user = $request->user();
        $request->validate(['current_password' => 'required', 'password' => Passwords::rules()]);
        if (! Hash::check($request->input('current_password'), (string) $user->password)) {
            return back()->withInput($request->only('_dlg'))->withErrors(['current_password' => 'Your current password isn’t right.']);
        }
        $user->forceFill(['password' => $request->input('password')])->save();
        Sessions::revokeAll($user, $request->session()->getId());
        Audit::log('security.password_changed', 'security', $user, actorType: 'user');

        return back()->with('status', 'Password changed. Other devices were signed out.');
    }

    public function startTwoFactor(Request $request)
    {
        $request->session()->put('login.user_id', $request->user()->id);
        $request->session()->put('url.intended', route('account'));

        return redirect()->route('login.mfa.setup');
    }

    public function disableTwoFactor(Request $request)
    {
        $user = $request->user();
        $request->validate(['current_password' => 'required']);
        if (! Hash::check($request->input('current_password'), (string) $user->password)) {
            return back()->withInput($request->only('_dlg'))->withErrors(['current_password' => 'Your password isn’t right.']);
        }
        if (Claims::policyRequiresMfa($user)) {
            return back()->withInput($request->only('_dlg'))->withErrors(['current_password' => '2-step verification is required for your account.']);
        }
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'sms_two_step' => false])->save();
        Audit::log('security.mfa_reset', 'security', $user, actorType: 'user');

        return back()->with('status', '2-step verification turned off.');
    }

    public function regenerateCodes(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasTwoFactor(), 404);
        $codes = TwoFactor::recoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return back()->with('new_codes', $codes);
    }

    public function revokeWeb(Request $request, string $id)
    {
        DB::table('sessions')->where('user_id', $request->user()->id)->where('id', $id)->delete();

        return back()->with('status', 'That browser was signed out.');
    }

    public function revokeApp(Request $request, int $id)
    {
        $cs = ClientSession::where('user_id', $request->user()->id)->findOrFail($id);
        Sessions::revokeClient($request->user(), $cs->client_id);

        return back()->with('status', 'Signed out of '.($cs->app?->name ?? 'the app').'.');
    }

    public function revokeAll(Request $request)
    {
        Sessions::revokeAll($request->user(), $request->session()->getId());
        Audit::log('security.signout_everywhere', 'security', $request->user(), actorType: 'user');

        return back()->with('status', 'Signed out everywhere else.');
    }
}
