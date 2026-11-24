<?php

namespace App\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/** RFC 9457 problem details, the error format of /api/v1 (SPEC-platform §5). */
class Problem
{
    public static function make(int $status, string $title, ?string $detail = null, array $errors = []): JsonResponse
    {
        if (request()->attributes->get('clauth_scim')) {
            return self::scim($status, $title, $detail, $errors);
        }
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

    /** SCIM 2.0 error (RFC 7644 §3.12) for /scim/v2. */
    public static function scim(int $status, string $title, ?string $detail = null, array $errors = []): JsonResponse
    {
        $type = match (true) {
            $status === 422 => 'invalidValue',
            $title === 'Conflict' || $status === 409 => 'uniqueness',
            $title === 'Invalid filter' || ($status === 400 && str_contains((string) $detail, 'filter')) => 'invalidFilter',
            $title === 'Mutability' => 'mutability',
            default => null,
        };
        $status = $status === 422 ? 400 : $status;
        $detail = trim(($detail ?? $title).($errors ? ' '.collect($errors)->flatten()->implode(' ') : ''));

        return new JsonResponse(array_filter(['schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'], 'status' => (string) $status,
            'scimType' => $type, 'detail' => $detail], fn ($v) => $v !== null), $status, ['Content-Type' => 'application/scim+json']);
    }
}
