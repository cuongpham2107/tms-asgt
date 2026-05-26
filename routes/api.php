<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DriverShiftController;
use App\Http\Controllers\Api\DriverSwapController;
use App\Http\Controllers\Api\EmptyKilometerController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\RouteController;
use App\Http\Controllers\Api\ShiftStatusController;
use App\Http\Controllers\Api\TripCheckpointController;
use App\Http\Controllers\Api\VehicleSearchController;
use App\Http\Middleware\EnsureRoleVehicle;
use Illuminate\Support\Facades\Route;

Route::post('/driver/login', [AuthController::class, 'login']);

// OSRM routing – dùng chung cho admin backend (Filament map) và mobile app
Route::post('/route', [RouteController::class, 'route']);

Route::middleware(['auth:sanctum', EnsureRoleVehicle::class])->prefix('driver')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // Shift status (trạng thái ca hiện tại + km gần nhất)
    Route::get('/shifts/active', [ShiftStatusController::class, 'active']);

    // Vehicle search (tìm xe theo 4 số cuối biển số)
    Route::get('/vehicles/search', [VehicleSearchController::class, 'search']);
    // Danh sách xe đang rảnh (chưa có lái)
    Route::get('/vehicles/available', [VehicleSearchController::class, 'available']);

    // Orders (danh sách đơn hàng được gán)
    Route::get('/orders/stats', [OrderController::class, 'stats']);
    Route::get('/orders/history', [OrderController::class, 'history']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::get('/orders/{order}/delivery-points', [OrderController::class, 'deliveryPoints']);

    // Shifts
    Route::post('/shifts/start', [DriverShiftController::class, 'start']);
    Route::post('/shifts/end', [DriverShiftController::class, 'end']);
    Route::get('/shifts/current', [DriverShiftController::class, 'current']);

    // Trip checkpoints (single endpoint for different types)
    Route::post('/checkpoints', [TripCheckpointController::class, 'checkpoint']);

    // Driver swap (đảo lái)
    Route::post('/driver-swap', [DriverSwapController::class, 'store']);

    // Empty kilometers (ghi nhận km không hàng)
    Route::post('/empty-kilometers', [EmptyKilometerController::class, 'store']);
});
