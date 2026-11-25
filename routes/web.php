<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AuthzenController;
use App\Http\Controllers\Admin;
use App\Http\Controllers\Api\BarController;
use App\Http\Controllers\Api\ReleaseNoteController;
use App\Http\Controllers\Auth;
use App\Http\Controllers\Auth\InviteController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SocialController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\OAuth\AuthorizeController;
use App\Http\Controllers\OAuth\ConsentController;
use App\Http\Controllers\OAuth\OidcController;
use App\Http\Controllers\OAuth\TokenController;
use App\Http\Controllers\OrganizationRequestController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SignOutEverywhereController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

// ---- OIDC / OAuth (SPEC §9) ----
Route::get('/.well-known/openid-configuration', [OidcController::class, 'discovery']);
Route::get('/.well-known/jwks.json', [OidcController::class, 'jwks']);
Route::get('/.well-known/oauth-authorization-server', [OidcController::class, 'oauthMetadata']);
Route::get('/.well-known/authzen-configuration', [AuthzenController::class, 'configuration']);
Route::get('/oauth/authorize', AuthorizeController::class)->name('oauth.authorize');
Route::match(['get', 'post'], '/oauth/logout', [OidcController::class, 'logout'])->name('oauth.logout');

Route::withoutMiddleware([ValidateCsrfToken::class])->group(function () {
    Route::post('/oauth/token', TokenController::class)->middleware('throttle:token')->name('oauth.token');
    Route::post('/oauth/revoke', [OidcController::class, 'revoke'])->middleware('throttle:token');
    Route::match(['get', 'post'], '/userinfo', [OidcController::class, 'userinfo']);
    Route::post('/oauth/register', [\App\Http\Controllers\OAuth\RegistrationController::class, 'register'])->middleware('throttle:20,1');
    Route::get('/oauth/register/{client}', [\App\Http\Controllers\OAuth\RegistrationController::class, 'show'])->middleware('throttle:60,1');
    Route::put('/oauth/register/{client}', [\App\Http\Controllers\OAuth\RegistrationController::class, 'update'])->middleware('throttle:20,1');
    Route::delete('/oauth/register/{client}', [\App\Http\Controllers\OAuth\RegistrationController::class, 'destroy'])->middleware('throttle:20,1');
    Route::post('/oauth/bc-authorize', [\App\Http\Controllers\OAuth\CibaController::class, 'start'])->middleware('throttle:token');
    Route::post('/oauth/device', [\App\Http\Controllers\OAuth\DeviceController::class, 'code'])->middleware('throttle:token');
    Route::post('/oauth/par', [\App\Http\Controllers\OAuth\ParController::class, '__invoke'])->middleware('throttle:token');
    Route::post('/oauth/introspect', [OidcController::class, 'introspect'])->middleware('throttle:token');
    Route::post('/access/v1/evaluation', [AuthzenController::class, 'evaluation']);
    Route::post('/access/v1/evaluations', [AuthzenController::class, 'evaluations']);
    Route::post('/access/v1/search/action', [AuthzenController::class, 'searchAction']);
    Route::get('/api/v1/bar', BarController::class)->middleware('throttle:240,1');
    Route::post('/api/v1/bar/read', [BarController::class, 'read'])->middleware('throttle:120,1');
    Route::post('/api/v1/release-notes', [ReleaseNoteController::class, 'store'])->middleware('throttle:30,1');
});

// ---- Hosted pages ----
Route::get('/', fn () => auth()->check() ? redirect()->route('account') : redirect()->route('login'));
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
Route::get('/login/c/{key}', [Auth\ConnectionLoginController::class, 'redirect'])->where('key', '[a-z0-9-]{1,40}')->middleware('throttle:30,1')->name('login.connection');
Route::get('/login/c/{key}/callback', [Auth\ConnectionLoginController::class, 'callback'])->where('key', '[a-z0-9-]{1,40}')->middleware('throttle:30,1')->name('login.connection.callback');
Route::get('/login/{provider}', [SocialController::class, 'redirect'])->whereIn('provider', SocialController::PROVIDERS)->middleware('throttle:login')->name('login.social');
Route::get('/login/{provider}/callback', [SocialController::class, 'callback'])->whereIn('provider', SocialController::PROVIDERS)->middleware('throttle:login')->name('login.social.callback');
Route::get('/login/locked', [LoginController::class, 'locked'])->name('login.locked');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::get('/login/2fa', [TwoFactorController::class, 'challenge'])->name('login.mfa');
Route::post('/login/2fa/sms', [TwoFactorController::class, 'sendSms'])->middleware('throttle:mfa')->name('login.mfa.sms');
Route::get('/login/2fa/sms-setup', [TwoFactorController::class, 'smsSetup'])->name('login.mfa.sms-setup');
Route::post('/login/2fa/sms-setup', [TwoFactorController::class, 'smsSetupSend'])->middleware('throttle:mfa');
Route::post('/login/2fa/sms-setup/confirm', [TwoFactorController::class, 'smsSetupConfirm'])->middleware('throttle:mfa');
// Device sign-in (RFC 8628). The route name passport.device also turns on Passport's device-code grant.
Route::get('/approve/{id}', [\App\Http\Controllers\OAuth\CibaController::class, 'show'])->middleware('auth')->where('id', '[A-Za-z0-9]{48}')->name('ciba.show');
Route::post('/approve/{id}', [\App\Http\Controllers\OAuth\CibaController::class, 'decide'])->middleware(['auth', 'throttle:20,1'])->where('id', '[A-Za-z0-9]{48}');
Route::get('/device', [\App\Http\Controllers\OAuth\DeviceController::class, 'show'])->middleware('auth')->name('passport.device');
Route::post('/device', [\App\Http\Controllers\OAuth\DeviceController::class, 'decide'])->middleware(['auth', 'throttle:20,1']);
Route::get('/signup', [Auth\SignupController::class, 'show'])->name('signup');
Route::post('/signup', [Auth\SignupController::class, 'store'])->middleware('throttle:5,10');
Route::post('/signup/verify', [Auth\SignupController::class, 'verify'])->middleware('throttle:10,10');
Route::get('/login/code', [Auth\LoginCodeController::class, 'show'])->name('login.code');
Route::post('/login/code', [Auth\LoginCodeController::class, 'send'])->middleware('throttle:login');
Route::post('/login/code/verify', [Auth\LoginCodeController::class, 'verify'])->middleware('throttle:mfa');
Route::post('/login/code/restart', [Auth\LoginCodeController::class, 'restart']);
Route::post('/login/2fa', [TwoFactorController::class, 'verify'])->middleware('throttle:mfa');
Route::get('/login/2fa/setup', [TwoFactorController::class, 'setup'])->name('login.mfa.setup');
Route::post('/login/2fa/setup', [TwoFactorController::class, 'confirm'])->middleware('throttle:mfa');
Route::post('/login/2fa/skip', [TwoFactorController::class, 'skip'])->name('login.mfa.skip');
Route::get('/login/2fa/codes', [TwoFactorController::class, 'codes'])->name('login.mfa.codes');
Route::post('/login/2fa/codes', [TwoFactorController::class, 'finish']);

Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:forgot')->name('password.email');
Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');

// Custom-domain ownership proof; answered by ServeCustomDomain on the app's domain, 404 everywhere else.
Route::get('/.well-known/clauth-domain/{token}', fn () => response('Not found', 404))->where('token', '[a-z0-9]{32}');
Route::post('/oauth/details', [AuthorizeController::class, 'details'])->middleware(['auth', 'throttle:30,1'])->name('oauth.details');
Route::post('/oauth/consent', ConsentController::class)->middleware(['auth', 'throttle:30,1'])->name('oauth.consent');
Route::get('/sso/check', [SsoController::class, 'check'])->middleware('throttle:60,1')->name('sso.check');
Route::post('/sso/check', [SsoController::class, 'confirm'])->middleware('throttle:60,1')->name('sso.confirm');
Route::get('/sso/accept', [SsoController::class, 'accept'])->middleware('throttle:60,1')->name('sso.accept');
Route::get('/sso/adopt', [SsoController::class, 'adoptOnMain'])->middleware('throttle:60,1')->name('sso.adopt');
Route::get('/invite/{token}', [InviteController::class, 'show'])->name('invite.show');
Route::post('/invite/{token}', [InviteController::class, 'store'])->middleware('throttle:10,1');

Route::get('/apps/{app}/logo', [Admin\AppController::class, 'logo'])->name('app-logo');
Route::get('/signout-everywhere', [SignOutEverywhereController::class, 'show'])->name('signout-everywhere');
Route::post('/signout-everywhere', [SignOutEverywhereController::class, 'store']);
Route::view('/privacy', 'pages.privacy')->name('privacy');
Route::view('/terms', 'pages.terms')->name('terms');
Route::view('/help', 'pages.help')->name('help');
Route::get('/organizations/new', [OrganizationRequestController::class, 'show'])->name('org-request');
Route::post('/organizations/new', [OrganizationRequestController::class, 'store'])->middleware('throttle:3,10')->name('org-request.store');
Route::get('/organizations/verify/{token}', [OrganizationRequestController::class, 'verify'])->middleware('throttle:20,10')->name('org-request.verify');
Route::view('/dpa', 'pages.dpa')->name('dpa');
Route::get('/report', [ReportController::class, 'show'])->name('report');
Route::post('/report', [ReportController::class, 'store'])->middleware('throttle:5,10')->name('report.store');

// ---- My account ----
Route::middleware('auth')->prefix('account')->name('account')->group(function () {
    Route::get('/', [AccountController::class, 'show']);
    Route::post('/profile', [AccountController::class, 'profile'])->name('.profile');
    Route::post('/password', [AccountController::class, 'password'])->name('.password');
    Route::post('/2fa', [AccountController::class, 'startTwoFactor'])->name('.2fa');
    Route::post('/2fa/disable', [AccountController::class, 'disableTwoFactor'])->name('.2fa.disable');
    Route::post('/2fa/codes', [AccountController::class, 'regenerateCodes'])->name('.2fa.codes');
    Route::delete('/sessions/web/{id}', [AccountController::class, 'revokeWeb'])->name('.sessions.web');
    Route::delete('/sessions/app/{id}', [AccountController::class, 'revokeApp'])->name('.sessions.app');
    Route::post('/sessions/revoke-all', [AccountController::class, 'revokeAll'])->name('.sessions.all');
    Route::get('/link/c/{key}', [Auth\ConnectionLoginController::class, 'link'])->where('key', '[a-z0-9-]{1,40}')->name('.connection.link');
    Route::delete('/link/c/{key}', [Auth\ConnectionLoginController::class, 'unlink'])->where('key', '[a-z0-9-]{1,40}')->name('.connection.unlink');
    Route::get('/link/{provider}', [SocialController::class, 'link'])->whereIn('provider', SocialController::PROVIDERS)->name('.social.link');
    Route::delete('/link/{provider}', [SocialController::class, 'unlink'])->whereIn('provider', SocialController::PROVIDERS)->name('.social.unlink');
    Route::delete('/consents/{app}', [AccountController::class, 'revokeConsent'])->name('.consents.revoke');
    Route::post('/phone', [\App\Http\Controllers\AccountPhoneController::class, 'send'])->middleware('throttle:10,1')->name('.phone');
    Route::post('/phone/verify', [\App\Http\Controllers\AccountPhoneController::class, 'verify'])->middleware('throttle:10,1')->name('.phone.verify');
    Route::post('/sms-two-step', [\App\Http\Controllers\AccountPhoneController::class, 'twoStep'])->name('.sms-two-step');
    Route::get('/tokens', [ApiTokenController::class, 'index'])->name('.tokens');
    Route::post('/tokens', [ApiTokenController::class, 'store'])->middleware('throttle:10,1')->name('.tokens.store');
    Route::delete('/tokens/{id}', [ApiTokenController::class, 'revoke'])->name('.tokens.revoke');
});

// ---- Admin console ----
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', Admin\DashboardController::class)->name('dashboard');
    Route::get('/search', Admin\SearchController::class)->middleware('throttle:120,1')->name('search');

    // People: Clauth owner or org owner/admin, scoped to their organizations.
    Route::middleware('admin:people')->group(function () {
        Route::get('/orgs/{org}/sign-in-rules', [Admin\SigninPolicyController::class, 'showOrg'])->name('orgs.policy');
        Route::put('/orgs/{org}/sign-in-rules', [Admin\SigninPolicyController::class, 'updateOrg']);
        Route::post('/orgs/{org}/sign-in-rules/restore', [Admin\SigninPolicyController::class, 'restoreOrg']);
        Route::post('/orgs/{org}/sign-in-rules/reset', [Admin\SigninPolicyController::class, 'resetOrg']);
        Route::get('/users', [Admin\UserController::class, 'index'])->name('users.index');
        Route::post('/users/invite', [Admin\UserController::class, 'invite'])->name('users.invite');
        Route::post('/users/bulk', [Admin\UserController::class, 'bulk'])->name('users.bulk');
        Route::get('/users/{id}', [Admin\UserController::class, 'show'])->name('users.show');
        Route::put('/users/{id}', [Admin\UserController::class, 'update'])->name('users.update');
        Route::post('/users/{id}/grants', [Admin\UserController::class, 'grants'])->name('users.grants');
        Route::post('/users/{id}/orgs', [Admin\UserController::class, 'addOrg'])->name('users.orgs.add');
        Route::delete('/users/{id}/orgs', [Admin\UserController::class, 'removeOrg'])->name('users.orgs.remove');
        Route::delete('/users/{id}/sessions/{kind}/{session}', [Admin\UserController::class, 'revokeSession'])->whereIn('kind', ['web', 'app'])->name('users.sessions.revoke');
        Route::delete('/users/{id}/links/{provider}', [Admin\UserController::class, 'unlink'])->name('users.links.destroy');
        Route::delete('/users/{id}', [Admin\UserController::class, 'destroy'])->name('users.destroy');
        Route::post('/users/{id}/{action}', [Admin\UserController::class, 'action'])->name('users.action');

        Route::get('/orgs', [Admin\OrgController::class, 'index'])->name('orgs.index');
        // Redesign 2026-11: one page per organization tab.
        Route::get('/orgs/{org}', [Admin\OrgController::class, 'show'])->name('orgs.show');
        Route::get('/orgs/{org}/apps', [Admin\OrgController::class, 'apps'])->name('orgs.apps.index');
        Route::get('/orgs/{org}/domain', [Admin\OrgController::class, 'domainPage'])->name('orgs.domain.show');
        Route::get('/orgs/{org}/settings', [Admin\OrgController::class, 'settings'])->name('orgs.settings');
        Route::put('/orgs/{org}/general', [Admin\OrgController::class, 'general'])->name('orgs.general');
        Route::post('/orgs/{org}/logo', [Admin\OrgController::class, 'logo'])->name('orgs.logo');
        Route::delete('/orgs/{org}/logo', [Admin\OrgController::class, 'removeLogo'])->name('orgs.logo.destroy');
        Route::get('/orgs/{org}/logo', [Admin\OrgController::class, 'showLogo'])->name('orgs.logo.show');
        Route::post('/orgs/{org}/roles', [Admin\OrgController::class, 'role'])->name('orgs.roles');
        Route::post('/orgs/{org}/domain', [Admin\OrgController::class, 'domain'])->name('orgs.domain');
        Route::post('/orgs/{org}/domain/check', [Admin\OrgController::class, 'checkDomain'])->name('orgs.domain.check');
        Route::post('/orgs/{org}/terms', [Admin\OrgController::class, 'acceptTerms'])->name('orgs.terms');
        Route::get('/orgs/{org}/export', [Admin\OrgController::class, 'export'])->name('orgs.export');
        Route::post('/orgs/{org}/deletion', [Admin\OrgController::class, 'requestDeletion'])->name('orgs.deletion');
        Route::delete('/orgs/{org}/deletion', [Admin\OrgController::class, 'cancelDeletion'])->name('orgs.deletion.cancel');
        Route::post('/orgs/{org}/apps', [Admin\OrgController::class, 'addApp'])->name('orgs.apps.store');
        Route::delete('/orgs/{org}/apps/{app}', [Admin\OrgController::class, 'removeApp'])->name('orgs.apps.destroy');
        Route::post('/orgs/{org}/requests/{app}', [Admin\OrgController::class, 'approve'])->name('orgs.requests.approve');
        Route::get('/orgs/{org}/agents', [Admin\AgentController::class, 'index'])->name('agents.index');
        Route::post('/orgs/{org}/agents', [Admin\AgentController::class, 'store'])->name('agents.store');
        Route::put('/orgs/{org}/agents/{agent}/access', [Admin\AgentController::class, 'access'])->name('agents.access');
        Route::post('/orgs/{org}/agents/{agent}/secret', [Admin\AgentController::class, 'rotate'])->name('agents.rotate');
        Route::post('/orgs/{org}/agents/{agent}/status', [Admin\AgentController::class, 'status'])->name('agents.status');
        Route::delete('/orgs/{org}/agents/{agent}', [Admin\AgentController::class, 'destroy'])->name('agents.destroy');
        Route::get('/orgs/{org}/messages', [Admin\MessagesController::class, 'index'])->name('messages.index');
        Route::post('/orgs/{org}/messages', [Admin\MessagesController::class, 'save'])->name('messages.save');
        Route::get('/orgs/{org}/connections', [Admin\ConnectionController::class, 'index'])->name('connections.index');
        Route::post('/orgs/{org}/connections', [Admin\ConnectionController::class, 'store'])->name('connections.store');
        Route::put('/orgs/{org}/connections/{connection}', [Admin\ConnectionController::class, 'update'])->whereNumber('connection')->name('connections.update');
        Route::delete('/orgs/{org}/connections/{connection}', [Admin\ConnectionController::class, 'destroy'])->whereNumber('connection')->name('connections.destroy');
        Route::post('/orgs/{org}/connections/{connection}/test', [Admin\ConnectionController::class, 'test'])->whereNumber('connection')->middleware('throttle:10,1')->name('connections.test');
        Route::get('/orgs/{org}/teams-fields', [Admin\OrgDataController::class, 'index'])->name('org-data.index');
        Route::post('/orgs/{org}/teams', [Admin\OrgDataController::class, 'storeTeam'])->name('org-data.teams.store');
        Route::delete('/orgs/{org}/teams/{team}', [Admin\OrgDataController::class, 'destroyTeam'])->whereNumber('team')->name('org-data.teams.destroy');
        Route::post('/orgs/{org}/fields', [Admin\OrgDataController::class, 'storeField'])->name('org-data.fields.store');
        Route::put('/orgs/{org}/fields/{field}', [Admin\OrgDataController::class, 'updateField'])->whereNumber('field')->name('org-data.fields.update');
        Route::delete('/orgs/{org}/fields/{field}', [Admin\OrgDataController::class, 'destroyField'])->whereNumber('field')->name('org-data.fields.destroy');
        Route::get('/orgs/{org}/api-clients', [Admin\ApiClientController::class, 'index'])->name('api-clients.index');
        Route::post('/orgs/{org}/api-clients', [Admin\ApiClientController::class, 'store'])->name('api-clients.store');
        Route::post('/orgs/{org}/api-clients/{client}/rotate', [Admin\ApiClientController::class, 'rotate'])->name('api-clients.rotate');
        Route::post('/orgs/{org}/api-clients/scim', [Admin\ApiClientController::class, 'scim'])->name('api-clients.scim');
        Route::post('/orgs/{org}/api-clients/{client}/revoke', [Admin\ApiClientController::class, 'revoke'])->name('api-clients.revoke');
        Route::get('/orgs/{org}/profiles', [Admin\ProfileController::class, 'index'])->name('profiles.index');
        Route::post('/orgs/{org}/profiles', [Admin\ProfileController::class, 'store'])->name('profiles.store');
        Route::get('/orgs/{org}/profiles/suggest', [Admin\ProfileController::class, 'suggest'])->name('profiles.suggest');
        Route::post('/orgs/{org}/profiles/suggest', [Admin\ProfileController::class, 'acceptSuggestion'])->name('profiles.suggest.accept');
        Route::get('/orgs/{org}/profiles/apps/{app}', [Admin\ProfileController::class, 'app'])->name('profiles.app');
        Route::put('/orgs/{org}/profiles/apps/{app}', [Admin\ProfileController::class, 'saveApp'])->name('profiles.app.save');
        Route::get('/orgs/{org}/alignment', [Admin\ProfileController::class, 'alignment'])->name('profiles.alignment');
        Route::get('/orgs/{org}/alignment.csv', [Admin\ProfileController::class, 'alignmentCsv'])->name('profiles.alignment.csv');
        Route::get('/orgs/{org}/profiles/{profile}', [Admin\ProfileController::class, 'show'])->name('profiles.show');
        Route::put('/orgs/{org}/profiles/{profile}', [Admin\ProfileController::class, 'update'])->name('profiles.update');
        Route::delete('/orgs/{org}/profiles/{profile}', [Admin\ProfileController::class, 'destroy'])->name('profiles.destroy');
        Route::post('/orgs/{org}/profiles/{profile}/people', [Admin\ProfileController::class, 'addPerson'])->name('profiles.people.add');
        Route::delete('/orgs/{org}/profiles/{profile}/people/{user}', [Admin\ProfileController::class, 'removePerson'])->name('profiles.people.remove');
        Route::get('/audit', [Admin\AuditController::class, 'index'])->name('audit');
        Route::get('/audit.csv', [Admin\AuditController::class, 'export'])->name('audit.export');
    });

    // Apps: Clauth owner or org owner/admin/developer of the app's publisher.
    Route::middleware('admin:apps')->group(function () {
        Route::get('/orgs/{org}/themes', [Admin\ThemeController::class, 'index'])->name('themes.index');
        Route::post('/orgs/{org}/themes', [Admin\ThemeController::class, 'store'])->name('themes.store');
        Route::put('/orgs/{org}/themes/{theme}', [Admin\ThemeController::class, 'update'])->whereNumber('theme')->name('themes.update');
        Route::delete('/orgs/{org}/themes/{theme}', [Admin\ThemeController::class, 'destroy'])->whereNumber('theme')->name('themes.destroy');
        Route::get('/apps/{app}/sign-in-rules', [Admin\SigninPolicyController::class, 'showApp'])->name('apps.policy');
        Route::put('/apps/{app}/sign-in-rules', [Admin\SigninPolicyController::class, 'updateApp']);
        Route::post('/apps/{app}/sign-in-rules/restore', [Admin\SigninPolicyController::class, 'restoreApp']);
        Route::post('/apps/{app}/sign-in-rules/reset', [Admin\SigninPolicyController::class, 'resetApp']);
        Route::get('/apps', [Admin\AppController::class, 'index'])->name('apps.index');
        Route::post('/apps', [Admin\AppController::class, 'store'])->name('apps.store');
        Route::get('/apps/{app}', [Admin\AppController::class, 'show'])->name('apps.show');
        Route::put('/apps/{app}', [Admin\AppController::class, 'update'])->name('apps.update');
        Route::delete('/apps/{app}', [Admin\AppController::class, 'destroy'])->name('apps.destroy');
        Route::get('/apps/{app}/preview', [Admin\AppController::class, 'preview'])->name('apps.preview');
        Route::post('/apps/{app}/branding', [Admin\AppController::class, 'branding'])->name('apps.branding');
        Route::post('/apps/{app}/installations', [Admin\AppController::class, 'requestInstall'])->name('apps.installations.store');
        Route::delete('/apps/{app}/installations/{org}', [Admin\AppController::class, 'removeInstall'])->name('apps.installations.destroy');
        Route::post('/apps/{app}/domain', [Admin\AppController::class, 'storeDomain'])->name('apps.domain.store');
        Route::post('/apps/{app}/domain/check', [Admin\AppController::class, 'checkDomain'])->name('apps.domain.check');
        Route::delete('/apps/{app}/domain', [Admin\AppController::class, 'destroyDomain'])->name('apps.domain.destroy');
        Route::patch('/apps/{app}/domain', [Admin\AppController::class, 'updateDomain'])->name('apps.domain.update');
        Route::post('/apps/{app}/sync', [Admin\AppController::class, 'sync'])->name('apps.sync');
        Route::post('/apps/{app}/manifest', [Admin\AppController::class, 'importManifest'])->name('apps.manifest');
        Route::post('/apps/{app}/matrix', [Admin\AppController::class, 'matrix'])->name('apps.matrix');
        Route::post('/apps/{app}/roles', [Admin\AppController::class, 'storeRole'])->name('apps.roles.store');
        Route::delete('/apps/{app}/roles/{role}', [Admin\AppController::class, 'destroyRole'])->name('apps.roles.destroy');
        Route::patch('/apps/{app}/roles/{role}', [Admin\AppController::class, 'updateRole'])->name('apps.roles.update');
        Route::post('/apps/{app}/roles-access', [Admin\AppController::class, 'accessRole'])->name('apps.roles.access');
        Route::post('/apps/{app}/permissions', [Admin\AppController::class, 'storePermission'])->name('apps.permissions.store');
        Route::post('/apps/{app}/clients', [Admin\AppController::class, 'addClient'])->name('apps.clients.store');
        Route::post('/apps/{app}/webhooks', [Admin\WebhookController::class, 'store'])->name('apps.webhooks.store');
        Route::patch('/apps/{app}/webhooks/{id}', [Admin\WebhookController::class, 'update'])->whereNumber('id')->name('apps.webhooks.update');
        Route::delete('/apps/{app}/webhooks/{id}', [Admin\WebhookController::class, 'destroy'])->whereNumber('id')->name('apps.webhooks.destroy');
        Route::post('/apps/{app}/webhooks/{id}/test', [Admin\WebhookController::class, 'test'])->whereNumber('id')->name('apps.webhooks.test');
        Route::post('/apps/{app}/deliveries/{delivery}/redeliver', [Admin\WebhookController::class, 'redeliver'])->name('apps.deliveries.redeliver');
        Route::put('/apps/{app}/clients/{client}', [Admin\AppController::class, 'updateClient'])->name('apps.clients.update');
        Route::post('/apps/{app}/clients/{client}/rotate', [Admin\AppController::class, 'rotateSecret'])->name('apps.clients.rotate');
        Route::post('/apps/{app}/clients/{client}/revoke', [Admin\AppController::class, 'revokeClient'])->name('apps.clients.revoke');
    });

    // Platform: Clauth owner only.
    Route::middleware('admin:owner')->group(function () {
        Route::get('/settings/sign-in-rules', [Admin\SigninPolicyController::class, 'showPlatform'])->name('settings.policy');
        Route::put('/settings/sign-in-rules', [Admin\SigninPolicyController::class, 'updatePlatform']);
        Route::post('/settings/sign-in-rules/restore', [Admin\SigninPolicyController::class, 'restorePlatform']);
        Route::post('/settings/sign-in-rules/reset', [Admin\SigninPolicyController::class, 'resetPlatform']);
        Route::post('/orgs', [Admin\OrgController::class, 'store'])->name('orgs.store');
        Route::put('/orgs/{org}', [Admin\OrgController::class, 'update'])->name('orgs.update');
        Route::post('/orgs/{org}/suspend', [Admin\OrgController::class, 'suspend'])->name('orgs.suspend');
        Route::get('/org-requests/{orgRequest}', [Admin\OrgController::class, 'showRequest'])->name('org-requests.show');
        Route::post('/org-requests/{orgRequest}/approve', [Admin\OrgController::class, 'approveRequest'])->name('org-requests.approve');
        Route::post('/org-requests/{orgRequest}/reject', [Admin\OrgController::class, 'rejectRequest'])->name('org-requests.reject');
        Route::delete('/orgs/{org}/suspend', [Admin\OrgController::class, 'restore'])->name('orgs.restore');
        Route::post('/apps/{app}/suspend', [Admin\AppController::class, 'suspend'])->name('apps.suspend');
        Route::delete('/apps/{app}/suspend', [Admin\AppController::class, 'restore'])->name('apps.restore');

        Route::get('/reports', [Admin\ReportController::class, 'index'])->name('reports');
        Route::post('/reports/{report}/close', [Admin\ReportController::class, 'close'])->name('reports.close');

        Route::get('/updates', [Admin\UpdatesController::class, 'index'])->name('updates');
        Route::post('/updates', [Admin\UpdatesController::class, 'store'])->name('updates.store');
        Route::put('/updates/{note}', [Admin\UpdatesController::class, 'update'])->name('updates.update');
        Route::delete('/updates/{note}', [Admin\UpdatesController::class, 'destroy'])->name('updates.destroy');

        Route::get('/settings', [Admin\SettingsController::class, 'show'])->name('settings');
        Route::put('/settings', [Admin\SettingsController::class, 'update'])->name('settings.update');
        Route::post('/settings/keys', [Admin\SettingsController::class, 'stageKey'])->name('settings.keys.stage');
        Route::post('/settings/keys/{key}/activate', [Admin\SettingsController::class, 'activateKey'])->name('settings.keys.activate');
    });
});
