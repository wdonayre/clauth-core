<?php

namespace App\Mail;

use App\Models\AccessRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Someone asked for access to an app: to the organization's admins (new), then to the person (approved/declined). */
class AccessRequestMail extends Mailable
{
    public function __construct(public AccessRequest $ar, public string $kind, public ?string $link = null) {}

    public function envelope(): Envelope
    {
        $app = $this->ar->app->name;

        return new Envelope(subject: match ($this->kind) {
            'new' => $this->ar->user->name.' asked for access to '.$app,
            'approved' => 'You can now use '.$app,
            default => 'About your request for '.$app,
        });
    }

    public function content(): Content
    {
        return new Content(view: 'mail.access-request');
    }
}
