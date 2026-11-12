<?php

return [
    // The issuer is final — every app trusts tokens by this exact value (SPEC §15.1).
    'issuer' => rtrim(env('CLAUTH_ISSUER', env('APP_URL', 'http://localhost')), '/'),
    'access_token_minutes' => (int) env('CLAUTH_ACCESS_TOKEN_MINUTES', 15),
    'id_token_minutes' => (int) env('CLAUTH_ID_TOKEN_MINUTES', 15),
    'refresh_token_days' => (int) env('CLAUTH_REFRESH_TOKEN_DAYS', 30),
    'client_secret_days' => (int) env('CLAUTH_CLIENT_SECRET_DAYS', 365),
    // Public IPs of this server (custom-domain DNS hints only; reachability is proven over HTTPS).
    'server_ips' => array_filter(explode(',', env('CLAUTH_SERVER_IPS', '209.74.81.192'))),
    'scopes' => [
        'openid' => 'Sign you in',
        'profile' => 'Your name',
        'email' => 'Your email address',
        'clauth' => 'Your organizations and roles in this app',
        'offline_access' => 'Stay signed in',
        'users:read' => 'Look up users (machine-to-machine)',
        'updates:write' => 'Post release notes for this app (machine-to-machine)',
    ],
];
