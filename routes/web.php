<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin;
use App\Http\Controllers\Api\BarController;
use App\Http\Controllers\Api\UserController as ApiUserController;
use App\Http\Controllers\Auth\InviteController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\OAuth\AuthorizeController;
use App\Http\Controllers\OAuth\OidcController;
use App\Http\Controllers\OAuth\TokenController;
use App\Http\Controllers\SignOutEverywhereController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

// ---- OIDC / OAuth (SPEC §9) ----
Route::get('/.well-known/openid-configuration', [OidcController::class, 'discovery']);
Route::get('/.well-known/jwks.json', [OidcController::class, 'jwks']);
Route::get('/oauth/authorize', AuthorizeController::class)->name('oauth.authorize');
Route::match(['get', 'post'], '/oauth/logout', [OidcController::class, 'logout'])->name('oauth.logout');

Route::withoutMiddleware([ValidateCsrfToken::class])->group(function () {
    Route::post('/oauth/token', TokenController::class)->middleware('throttle:token')->name('oauth.token');
    Route::post('/oauth/revoke', [OidcController::class, 'revoke'])->middleware('throttle:token');
    Route::match(['get', 'post'], '/userinfo', [OidcController::class, 'userinfo']);
    Route::get('/api/v1/users/{sub}', [ApiUserController::class, 'show'])->middleware('throttle:120,1');
    Route::get('/api/v1/bar', BarController::class)->middleware('throttle:240,1');
});

// ---- Hosted pages ----
Route::get('/', fn () => auth()->check() ? redirect()->route('account') : redirect()->route('login'));
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
Route::get('/login/locked', [LoginController::class, 'locked'])->name('login.locked');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::get('/login/2fa', [TwoFactorController::class, 'challenge'])->name('login.mfa');
Route::post('/login/2fa', [TwoFactorController::class, 'verify'])->middleware('throttle:mfa');
Route::get('/login/2fa/setup', [TwoFactorController::class, 'setup'])->name('login.mfa.setup');
Route::post('/login/2fa/setup', [TwoFactorController::class, 'confirm'])->middleware('throttle:mfa');
Route::get('/login/2fa/codes', [TwoFactorController::class, 'codes'])->name('login.mfa.codes');
Route::post('/login/2fa/codes', [TwoFactorController::class, 'finish']);

Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:forgot')->name('password.email');
Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');

Route::get('/invite/{token}', [InviteController::class, 'show'])->name('invite.show');
Route::post('/invite/{token}', [InviteController::class, 'store'])->middleware('throttle:10,1');

Route::get('/apps/{app}/logo', [Admin\AppController::class, 'logo'])->name('app-logo');
Route::get('/signout-everywhere', [SignOutEverywhereController::class, 'show'])->name('signout-everywhere');
Route::post('/signout-everywhere', [SignOutEverywhereController::class, 'store']);
Route::view('/privacy', 'pages.privacy')->name('privacy');
Route::view('/terms', 'pages.terms')->name('terms');
Route::view('/help', 'pages.help')->name('help');

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
});

// ---- Admin console ----
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', Admin\DashboardController::class)->name('dashboard');

    Route::get('/users', [Admin\UserController::class, 'index'])->name('users.index');
    Route::post('/users/invite', [Admin\UserController::class, 'invite'])->name('users.invite');
    Route::get('/users/{id}', [Admin\UserController::class, 'show'])->name('users.show');
    Route::put('/users/{id}', [Admin\UserController::class, 'update'])->name('users.update');
    Route::post('/users/{id}/grants', [Admin\UserController::class, 'grants'])->name('users.grants');
    Route::post('/users/{id}/orgs', [Admin\UserController::class, 'addOrg'])->name('users.orgs.add');
    Route::delete('/users/{id}/orgs', [Admin\UserController::class, 'removeOrg'])->name('users.orgs.remove');
    Route::post('/users/{id}/{action}', [Admin\UserController::class, 'action'])->name('users.action');

    Route::get('/orgs', [Admin\OrgController::class, 'index'])->name('orgs.index');
    Route::get('/audit', [Admin\AuditController::class, 'index'])->name('audit');
    Route::get('/audit.csv', [Admin\AuditController::class, 'export'])->name('audit.export');

    Route::middleware('admin:owner')->group(function () {
        Route::post('/orgs', [Admin\OrgController::class, 'store'])->name('orgs.store');
        Route::put('/orgs/{org}', [Admin\OrgController::class, 'update'])->name('orgs.update');
        Route::post('/orgs/{org}/admins', [Admin\OrgController::class, 'admin'])->name('orgs.admins');

        Route::get('/apps', [Admin\AppController::class, 'index'])->name('apps.index');
        Route::post('/apps', [Admin\AppController::class, 'store'])->name('apps.store');
        Route::get('/apps/{app}', [Admin\AppController::class, 'show'])->name('apps.show');
        Route::put('/apps/{app}', [Admin\AppController::class, 'update'])->name('apps.update');
        Route::delete('/apps/{app}', [Admin\AppController::class, 'destroy'])->name('apps.destroy');
        Route::get('/apps/{app}/preview', [Admin\AppController::class, 'preview'])->name('apps.preview');
        Route::post('/apps/{app}/branding', [Admin\AppController::class, 'branding'])->name('apps.branding');
        Route::post('/apps/{app}/sync', [Admin\AppController::class, 'sync'])->name('apps.sync');
        Route::post('/apps/{app}/manifest', [Admin\AppController::class, 'importManifest'])->name('apps.manifest');
        Route::post('/apps/{app}/matrix', [Admin\AppController::class, 'matrix'])->name('apps.matrix');
        Route::post('/apps/{app}/roles', [Admin\AppController::class, 'storeRole'])->name('apps.roles.store');
        Route::delete('/apps/{app}/roles/{role}', [Admin\AppController::class, 'destroyRole'])->name('apps.roles.destroy');
        Route::post('/apps/{app}/permissions', [Admin\AppController::class, 'storePermission'])->name('apps.permissions.store');
        Route::post('/apps/{app}/clients', [Admin\AppController::class, 'addClient'])->name('apps.clients.store');
        Route::put('/apps/{app}/clients/{client}', [Admin\AppController::class, 'updateClient'])->name('apps.clients.update');
        Route::post('/apps/{app}/clients/{client}/rotate', [Admin\AppController::class, 'rotateSecret'])->name('apps.clients.rotate');
        Route::post('/apps/{app}/clients/{client}/revoke', [Admin\AppController::class, 'revokeClient'])->name('apps.clients.revoke');

        Route::get('/settings', [Admin\SettingsController::class, 'show'])->name('settings');
        Route::put('/settings', [Admin\SettingsController::class, 'update'])->name('settings.update');
        Route::post('/settings/keys', [Admin\SettingsController::class, 'stageKey'])->name('settings.keys.stage');
        Route::post('/settings/keys/{key}/activate', [Admin\SettingsController::class, 'activateKey'])->name('settings.keys.activate');
    });
});
