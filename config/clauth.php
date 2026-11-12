<?php

return [
    // The issuer is final — every app trusts tokens by this exact value (SPEC §15.1).
    'issuer' => rtrim(env('CLAUTH_ISSUER', env('APP_URL', 'http://localhost')), '/'),
    'access_token_minutes' => (int) env('CLAUTH_ACCESS_TOKEN_MINUTES', 15),
    'id_token_minutes' => (int) env('CLAUTH_ID_TOKEN_MINUTES', 15),
    'refresh_token_days' => (int) env('CLAUTH_REFRESH_TOKEN_DAYS', 30),
    'client_secret_days' => (int) env('CLAUTH_CLIENT_SECRET_DAYS', 365),
    // Public IPs of this server (custom-domain DNS hints only; reachability is proven over HTTPS).
    // App keys and name words only Clauth owners may use (SPEC-organizations §9, §10.3): William's apps,
    // Clauth's own words, and brands people trust with sign-ins or money.
    'reserved_app_keys' => ['xpenses', 'cebpos', 'cakepricer', 'alab-studio', 'alab-erp', 'studio', 'erp', 'clauth', 'auth', 'admin',
        'api', 'www', 'login', 'account', 'google', 'apple', 'microsoft', 'facebook', 'gcash', 'maya', 'paymaya', 'paypal', 'stripe',
        'bdo', 'bpi', 'metrobank', 'landbank', 'unionbank', 'security-bank', 'rcbc', 'pnb', 'chinabank'],
    'reserved_key_prefixes' => ['clauth', 'bank', 'thecakelabs', 'alab', 'cebucake', 'cakesbygrace'],
    'reserved_name_words' => ['clauth', 'google', 'apple', 'microsoft', 'facebook', 'gcash', 'paymaya', 'paypal', 'bdo', 'bpi',
        'metrobank', 'landbank', 'unionbank', 'xpenses', 'cebpos', 'cakepricer', 'alab', 'cake labs', 'cakesbygrace'],

    // Per-organization limits (SPEC-organizations §10.1). Platform organizations (William's) have none. Per-org
    // overrides live in organizations.limits.
    'limits' => [
        'unverified' => ['apps' => 2, 'people' => 50, 'invites_per_day' => 50, 'domains' => 0, 'installs' => 0, 'token_per_minute' => 30],
        'verified' => ['apps' => 20, 'people' => 5000, 'invites_per_day' => 1000, 'domains' => 5, 'installs' => 50, 'token_per_minute' => 120],
    ],
    // Hosts under these parent domains can only be claimed by platform organizations (SPEC-organizations §7.2).
    'reserved_parent_domains' => array_filter(explode(',', env('CLAUTH_RESERVED_PARENT_DOMAINS',
        'thecakelabs.com,alabapparel.com,cebpos.com,cakesbygrace.ph,cebucake.com,alabsports.fit,veritak.net,gelomedia.com'))),
    // Custom-domain certificates: "san" = one certificate shared with the main domain (CloudPanel on h1, today);
    // "per-host" = one certificate per custom domain (deploy/domains-perhost.sh, the dedicated server).
    'cert_mode' => env('CLAUTH_CERT_MODE', 'san'),
    // per-host mode: where the generated nginx server blocks point (see deploy/domains-perhost.sh).
    'perhost' => [
        'public_root' => env('CLAUTH_PUBLIC_ROOT', base_path('public')),
        'php_fpm' => env('CLAUTH_PHP_FPM', 'unix:/run/php/php8.5-fpm.sock'),
        'acme_root' => env('CLAUTH_ACME_ROOT', '/var/www/clauth-acme'),
        'cert_dir' => env('CLAUTH_CERT_DIR', '/etc/letsencrypt/live'),
    ],

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
