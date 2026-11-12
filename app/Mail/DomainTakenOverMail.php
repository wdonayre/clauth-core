<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells the previous owner organization that another organization took over its stale sign-in domain (SPEC-organizations §7.2). */
class DomainTakenOverMail extends Mailable
{
    public function __construct(public string $host, public string $orgName, public string $newOrgName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Sign-in domain {$this->host} moved to another organization");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.domain-taken-over');
    }
}
