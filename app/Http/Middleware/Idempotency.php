<?php

namespace App\Http\Middleware;

use App\Api\ApiContext;
use App\Api\Problem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Idempotency-Key on POST /api/v1 (SPEC-platform §5): the same key from the same client within 24 h returns the
 * first response instead of doing the work again. A different body with a used key is a 422.
 */
class Idempotency
{
    public function handle(Request $request, Closure $next)
    {
        $key = (string) $request->header('Idempotency-Key');
        if (! $request->isMethod('POST') || $key === '') {
            return $next($request);
        }
        if (strlen($key) > 255) {
            return Problem::make(422, 'Bad idempotency key', 'Idempotency-Key must be at most 255 characters.');
        }
        $client = app(ApiContext::class)->client->id;
        $cacheKey = 'api:idem:'.hash('sha256', $client."\0".$request->path()."\0".$key);
        $fingerprint = hash('sha256', $request->getContent());
        if ($hit = Cache::get($cacheKey)) {
            if ($hit['fingerprint'] !== $fingerprint) {
                return Problem::make(422, 'Idempotency key reused', 'This Idempotency-Key was already used with a different request body.');
            }

            return response($hit['body'], $hit['status'], ['Content-Type' => $hit['type'], 'Idempotent-Replayed' => 'true']);
        }
        $lock = Cache::lock($cacheKey.':lock', 30);
        if (! $lock->get()) {
            return Problem::make(409, 'Request in progress', 'A request with this Idempotency-Key is still running.');
        }
        try {
            $response = $next($request);
            if ($response->getStatusCode() < 500) {
                Cache::put($cacheKey, ['fingerprint' => $fingerprint, 'status' => $response->getStatusCode(),
                    'body' => $response->getContent(), 'type' => $response->headers->get('Content-Type')], now()->addDay());
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
