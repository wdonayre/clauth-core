<?php

namespace App\Services;

use App\Models\Connection;
use App\Models\Organization;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Sends mail for an organization through its own email-smtp connection when it has one (SPEC-platform §7; needed
 * before outside organizations: their invites come from their address and their SMTP account), else Clauth's mailer.
 */
class OrgMail
{
    public static function send(?Organization $org, string $to, Mailable $mail, ?Connection $c = null): void
    {
        $c ??= Connections::mailerFor($org);
        if (! $c) {
            Mail::to($to)->send($mail);

            return;
        }
        $mail->from($c->cfg('from_address'), $c->cfg('from_name') ?: $org?->name);
        if (Mail::getFacadeRoot() instanceof \Illuminate\Support\Testing\Fakes\MailFake) {
            Mail::to($to)->send($mail); // tests: record it with the organization's sender instead of opening SMTP

            return;
        }
        Mail::build([
            'transport' => 'smtp', 'host' => $c->cfg('host'), 'port' => (int) $c->cfg('port'),
            'encryption' => $c->cfg('encryption'), 'username' => $c->cfg('username'), 'password' => $c->cfg('password'), 'timeout' => 15,
        ])->to($to)->send($mail);
        $c->forceFill(['last_used_at' => now()])->save();
    }
}
