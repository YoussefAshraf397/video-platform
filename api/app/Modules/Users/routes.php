<?php

use App\Modules\Users\Http\Controllers\MeController;
use App\Modules\Users\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('v1')->group(function () {
    Route::middleware('auth:api')->group(function () {
        Route::get('me', [MeController::class, 'show']);
        Route::patch('me', [MeController::class, 'update']);
    });

    Route::get('users/{handle}', [ProfileController::class, 'show'])->where('handle', '[A-Za-z0-9_.]{1,30}');
});
