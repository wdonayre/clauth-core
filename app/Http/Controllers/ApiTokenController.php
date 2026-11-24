<?php

namespace App\Http\Controllers;

use App\Services\PersonalTokens;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** My account › API tokens (SPEC-platform P1.1): personal tokens for scripts and the CLI, acting as you. */
class ApiTokenController
{
    public function index(Request $request)
    {
        $u = $request->user();

        return view('account.tokens', [
            'tokens' => PersonalTokens::list($u),
            'scopes' => collect(PersonalTokens::allowedScopes($u))->mapWithKeys(fn ($s) => [$s => config("clauth.scopes.$s")]),
            'days' => PersonalTokens::DAYS,
            'needsTwoStep' => ! $u->hasTwoFactor(),
        ]);
    }

    public function store(Request $request)
    {
        $u = $request->user();
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'scopes' => 'required|array|min:1', 'scopes.*' => Rule::in(PersonalTokens::allowedScopes($u)),
            'days' => ['required', 'integer', Rule::in(PersonalTokens::DAYS)],
        ]);
        // Tokens that can manage other people need 2-step on the account behind them.
        if (array_diff($data['scopes'], PersonalTokens::SELF_SCOPES) && ! $u->hasTwoFactor()) {
            return back()->withInput()->withErrors(['scopes' => 'Turn on 2-step verification first: tokens that manage people or apps need it.']);
        }
        abort_if(PersonalTokens::list($u)->where('revoked', false)->where('expires_at', '>', now())->count() >= 20, 422, 'You have 20 active tokens. Revoke some first.');
        [, $jwt] = PersonalTokens::create($u, $data['name'], array_values($data['scopes']), (int) $data['days']);

        return back()->with('new_token', ['name' => $data['name'], 'token' => $jwt]);
    }

    public function revoke(Request $request, string $id)
    {
        abort_unless(PersonalTokens::revoke($request->user(), $id), 404);

        return back()->with('status', 'Token revoked. It stopped working.');
    }
}
