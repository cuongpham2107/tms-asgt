<?php

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripDriverAssignment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGpsPoint;
use App\Services\OsrmService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('trip:mock-gps gán điểm cho đúng tài xế đang giữ chuyến theo từng lượt lái', function () {
    // OSRM offline -> đi theo đường thẳng, không phụ thuộc mạng.
    $this->mock(OsrmService::class, fn ($m) => $m->shouldReceive('getRoute')->andReturn(['success' => false]));

    $t0 = CarbonImmutable::parse('2026-10-05 08:00:00');
    $vehicle = Vehicle::factory()->create();
    $driverA = User::factory()->create();
    $driverB = User::factory()->create();

    $trip = Trip::factory()->create([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driverB->id, // tài xế "chính" là người cuối; trước khi fix mọi điểm sẽ dồn hết về đây
        'status' => TripStatus::Completed,
        'started_at' => $t0,
        'completed_at' => $t0->addMinutes(30),
    ]);

    // A giữ chuyến 2 phút đầu, B giữ phần còn lại.
    TripDriverAssignment::create(['trip_id' => $trip->id, 'driver_id' => $driverA->id, 'started_at' => $t0, 'ended_at' => $t0->addMinutes(2)]);
    TripDriverAssignment::create(['trip_id' => $trip->id, 'driver_id' => $driverB->id, 'started_at' => $t0->addMinutes(2), 'ended_at' => $t0->addMinutes(30)]);

    // 3 waypoint có toạ độ (khác nhau) để lệnh dựng được lộ trình nhiều chặng.
    foreach ([
        [CheckpointType::Started, 0, 21.00, 105.80],
        [CheckpointType::ArrivedPickup, 5, 21.01, 105.81],
        [CheckpointType::Completed, 10, 21.06, 105.86],
    ] as [$type, $minute, $lat, $lng]) {
        TripCheckpoint::create([
            'trip_id' => $trip->id,
            'checkpoint_type' => $type->value,
            'occurred_at' => $t0->addMinutes($minute),
            'gps_lat' => $lat,
            'gps_lng' => $lng,
        ]);
    }

    $this->artisan('trip:mock-gps', ['trip' => $trip->id, '--sync-checkpoints' => '0', '--clean' => true])
        ->assertSuccessful();

    // Trước khi fix: driver A không có điểm nào (tất cả về driver_id của trip = B).
    expect(VehicleGpsPoint::where('driver_id', $driverA->id)->exists())->toBeTrue()
        ->and(VehicleGpsPoint::where('driver_id', $driverB->id)->exists())->toBeTrue();
});
