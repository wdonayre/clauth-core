<?php

namespace App\Http\Controllers;

use App\Mail\OrgRequestMail;
use App\Models\Application;
use App\Models\Organization;
use App\Models\OrganizationRequest;
use App\Models\User;
use App\Services\Audit;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * "Set up an organization" (SPEC-organizations §8, SPEC-signup.md): a signed-in person sends a request, a Clauth owner
 * approves it (they become its Owner), asks for more information, or declines. One open request per account; it
 * closes by itself after 30 days without an answer. Closed until the legal documents are reviewed
 * (Settings › legal_reviewed). Old requests from the public form (no account) still confirm by email link.
 */
class OrganizationRequestController
{
    public function show(Request $request)
    {
        $open = (bool) Settings::get('legal_reviewed');
        if ($from = Application::where('key', (string) $request->query('from'))->value('id')) {
            $request->session()->put('orgreq.from', $from);
        }
        $user = $request->user();
        if (! $open || ! $user) {
            if (! $user) {
                $request->session()->put('url.intended', route('org-request'));
            }

            return view('pages.org-request', ['open' => $open, 'user' => $user, 'r' => null]);
        }
        if ($r = self::openFor($user)) {
            return redirect()->route('account')->with('status', "Your request for {$r->org_name} is already with us.");
        }

        return view('pages.org-request', ['open' => true, 'user' => $user, 'r' => null]);
    }

    public function store(Request $request)
    {
        abort_unless(Settings::get('legal_reviewed'), 404);
        $user = $request->user();
        if ($request->filled('website_url')) { // honeypot: people never see this field
            return redirect()->route('account');
        }
        if (self::openFor($user)) {
            return redirect()->route('account')->withErrors(['request' => 'You already have a request waiting. Edit that one instead.']);
        }
        $data = $this->validated($request);
        $from = $request->session()->pull('orgreq.from');
        $r = OrganizationRequest::create($data + [
            'user_id' => $user->id, 'contact_name' => $user->name ?: $user->email, 'contact_email' => $user->email,
            'email_verified_at' => $user->email_verified_at ?? now(), 'status' => 'pending',
            'source_app_id' => $from && Application::whereKey($from)->exists() ? $from : null,
            'ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
        ]);
        Audit::log('org.requested', 'admin', $r, after: ['org' => $r->org_name, 'slug' => $r->slug], actorId: $user->id, actorType: 'user');
        self::tellOwners($r, 'new');

        return redirect()->route('account')->with('status', "Thanks — we’ll review {$r->org_name} and email {$user->email}.");
    }

    public function edit(Request $request)
    {
        abort_unless(Settings::get('legal_reviewed'), 404);
        $r = self::openFor($request->user());
        abort_unless($r, 404);

        return view('pages.org-request', ['open' => true, 'user' => $request->user(), 'r' => $r]);
    }

    /** Edit a waiting request, or answer the reviewer's question (which sends it back for review). */
    public function update(Request $request)
    {
        abort_unless(Settings::get('legal_reviewed'), 404);
        $r = self::openFor($request->user());
        abort_unless($r, 404);
        $data = $this->validated($request, $r);
        $answered = $r->status === 'info_requested';
        $r->update($data + ['status' => 'pending']);
        Audit::log($answered ? 'org.request_answered' : 'org.request_edited', 'admin', $r, after: ['org' => $r->org_name], actorId: $request->user()->id, actorType: 'user');
        if ($answered) {
            self::tellOwners($r, 'answered');
        }

        return redirect()->route('account')->with('status', $answered ? 'Thanks — your answer is with us.' : 'Request updated.');
    }

    public function cancel(Request $request)
    {
        $r = self::openFor($request->user());
        abort_unless($r, 404);
        $r->update(['status' => 'cancelled', 'decided_at' => now()]);
        Audit::log('org.request_cancelled', 'admin', $r, after: ['org' => $r->org_name], actorId: $request->user()->id, actorType: 'user');

        return redirect()->route('account')->with('status', "Request for {$r->org_name} cancelled.");
    }

    /** Legacy public-form requests: the contact confirms their email by link. */
    public function verify(string $token)
    {
        $req = OrganizationRequest::where('verify_token_hash', hash('sha256', $token))->where('status', 'pending')->first();
        abort_unless($req, 404);
        $req->update(['email_verified_at' => $req->email_verified_at ?? now(), 'verify_token_hash' => null]);

        return view('auth.error', ['title' => 'Thanks, email confirmed', 'message' => "We’ll review the request for {$req->org_name} and reply to {$req->contact_email}."]);
    }

    /** The person's request that is still open (waiting for review, or for their answer). */
    public static function openFor(?User $user): ?OrganizationRequest
    {
        return $user ? OrganizationRequest::where('user_id', $user->id)->open()->latest('id')->first() : null;
    }

    /** Email every Clauth owner about a new or answered request. */
    public static function tellOwners(OrganizationRequest $r, string $kind): void
    {
        foreach (User::where('clauth_role', 'owner')->where('status', 'active')->pluck('email') as $email) {
            rescue(fn () => Mail::to($email)->send(new OrgRequestMail($r, $kind, route('admin.org-requests.show', $r->id))), report: true);
        }
    }

    private function validated(Request $request, ?OrganizationRequest $editing = null): array
    {
        $data = $request->validate([
            'org_name' => 'required|string|max:120',
            'slug' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{2,60}$/', Rule::unique('organizations', 'slug'),
                function ($a, $v, $fail) use ($editing) {
                    if (collect(config('clauth.reserved_key_prefixes'))->contains(fn ($p) => str_starts_with($v, $p))) {
                        $fail('That short name is reserved. Pick another one.');
                    }
                    if (OrganizationRequest::open()->where('slug', $v)->when($editing, fn ($q) => $q->whereKeyNot($editing->id))->exists()) {
                        $fail('Someone else has asked for that short name. Pick another one.');
                    }
                }],
            'website' => 'nullable|url|max:300',
            'country' => 'required|string|max:60',
            'team_size' => ['required', Rule::in(array_keys(OrganizationRequest::SIZES))],
            'purpose' => 'required|string|min:20|max:2000',
            'agree' => 'accepted',
        ], ['slug.regex' => 'Use 3–60 lowercase letters, numbers and dashes.', 'slug.unique' => 'That short name is taken.',
            'agree.accepted' => 'Please accept the Terms and the Data Processing Agreement.', 'purpose.min' => 'Tell us a little more (at least a sentence).',
            'team_size.required' => 'Pick how many people will sign in.']);

        return collect($data)->except('agree')->all();
    }
}
