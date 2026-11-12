<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Services\Domains;
use App\Services\Sso;
use App\Services\Theme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The hops that carry a sign-in between Clauth's main domain and apps' own sign-in domains. */
class SsoController
{
    /** MAIN: "is this browser signed in here?" — answers the app domain with a ticket or "no". */
    public function check(Request $request)
    {
        abort_unless(Domains::isMain($request->getHost()), 404);
        $host = strtolower((string) $request->query('host'));
        $state = (string) $request->query('state');
        abort_unless(($domain = Domains::activeFor($host)) && preg_match('/^[A-Za-z0-9]{32,64}$/', $state), 400);
        $back = 'https://'.$host.'/sso/accept?state='.urlencode($state);

        $user = $request->user();
        if (! $user || $user->status !== 'active') {
            return redirect()->away($back.'&none=1');
        }
        // Only trusted domains (Clauth's own organizations) get a silent sign-in. Anyone else's domain could be
        // repointed by its owner to catch the ticket, so the person confirms first.
        if (! $domain->isTrusted() && ! $request->isMethod('POST')) {
            return response()->view('auth.sso-confirm', ['domain' => $domain, 'user' => $user, 'state' => $state, 'back' => $back,
                'theme' => Theme::for($domain->brandApp()) + ['brand' => $domain->brandName()]]);
        }

        Sso::register($request, $user); // so signing out on the app domain also ends this session

        return redirect()->away($back.'&ticket='.Sso::issue(Sso::snapshot($request, $user) + ['host' => $host, 'state' => $state]));
    }

    /** MAIN: the person confirmed "Continue as …" for an untrusted domain. */
    public function confirm(Request $request)
    {
        $request->query->replace(['host' => (string) $request->input('host'), 'state' => (string) $request->input('state')]);

        return $this->check($request);
    }

    /** APP DOMAIN: take the answer from the main domain. */
    public function accept(Request $request)
    {
        $domain = $request->attributes->get('clauth.domain');
        abort_unless($domain, 404);
        $expected = $request->session()->pull('sso.state');
        $request->session()->put('sso.checked', true);
        $state = (string) $request->query('state');
        $t = Sso::take($request->query('ticket'));

        if ($expected && hash_equals($expected, $state) && $t && ($t['host'] ?? null) === $domain->host && hash_equals($expected, (string) ($t['state'] ?? ''))
            && ($user = User::find($t['user'])) && $user->status === 'active' && ! $user->isLocked()) {
            Sso::adopt($request, $user, $t);
        }

        return redirect()->to($request->session()->pull('sso.return') ?: '/login');
    }

    /** MAIN: someone just signed in on an app domain — sign this browser in here too, then go back. */
    public function adoptOnMain(Request $request)
    {
        abort_unless(Domains::isMain($request->getHost()), 404);
        $t = Sso::take($request->query('ticket'));
        $return = $t['return'] ?? null;
        $returnHost = $return ? strtolower((string) parse_url($return, PHP_URL_HOST)) : null;
        if (! $t || ($t['to'] ?? null) !== 'main' || ! $returnHost || ! (Domains::isMain($returnHost) || Domains::activeFor($returnHost)?->isTrusted())) {
            return redirect()->route('login');
        }
        $user = User::find($t['user']);
        if ($user && $user->status === 'active' && ! $request->user()) {
            Sso::adopt($request, $user, $t);
        } elseif ($user && $request->user()?->id === $user->id) {
            // Already signed in here as the same person: join this session to the app domain's family.
            DB::table('sso_sessions')->updateOrInsert(
                ['family' => $t['family'], 'session_id' => $request->session()->getId()],
                ['user_id' => $user->id, 'host' => strtolower($request->getHost()), 'created_at' => now()],
            );
        }

        return redirect()->away($return);
    }
}
