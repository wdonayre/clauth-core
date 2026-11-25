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
        'POST /flow/execute' => ['App-native sign-in, one step at a time (no token; public client + PKCE). Start with client_id, redirect_uri, code_challenge, scope; then send flow_id + action: identify (email) → password | send_code (via) → code → totp | send_sms → sms → done (an authorization code for /oauth/token). step "browser" gives a URL when only the browser can continue.', 'Sign-in', ['flow_id' => 'from the previous answer', 'client_id' => 'always', 'action' => 'identify|password|send_code|code|totp|send_sms|sms', 'email' => 'identify', 'password' => 'password', 'via' => 'email|sms', 'code' => 'code / totp / sms', 'redirect_uri' => 'start', 'code_challenge' => 'start (S256)', 'scope' => 'start', 'state' => 'start', 'nonce' => 'start']],
        'GET /capabilities' => ['What this token may do (display only)', 'Meta'],
        'GET /users' => ['List people (filters: status, org, app, q)', 'Users'],
        'GET /users/{user}' => ['One person with orgs, grants, profiles and sign-in methods ({user} = usr_ id or email)', 'Users'],
        'PATCH /users/{user}' => ['Change name or phone (email: platform clients only)', 'Users', ['name' => 'string', 'phone' => 'string|null', 'email' => 'string (platform only)']],
        'POST /users/{user}/{action}' => ['disable | enable | unlock | reset-two-step | two-step-exempt | two-step-required | sign-out | password-reset', 'Users'],
        'GET /invites' => ['List invites (filters: status, org)', 'Invites'],
        'POST /invites' => ['Invite someone into an organization; returns the invite link', 'Invites', ['email' => 'string', 'name' => 'string', 'org' => 'slug', 'profiles' => 'profile keys', 'apps' => '{app_key: role_key}', 'teams' => 'team keys', 'fields' => '{key: value}', 'require_two_step' => 'bool', 'org_role' => 'member|developer|admin', 'send' => 'bool (default true)']],
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
        'DELETE /users/{user}' => ['Delete an account everywhere (platform only; frees the email, keeps an anonymised row)', 'Users'],
        'POST /apps' => ['Register an app (+ its first client; the secret is in this response only)', 'Apps', ['name' => 'string', 'key' => 'string', 'type' => 'web|public|m2m', 'redirect_uri' => 'url', 'home_url' => 'url', 'publisher' => 'org slug (personal/platform tokens)', 'orgs' => 'org slugs to install in', 'privacy_url' => 'url', 'terms_url' => 'url', 'support_email' => 'email']],
        'PATCH /apps/{app}' => ['Change app settings (If-Match supported)', 'Apps', ['name' => 'string', 'home_url' => 'url', 'manifest_url' => 'url', 'status' => 'live|paused|not_connected', 'color' => '#rrggbb', 'visibility' => 'private|installable', 'require_mfa_roles' => 'role keys']],
        'DELETE /apps/{app}' => ['Delete the app (owners/admins of the publisher; send confirm = app key)', 'Apps', ['confirm' => 'the app key']],
        'POST /apps/{app}/clients' => ['Add a client (secret shown once), or one described by a client ID metadata document (cimd_url)', 'Apps', ['type' => 'web|public|m2m|device', 'name' => 'string', 'redirect_uri' => 'url', 'cimd_url' => 'https URL of a client metadata document']],
        'POST /apps/{app}/registration-tokens' => ['Initial access token for dynamic client registration (RFC 7591) at /oauth/register into this app; shown once', 'Apps', ['name' => 'string', 'uses' => 'int', 'days' => 'int (default 30)']],
        'GET /apps/{app}/authorization-detail-types' => ['Types the app accepts in authorization_details (RFC 9396)', 'Apps'],
        'PUT /apps/{app}/authorization-detail-types/{type}' => ['Register a type with its JSON Schema and optional processor (your server confirms or fills in)', 'Apps', ['description' => 'shown to the person', 'schema' => 'JSON Schema (object)', 'processor_url' => 'https URL', 'processor_secret' => 'HMAC secret']],
        'DELETE /apps/{app}/authorization-detail-types/{type}' => ['Remove a type', 'Apps'],
        'GET /cimd/preview' => ['Read a client ID metadata document (?url=) before registering it', 'Apps'],
        'PATCH /apps/{app}/clients/{client}' => ['Change a client\'s name or redirect URLs', 'Apps', ['name' => 'string', 'redirect_uris' => 'urls', 'post_logout_redirect_uris' => 'urls']],
        'POST /apps/{app}/roles' => ['Add a role', 'Roles', ['key' => 'string', 'name' => 'string', 'description' => 'string', 'tier' => 'owner|admin|member|viewer']],
        'PATCH /apps/{app}/roles/{role}' => ['Change a role (manifest roles: tier only)', 'Roles', ['name' => 'string', 'description' => 'string', 'tier' => 'owner|admin|member|viewer', 'aliases' => 'old keys', 'assignable' => 'bool']],
        'DELETE /apps/{app}/roles/{role}' => ['Delete a role nobody holds', 'Roles'],
        'PUT /apps/{app}/roles/{role}/permissions' => ['Set the exact permissions of a role', 'Roles', ['permissions' => 'permission keys']],
        'POST /apps/{app}/permissions' => ['Add a permission by hand', 'Roles', ['key' => 'string', 'description' => 'string']],
        'PUT /apps/{app}/branding' => ['Sign-in page look (JSON or multipart with logo files)', 'Apps', ['login_theme' => 'classic|daylight|midnight|auto', 'theme' => 'designed theme key (instead of login_theme)', 'accent_color' => '#rrggbb', 'logo' => 'png/svg file', 'logo_dark' => 'png/svg file', 'remove_logo' => 'bool']],
        'POST /apps/{app}/branding' => ['Same as PUT (for multipart uploads from tools that only POST)', 'Apps'],
        'GET /apps/{app}/installations' => ['Organizations the app is installed in or requested by', 'Apps'],
        'POST /apps/{app}/installations' => ['Install in an organization you run, or request it elsewhere', 'Apps', ['org' => 'slug']],
        'DELETE /apps/{app}/installations/{org}' => ['Remove from an organization (its access there goes)', 'Apps'],
        'GET /apps/{app}/domain' => ['The app\'s sign-in domain, status and DNS records', 'Domains'],
        'POST /apps/{app}/domain' => ['Add a sign-in domain (or share one the publisher verified)', 'Domains', ['host' => 'auth.example.com', 'share' => 'bool']],
        'POST /apps/{app}/domain/check' => ['Check DNS and certificate now', 'Domains'],
        'PATCH /apps/{app}/domain' => ['Display name, theme (trusted: Clauth owners)', 'Domains', ['display_name' => 'string', 'theme' => 'designed theme key or null', 'trusted' => 'bool']],
        'DELETE /apps/{app}/domain' => ['Stop using the domain (last app out deletes it)', 'Domains'],
        'GET /me' => ['Your account (personal token)', 'Me'],
        'PATCH /me' => ['Change your name or phone', 'Me', ['name' => 'string', 'phone' => 'string']],
        'GET /me/apps' => ['Apps you can open, with your roles', 'Me'],
        'GET /me/sessions' => ['Browsers and apps you are signed in to', 'Me'],
        'DELETE /me/sessions/{id}' => ['Sign out one browser (web_…) or app (app_…)', 'Me'],
        'GET /me/consents' => ['Outside apps you allowed to receive your details', 'Me'],
        'DELETE /me/consents/{app}' => ['Take that permission back', 'Me'],
        'GET /orgs/{org}/teams' => ['Teams (stores, branches, departments) of the organization', 'Teams & fields'],
        'GET /orgs/{org}/teams/{team}' => ['One team with its people', 'Teams & fields'],
        'POST /orgs/{org}/teams' => ['Add a team (its key is fixed; apps see it in tokens)', 'Teams & fields', ['name' => 'string', 'key' => 'string', 'description' => 'string']],
        'PATCH /orgs/{org}/teams/{team}' => ['Rename a team', 'Teams & fields', ['name' => 'string', 'description' => 'string']],
        'DELETE /orgs/{org}/teams/{team}' => ['Delete a team', 'Teams & fields'],
        'PUT /orgs/{org}/teams/{team}/members/{user}' => ['Put a member in the team', 'Teams & fields'],
        'DELETE /orgs/{org}/teams/{team}/members/{user}' => ['Take a member out of the team', 'Teams & fields'],
        'GET /orgs/{org}/fields' => ['Profile fields the organization keeps about its people', 'Teams & fields'],
        'POST /orgs/{org}/fields' => ['Add a profile field', 'Teams & fields', ['key' => 'string', 'label' => 'string', 'type' => 'string|number|bool|date|enum', 'options' => 'choices (enum)', 'required' => 'bool', 'editable_by' => 'admin|self', 'in_token' => 'bool (send to apps)']],
        'PATCH /orgs/{org}/fields/{field}' => ['Change a field (key and type are fixed)', 'Teams & fields', ['label' => 'string', 'options' => 'choices', 'required' => 'bool', 'editable_by' => 'admin|self', 'in_token' => 'bool']],
        'DELETE /orgs/{org}/fields/{field}' => ['Delete a field and its values', 'Teams & fields'],
        'GET /orgs/{org}/members/{user}/profile' => ['A member\'s teams and field values (and missing required fields)', 'Teams & fields'],
        'PATCH /orgs/{org}/members/{user}/profile' => ['Set a member\'s teams (exact list) and field values', 'Teams & fields', ['teams' => 'team keys', 'fields' => '{key: value|null}']],
        'GET /me/orgs/{org}/profile' => ['Your teams and fields in one organization', 'Me'],
        'PATCH /me/orgs/{org}/profile' => ['Change the fields the organization lets you edit', 'Me', ['fields' => '{key: value}']],
        'GET /connections' => ['Sign-in providers, SMS gateway and email senders (secrets masked; filter: org)', 'Connections'],
        'GET /connections/{key}' => ['One connection, with the redirect URL to register at the provider', 'Connections'],
        'POST /connections' => ['Add a connection: oidc, oauth (org or platform), meta, sms (platform), email-smtp (org)', 'Connections', ['type' => 'oidc|oauth|meta|sms|email-smtp', 'name' => 'button label', 'org' => 'slug (omit = platform)', 'config' => 'issuer, client_id, client_secret, domains, jit, require… (see docs)']],
        'PATCH /connections/{key}' => ['Change name, status or config (secrets: leave out to keep)', 'Connections', ['name' => 'string', 'status' => 'active|disabled', 'config' => 'object']],
        'DELETE /connections/{key}' => ['Delete a connection', 'Connections'],
        'GET /apps/{app}/policy' => ['Sign-in rules of an app: rules in force, where they come from, version', 'Sign-in rules'],
        'GET /apps/{app}/policy/versions' => ['Saved versions of these rules', 'Sign-in rules'],
        'PUT /apps/{app}/policy' => ['Replace the rules (new version)', 'Sign-in rules', ['password' => 'bool', 'google' => 'bool', 'connections' => '"all" or keys', 'email_code' => 'bool', 'sms_code' => 'bool', 'two_step' => 'default|always', 'sms_second_step' => 'bool', 'registration' => 'invite|open (apps)', 'open_domains' => 'domains', 'open_profile' => 'profile key', 'check' => 'http connection key', 'check_fail_open' => 'bool']],
        'POST /apps/{app}/policy/restore' => ['Restore a saved version (as a new version)', 'Sign-in rules', ['version' => 'int']],
        'DELETE /apps/{app}/policy' => ['Remove these rules so the next level applies', 'Sign-in rules'],
        'GET /orgs/{org}/policy' => ['Sign-in rules of an organization (default for its apps): rules in force, where they come from, version', 'Sign-in rules'],
        'GET /orgs/{org}/policy/versions' => ['Saved versions of these rules', 'Sign-in rules'],
        'PUT /orgs/{org}/policy' => ['Replace the rules (new version)', 'Sign-in rules', ['password' => 'bool', 'google' => 'bool', 'connections' => '"all" or keys', 'email_code' => 'bool', 'sms_code' => 'bool', 'two_step' => 'default|always', 'sms_second_step' => 'bool', 'registration' => 'invite|open (apps)', 'open_domains' => 'domains', 'open_profile' => 'profile key', 'check' => 'http connection key', 'check_fail_open' => 'bool']],
        'POST /orgs/{org}/policy/restore' => ['Restore a saved version (as a new version)', 'Sign-in rules', ['version' => 'int']],
        'DELETE /orgs/{org}/policy' => ['Remove these rules so the next level applies', 'Sign-in rules'],
        'GET /policy' => ['Sign-in rules of the platform: rules in force, where they come from, version', 'Sign-in rules'],
        'GET /policy/versions' => ['Saved versions of these rules', 'Sign-in rules'],
        'PUT /policy' => ['Replace the rules (new version)', 'Sign-in rules', ['password' => 'bool', 'google' => 'bool', 'connections' => '"all" or keys', 'email_code' => 'bool', 'sms_code' => 'bool', 'two_step' => 'default|always', 'sms_second_step' => 'bool', 'registration' => 'invite|open (apps)', 'open_domains' => 'domains', 'open_profile' => 'profile key', 'check' => 'http connection key', 'check_fail_open' => 'bool']],
        'POST /policy/restore' => ['Restore a saved version (as a new version)', 'Sign-in rules', ['version' => 'int']],
        'DELETE /policy' => ['Remove these rules so the next level applies', 'Sign-in rules'],
        'GET /themes' => ['Built-in styles and designed sign-in themes you can use', 'Themes'],
        'GET /themes/resolve' => ['What a sign-in page will look like (?app=, ?domain=): computed colors, fonts, layout', 'Themes'],
        'GET /themes/{key}' => ['One designed theme', 'Themes'],
        'GET /themes/{key}/usages' => ['Apps and domains using the theme', 'Themes'],
        'POST /themes' => ['Design a theme', 'Themes', ['name' => 'string', 'org' => 'slug (omit = platform)', 'base' => 'classic|daylight|midnight|auto', 'accent' => '#rrggbb', 'background' => '#rrggbb', 'card' => '#rrggbb', 'font' => 'Inter|Manrope|Poppins|…', 'heading_font' => 'font', 'radius' => '0–28', 'buttons' => 'rounded|pill|square', 'layout' => 'center|split', 'tagline' => 'string']],
        'PATCH /themes/{key}' => ['Change a theme (apps using it change too)', 'Themes', ['name' => 'string', '…' => 'any setting']],
        'DELETE /themes/{key}' => ['Delete a theme (its apps go back to their built-in style)', 'Themes'],
        'GET /languages' => ['Languages of the sign-in pages and emails', 'Languages & messages'],
        'GET /languages/{lang}/translations' => ['The wording in one language (built-in + your changes)', 'Languages & messages'],
        'PUT /languages/{lang}/translations' => ['Change wording: {"English text": "your text" | null} (platform only)', 'Languages & messages', ['<English text>' => 'your text, or null to undo']],
        'GET /templates' => ['Message templates (?org=): invite, login_code, new_device, lockout (email), sms_code (text)', 'Languages & messages'],
        'PUT /templates/{key}' => ['Write a message (an organization: its invite; the platform: all)', 'Languages & messages', ['org' => 'slug (omit = platform)', 'locale' => 'en|fil', 'subject' => 'email subject', 'body' => 'plain text with {{placeholders}}']],
        'DELETE /templates/{key}' => ['Go back to the built-in message', 'Languages & messages', ['org' => 'slug', 'locale' => 'en|fil']],
        'GET /orgs/{org}/agents' => ['The organization’s agents (AI assistants, bots, scripts)', 'Agents'],
        'POST /orgs/{org}/agents' => ['Create an agent; the response has client_id and client_secret (shown once). It gets tokens with client credentials + audience=<app key>', 'Agents', ['name' => 'string', 'description' => 'string', 'apps' => '{app_key: role_key}']],
        'GET /agents/{agent}' => ['One agent ({agent} = agt_ id)', 'Agents'],
        'PATCH /agents/{agent}' => ['Rename, or suspend / resume (status)', 'Agents', ['name' => 'string', 'description' => 'string', 'status' => 'active|suspended']],
        'PUT /agents/{agent}/access' => ['An organization agent’s role per app (null = none)', 'Agents', ['apps' => '{app_key: role_key|null}']],
        'POST /agents/{agent}/secret' => ['New credentials (the old ones stop working)', 'Agents'],
        'DELETE /agents/{agent}' => ['Delete the agent and revoke its tokens', 'Agents'],
        'GET /me/agents' => ['Your own agents (they act for you, never with more access than you)', 'Me'],
        'POST /me/agents' => ['Create your own agent in one of your organizations', 'Me', ['name' => 'string', 'org' => 'slug', 'description' => 'string']],
        'PATCH /me/agents/{agent}' => ['Rename, suspend or resume your agent', 'Me', ['name' => 'string', 'status' => 'active|suspended']],
        'POST /me/agents/{agent}/secret' => ['New credentials for your agent', 'Me'],
        'DELETE /me/agents/{agent}' => ['Delete your agent', 'Me'],
        'GET /export' => ['An organization’s setup as one JSON document (?org=): teams, fields, profiles, sign-in rules, its apps’ roles and look, themes, messages, connections (secrets as ${secret:NAME})', 'Config'],
        'POST /import' => ['Import a document into an organization. dry_run (default true) lists what would change; dry_run=false applies it', 'Config', ['org' => 'slug', 'document' => 'an export', 'dry_run' => 'bool']],
        'GET /variables' => ['Variables (?org=)', 'Config'],
        'PUT /variables/{name}' => ['Set a variable (${var:NAME} in imports)', 'Config', ['value' => 'string', 'org' => 'slug (omit = platform)']],
        'DELETE /variables/{name}' => ['Delete a variable', 'Config', ['org' => 'slug']],
        'GET /secrets' => ['Secret names (?org=); values are never shown', 'Config'],
        'PUT /secrets/{name}' => ['Set a secret (${secret:NAME} in imports), stored encrypted', 'Config', ['value' => 'string', 'org' => 'slug (omit = platform)']],
        'DELETE /secrets/{name}' => ['Delete a secret', 'Config', ['org' => 'slug']],
        'GET /apps/{app}/people' => ['Everyone with access to the app, with the orgs claim their token carries (?since= for changes). The app’s own server client (users:read) or an API client', 'Webhooks'],
        'GET /apps/{app}/webhooks' => ['Webhook endpoints of the app', 'Webhooks'],
        'POST /apps/{app}/webhooks' => ['Add an endpoint; the signing secret is in this response only', 'Webhooks', ['url' => 'https URL', 'events' => 'access.granted|access.changed|access.removed|user.updated|user.disabled|user.enabled|invite.accepted (empty = all)']],
        'PATCH /apps/{app}/webhooks/{id}' => ['Change URL or events, switch off/on (on = resend recent failures), rotate the secret', 'Webhooks', ['url' => 'https URL', 'events' => 'list', 'status' => 'active|disabled', 'rotate_secret' => 'bool']],
        'DELETE /apps/{app}/webhooks/{id}' => ['Remove an endpoint', 'Webhooks'],
        'POST /apps/{app}/webhooks/{id}/test' => ['Send a ping now and see the answer', 'Webhooks'],
        'GET /apps/{app}/webhooks/{id}/deliveries' => ['Recent deliveries (30 days; ?status=)', 'Webhooks'],
        'POST /apps/{app}/deliveries/{delivery}/redeliver' => ['Send a delivery again', 'Webhooks'],
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
            if ($route->gatherMiddleware() && collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'api.client')) || in_array($path, ['/users/{user}', '/apps/{app}/people'], true)) {
                $op['security'] = str_starts_with((string) $scope, 'me:') ? [['personalToken' => []]]
                    : [['clientCredentials' => $scope ? [$scope] : []], ['personalToken' => []]];
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
                'description' => 'Manage people, organizations, access profiles and apps. Authenticate with an API client (Organization › API clients: client credentials at /oauth/token, then Bearer) or a personal API token (My account › API tokens). Errors are RFC 9457 problem+json. POST accepts Idempotency-Key. Single resources send an ETag; PATCH/PUT/DELETE honour If-Match (412 when it changed).',
                'license' => ['name' => 'MIT']],
            'servers' => [['url' => $iss.'/api/v1']],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'clientCredentials' => ['type' => 'oauth2', 'flows' => ['clientCredentials' => [
                        'tokenUrl' => $iss.'/oauth/token',
                        'scopes' => collect(config('clauth.api.scopes'))->mapWithKeys(fn ($s) => [$s => config("clauth.scopes.$s")])->all(),
                    ]]],
                    'personalToken' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT',
                        'description' => 'Personal API token from My account › API tokens. Acts as you; also unlocks /me (me:read, me:write).'],
                ],
                'schemas' => ['Problem' => ['type' => 'object', 'properties' => [
                    'type' => ['type' => 'string'], 'title' => ['type' => 'string'], 'status' => ['type' => 'integer'],
                    'detail' => ['type' => 'string'], 'errors' => ['type' => 'object'], 'request_id' => ['type' => 'string'],
                ]]],
            ],
        ];
    }
}
