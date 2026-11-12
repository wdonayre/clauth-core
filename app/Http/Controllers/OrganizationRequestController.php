<?php

namespace App\Http\Controllers;

use App\Mail\OrgRequestMail;
use App\Models\Organization;
use App\Models\OrganizationRequest;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Public "Use Clauth for your organization" form (SPEC-organizations §8): request → confirm email → William reviews.
 * Closed until the legal documents are reviewed (Settings › legal_reviewed).
 */
class OrganizationRequestController
{
    public function show()
    {
        return view('pages.org-request', ['open' => (bool) Settings::get('legal_reviewed')]);
    }

    public function store(Request $request)
    {
        abort_unless(Settings::get('legal_reviewed'), 404);
        if ($request->filled('website_url')) { // honeypot: people never see this field
            return view('auth.error', ['title' => 'Thanks', 'message' => 'Check your email to confirm your request.']);
        }
        $data = $request->validate([
            'org_name' => 'required|string|max:120',
            'slug' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{2,60}$/', Rule::unique('organizations', 'slug'),
                function ($a, $v, $fail) {
                    if (collect(config('clauth.reserved_key_prefixes'))->contains(fn ($p) => str_starts_with($v, $p))) {
                        $fail('That short name is reserved. Pick another one.');
                    }
                }],
            'website' => 'nullable|url|max:300',
            'contact_name' => 'required|string|max:120',
            'contact_email' => 'required|email|max:190',
            'purpose' => 'required|string|min:20|max:2000',
            'agree' => 'accepted',
        ], ['slug.regex' => 'Use 3–60 lowercase letters, numbers and dashes.', 'slug.unique' => 'That short name is taken.',
            'agree.accepted' => 'Please accept the Terms and the Data Processing Agreement.', 'purpose.min' => 'Tell us a little more (at least a sentence).']);
        $token = Str::random(48);
        $req = OrganizationRequest::create(collect($data)->except('agree')->all() + [
            'contact_email' => mb_strtolower($data['contact_email']), 'verify_token_hash' => hash('sha256', $token),
            'ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
        ]);
        rescue(fn () => Mail::to($req->contact_email)->send(new OrgRequestMail($req, 'verify', route('org-request.verify', $token))), report: true);

        return view('auth.error', ['title' => 'Check your email', 'message' => "We sent a link to {$req->contact_email}. Confirm it and we’ll review your request for {$req->org_name}."]);
    }

    public function verify(string $token)
    {
        $req = OrganizationRequest::where('verify_token_hash', hash('sha256', $token))->where('status', 'pending')->first();
        abort_unless($req, 404);
        $req->update(['email_verified_at' => $req->email_verified_at ?? now(), 'verify_token_hash' => null]);

        return view('auth.error', ['title' => 'Thanks, email confirmed', 'message' => "We’ll review the request for {$req->org_name} and reply to {$req->contact_email}."]);
    }
}
