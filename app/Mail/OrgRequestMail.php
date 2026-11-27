<?php

namespace App\Mail;

use App\Models\OrganizationRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Emails about an organization request. To the person: verify (old public form), info (the reviewer asks something),
 * approved, rejected, expired. To Clauth owners: new, answered.
 */
class OrgRequestMail extends Mailable
{
    public function __construct(public OrganizationRequest $req, public string $kind, public ?string $link = null) {}

    public function envelope(): Envelope
    {
        $org = $this->req->org_name;

        return new Envelope(subject: match ($this->kind) {
            'verify' => 'Confirm your Clauth request for '.$org,
            'new' => 'New organization request: '.$org,
            'answered' => $org.' answered your question',
            'info' => 'A question about your Clauth request for '.$org,
            'approved' => $org.' is ready on Clauth',
            'rejected' => 'About your Clauth request for '.$org,
            'expired' => 'Your Clauth request for '.$org.' has closed',
            default => 'Clauth',
        });
    }

    public function content(): Content
    {
        return new Content(view: 'mail.org-request');
    }
}
