<?php

use App\Http\Controllers\Api\V1\AppsController;
use App\Http\Controllers\Api\V1\InvitesController;
use App\Http\Controllers\Api\V1\LegacyUserLookup;
use App\Http\Controllers\Api\V1\MiscController;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Controllers\Api\V1\OrgsController;
use App\Http\Controllers\Api\V1\ProfilesController;
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
});
Route::middleware(['api.client:orgs:write', 'api.idempotent'])->group(function () {
    Route::put('orgs/{org}/members/{user}', [OrgsController::class, 'putMember']);
    Route::delete('orgs/{org}/members/{user}', [OrgsController::class, 'removeMember']);
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
    Route::post('apps/{app}/manifest/sync', [AppsController::class, 'syncManifest']);
    Route::post('apps/{app}/clients/{client}/rotate', [AppsController::class, 'rotateClient']);
    Route::post('apps/{app}/clients/{client}/revoke', [AppsController::class, 'revokeClient']);
});

Route::middleware('api.client:audit:read')->get('audit', [MiscController::class, 'audit']);
Route::middleware('api.client:settings:read')->get('settings', [MiscController::class, 'settings']);
Route::middleware('api.client:settings:write')->patch('settings', [MiscController::class, 'updateSettings']);
