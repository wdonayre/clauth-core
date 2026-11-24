<?php

namespace App\Api;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optimistic concurrency for /api/v1 (SPEC-platform §5): single resources carry an ETag; a PATCH/PUT/DELETE that
 * sends If-Match with an old value gets 412 instead of overwriting someone else's change. If-Match is optional.
 */
class Etag
{
    public static function of(Model $m): string
    {
        return 'W/"'.substr(sha1(get_class($m).'|'.$m->getKey().'|'.($m->updated_at?->format('Y-m-d H:i:s.u') ?? '')), 0, 20).'"';
    }

    public static function check(Model $m): void
    {
        $want = request()->header('If-Match');
        if ($want === null || trim($want) === '*') {
            return;
        }
        $tags = array_map(fn ($t) => preg_replace('/^W\//', '', trim($t)), explode(',', $want));
        abort_unless(in_array(preg_replace('/^W\//', '', self::of($m)), $tags, true), 412, 'It changed since you read it (If-Match did not match). Read it again and retry.');
    }

    public static function attach(Response $r, Model $m): Response
    {
        $r->headers->set('ETag', self::of($m));

        return $r;
    }
}
