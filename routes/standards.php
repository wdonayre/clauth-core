<?php

use App\Http\Controllers\AuthzenController;
use App\Http\Controllers\ScimController;
use Illuminate\Support\Facades\Route;

/*
 * Standard protocols on the root path, stateless (api middleware group): SCIM 2.0 (SPEC-platform §9.2).
 * AuthZEN lives in web.php next to the other OAuth endpoints.
 */
Route::prefix('scim/v2')->middleware(['scim', 'api.client:scim'])->group(function () {
    Route::get('ServiceProviderConfig', [ScimController::class, 'serviceProviderConfig']);
    Route::get('ResourceTypes', [ScimController::class, 'resourceTypes']);
    Route::get('Schemas', [ScimController::class, 'schemas']);
    Route::get('Users', [ScimController::class, 'users']);
    Route::post('Users', [ScimController::class, 'createUser']);
    Route::get('Users/{id}', [ScimController::class, 'user']);
    Route::put('Users/{id}', [ScimController::class, 'replaceUser']);
    Route::patch('Users/{id}', [ScimController::class, 'patchUser']);
    Route::delete('Users/{id}', [ScimController::class, 'deleteUser']);
    Route::get('Groups', [ScimController::class, 'groups']);
    Route::post('Groups', [ScimController::class, 'createGroup']);
    Route::get('Groups/{id}', [ScimController::class, 'group']);
    Route::put('Groups/{id}', [ScimController::class, 'replaceGroup']);
    Route::patch('Groups/{id}', [ScimController::class, 'patchGroup']);
    Route::delete('Groups/{id}', [ScimController::class, 'deleteGroup']);
});
