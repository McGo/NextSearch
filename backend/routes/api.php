<?php

use App\Http\Controllers\Admin\FolderController;
use App\Http\Controllers\Admin\IndexController;
use App\Http\Controllers\Admin\InstanceController;
use App\Http\Controllers\Admin\QueueController;
use App\Http\Controllers\Admin\StatusController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\DirectoryController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\IndexingStatusController;
use App\Http\Controllers\SavedSearchController;
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

    // Sets the XSRF-TOKEN cookie the frontend needs before logging in.
    Route::get('/auth/csrf', fn () => response()->noContent());

    Route::post('/auth/login', [SessionController::class, 'login']);
    // Second step of a 2FA login — the user isn't authenticated yet, the
    // pending id lives in the session from the password step.
    Route::post('/auth/two-factor-challenge', [SessionController::class, 'twoFactorChallenge']);

    // Branding reads are public: the browser fetches the favicon, the manifest
    // and its icons before anyone is signed in.
    Route::get('/branding', [BrandingController::class, 'show']);
    Route::get('/branding/logo', [BrandingController::class, 'logo']);
    Route::get('/branding/icon/{variant}', [BrandingController::class, 'icon']);

    Route::middleware('auth')->group(function () {
        Route::post('/auth/logout', [SessionController::class, 'logout']);
        Route::get('/auth/me', [SessionController::class, 'me']);
        Route::put('/auth/password', [SessionController::class, 'changePassword']);

        // Manage your own two-factor.
        Route::post('/auth/two-factor', [TwoFactorController::class, 'enable']);
        Route::post('/auth/two-factor/confirm', [TwoFactorController::class, 'confirm']);
        Route::get('/auth/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes']);
        Route::post('/auth/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);
        Route::delete('/auth/two-factor', [TwoFactorController::class, 'disable']);

        Route::get('/search', SearchController::class);
        Route::get('/indexing-status', IndexingStatusController::class);

        // Saved searches — scoped to the signed-in user inside the controller.
        Route::get('/saved-searches', [SavedSearchController::class, 'index']);
        Route::post('/saved-searches', [SavedSearchController::class, 'store']);
        Route::delete('/saved-searches/{savedSearch}', [SavedSearchController::class, 'destroy']);

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
            Route::post('/queues/{queue}/clear', [QueueController::class, 'clear']);
            Route::post('/index/clear', [IndexController::class, 'clear']);
            Route::post('/index/rebuild', [IndexController::class, 'rebuild']);

            Route::post('/branding/logo', [BrandingController::class, 'upload']);
            Route::delete('/branding/logo', [BrandingController::class, 'destroy']);
            Route::put('/branding/name', [BrandingController::class, 'updateName']);

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
