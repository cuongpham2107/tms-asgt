<?php

use App\Enums\DriverWorkShift;
use App\Enums\OnDutyLocation;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $admin = User::factory()->create();
    $this->actingAs($admin);
});

test('vehicle dispatch board page renders successfully with company vehicles', function () {
    $companyVehicle = Vehicle::factory()->create([
        'plate_number' => '20C-12345',
        'type' => VehicleOwnerType::Company,
        'status' => VehicleStatus::On,
        'gps_speed' => 45,
        'is_active' => true,
    ]);

    $rentVehicle = Vehicle::factory()->create([
        'plate_number' => '29H-99999',
        'type' => VehicleOwnerType::Rent,
        'status' => VehicleStatus::On,
        'gps_speed' => 0,
        'last_gps_update' => now(),
        'is_active' => true,
    ]);

    Livewire::test(VehicleDispatchBoard::class)
        ->assertStatus(200)
        ->assertSee('KIỂM SOÁT TÌNH TRẠNG XE')
        ->assertSee('20C-12345')
        ->assertDontSee('29H-99999')
        ->assertSee('CH')
        ->assertDontSee('KH')
        ->assertHasNoErrors();
});

test('vehicle dispatch board summary stats correctly count vehicles', function () {
    // 1 running vehicle
    Vehicle::factory()->create([
        'type' => VehicleOwnerType::Company,
        'gps_speed' => 50,
        'status' => VehicleStatus::On,
        'is_active' => true,
    ]);

    // 1 idling vehicle (speed <= 5, recent GPS update)
    Vehicle::factory()->create([
        'type' => VehicleOwnerType::Rent,
        'gps_speed' => 0,
        'last_gps_update' => now()->subMinutes(2),
        'status' => VehicleStatus::On,
        'is_active' => true,
    ]);

    // 1 stopped vehicle (status off)
    Vehicle::factory()->create([
        'type' => VehicleOwnerType::Company,
        'gps_speed' => 0,
        'status' => VehicleStatus::Off,
        'is_active' => true,
    ]);

    $component = Livewire::test(VehicleDispatchBoard::class);
    $stats = $component->instance()->getSummaryStats();

    expect($stats['total'])->toBe(3)
        ->and($stats['running'])->toBe(1)
        ->and($stats['idling'])->toBe(1)
        ->and($stats['stopped'])->toBe(1);
});

test('vehicle dispatch board only shows company vehicles', function () {
    Vehicle::factory()->create([
        'plate_number' => '20C-COMPANY',
        'type' => VehicleOwnerType::Company,
        'is_active' => true,
    ]);

    Vehicle::factory()->create([
        'plate_number' => '29H-RENT',
        'type' => VehicleOwnerType::Rent,
        'is_active' => true,
    ]);

    Livewire::test(VehicleDispatchBoard::class)
        ->assertSee('20C-COMPANY')
        ->assertDontSee('29H-RENT');
});

test('vehicle helpers format BSX, status and driver information correctly', function () {
    $driver = User::factory()->create([
        'name' => 'Nguyễn Văn Test',
        'station' => OnDutyLocation::Bn,
        'work_shift' => DriverWorkShift::Even,
    ]);

    $vehicle = Vehicle::factory()->create([
        'plate_number' => '99H-00902',
        'load_capacity' => 7.0,
        'door_count' => 3,
        'current_driver_id' => $driver->id,
        'gps_speed' => 56,
        'type' => VehicleOwnerType::Company,
    ]);

    $bsx = VehicleDispatchBoard::renderBsx($vehicle);
    expect($bsx)->toContain('99H-00902')
        ->and($bsx)->toContain('7T')
        ->and($bsx)->not->toContain('3C');

    expect(VehicleDispatchBoard::formatShortAddress('ALS Bắc Ninh, KCN Yên Phong, đường YP6, Yên Phong, Bắc Ninh, Việt Nam'))
        ->toBe('Yên Phong, Bắc Ninh');

    expect(VehicleDispatchBoard::getDriverName($vehicle))->toBe('Nguyễn Văn Test')
        ->and(VehicleDispatchBoard::getDriverStation($vehicle))->toBe('BN')
        ->and(VehicleDispatchBoard::getMovementStatus($vehicle))->toBe('Đang chạy')
        ->and(VehicleDispatchBoard::getSpeed($vehicle))->toBe('56 km/h');

    // Test with active trip
    $trip = Trip::factory()->create([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Delivering,
    ]);

    // Reload vehicle relations
    $vehicle->load('trips');

    expect(VehicleDispatchBoard::getTripStatusLabel($vehicle))->toBe('Đang giao')
        ->and(VehicleDispatchBoard::getStatusBadgeColor($vehicle))->toBe('warning');

    // Test EUP movement statuses:
    $stoppedVehicle = Vehicle::factory()->create([
        'gps_speed' => 0,
        'car_status' => 2,
    ]);
    expect(VehicleDispatchBoard::getMovementStatus($stoppedVehicle))->toBe('Xe dừng');

    $idlingVehicle = Vehicle::factory()->create([
        'gps_speed' => 0,
        'car_status' => 0,
    ]);
    expect(VehicleDispatchBoard::getMovementStatus($idlingVehicle))->toBe('Ko tắt máy');

    $lostGpsVehicle = Vehicle::factory()->create([
        'gps_speed' => 0,
        'car_status' => 5,
    ]);
    expect(VehicleDispatchBoard::getMovementStatus($lostGpsVehicle))->toBe('Mất GPS');
});

test('syncEupGps handles corrupted cache gracefully without error', function () {
    // Put non-numeric corrupted data in cache
    cache()->put('last_eup_gps_sync_at', 'invalid-serialized-data', 60);

    $page = new VehicleDispatchBoard;
    // Should not throw any exception
    $page->syncEupGps();

    expect(true)->toBeTrue();
});
