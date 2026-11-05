<?php

use App\Modules\Playback\Http\Controllers\PlaybackController;
use App\Modules\Playback\Http\Controllers\TestPlayerController;
use Illuminate\Support\Facades\Route;

// Signed in or not: anonymous viewers can play public and unlisted videos.
Route::middleware('api')->prefix('v1')->group(function () {
    Route::get('videos/{video}/playback', [PlaybackController::class, 'show'])->where('video', '[A-Za-z0-9_-]{11}')
        ->middleware('throttle:playback');
});

// Internal test player (S4-07): 404 unless PLAYBACK_TEST_PLAYER is on. No session: the page signs in
// through the API like any client.
Route::get('dev/player', TestPlayerController::class);
