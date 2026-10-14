<?php

use App\Modules\Playback\Http\Controllers\PlaybackController;
use Illuminate\Support\Facades\Route;

// Signed in or not: anonymous viewers can play public and unlisted videos.
Route::middleware('api')->prefix('v1')->group(function () {
    Route::get('videos/{video}/playback', [PlaybackController::class, 'show'])->where('video', '[A-Za-z0-9_-]{11}')
        ->middleware('throttle:playback');
});
