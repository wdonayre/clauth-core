<?php

namespace App\Mail;

use App\Models\OrganizationRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Emails about an organization request: confirm the email address, or the decision. */
class OrgRequestMail extends Mailable
{
    public function __construct(public OrganizationRequest $req, public string $kind, public ?string $link = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->kind) {
            'verify' => 'Confirm your Clauth request for '.$this->req->org_name,
            'rejected' => 'About your Clauth request for '.$this->req->org_name,
            default => 'Clauth',
        });
    }

    public function content(): Content
    {
        return new Content(view: 'mail.org-request');
    }
}
