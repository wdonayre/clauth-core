<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** An email written by an admin (App\Services\Templates): plain text, links clickable, in Clauth's mail layout. */
class TemplateMail extends Mailable
{
    public function __construct(public string $subjectLine, public string $bodyText, public ?string $link = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine ?: 'Clauth');
    }

    public function content(): Content
    {
        $html = collect(preg_split("/\n{2,}/", trim($this->bodyText)))->map(function ($p) {
            $p = e($p);
            $p = preg_replace('~(https://[^\s<]+)~', '<a href="$1" style="color:#0E7490">$1</a>', $p);

            return '<p style="margin:0 0 12px">'.nl2br($p).'</p>';
        })->implode('');

        return new Content(view: 'mail.template', with: ['html' => $html, 'link' => $this->link]);
    }
}
