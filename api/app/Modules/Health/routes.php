<?php

use App\Modules\Health\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Registered without middleware groups: probes must not touch sessions, auth, or the database
// (except the readiness checks themselves).
Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);
