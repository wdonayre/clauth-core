<?php

namespace App\Services;

use App\Mail\TemplateMail;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Editable messages (SPEC-platform §8.5). An organization's template for the person's language wins, then the
 * platform's, else the built-in message. Placeholders are {{name}}, {{email}}, {{link}}, {{code}}, {{app}}, {{org}},
 * {{inviter}}, {{minutes}}, {{device}}, {{ip}} — whatever the message has. Bodies are plain text: links become
 * clickable, nothing else is HTML.
 */
class Templates
{
    public const KEYS = [
        'invite' => ['email', 'Invite', ['name', 'email', 'link', 'app', 'org', 'inviter']],
        'login_code' => ['email', 'One-time code by email', ['code']],
        'new_device' => ['email', 'New device sign-in', ['name', 'device', 'ip']],
        'lockout' => ['email', 'Account locked', ['name', 'minutes']],
        'sms_code' => ['sms', 'One-time code by text', ['code']],
    ];

    public static function find(string $key, ?Organization $org, ?string $locale = null)
    {
        $locale = $locale ?: app()->getLocale();
        foreach (array_filter([$org?->id, null], fn ($v, $i) => $i === 1 || $v, ARRAY_FILTER_USE_BOTH) as $orgId) {
            foreach (array_unique([$locale, 'en']) as $l) {
                $row = DB::table('message_templates')->where('key', $key)->where('locale', $l)
                    ->when($orgId, fn ($q) => $q->where('organization_id', $orgId), fn ($q) => $q->whereNull('organization_id'))->first();
                if ($row) {
                    return $row;
                }
            }
        }

        return null;
    }

    public static function fill(string $text, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $text);
    }

    /** A mailable from the template, or null to use the built-in one. */
    public static function mail(string $key, ?Organization $org, array $vars, ?string $locale = null): ?TemplateMail
    {
        $t = self::find($key, $org, $locale);

        return $t ? new TemplateMail(self::fill((string) $t->subject, $vars), self::fill($t->body, $vars), $vars['link'] ?? null) : null;
    }

    public static function text(string $key, ?Organization $org, array $vars, string $default, ?string $locale = null): string
    {
        $t = self::find($key, $org, $locale);

        return $t ? mb_substr(self::fill($t->body, $vars), 0, 320) : $default;
    }
}
