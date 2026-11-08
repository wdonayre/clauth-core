<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NewDeviceMail extends Mailable
{
    public function __construct(public User $user, public string $agent, public string $ip) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New sign-in to your Clauth account');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.new-device');
    }
}
