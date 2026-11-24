<?php

namespace App\Api;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * Builds /api/v1/openapi.json from the router itself (SPEC-platform §5), so the document can't list a route that
 * doesn't exist or miss one that does. Each route needs a line in DOCS (a test enforces it both ways).
 */
class OpenApi
{
    /** "METHOD /path" => [summary, tag, request body fields (name => description)] */
    public const DOCS = [
        'GET /health/live' => ['The service is up', 'Health'],
        'GET /health/ready' => ['Database, cache and signing key are ready', 'Health'],
        'GET /openapi.json' => ['This document', 'Meta'],
        'GET /capabilities' => ['What this token may do (display only)', 'Meta'],
        'GET /users' => ['List people (filters: status, org, app, q)', 'Users'],
        'GET /users/{user}' => ['One person with orgs, grants, profiles and sign-in methods ({user} = usr_ id or email)', 'Users'],
        'PATCH /users/{user}' => ['Change name or phone (email: platform clients only)', 'Users', ['name' => 'string', 'phone' => 'string|null', 'email' => 'string (platform only)']],
        'POST /users/{user}/{action}' => ['disable | enable | unlock | reset-two-step | two-step-exempt | two-step-required | sign-out | password-reset', 'Users'],
        'GET /invites' => ['List invites (filters: status, org)', 'Invites'],
        'POST /invites' => ['Invite someone into an organization; returns the invite link', 'Invites', ['email' => 'string', 'name' => 'string', 'org' => 'slug', 'profiles' => 'profile keys', 'apps' => '{app_key: role_key}', 'require_two_step' => 'bool', 'org_role' => 'member|developer|admin', 'send' => 'bool (default true)']],
        'DELETE /invites/{invite}' => ['Revoke a pending invite', 'Invites'],
        'POST /invites/{invite}/resend' => ['New link (the old one stops working) and email', 'Invites', ['send' => 'bool (default true)']],
        'GET /orgs' => ['Organizations this client can see', 'Organizations'],
        'GET /orgs/{org}' => ['One organization ({org} = id or slug)', 'Organizations'],
        'GET /orgs/{org}/members' => ['Members of the organization', 'Organizations'],
        'PUT /orgs/{org}/members/{user}' => ['Add an existing person (email or usr_ id) or change their org role', 'Organizations', ['role' => 'member|developer|admin|owner']],
        'DELETE /orgs/{org}/members/{user}' => ['Remove from the organization (their profiles, exceptions and grants there go too)', 'Organizations'],
        'GET /orgs/{org}/members/{user}/access' => ['What the person gets in each app and why', 'Access'],
        'PUT /orgs/{org}/members/{user}/access' => ['Set profiles (exact list) and per-app exceptions, then work out grants', 'Access', ['profiles' => 'profile keys', 'apps' => '{app_key: "profile"|"none"|role_key}', 'reason' => 'string']],
        'GET /orgs/{org}/alignment' => ['Who has what: people × apps (?format=csv)', 'Access'],
        'GET /orgs/{org}/profiles' => ['Access profiles with their app roles and people', 'Access'],
        'GET /orgs/{org}/profiles/{profile}' => ['One access profile ({profile} = key)', 'Access'],
        'POST /orgs/{org}/profiles' => ['Create an access profile', 'Access', ['name' => 'string', 'key' => 'string', 'description' => 'string', 'apps' => '{app_key: role_key|"none"}']],
        'PATCH /orgs/{org}/profiles/{profile}' => ['Rename or change the role per app (null = not set)', 'Access', ['name' => 'string', 'description' => 'string', 'apps' => '{app_key: role_key|"none"|null}']],
        'DELETE /orgs/{org}/profiles/{profile}' => ['Delete; holders lose what only this profile gave them', 'Access'],
        'PUT /orgs/{org}/profiles/{profile}/people/{user}' => ['Give the profile to a member', 'Access'],
        'DELETE /orgs/{org}/profiles/{profile}/people/{user}' => ['Take the profile away', 'Access'],
        'GET /grants' => ['Effective grants tokens are built from (filters: org, app, user)', 'Access'],
        'GET /apps' => ['Apps published by or installed in this client\'s organization', 'Apps'],
        'GET /apps/{app}' => ['One app with roles, permissions and (publisher only) clients', 'Apps'],
        'POST /apps/{app}/manifest/sync' => ['Fetch /.well-known/clauth.json and apply it', 'Apps'],
        'POST /apps/{app}/clients/{client}/rotate' => ['New client secret (shown once)', 'Apps'],
        'POST /apps/{app}/clients/{client}/revoke' => ['Turn a client off and revoke its tokens', 'Apps'],
        'GET /audit' => ['Audit log (filters: category, event, org, user, since, until)', 'Audit'],
        'GET /settings' => ['Platform settings (platform clients only)', 'Settings'],
        'PATCH /settings' => ['Change platform settings (platform clients only)', 'Settings', ['require_mfa_everyone' => 'bool', 'password_min_length' => 'int', 'session_idle_hours' => 'int', '…' => 'see GET /settings']],
    ];

    /** @return array<string, Route> "METHOD /path" => route, for every /api/v1 route. */
    public static function routes(): array
    {
        $out = [];
        foreach (Router::getRoutes() as $r) {
            if (! str_starts_with($r->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($r->methods(), ['HEAD']) as $m) {
                $out[$m.' '.substr($r->uri(), 6)] = $r;
            }
        }
        // Older app-facing endpoints that live in web.php (Clauth bar, release notes) are documented in the kit, not here.
        return array_filter($out, fn ($r, $k) => ! preg_match('#^(GET|POST) /(bar|bar/read|release-notes)$#', $k), ARRAY_FILTER_USE_BOTH);
    }

    public static function document(): array
    {
        $paths = [];
        foreach (self::routes() as $key => $route) {
            [$method, $path] = explode(' ', $key, 2);
            [$summary, $tag, $body] = (self::DOCS[$key] ?? ['(undocumented)', 'Other']) + [2 => null];
            $scope = collect($route->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'api.client'));
            $scope = $scope ? (explode(':', $scope, 2)[1] ?? null) : null;
            preg_match_all('/\{(\w+)\}/', $path, $m);
            $op = [
                'summary' => $summary, 'tags' => [$tag],
                'parameters' => array_map(fn ($p) => ['name' => $p, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']], $m[1]),
                'responses' => ['200' => ['description' => 'OK'], 'default' => ['description' => 'Error', 'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]]],
            ];
            if ($route->gatherMiddleware() && collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'api.client')) || $path === '/users/{user}') {
                $op['security'] = [['clientCredentials' => $scope ? [$scope] : []]];
            } else {
                $op['security'] = [];
            }
            if ($body) {
                $op['requestBody'] = ['content' => ['application/json' => ['schema' => ['type' => 'object',
                    'properties' => collect($body)->map(fn ($d) => ['description' => $d])->all()]]]];
            }
            if ($method === 'GET' && in_array($path, ['/users', '/invites', '/orgs', '/orgs/{org}/members', '/orgs/{org}/profiles', '/grants', '/apps', '/audit'], true)) {
                $op['parameters'][] = ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'maximum' => 200, 'default' => 50]];
                $op['parameters'][] = ['name' => 'cursor', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'next_cursor from the previous page'];
            }
            $paths[$path][strtolower($method)] = $op;
        }
        ksort($paths);
        $iss = config('clauth.issuer');

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Clauth API', 'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: 'dev',
                'description' => 'Manage people, organizations, access profiles and apps. Authenticate with an API client (Organization › API clients): client credentials at /oauth/token, then Bearer. Errors are RFC 9457 problem+json. POST accepts Idempotency-Key.',
                'license' => ['name' => 'MIT']],
            'servers' => [['url' => $iss.'/api/v1']],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['clientCredentials' => ['type' => 'oauth2', 'flows' => ['clientCredentials' => [
                    'tokenUrl' => $iss.'/oauth/token',
                    'scopes' => collect(config('clauth.api.scopes'))->mapWithKeys(fn ($s) => [$s => config("clauth.scopes.$s")])->all(),
                ]]]],
                'schemas' => ['Problem' => ['type' => 'object', 'properties' => [
                    'type' => ['type' => 'string'], 'title' => ['type' => 'string'], 'status' => ['type' => 'integer'],
                    'detail' => ['type' => 'string'], 'errors' => ['type' => 'object'], 'request_id' => ['type' => 'string'],
                ]]],
            ],
        ];
    }
}
