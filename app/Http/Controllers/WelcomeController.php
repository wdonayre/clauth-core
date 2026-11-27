<?php

namespace App\Http\Controllers;

use App\Services\Domains;
use App\Services\Settings;
use Illuminate\Http\Request;

/** Right after someone creates a Clauth account on its own (SPEC-signup.md): what would they like to do? */
class WelcomeController
{
    public function show(Request $request)
    {
        $user = $request->user();

        return view('auth.welcome', [
            'user' => $user,
            'orgsOpen' => (bool) Settings::get('legal_reviewed'),
            'openRequest' => OrganizationRequestController::openFor($user),
        ]);
    }

    /** "I was invited": accept a pasted invite link, but only one that points at an invite on this Clauth. */
    public function invite(Request $request)
    {
        $link = trim((string) $request->input('link'));
        $path = (string) parse_url($link, PHP_URL_PATH);
        $host = parse_url($link, PHP_URL_HOST);
        $ours = $host === null || Domains::isMain(mb_strtolower($host)) || Domains::activeFor(mb_strtolower($host)) !== null;
        if (! $ours || ! preg_match('#^/invite/([A-Za-z0-9]{20,100})$#', $path, $m)) {
            return back()->withErrors(['link' => 'That doesn’t look like a Clauth invite link. Open the link from your invite email.']);
        }

        return redirect()->route('invite.show', $m[1]);
    }
}
