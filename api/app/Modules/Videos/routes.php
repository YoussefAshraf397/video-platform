<?php

use App\Modules\Videos\Http\Controllers\CategoryController;
use App\Modules\Videos\Http\Controllers\PublicVideosController;
use App\Modules\Videos\Http\Controllers\SubtitleController;
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

        // Subtitle tracks (S4-06): POST, PUT the file to the returned URL, then :complete.
        Route::get('videos/{video}/subtitles', [SubtitleController::class, 'index']);
        Route::post('videos/{video}/subtitles', [SubtitleController::class, 'store'])->middleware('idempotent');
        Route::post('videos/{video}/subtitles/{subtitle}:complete', [SubtitleController::class, 'complete'])->whereUuid('subtitle');
        Route::delete('videos/{video}/subtitles/{subtitle}', [SubtitleController::class, 'destroy'])->whereUuid('subtitle');
    });

    // Signed in or not: owners see their own drafts, everyone else only what's visible to the public.
    Route::get('videos/{video}', [VideoController::class, 'show']);
    Route::get('videos', [PublicVideosController::class, 'latest']);
    Route::get('users/{handle}/videos', [PublicVideosController::class, 'byCreator']);
    Route::get('categories', [CategoryController::class, 'index']);
});
