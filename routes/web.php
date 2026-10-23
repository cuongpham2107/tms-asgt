<?php

use App\Http\Controllers\MapboxController;
use App\Http\Controllers\PublicTripTrackingController;
use App\Http\Controllers\TripPhotoDownloadController;
use App\Http\Middleware\ValidateTrackingSignature;
use Illuminate\Support\Facades\Route;

// Trang theo dõi lộ trình công khai cho khách hàng (URL có chữ ký + hạn dùng)
Route::middleware([ValidateTrackingSignature::class, 'throttle:120,1'])->prefix('theo-doi/{trip}')->group(function () {
    Route::get('/', [PublicTripTrackingController::class, 'show'])
        ->name('trips.public-tracking');

    Route::get('/vi-tri', [PublicTripTrackingController::class, 'position'])
        ->name('trips.public-tracking.position');
});

// Tải tất cả ảnh của chuyến đi dạng file zip
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/trips/{trip}/photos/download', [TripPhotoDownloadController::class, 'downloadAll'])
        ->name('trips.photos.download-zip');
});

// Mapbox helper for map matching (server-side)
Route::post('/mapbox/match', [MapboxController::class, 'match']);

// Chính sách quyền riêng tư (Privacy Policy) cho Apple App Store & Google Play Console
Route::get('/privacy-policy', function () {
    return view('privacy-policy');
})->name('privacy.policy');

// Mobile SPA — serve Expo web build
Route::get('/mobile/{any?}', function () {
    return response()->file(public_path('mobile/index.html'));
})->where('any', '.*');
