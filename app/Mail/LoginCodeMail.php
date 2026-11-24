<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A one-time sign-in code by email (SPEC-platform §7). */
class LoginCodeMail extends Mailable
{
    public function __construct(public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->code} is your Clauth code");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.login-code');
    }
}
