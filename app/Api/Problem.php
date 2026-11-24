<?php

namespace App\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/** RFC 9457 problem details, the error format of /api/v1 (SPEC-platform §5). */
class Problem
{
    public static function make(int $status, string $title, ?string $detail = null, array $errors = []): JsonResponse
    {
        $body = array_filter([
            'type' => 'https://helloclauth.com/docs/api/#error-'.Str::slug($title),
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'errors' => $errors ?: null,
            'request_id' => request()->header('X-Request-Id') ?: (string) Str::ulid(),
        ], fn ($v) => $v !== null);

        return new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json']);
    }
}
