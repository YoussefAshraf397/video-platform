<?php

use App\Modules\Videos\Http\Controllers\CategoryController;
use App\Modules\Videos\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('v1')->group(function () {
    Route::pattern('video', '[A-Za-z0-9_-]{11}');

    Route::middleware('auth:api')->group(function () {
        Route::post('videos', [VideoController::class, 'store'])->middleware('idempotent');
        Route::patch('videos/{video}', [VideoController::class, 'update']);
        Route::delete('videos/{video}', [VideoController::class, 'destroy']);
        Route::post('videos/{video}:publish', [VideoController::class, 'publish']);
        Route::post('videos/{video}:unpublish', [VideoController::class, 'unpublish']);
        Route::get('me/videos', [VideoController::class, 'mine']);
    });

    // Signed in or not: owners see their own drafts, everyone else only what's visible to the public.
    Route::get('videos/{video}', [VideoController::class, 'show']);
    Route::get('categories', [CategoryController::class, 'index']);
});
