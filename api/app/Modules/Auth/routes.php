<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\SessionsController;
use App\Modules\Auth\Http\Middleware\RequireAllowedOrigin;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('v1/auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
    Route::post('email/verify', [AuthController::class, 'verifyEmail']);
    Route::post('email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:auth-email');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth-email');
    Route::post('password/reset', [AuthController::class, 'resetPassword']);
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware(RequireAllowedOrigin::class);

    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('logout-all', [AuthController::class, 'logoutAll']);
        Route::get('sessions', [SessionsController::class, 'index']);
        Route::delete('sessions/{session}', [SessionsController::class, 'destroy'])->whereUuid('session');
    });
});
