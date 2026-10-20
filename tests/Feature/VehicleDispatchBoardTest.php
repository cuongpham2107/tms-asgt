<?php

use App\Enums\CheckpointType;
use App\Enums\DriverWorkShift;
use App\Enums\OnDutyLocation;
use App\Enums\TripKmReportStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripKmReport;
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

test('vehicle dispatch board allows clicking trip status when driver swap is active', function () {
    $driver = User::factory()->create(['name' => 'Tài xế Cũ']);
    $vehicle = Vehicle::factory()->create([
        'plate_number' => '20C-SWAP1',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driver->id,
        'is_active' => true,
    ]);

    $trip = Trip::factory()->create([
        'trip_code' => 'TRIP-SWAP-001',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::DriverSwap,
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => CheckpointType::DriverSwap,
        'km_reading' => 12500,
        'occurred_at' => now(),
    ]);

    $vehicle->load('trips');

    expect(VehicleDispatchBoard::canClickTripStatus($vehicle))->toBeTrue()
        ->and(VehicleDispatchBoard::getTripStatusLabel($vehicle))->toBe('Đảo lái');

    Livewire::test(VehicleDispatchBoard::class)
        ->assertStatus(200)
        ->assertSee('mountTableAction(\'reassign_driver\', \''.$vehicle->id.'\')', false)
        ->assertSee('Đảo lái')
        ->mountTableAction('reassign_driver', $vehicle)
        ->assertHasNoTableActionErrors();
});

test('vehicle dispatch board allows clicking trip status when trip is paused due to km report', function () {
    $driver = User::factory()->create(['name' => 'Tài xế Báo Sai']);
    $vehicle = Vehicle::factory()->create([
        'plate_number' => '99C-PAUSE1',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driver->id,
        'is_active' => true,
    ]);

    $trip = Trip::factory()->create([
        'trip_code' => 'TRIP-PAUSE-001',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Started,
        'is_paused' => true,
    ]);

    $report = TripKmReport::create([
        'trip_id' => $trip->id,
        'driver_id' => $driver->id,
        'reported_km' => 54321,
        'system_km' => 54000,
        'status' => TripKmReportStatus::Pending,
        'note' => 'Taplo hiển thị khác',
    ]);

    $vehicle->load('trips.latestPendingKmReport');

    expect(VehicleDispatchBoard::canClickTripStatus($vehicle))->toBeTrue()
        ->and(VehicleDispatchBoard::getTripStatusLabel($vehicle))->toBe('Tạm dừng (Sửa Km)');

    Livewire::test(VehicleDispatchBoard::class)
        ->assertStatus(200)
        ->assertSee('mountTableAction(\'resolve_km_report\', \''.$vehicle->id.'\')', false)
        ->assertSee('Tạm dừng (Sửa Km)')
        ->mountTableAction('resolve_km_report', $vehicle)
        ->assertHasNoTableActionErrors();
});

test('vehicle dispatch board does not make status clickable for normal trips or vehicles without trip', function () {
    $vehicleWithNormalTrip = Vehicle::factory()->create([
        'plate_number' => '20C-NORMAL',
        'type' => VehicleOwnerType::Company,
        'is_active' => true,
    ]);

    Trip::factory()->create([
        'vehicle_id' => $vehicleWithNormalTrip->id,
        'status' => TripStatus::Delivering,
    ]);

    $vehicleWithoutTrip = Vehicle::factory()->create([
        'plate_number' => '20C-NOTRIP',
        'type' => VehicleOwnerType::Company,
        'status' => VehicleStatus::On,
        'is_active' => true,
    ]);

    $vehicleWithNormalTrip->load('trips');
    $vehicleWithoutTrip->load('trips');

    expect(VehicleDispatchBoard::canClickTripStatus($vehicleWithNormalTrip))->toBeFalse()
        ->and(VehicleDispatchBoard::canClickTripStatus($vehicleWithoutTrip))->toBeFalse();

    Livewire::test(VehicleDispatchBoard::class)
        ->assertStatus(200)
        ->assertDontSee('mountTableAction(\'reassign_driver\', \''.$vehicleWithNormalTrip->id.'\')', false)
        ->assertDontSee('mountTableAction(\'resolve_km_report\', \''.$vehicleWithNormalTrip->id.'\')', false)
        ->assertDontSee('mountTableAction(\'reassign_driver\', \''.$vehicleWithoutTrip->id.'\')', false)
        ->assertDontSee('mountTableAction(\'resolve_km_report\', \''.$vehicleWithoutTrip->id.'\')', false);
});

test('vehicle dispatch board prioritizes resolve_km_report when a driver swap trip is paused', function () {
    $driver = User::factory()->create(['name' => 'Tài xế Đảo Lái']);
    $vehicle = Vehicle::factory()->create([
        'plate_number' => '20C-SWAPPAUSE',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driver->id,
        'is_active' => true,
    ]);

    $trip = Trip::factory()->create([
        'trip_code' => 'TRIP-SP-001',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::DriverSwap,
        'is_paused' => true,
    ]);

    $vehicle->load('trips');

    expect(VehicleDispatchBoard::getTripStatusLabel($vehicle))->toBe('Tạm dừng (Sửa Km)');

    Livewire::test(VehicleDispatchBoard::class)
        ->assertStatus(200)
        ->assertSee('mountTableAction(\'resolve_km_report\', \''.$vehicle->id.'\')', false)
        ->assertDontSee('mountTableAction(\'reassign_driver\', \''.$vehicle->id.'\')', false)
        ->mountTableAction('resolve_km_report', $vehicle)
        ->assertHasNoTableActionErrors();
});

test('vehicle dispatch board shows list of all trips in the day when clicking vehicle plate', function () {
    $driver = User::factory()->create(['name' => 'Lái Xe Ca Sáng']);
    $vehicle = Vehicle::factory()->create([
        'plate_number' => '29H-TRIPS-TODAY',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driver->id,
        'is_active' => true,
    ]);

    // Trip 1: Morning trip, completed
    $trip1 = Trip::factory()->create([
        'trip_code' => 'TRIP-TODAY-001',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->startOfDay()->addHours(7),
        'completed_at' => now()->startOfDay()->addHours(10),
        'start_km' => 1000,
        'end_km' => 1050,
        'total_km' => 50,
    ]);

    // Trip 2: Afternoon trip, delivering
    $trip2 = Trip::factory()->create([
        'trip_code' => 'TRIP-TODAY-002',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Delivering,
        'started_at' => now()->startOfDay()->addHours(13),
        'start_km' => 1050,
    ]);

    // Trip 3: Yesterday trip, should NOT be in today's list
    $yesterdayTrip = Trip::factory()->create([
        'trip_code' => 'TRIP-YESTERDAY-999',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subDays(2)->setTime(8, 0),
        'completed_at' => now()->subDays(2)->setTime(11, 0),
        'created_at' => now()->subDays(2),
    ]);

    $page = new VehicleDispatchBoard;
    $todayTrips = $page->getTodayTripsForVehicle($vehicle);

    expect($todayTrips)->toHaveCount(2)
        ->and($todayTrips->first()->trip_code)->toBe('TRIP-TODAY-001')
        ->and($todayTrips->last()->trip_code)->toBe('TRIP-TODAY-002')
        ->and(VehicleDispatchBoard::getTodayTripsCount($vehicle))->toBe(2);

    Livewire::test(VehicleDispatchBoard::class)
        ->call('openTripModal', $vehicle->id)
        ->assertDispatched('open-modal', id: 'vehicle-trip-modal')
        ->assertSee('TRIP-TODAY-001')
        ->assertSee('TRIP-TODAY-002')
        ->assertDontSee('TRIP-YESTERDAY-999')
        ->assertSee('Chuyến 1')
        ->assertSee('Chuyến 2')
        ->assertSee('Tổng: 2 chuyến');
});

test('vehicle dispatch board shows friendly empty state when vehicle has no trips today', function () {
    $vehicle = Vehicle::factory()->create([
        'plate_number' => '29H-NO-TRIP',
        'type' => VehicleOwnerType::Company,
        'is_active' => true,
    ]);

    $page = new VehicleDispatchBoard;
    $todayTrips = $page->getTodayTripsForVehicle($vehicle);

    expect($todayTrips)->toHaveCount(0);

    Livewire::test(VehicleDispatchBoard::class)
        ->call('openTripModal', $vehicle->id)
        ->assertDispatched('open-modal', id: 'vehicle-trip-modal')
        ->assertSee('Không có chuyến xe nào trong ngày hôm nay');
});
