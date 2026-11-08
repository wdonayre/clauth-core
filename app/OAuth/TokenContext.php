<?php

namespace App\OAuth;

/** Request-scoped facts for the token being issued (nonce, amr, auth_time) — filled by TokenController. */
class TokenContext
{
    public ?string $nonce = null;

    public array $amr = ['pwd'];

    public ?int $authTime = null;
}
