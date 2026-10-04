<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\SessionsController;
use App\Modules\Auth\Http\Middleware\RequireAllowedOrigin;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('v1/auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('email/verify', [AuthController::class, 'verifyEmail']);
    Route::post('email/resend', [AuthController::class, 'resendVerification']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware(RequireAllowedOrigin::class);

    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('logout-all', [AuthController::class, 'logoutAll']);
        Route::get('sessions', [SessionsController::class, 'index']);
        Route::delete('sessions/{session}', [SessionsController::class, 'destroy'])->whereUuid('session');
    });
});
