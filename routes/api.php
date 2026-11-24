<?php

use App\Http\Controllers\Api\V1\AppsController;
use App\Http\Controllers\Api\V1\AppWritesController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\InvitesController;
use App\Http\Controllers\Api\V1\LegacyUserLookup;
use App\Http\Controllers\Api\V1\MiscController;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Controllers\Api\V1\OrgsController;
use App\Http\Controllers\Api\V1\ProfilesController;
use App\Http\Controllers\Api\V1\TeamsController;
use App\Http\Controllers\Api\V1\UsersController;
use Illuminate\Support\Facades\Route;

/*
 * Platform API /api/v1 (SPEC-platform §5–§6). Every route here except health and the OpenAPI file runs behind
 * api.client:<scope>. Keep App\Api\OpenApi in step: a test fails when a route has no description there.
 */

Route::get('health/live', [MiscController::class, 'live']);
Route::get('health/ready', [MiscController::class, 'ready'])->middleware('throttle:60,1');
Route::get('openapi.json', OpenApiController::class)->middleware('throttle:60,1');

// Apps' own m2m lookup (SPEC §9) and the API's user read share this path.
Route::get('users/{user}', LegacyUserLookup::class)->middleware('throttle:600,1');

Route::middleware('api.client')->get('capabilities', [MiscController::class, 'capabilities']);

Route::middleware(['api.client:users:read'])->group(function () {
    Route::get('users', [UsersController::class, 'index']);
    Route::get('invites', [InvitesController::class, 'index']);
});
Route::middleware(['api.client:users:write', 'api.idempotent'])->group(function () {
    Route::patch('users/{user}', [UsersController::class, 'update']);
    Route::delete('users/{user}', [UsersController::class, 'destroy']);
    Route::post('users/{user}/{action}', [UsersController::class, 'action'])
        ->whereIn('action', ['disable', 'enable', 'unlock', 'reset-two-step', 'two-step-exempt', 'two-step-required', 'sign-out', 'password-reset']);
    Route::post('invites', [InvitesController::class, 'store']);
    Route::delete('invites/{invite}', [InvitesController::class, 'destroy']);
    Route::post('invites/{invite}/resend', [InvitesController::class, 'resend']);
});

Route::middleware(['api.client:orgs:read'])->group(function () {
    Route::get('orgs', [OrgsController::class, 'index']);
    Route::get('orgs/{org}', [OrgsController::class, 'show']);
    Route::get('orgs/{org}/members', [OrgsController::class, 'members']);
    Route::get('orgs/{org}/teams', [TeamsController::class, 'index']);
    Route::get('orgs/{org}/teams/{team}', [TeamsController::class, 'show']);
    Route::get('orgs/{org}/fields', [TeamsController::class, 'fields']);
    Route::get('orgs/{org}/members/{user}/profile', [TeamsController::class, 'memberProfile']);
});
Route::middleware(['api.client:orgs:write', 'api.idempotent'])->group(function () {
    Route::put('orgs/{org}/members/{user}', [OrgsController::class, 'putMember']);
    Route::delete('orgs/{org}/members/{user}', [OrgsController::class, 'removeMember']);
    Route::post('orgs/{org}/teams', [TeamsController::class, 'store']);
    Route::patch('orgs/{org}/teams/{team}', [TeamsController::class, 'update']);
    Route::delete('orgs/{org}/teams/{team}', [TeamsController::class, 'destroy']);
    Route::put('orgs/{org}/teams/{team}/members/{user}', [TeamsController::class, 'addMember']);
    Route::delete('orgs/{org}/teams/{team}/members/{user}', [TeamsController::class, 'removeMember']);
    Route::post('orgs/{org}/fields', [TeamsController::class, 'storeField']);
    Route::patch('orgs/{org}/fields/{field}', [TeamsController::class, 'updateField']);
    Route::delete('orgs/{org}/fields/{field}', [TeamsController::class, 'destroyField']);
    Route::patch('orgs/{org}/members/{user}/profile', [TeamsController::class, 'updateMemberProfile']);
});

Route::middleware(['api.client:access:read'])->group(function () {
    Route::get('grants', [AppsController::class, 'grants']);
    Route::get('orgs/{org}/members/{user}/access', [OrgsController::class, 'access']);
    Route::get('orgs/{org}/alignment', [OrgsController::class, 'alignment']);
    Route::get('orgs/{org}/profiles', [ProfilesController::class, 'index']);
    Route::get('orgs/{org}/profiles/{profile}', [ProfilesController::class, 'show']);
});
Route::middleware(['api.client:access:write', 'api.idempotent'])->group(function () {
    Route::put('orgs/{org}/members/{user}/access', [OrgsController::class, 'putAccess']);
    Route::post('orgs/{org}/profiles', [ProfilesController::class, 'store']);
    Route::patch('orgs/{org}/profiles/{profile}', [ProfilesController::class, 'update']);
    Route::delete('orgs/{org}/profiles/{profile}', [ProfilesController::class, 'destroy']);
    Route::put('orgs/{org}/profiles/{profile}/people/{user}', [ProfilesController::class, 'addPerson']);
    Route::delete('orgs/{org}/profiles/{profile}/people/{user}', [ProfilesController::class, 'removePerson']);
});

Route::middleware(['api.client:apps:read'])->group(function () {
    Route::get('apps', [AppsController::class, 'index']);
    Route::get('apps/{app}', [AppsController::class, 'show']);
});
Route::middleware(['api.client:apps:write', 'api.idempotent'])->group(function () {
    Route::post('apps', [AppWritesController::class, 'store']);
    Route::patch('apps/{app}', [AppWritesController::class, 'update']);
    Route::delete('apps/{app}', [AppWritesController::class, 'destroy']);
    Route::post('apps/{app}/manifest/sync', [AppsController::class, 'syncManifest']);
    Route::post('apps/{app}/clients', [AppWritesController::class, 'addClient']);
    Route::patch('apps/{app}/clients/{client}', [AppWritesController::class, 'updateClient']);
    Route::post('apps/{app}/roles', [AppWritesController::class, 'storeRole']);
    Route::patch('apps/{app}/roles/{role}', [AppWritesController::class, 'updateRole']);
    Route::delete('apps/{app}/roles/{role}', [AppWritesController::class, 'destroyRole']);
    Route::put('apps/{app}/roles/{role}/permissions', [AppWritesController::class, 'rolePermissions']);
    Route::post('apps/{app}/permissions', [AppWritesController::class, 'storePermission']);
    Route::match(['put', 'post'], 'apps/{app}/branding', [AppWritesController::class, 'branding']);
    Route::get('apps/{app}/installations', [AppWritesController::class, 'installations'])->withoutMiddleware('api.idempotent');
    Route::post('apps/{app}/installations', [AppWritesController::class, 'addInstallation']);
    Route::delete('apps/{app}/installations/{org}', [AppWritesController::class, 'removeInstallation']);
    Route::get('apps/{app}/domain', [AppWritesController::class, 'domain']);
    Route::post('apps/{app}/domain', [AppWritesController::class, 'storeDomain']);
    Route::post('apps/{app}/domain/check', [AppWritesController::class, 'checkDomain']);
    Route::patch('apps/{app}/domain', [AppWritesController::class, 'updateDomain']);
    Route::delete('apps/{app}/domain', [AppWritesController::class, 'destroyDomain']);
    Route::post('apps/{app}/clients/{client}/rotate', [AppsController::class, 'rotateClient']);
    Route::post('apps/{app}/clients/{client}/revoke', [AppsController::class, 'revokeClient']);
});

Route::middleware('api.client:me:read')->group(function () {
    Route::get('me', [MeController::class, 'show']);
    Route::get('me/apps', [MeController::class, 'apps']);
    Route::get('me/sessions', [MeController::class, 'sessions']);
    Route::get('me/consents', [MeController::class, 'consents']);
    Route::get('me/orgs/{org}/profile', [MeController::class, 'orgProfile']);
});
Route::middleware(['api.client:me:write', 'api.idempotent'])->group(function () {
    Route::patch('me', [MeController::class, 'update']);
    Route::delete('me/sessions/{id}', [MeController::class, 'endSession']);
    Route::delete('me/consents/{app}', [MeController::class, 'revokeConsent']);
    Route::patch('me/orgs/{org}/profile', [MeController::class, 'updateOrgProfile']);
});

Route::middleware('api.client:audit:read')->get('audit', [MiscController::class, 'audit']);
Route::middleware('api.client:settings:read')->get('settings', [MiscController::class, 'settings']);
Route::middleware('api.client:settings:write')->patch('settings', [MiscController::class, 'updateSettings']);
