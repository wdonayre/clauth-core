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
        Brand::forMail($org, fn () => self::deliver($org, $to, $mail, $c));
    }

    private static function deliver(?Organization $org, string $to, Mailable $mail, ?Connection $c): void
    {
        $c ??= Connections::mailerFor($org);
        if (! $c) {
            // Clauth's own sender; a branded organization's name shows as the sender name (SPEC-branding §5.5).
            $b = Brand::current();
            if ($b['level'] !== 'clauth') {
                $mail->from(config('mail.from.address'), $b['account']);
            }
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
