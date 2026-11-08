<?php

namespace App\Mail;

use App\Models\AppRole;
use App\Models\Invite;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InviteMail extends Mailable
{
    use Queueable;

    public function __construct(public Invite $invite, public string $link) {}

    public function envelope(): Envelope
    {
        $app = $this->invite->primaryApp();

        return new Envelope(subject: $app ? "You’re invited to {$app->name}" : 'You’re invited to Clauth');
    }

    public function content(): Content
    {
        $app = $this->invite->primaryApp();
        $role = ($id = $this->invite->grants[0]['role_id'] ?? null) ? AppRole::find($id) : null;

        return new Content(view: 'mail.invite', with: [
            'invite' => $this->invite, 'link' => $this->link, 'app' => $app, 'role' => $role,
            'inviter' => $this->invite->inviter?->name ?? 'Your admin', 'org' => $this->invite->organization?->name,
        ]);
    }
}
