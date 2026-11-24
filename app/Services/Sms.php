<?php

namespace App\Services;

use App\Models\Connection;
use Illuminate\Support\Facades\Http;

/** Sends SMS through the platform's SMS connection (SPEC-platform §7): Semaphore, Twilio or Vonage. */
class Sms
{
    public static function available(): bool
    {
        return Connections::sms() !== null;
    }

    /** +63… / 09… → E.164 (+639…). Returns null when it doesn't look like a mobile number. */
    public static function normalise(string $raw): ?string
    {
        $d = preg_replace('/[^\d+]/', '', $raw);
        if (preg_match('/^09\d{9}$/', $d)) {
            $d = '+63'.substr($d, 1);
        } elseif (preg_match('/^639\d{9}$/', $d)) {
            $d = '+'.$d;
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $d) ? $d : null;
    }

    public static function mask(?string $phone): string
    {
        return $phone ? str_repeat('•', max(0, strlen($phone) - 4)).substr($phone, -4) : '';
    }

    /** Throws on a provider error. */
    public static function send(string $to, string $text, ?Connection $c = null): void
    {
        $c ??= Connections::sms() ?? throw new \RuntimeException('No SMS gateway is set up.');
        $sender = $c->cfg('sender');
        match ($c->cfg('driver')) {
            'semaphore' => Http::asForm()->timeout(15)->post('https://api.semaphore.co/api/v4/messages', array_filter([
                'apikey' => $c->cfg('api_key'), 'number' => ltrim($to, '+'), 'message' => $text, 'sendername' => $sender,
            ]))->throw(),
            'twilio' => Http::asForm()->timeout(15)->withBasicAuth($c->cfg('api_key'), (string) $c->cfg('api_secret'))
                ->post('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($c->cfg('api_key')).'/Messages.json', ['From' => $sender, 'To' => $to, 'Body' => $text])->throw(),
            'vonage' => tap(Http::asForm()->timeout(15)->post('https://rest.nexmo.com/sms/json', [
                'api_key' => $c->cfg('api_key'), 'api_secret' => $c->cfg('api_secret'), 'from' => $sender ?: 'Clauth', 'to' => ltrim($to, '+'), 'text' => $text,
            ])->throw(), function ($r) {
                if ((string) data_get($r->json(), 'messages.0.status', '0') !== '0') {
                    throw new \RuntimeException('Vonage: '.data_get($r->json(), 'messages.0.error-text', 'send failed'));
                }
            }),
            default => throw new \RuntimeException('Unknown SMS provider.'),
        };
        $c->forceFill(['last_used_at' => now()])->save();
    }
}
