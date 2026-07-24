<?php

use App\Http\Controllers\Admin\FolderController;
use App\Http\Controllers\Admin\InstanceController;
use App\Http\Controllers\Admin\StatusController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\DirectoryController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/*
 * All routes run on the `web` stack: Nuxt forwards /api via the Nitro proxy to
 * this backend, so the UI and the API share an origin. That way the session
 * cookie and CSRF protection work without extra configuration.
 */

Route::prefix('api')->group(function () {
    // For the container health check. Says nothing about signed-in users.
    Route::get('/health', fn () => response()->json(['status' => 'ok']));

    // Setzt den XSRF-TOKEN-Cookie, den das Frontend vor dem Login braucht.
    Route::get('/auth/csrf', fn () => response()->noContent());

    Route::post('/auth/login', [SessionController::class, 'login']);

    Route::middleware('auth')->group(function () {
        Route::post('/auth/logout', [SessionController::class, 'logout']);
        Route::get('/auth/me', [SessionController::class, 'me']);
        Route::put('/auth/password', [SessionController::class, 'changePassword']);

        Route::get('/search', SearchController::class);

        Route::get('/documents/{document}', [DocumentController::class, 'show']);
        Route::get('/documents/{document}/raw', [DocumentController::class, 'raw']);
        Route::get('/documents/{document}/preview', [DocumentController::class, 'preview']);
        Route::get('/documents/{document}/content', [DocumentController::class, 'content']);

        // Instance and folder images — for every signed-in user, because they
        // appear on hits and facets. Uploading stays an admin matter.
        Route::get('/directory', [DirectoryController::class, 'index']);
        Route::get('/instances/{instance}/image', [DirectoryController::class, 'instanceImage']);
        Route::get('/folders/{folder}/image', [DirectoryController::class, 'folderImage']);

        Route::middleware('admin')->prefix('admin')->group(function () {
            Route::get('/status', StatusController::class);

            Route::apiResource('instances', InstanceController::class)
                ->parameters(['instances' => 'instance'])
                ->except('show');
            Route::post('/instances/{instance}/test', [InstanceController::class, 'test']);
            Route::get('/instances/{instance}/browse', [InstanceController::class, 'browse']);
            Route::post('/instances/{instance}/image', [InstanceController::class, 'uploadImage']);
            Route::delete('/instances/{instance}/image', [InstanceController::class, 'deleteImage']);

            Route::apiResource('folders', FolderController::class)->except('show');
            Route::post('/folders/{folder}/reindex', [FolderController::class, 'reindex']);
            Route::post('/folders/{folder}/image', [FolderController::class, 'uploadImage']);
            Route::delete('/folders/{folder}/image', [FolderController::class, 'deleteImage']);

            Route::apiResource('users', UserController::class)->except('show');
            Route::put('/users/{user}/folders', [UserController::class, 'syncFolderAccess']);
        });
    });
});
