<?php

use App\Http\Controllers\MapboxController;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Services\EupGpsService;
use Illuminate\Support\Facades\Route;

// Mapbox helper for map matching (server-side)
Route::post('/mapbox/match', [MapboxController::class, 'match']);

// Đồng bộ GPS từ API EUP
Route::get('/gps-sync', function (EupGpsService $service) {
    return response()->json($service->sync());
})->name('gps.sync');

// Mobile SPA — serve Expo web build
Route::get('/mobile/{any?}', function () {
    return response()->file(public_path('mobile/index.html'));
})->where('any', '.*');

// Bulk update trip checkpoints (Filament admin)
Route::post('/trips/{trip}/checkpoints/bulk-update', function (Trip $trip) {
    $ids = request()->input('ids', []);
    $km = request()->input('km_reading');
    $occurredAt = request()->input('occurred_at');

    if (empty($ids)) {
        return response()->json(['ok' => false], 422);
    }

    $data = [];
    if ($km !== null && $km !== '') {
        $data['km_reading'] = (float) $km;
    }
    if ($occurredAt !== null && $occurredAt !== '') {
        $data['occurred_at'] = $occurredAt;
    }

    if (empty($data)) {
        return response()->json(['ok' => true]);
    }

    TripCheckpoint::whereIn('id', $ids)->update($data);

    return response()->json(['ok' => true]);
})->middleware('auth')->name('checkpoints.bulk-update');
