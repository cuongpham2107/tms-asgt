<?php

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripDriverAssignment;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TripKmCalculatorService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Gửi 1 lô điểm GPS "đang di chuyển" của một lái xe qua đúng API thật
 * (/api/driver/gps-points) — như điện thoại gửi lên, nhưng không cần điện thoại.
 * Xe chạy đều về phía bắc 1 km mỗi phút.
 */
function postDriveSegment(User $driver, Vehicle $vehicle, CarbonImmutable $t0, int $fromMinute, int $toMinute): void
{
    $points = [];
    $seq = 1;
    for ($s = $fromMinute * 60; $s <= $toMinute * 60; $s += 5) {
        $points[] = [
            'seq' => $seq++,
            'recorded_at' => $t0->addSeconds($s)->toIso8601String(),
            'lat' => 10.0 + ($s / 60 * 1000) / 111195.0,
            'lng' => 106.0,
            'speed' => 60,
            'accuracy' => 8,
            'mocked' => false,
        ];
    }

    Sanctum::actingAs($driver);
    test()->postJson('/api/driver/gps-points', [
        'device_id' => 'dev-'.$driver->id,
        'vehicle_id' => $vehicle->id,
        'points' => $points,
    ])->assertSuccessful();
}

test('GPS gửi qua API (không cần điện thoại thật) chia đúng km từng lượt lái khi đảo lái', function () {
    $t0 = CarbonImmutable::parse('2026-10-05 08:00:00');

    Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    $driverA = User::factory()->create();
    $driverB = User::factory()->create();
    $driverA->assignRole('driver');
    $driverB->assignRole('driver');

    $vehicle = Vehicle::factory()->create();
    $area = Area::create(['type' => OrderType::Hhhk, 'code' => 'E2E', 'name' => 'E2E Area']);
    $customer = Customer::create(['code' => 'CUST-E2E', 'name' => 'E2E Customer', 'is_active' => true]);

    $trip = Trip::factory()->create([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driverA->id,
        'status' => TripStatus::Started,
        'started_at' => $t0,
    ]);

    $order = Order::create([
        'order_code' => 'ORD-E2E',
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'trip_id' => $trip->id,
        'pickup_address' => 'Pickup',
        'status' => OrderStatus::Completed,
        'created_by' => $driverA->id,
    ]);
    // Có hàng từ phút 10 (đến lấy hàng) tới phút 40 (hoàn thành).
    TripCheckpoint::create(['trip_id' => $trip->id, 'order_id' => $order->id, 'checkpoint_type' => CheckpointType::ArrivedPickup->value, 'occurred_at' => $t0->addMinutes(10)]);
    TripCheckpoint::create(['trip_id' => $trip->id, 'order_id' => $order->id, 'checkpoint_type' => CheckpointType::Completed->value, 'occurred_at' => $t0->addMinutes(40)]);

    // Lượt A: 0–25', đảo lái; lượt B: 25–50'.
    TripDriverAssignment::create(['trip_id' => $trip->id, 'driver_id' => $driverA->id, 'started_at' => $t0, 'ended_at' => $t0->addMinutes(25)]);
    TripDriverAssignment::create(['trip_id' => $trip->id, 'driver_id' => $driverB->id, 'started_at' => $t0->addMinutes(25), 'ended_at' => $t0->addMinutes(50)]);

    // Lái A đang giữ xe -> gửi GPS đoạn của mình.
    $vehicle->update(['current_driver_id' => $driverA->id]);
    postDriveSegment($driverA, $vehicle, $t0, 0, 25);

    // Đảo lái: B giữ xe -> gửi tiếp GPS đoạn của B.
    $trip->update(['driver_id' => $driverB->id]);
    $vehicle->update(['current_driver_id' => $driverB->id]);
    postDriveSegment($driverB, $vehicle, $t0, 25, 50);

    // Chốt chuyến & tính km như khi hoàn thành.
    $trip->update(['status' => TripStatus::Completed, 'completed_at' => $t0->addMinutes(50)]);
    app(TripKmCalculatorService::class)->calculate($trip);

    $a = TripDriverAssignment::where('trip_id', $trip->id)->where('driver_id', $driverA->id)->first();
    $b = TripDriverAssignment::where('trip_id', $trip->id)->where('driver_id', $driverB->id)->first();

    // A: 25 km (rỗng 10' tới kho + có hàng 15'); B: 25 km (có hàng 15' + rỗng 10').
    expect((float) $a->km)->toEqualWithDelta(25, 1.0)
        ->and((float) $a->km_loaded)->toEqualWithDelta(15, 1.0)
        ->and((float) $b->km)->toEqualWithDelta(25, 1.0)
        ->and((float) $b->km_loaded)->toEqualWithDelta(15, 1.0)
        ->and((float) $trip->fresh()->total_km)->toEqualWithDelta(50, 1.5);
});
