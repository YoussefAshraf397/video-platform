<?php

use App\Modules\Processing\Http\Controllers\AdminJobsController;
use App\Modules\Processing\Http\Controllers\ProcessingStatusController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:api'])->prefix('v1')->group(function () {
    Route::get('videos/{video}/processing', [ProcessingStatusController::class, 'show']);
});

// Admin API (design doc §22: separate prefix and policy). In AWS it is also only reachable from the VPN/SSO path.
Route::middleware(['api', 'auth:api', 'admin', 'throttle:admin'])->prefix('admin/v1')->group(function () {
    Route::get('processing/jobs', [AdminJobsController::class, 'index']);
    Route::post('processing/jobs/{job}:retry', [AdminJobsController::class, 'retry'])
        ->where('job', '[0-9a-f-]{36}')->middleware('idempotent');
});
