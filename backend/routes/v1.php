<?php

use App\Http\Controllers\Admin\DocumentLookupController;
use App\Http\Controllers\Admin\FolderController;
use App\Http\Controllers\Admin\InstanceController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentTagController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TagController;
use Illuminate\Support\Facades\Route;

/*
 * The public API under /api/v1. Unlike routes/api.php this runs on the
 * stateless `api` stack: no session cookie, no CSRF token, authentication via
 * a personal key in the Authorization header.
 *
 *     Authorization: Bearer nxs_…
 *
 * A key carries no permissions of its own — a request does exactly what its
 * owner can do in the interface, folder shares included. The controllers are
 * the same ones the interface uses, so both sides never drift apart.
 *
 * Documented in docs/api.md, machine-readable in docs/api/openapi.yaml.
 */

Route::middleware(['auth:api', 'throttle:api'])->group(function () {
    Route::get('/me', [SessionController::class, 'me']);

    // The vocabulary.
    Route::get('/tags', [TagController::class, 'index']);
    Route::post('/tags', [TagController::class, 'store']);
    Route::patch('/tags/{tag}', [TagController::class, 'update']);
    Route::delete('/tags/{tag}', [TagController::class, 'destroy']);

    // Finding documents. Without this there is no way to reach the uuids the
    // tag calls below are addressed by.
    Route::get('/documents', SearchController::class);

    // Bulk before the parameterised routes — otherwise /documents/tags is read
    // as a document with the uuid "tags".
    Route::post('/documents/tags', [DocumentTagController::class, 'bulk']);

    Route::get('/documents/{document}', [DocumentController::class, 'show']);
    Route::get('/documents/{document}/tags', [DocumentTagController::class, 'index']);
    Route::post('/documents/{document}/tags', [DocumentTagController::class, 'store']);
    Route::put('/documents/{document}/tags', [DocumentTagController::class, 'replace']);
    Route::delete('/documents/{document}/tags/{tagKey}', [DocumentTagController::class, 'destroy']);

    /*
     * The admin API: accounts, folders, shares, re-indexing. Only for a key
     * that was made with the admin flag, by someone who is still an
     * administrator — an admin's ordinary key does not get in here.
     *
     * Deliberately missing: deleting accounts and folders, and anything
     * touching instance credentials. Those stay in the interface, where the
     * consequences are visible.
     */
    Route::middleware('admin.api')->prefix('admin')->group(function () {
        Route::get('/instances', [InstanceController::class, 'index']);

        Route::get('/folders', [FolderController::class, 'index']);
        Route::post('/folders', [FolderController::class, 'store']);
        Route::post('/folders/{folder}/reindex', [FolderController::class, 'reindex']);

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::put('/users/{user}/folders', [UserController::class, 'syncFolderAccess']);
        Route::post('/users/{user}/invitation', [UserController::class, 'invitation']);

        Route::get('/documents', DocumentLookupController::class);
    });
});
