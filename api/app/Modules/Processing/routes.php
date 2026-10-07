<?php

use App\Modules\Processing\Http\Controllers\ProcessingStatusController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:api'])->prefix('v1')->group(function () {
    Route::get('videos/{video}/processing', [ProcessingStatusController::class, 'show']);
});
