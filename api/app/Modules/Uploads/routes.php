<?php

use App\Modules\Uploads\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:api'])->prefix('v1')->group(function () {
    Route::pattern('upload', '[^/:]+');   // so "{upload}:complete" splits at the colon

    Route::post('videos/{video}/uploads', [UploadController::class, 'store'])->middleware(['throttle:uploads-create', 'idempotent']);
    Route::post('uploads/{upload}/parts:sign', [UploadController::class, 'sign'])->middleware('throttle:uploads-sign');
    Route::post('uploads/{upload}:complete', [UploadController::class, 'complete'])->middleware('idempotent');
    Route::get('uploads/{upload}', [UploadController::class, 'show']);
    Route::delete('uploads/{upload}', [UploadController::class, 'destroy']);
});
