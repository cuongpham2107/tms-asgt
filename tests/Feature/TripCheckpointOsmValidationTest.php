<?php

use App\Enums\CheckpointType;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Services\OsrmService;
use App\Services\Trip\TripCheckpointOsmValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('validateSegment returns unknown when km reading is missing', function () {
    $service = app(TripCheckpointOsmValidationService::class);

    $prev = new TripCheckpoint([
        'id' => 1,
        'km_reading' => null,
        'checkpoint_type' => CheckpointType::Started,
    ]);
    $curr = new TripCheckpoint([
        'id' => 2,
        'km_reading' => 100.0,
        'checkpoint_type' => CheckpointType::ArrivedPickup,
    ]);

    $result = $service->validateSegment($prev, $curr);

    expect($result['status'])->toBe(TripCheckpointOsmValidationService::STATUS_UNKNOWN);
});

test('validateSegment flags danger when km reading decreases', function () {
    $service = app(TripCheckpointOsmValidationService::class);

    $prev = new TripCheckpoint([
        'id' => 1,
        'km_reading' => 100.0,
        'checkpoint_type' => CheckpointType::Started,
    ]);
    $curr = new TripCheckpoint([
        'id' => 2,
        'km_reading' => 95.0,
        'checkpoint_type' => CheckpointType::ArrivedPickup,
    ]);

    $result = $service->validateSegment($prev, $curr);

    expect($result['status'])->toBe(TripCheckpointOsmValidationService::STATUS_DANGER)
        ->and($result['driver_delta_km'])->toBe(-5.0);
});

test('validateSegment returns no_gps when GPS coordinates are missing', function () {
    $service = app(TripCheckpointOsmValidationService::class);

    $prev = new TripCheckpoint([
        'id' => 1,
        'km_reading' => 100.0,
        'gps_lat' => null,
        'gps_lng' => null,
        'checkpoint_type' => CheckpointType::Started,
    ]);
    $curr = new TripCheckpoint([
        'id' => 2,
        'km_reading' => 120.0,
        'gps_lat' => null,
        'gps_lng' => null,
        'checkpoint_type' => CheckpointType::ArrivedPickup,
    ]);

    $result = $service->validateSegment($prev, $curr);

    expect($result['status'])->toBe(TripCheckpointOsmValidationService::STATUS_NO_GPS)
        ->and($result['driver_delta_km'])->toBe(20.0)
        ->and($result['has_gps'])->toBeFalse();
});

test('validateSegment returns valid for same location when delta km is small', function () {
    $service = app(TripCheckpointOsmValidationService::class);

    $prev = new TripCheckpoint([
        'id' => 1,
        'km_reading' => 100.0,
        'gps_lat' => 21.0285,
        'gps_lng' => 105.8542,
        'checkpoint_type' => CheckpointType::ArrivedPickup,
    ]);
    $curr = new TripCheckpoint([
        'id' => 2,
        'km_reading' => 100.0,
        'gps_lat' => 21.02851,
        'gps_lng' => 105.85421,
        'checkpoint_type' => CheckpointType::LeftPickup,
    ]);

    $result = $service->validateSegment($prev, $curr);

    expect($result['status'])->toBe(TripCheckpointOsmValidationService::STATUS_VALID)
        ->and($result['driver_delta_km'])->toBe(0.0)
        ->and($result['osm_km'])->toBe(0.0);
});

test('validateSegment flags danger when vehicle does not move but delta km is large', function () {
    $service = app(TripCheckpointOsmValidationService::class);

    $prev = new TripCheckpoint([
        'id' => 1,
        'km_reading' => 100.0,
        'gps_lat' => 21.0285,
        'gps_lng' => 105.8542,
        'checkpoint_type' => CheckpointType::ArrivedPickup,
    ]);
    $curr = new TripCheckpoint([
        'id' => 2,
        'km_reading' => 150.0, // +50km at same location
        'gps_lat' => 21.02851,
        'gps_lng' => 105.85421,
        'checkpoint_type' => CheckpointType::LeftPickup,
    ]);

    $result = $service->validateSegment($prev, $curr);

    expect($result['status'])->toBe(TripCheckpointOsmValidationService::STATUS_DANGER)
        ->and($result['driver_delta_km'])->toBe(50.0);
});

test('validateSegment returns valid when driver km matches OSRM route distance', function () {
    $osrmMock = Mockery::mock(OsrmService::class);
    $osrmMock->shouldReceive('getRoute')
        ->with(21.0285, 105.8542, 21.1861, 106.0763)
        ->andReturn([
            'success' => true,
            'data' => [
                'distance' => 31200, // 31.2 km
            ],
        ]);

    $service = new TripCheckpointOsmValidationService($osrmMock);

    $prev = new TripCheckpoint([
        'id' => 1,
        'km_reading' => 100.0,
        'gps_lat' => 21.0285,
        'gps_lng' => 105.8542,
        'checkpoint_type' => CheckpointType::Started,
    ]);
    $curr = new TripCheckpoint([
        'id' => 2,
        'km_reading' => 132.0, // 32.0 km vs 31.2 km (diff = 0.8 km -> valid)
        'gps_lat' => 21.1861,
        'gps_lng' => 106.0763,
        'checkpoint_type' => CheckpointType::ArrivedDelivery,
    ]);

    $result = $service->validateSegment($prev, $curr);

    expect($result['status'])->toBe(TripCheckpointOsmValidationService::STATUS_VALID)
        ->and($result['osm_km'])->toBe(31.2)
        ->and($result['driver_delta_km'])->toBe(32.0)
        ->and($result['diff_km'])->toBe(0.8);
});

test('validateSegment returns warning when driver km deviates moderately from OSRM', function () {
    $osrmMock = Mockery::mock(OsrmService::class);
    $osrmMock->shouldReceive('getRoute')
        ->andReturn([
            'success' => true,
            'data' => [
                'distance' => 20000, // 20.0 km
            ],
        ]);

    $service = new TripCheckpointOsmValidationService($osrmMock);

    $prev = new TripCheckpoint([
        'id' => 1,
        'km_reading' => 100.0,
        'gps_lat' => 21.0285,
        'gps_lng' => 105.8542,
        'checkpoint_type' => CheckpointType::Started,
    ]);
    $curr = new TripCheckpoint([
        'id' => 2,
        'km_reading' => 125.0, // 25.0 km vs 20.0 km (+5km, +25% -> warning)
        'gps_lat' => 21.2000,
        'gps_lng' => 105.9000,
        'checkpoint_type' => CheckpointType::ArrivedDelivery,
    ]);

    $result = $service->validateSegment($prev, $curr);

    expect($result['status'])->toBe(TripCheckpointOsmValidationService::STATUS_WARNING)
        ->and($result['osm_km'])->toBe(20.0)
        ->and($result['driver_delta_km'])->toBe(25.0)
        ->and($result['diff_percent'])->toBe(25.0);
});

test('validateTrip aggregates segment statuses correctly for a trip', function () {
    $trip = Trip::factory()->create();

    // Checkpoint 1
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => CheckpointType::Started,
        'km_reading' => 1000.0,
        'gps_lat' => 21.0285,
        'gps_lng' => 105.8542,
        'occurred_at' => now()->subMinutes(60),
    ]);

    // Checkpoint 2 (same spot)
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup,
        'km_reading' => 1000.0,
        'gps_lat' => 21.02851,
        'gps_lng' => 105.85421,
        'occurred_at' => now()->subMinutes(50),
    ]);

    $service = app(TripCheckpointOsmValidationService::class);
    $validation = $service->validateTrip($trip);

    expect($validation['overall_status'])->toBe(TripCheckpointOsmValidationService::STATUS_VALID)
        ->and($validation['valid_count'])->toBe(1)
        ->and($validation['warning_count'])->toBe(0)
        ->and($validation['danger_count'])->toBe(0);
});
