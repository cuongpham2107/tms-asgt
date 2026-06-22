<?php

use App\Http\Controllers\MapboxController;
use App\Services\EupGpsService;
use Illuminate\Support\Facades\Route;

// Mapbox helper for map matching (server-side)
Route::post('/mapbox/match', [MapboxController::class, 'match']);

// Đồng bộ GPS từ API EUP
Route::get('/gps-sync', function (EupGpsService $service) {
    return response()->json($service->sync());
})->name('gps.sync');
