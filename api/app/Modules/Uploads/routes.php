<?php

use App\Modules\Uploads\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:api'])->prefix('v1')->group(function () {
    Route::post('videos/{video}/uploads', [UploadController::class, 'store'])->middleware('idempotent');
    Route::post('uploads/{upload}/parts:sign', [UploadController::class, 'sign']);
    Route::get('uploads/{upload}', [UploadController::class, 'show']);
    Route::delete('uploads/{upload}', [UploadController::class, 'destroy']);
});
