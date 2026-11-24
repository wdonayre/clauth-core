<?php

namespace App\Services;

use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Rich authorization requests, RFC 9396 (SPEC-platform §8.3): an app asks for something specific ("pay ₱1,250 to
 * Ana") with `authorization_details`. Each item's `type` must be registered for the app, with a JSON Schema (a
 * practical subset: type, required, properties with type / enum / maxLength / minimum / maximum, additionalProperties).
 * The person sees every item and approves; a type's processor (the app's own server) may refuse or fill in fields.
 * Approved details go into the access token and the token response. They belong to that one approval: refreshed
 * access tokens don't carry them.
 */
class AuthDetails
{
    public static function types(Application $app)
    {
        return DB::table('authorization_detail_types')->where('app_id', $app->id)->get()->keyBy('type');
    }

    /** Validated items, or a string saying what's wrong. */
    public static function parse(?string $raw, Application $app): array|string
    {
        $items = json_decode((string) $raw, true);
        if (! is_array($items) || ! array_is_list($items) || ! $items || count($items) > 10) {
            return 'authorization_details must be a JSON array of 1–10 objects.';
        }
        $types = self::types($app);
        foreach ($items as $i => $item) {
            if (! is_array($item) || ! is_string($item['type'] ?? null)) {
                return "authorization_details[$i] needs a type.";
            }
            $t = $types->get($item['type']);
            if (! $t) {
                return "{$item['type']} is not an authorization details type of {$app->name}.";
            }
            if ($err = self::check($item, json_decode($t->schema, true) ?: [], "authorization_details[$i]")) {
                return $err;
            }
        }

        return $items;
    }

    private static function check(mixed $v, array $s, string $path): ?string
    {
        $type = $s['type'] ?? null;
        $ok = match ($type) {
            'object' => is_array($v) && ($v === [] || ! array_is_list($v)),
            'array' => is_array($v) && array_is_list($v),
            'string' => is_string($v), 'integer' => is_int($v), 'number' => is_int($v) || is_float($v), 'boolean' => is_bool($v),
            null => true, default => false,
        };
        if (! $ok) {
            return "$path must be a $type.";
        }
        if (isset($s['enum']) && ! in_array($v, $s['enum'], true)) {
            return "$path must be one of: ".implode(', ', array_map('json_encode', $s['enum'])).'.';
        }
        if (is_string($v) && isset($s['maxLength']) && mb_strlen($v) > $s['maxLength']) {
            return "$path is too long.";
        }
        if ((is_int($v) || is_float($v)) && ((isset($s['minimum']) && $v < $s['minimum']) || (isset($s['maximum']) && $v > $s['maximum']))) {
            return "$path is out of range.";
        }
        if ($type === 'object') {
            foreach ($s['required'] ?? [] as $r) {
                if (! array_key_exists($r, $v)) {
                    return "$path.$r is required.";
                }
            }
            foreach ($v as $k => $sub) {
                if (isset($s['properties'][$k])) {
                    if ($e = self::check($sub, $s['properties'][$k], "$path.$k")) {
                        return $e;
                    }
                } elseif (($s['additionalProperties'] ?? true) === false && $k !== 'type') {
                    return "$path.$k is not allowed.";
                }
            }
        }
        if ($type === 'array' && isset($s['items'])) {
            foreach ($v as $i => $sub) {
                if ($e = self::check($sub, $s['items'], "{$path}[$i]")) {
                    return $e;
                }
            }
        }

        return null;
    }

    /** "label: value" lines for the approval screen. */
    public static function describe(array $item, Application $app): array
    {
        $desc = self::types($app)->get($item['type'])?->description ?: $item['type'];
        $lines = [];
        foreach ($item as $k => $v) {
            if ($k !== 'type') {
                $lines[] = [ucfirst(str_replace(['_', '-'], ' ', (string) $k)), is_scalar($v) ? (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v) : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            }
        }

        return ['title' => $desc, 'lines' => $lines];
    }

    /**
     * Ask each type's processor. Returns the (possibly filled-in) items, or a string reason to refuse.
     * Body {"authorization_details": [...], "user": {...}, "app": key}, HMAC-SHA256 in X-Clauth-Signature;
     * the answer {"approved": true, "authorization_details": [...]?}. Unreachable = refuse.
     */
    public static function process(Application $app, array $items, User $user): array|string
    {
        $types = self::types($app);
        $groups = collect($items)->groupBy(fn ($i) => $types[$i['type']]->processor_url ?? '');
        $out = [];
        foreach ($groups as $url => $group) {
            if ($url === '') {
                $out = array_merge($out, $group->all());

                continue;
            }
            $t = $types->first(fn ($x) => $x->processor_url === $url);
            $body = json_encode(['authorization_details' => $group->values()->all(), 'app' => $app->key, 'user' => ['id' => $user->sub(), 'email' => $user->email]]);
            try {
                $r = Http::withBody($body, 'application/json')->withHeaders(['X-Clauth-Signature' => 'sha256='.hash_hmac('sha256', $body, $t->processor_secret ? Crypt::decryptString($t->processor_secret) : '')])
                    ->timeout(5)->post($url);
            } catch (\Throwable $e) {
                report($e);

                return __(':app couldn’t confirm this request. Try again.', ['app' => $app->name]);
            }
            if (! $r->successful() || $r->json('approved') !== true) {
                return (string) ($r->json('message') ?: __(':app refused this request.', ['app' => $app->name]));
            }
            $filled = $r->json('authorization_details');
            $out = array_merge($out, is_array($filled) && count($filled) === $group->count() ? $filled : $group->all());
        }

        return $out;
    }
}
