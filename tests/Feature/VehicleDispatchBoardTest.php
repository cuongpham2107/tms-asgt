<?php

use App\Enums\CheckpointType;
use App\Enums\DriverWorkShift;
use App\Enums\OnDutyLocation;
use App\Enums\OrderStatus;
use App\Enums\ShiftType;
use App\Enums\TripKmReportStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Location;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripKmReport;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\EupGpsService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    $vehicleWithNote = Vehicle::factory()->create([
        'plate_number' => '20C-04240',
        'load_capacity' => 5.0,
        'notes' => 'Xe chuyên tuyến SS',
        'type' => VehicleOwnerType::Company,
    ]);
    $bsxWithNote = VehicleDispatchBoard::renderBsx($vehicleWithNote);
    expect($bsxWithNote)->toContain('20C-04240')
        ->and($bsxWithNote)->toContain('5T')
        ->and($bsxWithNote)->toContain('(Xe chuyên tuyến SS)');

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
        ->and($todayTrips->first()->trip_code)->toBe('TRIP-TODAY-002')
        ->and($todayTrips->last()->trip_code)->toBe('TRIP-TODAY-001')
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

test('vehicle dispatch board reacts to echo TripUpdated event by refreshing table without notification', function () {
    $vehicle = Vehicle::factory()->create([
        'plate_number' => '20C-REALTIME',
        'type' => VehicleOwnerType::Company,
        'is_active' => true,
    ]);

    $trip = Trip::create([
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Pending,
        'trip_code' => 'TRIP-RT-DISPATCH',
    ]);

    Livewire::test(VehicleDispatchBoard::class)
        ->call('onTripUpdated', [
            'trip_id' => $trip->id,
            'action' => 'updated',
            'vehicle_plate' => '20C-REALTIME',
            'status' => TripStatus::Delivering->value,
        ])
        ->assertSuccessful()
        ->assertHasNoErrors();
});

test('vehicle active_trip and dispatch board prioritizes in-progress trip over next pending trip', function () {
    $vehicle = Vehicle::factory()->create([
        'plate_number' => '29C-PRIORITY',
        'type' => VehicleOwnerType::Company,
        'is_active' => true,
    ]);

    // 1. Chuyến 1: Đang giao (in-progress)
    $runningTrip = Trip::create([
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Delivering,
        'trip_code' => 'TRIP-RUNNING-01',
        'started_at' => now(),
    ]);

    // 2. Chuyến 2: Được tạo sau, trạng thái Chờ chạy (pending) có ID lớn hơn
    $pendingTrip = Trip::create([
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Pending,
        'trip_code' => 'TRIP-PENDING-02',
    ]);

    expect($pendingTrip->id)->toBeGreaterThan($runningTrip->id);

    // Khi load relation trips (như trên VehicleDispatchBoard)
    $vehicle->load('trips.checkpoints');
    expect($vehicle->active_trip?->id)->toBe($runningTrip->id)
        ->and(VehicleDispatchBoard::getTripStatusLabel($vehicle))->toBe('Đang giao');

    // Chuyển chuyến 1 sang Completed nhưng chưa có checkpoint end (chưa hạ xe/kết ca)
    $runningTrip->update(['status' => TripStatus::Completed]);
    $vehicle->load('trips.checkpoints');
    expect($vehicle->active_trip?->id)->toBe($runningTrip->id)
        ->and(VehicleDispatchBoard::getTripStatusLabel($vehicle))->toBe('Hoàn thành');

    // Khi chuyến 1 chính thức kết thúc (có mốc end)
    $runningTrip->complete(endKm: 15000);
    $vehicle->load('trips.checkpoints');
    expect($vehicle->active_trip?->id)->toBe($pendingTrip->id)
        ->and(VehicleDispatchBoard::getTripStatusLabel($vehicle))->toBe('Chờ chạy');

    // Test nhánh query trực tiếp khi relation trips chưa được eager load
    $freshVehicle = Vehicle::find($vehicle->id);
    expect($freshVehicle->active_trip?->id)->toBe($pendingTrip->id);
});

test('vehicle station resolves from driver station in users table', function () {
    // 1. Xe có tài xế trực tại NBA (Nội Bài)
    $driverNB = User::factory()->create([
        'name' => 'Tài xế Nội Bài',
        'station' => OnDutyLocation::Nba,
    ]);
    $vNB = Vehicle::factory()->create([
        'plate_number' => '20C-DRIVER-NB',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driverNB->id,
    ]);
    expect(VehicleDispatchBoard::getDriverStation($vNB))->toBe('NB');

    // 2. Xe có tài xế trực tại BN (Bắc Ninh)
    $driverBN = User::factory()->create([
        'name' => 'Tài xế Bắc Ninh',
        'station' => OnDutyLocation::Bn,
    ]);
    $vBN = Vehicle::factory()->create([
        'plate_number' => '29C-DRIVER-BN',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driverBN->id,
    ]);
    expect(VehicleDispatchBoard::getDriverStation($vBN))->toBe('BN');

    // 3. Xe có tài xế trực tại TN (Thái Nguyên)
    $driverTN = User::factory()->create([
        'name' => 'Tài xế Thái Nguyên',
        'station' => OnDutyLocation::Tn,
    ]);
    $vTN = Vehicle::factory()->create([
        'plate_number' => '99H-DRIVER-TN',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driverTN->id,
    ]);
    expect(VehicleDispatchBoard::getDriverStation($vTN))->toBe('TN');

    // 4. Xe chưa có tài xế trực -> Trả về OTHER
    $vEmpty = Vehicle::factory()->create([
        'plate_number' => '29C-EMPTY-DRIVER',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => null,
        'even_driver_id' => null,
        'odd_driver_id' => null,
    ]);
    expect(VehicleDispatchBoard::getDriverStation($vEmpty))->toBe('OTHER');
});

test('trip count only counts trips from start to end and excludes pending trips', function () {
    $driver = User::factory()->create(['name' => 'Lái Xe Test Chuyến']);
    $vehicle = Vehicle::factory()->create([
        'plate_number' => '29C-TRIP-TEST',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driver->id,
        'is_active' => true,
    ]);

    // Giả lập thời gian vào 10:00 sáng
    Carbon::setTestNow(Carbon::create(2026, 10, 21, 10, 0, 0));

    // 1. Chuyến đã hoàn thành trong ca (bắt đầu 8:30, xong 9:30) -> PHẢI ĐẾM
    Trip::factory()->create([
        'trip_code' => 'TRIP-COMPLETED-01',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::create(2026, 10, 21, 8, 30, 0),
        'completed_at' => Carbon::create(2026, 10, 21, 9, 30, 0),
    ]);

    // 2. Chuyến đang giao (bắt đầu 9:45) -> PHẢI ĐẾM
    Trip::factory()->create([
        'trip_code' => 'TRIP-DELIVERING-02',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Delivering,
        'started_at' => Carbon::create(2026, 10, 21, 9, 45, 0),
    ]);

    // 3. Chuyến chờ chạy (Pending) -> KHÔNG ĐƯỢC ĐẾM
    Trip::factory()->create([
        'trip_code' => 'TRIP-PENDING-03',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Pending,
        'created_at' => Carbon::create(2026, 10, 21, 9, 50, 0),
    ]);

    // 4. Chuyến đã huỷ (Cancelled) -> KHÔNG ĐƯỢC ĐẾM
    Trip::factory()->create([
        'trip_code' => 'TRIP-CANCELLED-04',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Cancelled,
        'created_at' => Carbon::create(2026, 10, 21, 9, 0, 0),
    ]);

    // Kiểm tra khi relation chưa load
    expect(VehicleDispatchBoard::getTodayTripsCount($vehicle))->toBe(2);

    // Kiểm tra khi relation đã load (như trên table view)
    $vehicle->load('trips');
    expect(VehicleDispatchBoard::getTodayTripsCount($vehicle))->toBe(2);

    $page = new VehicleDispatchBoard;
    $trips = $page->getTodayTripsForVehicle($vehicle);
    expect($trips)->toHaveCount(3)
        ->and($trips->pluck('trip_code')->all())->toContain('TRIP-COMPLETED-01', 'TRIP-DELIVERING-02', 'TRIP-PENDING-03')
        ->and($trips->pluck('trip_code')->all())->not->toContain('TRIP-CANCELLED-04')
        // Sắp xếp ưu tiên: Đang thực hiện -> Chờ chạy -> Hoàn thành
        ->and($trips->first()->trip_code)->toBe('TRIP-DELIVERING-02')
        ->and($trips->get(1)->trip_code)->toBe('TRIP-PENDING-03')
        ->and($trips->last()->trip_code)->toBe('TRIP-COMPLETED-01');

    Carbon::setTestNow();
});

test('vehicle dispatch board respects 8am to 8am next day shift window', function () {
    $driver = User::factory()->create(['name' => 'Lái Xe 8h']);
    $vehicle = Vehicle::factory()->create([
        'plate_number' => '29C-SHIFT-8AM',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driver->id,
        'is_active' => true,
    ]);

    // Chuyến A: Hoàn thành lúc 07:30 ngày 21/10 (trước 8h sáng) -> Thuộc ca ngày 20/10
    Trip::factory()->create([
        'trip_code' => 'TRIP-OCT20-NIGHT',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::create(2026, 10, 21, 5, 0, 0),
        'completed_at' => Carbon::create(2026, 10, 21, 7, 30, 0),
    ]);

    // Chuyến B: Bắt đầu lúc 09:00 ngày 21/10 (sau 8h sáng) -> Thuộc ca ngày 21/10
    Trip::factory()->create([
        'trip_code' => 'TRIP-OCT21-MORNING',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::create(2026, 10, 21, 9, 0, 0),
        'completed_at' => Carbon::create(2026, 10, 21, 11, 0, 0),
    ]);

    // Chuyến C: Chạy lúc 02:00 rạng sáng ngày 22/10 -> Vẫn thuộc ca ngày 21/10 (vì trước 8h ngày 22)
    Trip::factory()->create([
        'trip_code' => 'TRIP-OCT21-OVERNIGHT',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::create(2026, 10, 22, 2, 0, 0),
        'completed_at' => Carbon::create(2026, 10, 22, 4, 30, 0),
    ]);

    // Khi test lúc 14:00 ngày 21/10 (ca ngày 21/10 từ 08:00 ngày 21 -> 08:00 ngày 22)
    Carbon::setTestNow(Carbon::create(2026, 10, 21, 14, 0, 0));
    $vehicle->load('trips');
    $page = new VehicleDispatchBoard;
    $tripsAt14h = $page->getTodayTripsForVehicle($vehicle);

    // Không thấy chuyến A (vì kết thúc trước 8h sáng ngày 21/10, thuộc ca 20/10)
    // Thấy chuyến B và C (thuộc ca 21/10)
    expect($tripsAt14h->pluck('trip_code')->all())
        ->not->toContain('TRIP-OCT20-NIGHT')
        ->toContain('TRIP-OCT21-MORNING', 'TRIP-OCT21-OVERNIGHT')
        ->toHaveCount(2);

    expect(VehicleDispatchBoard::getTodayTripsCount($vehicle))->toBe(2);

    // Khi test lúc 06:00 sáng ngày 22/10 (trước 8h sáng -> VẪN là ca ngày 21/10)
    Carbon::setTestNow(Carbon::create(2026, 10, 22, 6, 0, 0));
    $tripsAt6h = $page->getTodayTripsForVehicle($vehicle);
    expect($tripsAt6h->pluck('trip_code')->all())
        ->not->toContain('TRIP-OCT20-NIGHT')
        ->toContain('TRIP-OCT21-MORNING', 'TRIP-OCT21-OVERNIGHT')
        ->toHaveCount(2);

    expect(VehicleDispatchBoard::getTodayTripsCount($vehicle))->toBe(2);

    Carbon::setTestNow();
});

test('vehicle dispatch board prioritizes paused trip over pending trip so km report is never hidden', function () {
    $driver = User::factory()->create(['name' => 'Lái Xe Báo Km']);
    $vehicle = Vehicle::factory()->create([
        'plate_number' => '29C-PAUSE-PENDING',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driver->id,
        'is_active' => true,
    ]);

    // 1. Chuyến 1: Đã hoàn thành (có mốc kết thúc), nhưng bị báo sai Km (is_paused = true)
    $completedPausedTrip = Trip::factory()->create([
        'trip_code' => 'TRIP-PAUSED-COMPLETED',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Completed,
        'is_paused' => true,
        'started_at' => now()->subHours(3),
        'completed_at' => now()->subHours(1),
        'end_km' => 12050,
    ]);

    TripKmReport::create([
        'trip_id' => $completedPausedTrip->id,
        'driver_id' => $driver->id,
        'reported_km' => 12000,
        'system_km' => 12050,
        'status' => TripKmReportStatus::Pending,
        'note' => 'Báo sai km sau khi hạ hàng',
    ]);

    // 2. Chuyến 2: Đã được tạo sẵn ở trạng thái Chờ chạy (Pending)
    $nextPendingTrip = Trip::factory()->create([
        'trip_code' => 'TRIP-NEXT-PENDING',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Pending,
    ]);

    $vehicle->load('trips.latestPendingKmReport');

    // Chuyến active_trip PHẢI là chuyến bị pause ($completedPausedTrip), không phải $nextPendingTrip
    expect($vehicle->active_trip?->id)->toBe($completedPausedTrip->id)
        ->and(VehicleDispatchBoard::getTripStatusLabel($vehicle))->toBe('Tạm dừng (Sửa Km)')
        ->and(VehicleDispatchBoard::canClickTripStatus($vehicle))->toBeTrue();

    // Trên Livewire component, phải thấy action resolve_km_report
    Livewire::test(VehicleDispatchBoard::class)
        ->assertStatus(200)
        ->assertSee('mountTableAction(\'resolve_km_report\', \''.$vehicle->id.'\')', false)
        ->assertSee('Tạm dừng (Sửa Km)');
});

test('vehicle dispatch board distinguishes idle vehicle and pending trip with different colors', function () {
    // 1. Xe chờ: không có chuyến nào
    $idleVehicle = Vehicle::factory()->create([
        'plate_number' => '29C-IDLE-VEHICLE',
        'type' => VehicleOwnerType::Company,
        'status' => VehicleStatus::On,
        'is_active' => true,
    ]);

    expect(VehicleDispatchBoard::getTripStatusLabel($idleVehicle))->toBe('Xe chờ')
        ->and(VehicleDispatchBoard::getTripStatusBadgeClass($idleVehicle))->toContain('bg-slate-100', 'text-slate-500', 'font-normal');

    // 2. Xe có chuyến Chờ chạy (Pending)
    $pendingVehicle = Vehicle::factory()->create([
        'plate_number' => '29C-PENDING-VEHICLE',
        'type' => VehicleOwnerType::Company,
        'status' => VehicleStatus::On,
        'is_active' => true,
    ]);

    Trip::factory()->create([
        'trip_code' => 'TRIP-PENDING-ONLY',
        'vehicle_id' => $pendingVehicle->id,
        'status' => TripStatus::Pending,
    ]);

    $pendingVehicle->load('trips');

    expect(VehicleDispatchBoard::getTripStatusLabel($pendingVehicle))->toBe('Chờ chạy')
        ->and(VehicleDispatchBoard::getTripStatusBadgeClass($pendingVehicle))->toContain('bg-purple-600', 'text-white', 'font-bold')
        ->and(TripStatus::Pending->getColor())->toBe('purple');
});

test('vehicle trip modal displays pending trips and prioritizes in-progress over pending over completed', function () {
    $vehicle = Vehicle::factory()->create([
        'plate_number' => '29C-TRIP-ORDER',
        'type' => VehicleOwnerType::Company,
        'is_active' => true,
    ]);

    // 1. Chuyến hoàn thành sáng sớm (08:30)
    $completedTrip = Trip::factory()->create([
        'trip_code' => 'TRIP-COMPLETED-EARLY',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->startOfDay()->addHours(8)->addMinutes(30),
        'completed_at' => now()->startOfDay()->addHours(9)->addMinutes(30),
    ]);

    // 2. Chuyến chờ chạy (Pending)
    $pendingTrip = Trip::factory()->create([
        'trip_code' => 'TRIP-PENDING-MIDDAY',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Pending,
        'created_at' => now()->startOfDay()->addHours(10),
    ]);

    // 3. Chuyến đang thực hiện (Delivering)
    $deliveringTrip = Trip::factory()->create([
        'trip_code' => 'TRIP-DELIVERING-NOW',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Delivering,
        'started_at' => now()->startOfDay()->addHours(11),
    ]);

    $page = new VehicleDispatchBoard;
    $trips = $page->getTodayTripsForVehicle($vehicle);

    // Modal phải có đủ 3 chuyến
    expect($trips)->toHaveCount(3)
        // Thứ tự ưu tiên: Đang thực hiện -> Chờ chạy -> Hoàn thành
        ->and($trips->get(0)->trip_code)->toBe('TRIP-DELIVERING-NOW')
        ->and($trips->get(1)->trip_code)->toBe('TRIP-PENDING-MIDDAY')
        ->and($trips->get(2)->trip_code)->toBe('TRIP-COMPLETED-EARLY');

    // Số chuyến đếm trên bảng (cột Chuyến) vẫn chỉ đếm các chuyến từ bắt đầu đến kết thúc (loại trừ pending)
    expect(VehicleDispatchBoard::getTodayTripsCount($vehicle))->toBe(2);

    Livewire::test(VehicleDispatchBoard::class)
        ->call('openTripModal', $vehicle->id)
        ->assertDispatched('open-modal', id: 'vehicle-trip-modal')
        ->assertSee('TRIP-DELIVERING-NOW')
        ->assertSee('TRIP-PENDING-MIDDAY')
        ->assertSee('TRIP-COMPLETED-EARLY')
        ->assertSee('1 đang chạy')
        ->assertSee('1 chờ chạy')
        ->assertSee('1 hoàn thành')
        ->assertSee('Tổng: 3 chuyến');
});

test('vehicle dispatch board counts waiting orders in pending trips correctly and displays column', function () {
    $customer = Customer::create(['code' => 'CUST-WAITING', 'name' => 'Khách Hàng Chờ', 'is_active' => true]);
    $area = Area::create(['code' => 'AREA-WAITING', 'name' => 'Khu Vực Chờ']);
    $vehicle = Vehicle::factory()->create([
        'plate_number' => '29C-WAITING-ORDERS',
        'type' => VehicleOwnerType::Company,
        'is_active' => true,
    ]);

    // 1. Chuyến đã hoàn thành với 2 đơn hàng
    $completedTrip = Trip::factory()->create([
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->startOfDay()->addHours(8),
        'completed_at' => now()->startOfDay()->addHours(10),
    ]);

    Order::create([
        'order_code' => 'ORD-DONE-01',
        'trip_id' => $completedTrip->id,
        'customer_id' => $customer->id,
        'area_id' => $area->id,
        'status' => OrderStatus::Completed,
        'created_by' => auth()->id(),
    ]);

    // 2. Chuyến chờ chạy (Pending) với 3 đơn hàng: 2 đơn hợp lệ, 1 đơn đã huỷ
    $pendingTrip = Trip::factory()->create([
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Pending,
    ]);

    Order::create([
        'order_code' => 'ORD-WAIT-01',
        'trip_id' => $pendingTrip->id,
        'customer_id' => $customer->id,
        'area_id' => $area->id,
        'status' => OrderStatus::Sent,
        'created_by' => auth()->id(),
    ]);

    Order::create([
        'order_code' => 'ORD-WAIT-02',
        'trip_id' => $pendingTrip->id,
        'customer_id' => $customer->id,
        'area_id' => $area->id,
        'status' => OrderStatus::Assigned,
        'created_by' => auth()->id(),
    ]);

    Order::create([
        'order_code' => 'ORD-CANCELLED-03',
        'trip_id' => $pendingTrip->id,
        'customer_id' => $customer->id,
        'area_id' => $area->id,
        'status' => OrderStatus::Cancelled,
        'created_by' => auth()->id(),
    ]);

    // Khi relation chưa eager-load
    expect(VehicleDispatchBoard::getWaitingOrdersCount($vehicle))->toBe(2)
        ->and(VehicleDispatchBoard::getTodayTripsCount($vehicle))->toBe(1);

    // Khi relation đã eager-load (như trên bảng điều phối)
    $vehicle->load('trips.orders');
    expect(VehicleDispatchBoard::getWaitingOrdersCount($vehicle))->toBe(2)
        ->and(VehicleDispatchBoard::getTodayTripsCount($vehicle))->toBe(1);

    // Kiểm tra trên giao diện component hiển thị cột Đơn chờ và số lượng 2
    Livewire::test(VehicleDispatchBoard::class)
        ->assertStatus(200)
        ->assertSee('Đơn chờ')
        ->assertSee('29C-WAITING-ORDERS');
});

test('vehicle dispatch board only shows shift label when driver has an open active shift from app login', function () {
    $driver = User::factory()->create([
        'name' => 'Lái Xe Ca App',
        'work_shift' => DriverWorkShift::Even, // Hồ sơ ca chẵn
    ]);

    $vehicle = Vehicle::factory()->create([
        'plate_number' => '29C-SHIFT-APP',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driver->id,
        'is_active' => true,
    ]);

    // 1. Khi lái xe chưa đăng nhập mở ca trên app -> Phải trả về '—', KHÔNG tự tiện hiện 'Ca chẵn'
    expect(VehicleDispatchBoard::getShiftLabel($vehicle))->toBe('—');

    // 2. Khi lái xe có ca cũ nhưng ĐÃ KẾT THÚC (end_time !== null) -> Vẫn là '—'
    $endedShift = DriverShift::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::MorningHalf,
        'start_time' => now()->subHours(8),
        'end_time' => now()->subHours(2),
    ]);

    $vehicle->load('driver.driverShifts');
    expect(VehicleDispatchBoard::getShiftLabel($vehicle))->toBe('—');

    // 3. Khi lái xe đăng nhập app và mở ca làm việc hiện tại (end_time === null)
    $activeShift = DriverShift::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::MorningHalf,
        'start_time' => now()->subHour(),
        'end_time' => null,
    ]);

    $vehicle->load('driver.driverShifts');
    expect(VehicleDispatchBoard::getShiftLabel($vehicle))->toBe('X/2');

    // Cập nhật sang ca đêm
    $activeShift->update(['shift_type' => ShiftType::NightHalf]);
    $vehicle->load('driver.driverShifts');
    expect(VehicleDispatchBoard::getShiftLabel($vehicle))->toBe('Y/2');

    // Cập nhật sang ca cả ngày
    $activeShift->update(['shift_type' => ShiftType::Full]);
    $vehicle->load('driver.driverShifts');
    expect(VehicleDispatchBoard::getShiftLabel($vehicle))->toBe('X');
});

test('snapshot monthly vehicle km is scheduled at 12:00 on the 23rd', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'vehicles:snapshot-monthly-km'));

    expect($event?->expression)->toBe('0 12 23 * *');
});

test('snapshot monthly vehicle km command copies current mileage to monthly_start_km and defers active trips to next month', function () {
    $vehicle1 = Vehicle::factory()->create([
        'plate_number' => '29C-SNAP-1',
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 15000,
        'monthly_start_km' => null,
    ]);

    $vehicle2 = Vehicle::factory()->create([
        'plate_number' => '29C-SNAP-2',
        'type' => VehicleOwnerType::Rent,
        'current_mileage' => 45200,
        'monthly_start_km' => 40000,
    ]);

    Trip::factory()->create([
        'vehicle_id' => $vehicle2->id,
        'status' => TripStatus::Started,
        'start_km' => 45000,
        'completed_at' => null,
    ]);

    // Chạy command snapshot
    $this->artisan('vehicles:snapshot-monthly-km')
        ->assertSuccessful();

    // Xe 1 không có chuyến đang chạy -> chốt theo current_mileage
    expect($vehicle1->fresh()->monthly_start_km)->toEqual(15000.00)
        // Xe 2 đang chạy chuyến -> mốc tháng mới lấy start_km = 45000 để toàn bộ chuyến tính sang tháng sau
        ->and($vehicle2->fresh()->monthly_start_km)->toEqual(45000.00);
});

test('vehicle dispatch board calculates km over and highlights background based on thresholds', function () {
    // 1. Xe chưa có monthly_start_km (chưa từng chốt ngày 23 và không có mốc)
    $vehicleNoStart = Vehicle::factory()->create([
        'plate_number' => '29C-KM-NONE',
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 10000,
        'status' => VehicleStatus::On,
        'is_active' => true,
    ]);
    $vehicleNoStart->monthly_start_km = null;
    $vehicleNoStart->saveQuietly();

    $dataNone = VehicleDispatchBoard::getKmOverData($vehicleNoStart);
    expect($dataNone['text'])->toBe('—')
        ->and($dataNone['status'])->toBe('none')
        ->and($dataNone['cellClass'])->toContain('text-gray-400');

    // 2. Xe chạy bình thường (delta = 1500 < 2600)
    $vehicleNormal = Vehicle::factory()->create([
        'plate_number' => '29C-KM-NORMAL',
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 11500,
        'monthly_start_km' => 10000,
        'status' => VehicleStatus::On,
        'is_active' => true,
    ]);

    $dataNormal = VehicleDispatchBoard::getKmOverData($vehicleNormal);
    expect($dataNormal['text'])->toBe('1.500')
        ->and($dataNormal['status'])->toBe('normal')
        ->and($dataNormal['fillPercent'])->toBe(50)
        ->and($dataNormal['cellClass'])->not->toContain('bg-orange-400')
        ->and($dataNormal['cellClass'])->not->toContain('bg-rose-500');

    // 3. Xe sắp đến hạn mức (delta = 2800: đúng ngưỡng >= 2800 và < 3000) -> Tô cam
    $vehicleWarning = Vehicle::factory()->create([
        'plate_number' => '29C-KM-WARN',
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 12800,
        'monthly_start_km' => 10000,
        'status' => VehicleStatus::On,
        'is_active' => true,
    ]);

    $dataWarn = VehicleDispatchBoard::getKmOverData($vehicleWarning);
    expect($dataWarn['text'])->toBe('2.800')
        ->and($dataWarn['status'])->toBe('warning')
        ->and($dataWarn['cellClass'])->toContain('bg-orange-400')
        ->and($dataWarn['cellClass'])->toContain('km-over-cell');

    // 4. Xe từ 3000 trở lên (delta = 3120 >= 3000) -> Tô đỏ
    $vehicleDanger = Vehicle::factory()->create([
        'plate_number' => '29C-KM-DANGER',
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 13120,
        'monthly_start_km' => 10000,
        'status' => VehicleStatus::On,
        'is_active' => true,
    ]);

    $dataDanger = VehicleDispatchBoard::getKmOverData($vehicleDanger);
    expect($dataDanger['text'])->toBe('3.120')
        ->and($dataDanger['status'])->toBe('danger')
        ->and($dataDanger['cellClass'])->toContain('bg-rose-500')
        ->and($dataDanger['cellClass'])->toContain('text-white')
        ->and($dataDanger['cellClass'])->toContain('km-over-cell');

    // Kiểm tra trên Livewire View có render đúng màu vàng và đỏ
    Livewire::test(VehicleDispatchBoard::class)
        ->assertStatus(200)
        ->assertSee('29C-KM-WARN')
        ->assertSee('2.800')
        ->assertSee('bg-orange-400')
        ->assertSee('29C-KM-DANGER')
        ->assertSee('3.120')
        ->assertSee('bg-rose-500')
        ->assertSeeHtml('rgb(59 130 246 / 0.22) 50%');
});

test('vehicle dispatch board renders loading overlay and spam click protection for modals', function () {
    $vehicle = Vehicle::factory()->create([
        'plate_number' => '29C-MODAL-TEST',
        'type' => VehicleOwnerType::Company,
        'is_active' => true,
    ]);

    Livewire::test(VehicleDispatchBoard::class)
        ->assertStatus(200)
        ->assertSee('wire:loading', false)
        ->assertSee('wire:target="openTripModal, openGpsModal"', false)
        ->assertSee('Đang tải dữ liệu...')
        ->assertSee('Vui lòng đợi trong giây lát')
        ->assertSee('wire:click="openTripModal('.$vehicle->id.')"', false)
        ->call('openGpsModal', $vehicle->id)
        ->assertDispatched('open-modal', id: 'vehicle-gps-modal')
        ->call('openTripModal', $vehicle->id)
        ->assertDispatched('open-modal', id: 'vehicle-trip-modal');
});

test('resolveStationFromGps accurately resolves station from address and coordinates', function () {
    // 1. Theo địa chỉ
    $vTN = Vehicle::factory()->create(['gps_address' => 'KCN Yên Bình, Phổ Yên, Thái Nguyên']);
    expect(VehicleDispatchBoard::resolveStationFromGps($vTN))->toBe('TN');

    $vBN = Vehicle::factory()->create(['gps_address' => 'KCN Yên Phong, Bắc Ninh']);
    expect(VehicleDispatchBoard::resolveStationFromGps($vBN))->toBe('BN');

    $vNB = Vehicle::factory()->create(['gps_address' => 'Sân bay Quốc tế Nội Bài, Sóc Sơn, Hà Nội']);
    expect(VehicleDispatchBoard::resolveStationFromGps($vNB))->toBe('NB');

    $vProv = Vehicle::factory()->create(['gps_address' => 'KCN Tràng Duệ, An Dương, Hải Phòng']);
    expect(VehicleDispatchBoard::resolveStationFromGps($vProv))->toBe('Province');

    // 2. Theo toạ độ GPS khi không có địa chỉ
    $vCoordTN = Vehicle::factory()->create([
        'gps_address' => null,
        'gps_lat' => 21.50,
        'gps_lng' => 105.80,
    ]);
    expect(VehicleDispatchBoard::resolveStationFromGps($vCoordTN))->toBe('TN');

    $vCoordBN = Vehicle::factory()->create([
        'gps_address' => null,
        'gps_lat' => 21.20,
        'gps_lng' => 106.05,
    ]);
    expect(VehicleDispatchBoard::resolveStationFromGps($vCoordBN))->toBe('BN');

    $vCoordNB = Vehicle::factory()->create([
        'gps_address' => null,
        'gps_lat' => 21.22,
        'gps_lng' => 105.80,
    ]);
    expect(VehicleDispatchBoard::resolveStationFromGps($vCoordNB))->toBe('NB');

    $vCoordProv = Vehicle::factory()->create([
        'gps_address' => null,
        'gps_lat' => 20.85,
        'gps_lng' => 106.68,
    ]);
    expect(VehicleDispatchBoard::resolveStationFromGps($vCoordProv))->toBe('Province');

    // 3. Không có dữ liệu GPS
    $vNoGps = Vehicle::factory()->create([
        'gps_address' => null,
        'gps_lat' => null,
        'gps_lng' => null,
    ]);
    expect(VehicleDispatchBoard::resolveStationFromGps($vNoGps))->toBeNull();
});

test('vehicle location group priority: destination first, GPS second, driver station third', function () {
    $driverNB = User::factory()->create([
        'name' => 'Lái xe trực NBA',
        'station' => OnDutyLocation::Nba,
    ]);

    // Case 1: Xe có tài xế trực tại NBA, xe đang đỗ ở Hà Nội (GPS = NB),
    // NHƯNG có chuyến đang chạy đến Bắc Ninh -> Phải gom vào nhóm 'BN' theo Ưu tiên 1 (Điểm đến của chuyến)
    $locBN = Location::create([
        'code' => 'ALSB',
        'name' => 'Kho ALSB Bắc Ninh',
        'address' => 'KCN Yên Phong, Bắc Ninh',
    ]);
    $vWithTrip = Vehicle::factory()->create([
        'plate_number' => '29C-TRIP-DEST-BN',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driverNB->id,
        'gps_address' => 'Sóc Sơn, Hà Nội',
        'is_active' => true,
    ]);

    $trip = Trip::factory()->create([
        'vehicle_id' => $vWithTrip->id,
        'driver_id' => $driverNB->id,
        'status' => TripStatus::Delivering,
        'started_at' => now()->subHour(),
        'end_location_id' => $locBN->id,
    ]);

    $vWithTrip->load('trips.orders.deliveryPoints.location.area', 'trips.endLocation.area');

    // Ưu tiên 1: Điểm đến của chuyến là Bắc Ninh (BN)
    expect(VehicleDispatchBoard::resolveVehicleLocationGroup($vWithTrip))->toBe('BN')
        // Nhưng cột Trực vẫn là NB theo tài xế
        ->and(VehicleDispatchBoard::getDriverStation($vWithTrip))->toBe('NB');

    // Case 2: Xe chưa có điểm đến (xe chờ/không có chuyến), nhưng có GPS ở Thái Nguyên -> Phải gom vào 'TN' theo Ưu tiên 2 (GPS)
    $vWithGps = Vehicle::factory()->create([
        'plate_number' => '29C-WAIT-TN',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driverNB->id,
        'gps_address' => 'KCN Yên Bình, Phổ Yên, Thái Nguyên',
        'is_active' => true,
    ]);

    expect(VehicleDispatchBoard::resolveVehicleLocationGroup($vWithGps))->toBe('TN')
        // Cột Trực vẫn hiển thị điểm trực của lái xe (NB)
        ->and(VehicleDispatchBoard::getDriverStation($vWithGps))->toBe('NB');

    // Case 3: Xe không có chuyến, mất GPS -> Fallback theo điểm trực của tài xế (NB)
    $vFallbackStation = Vehicle::factory()->create([
        'plate_number' => '20C-FALLBACK',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driverNB->id,
        'gps_address' => null,
        'gps_lat' => null,
        'gps_lng' => null,
        'is_active' => true,
    ]);

    expect(VehicleDispatchBoard::resolveVehicleLocationGroup($vFallbackStation))->toBe('NB');
});

test('getGroupedVehicles groups vehicles by location destination/GPS while maintaining driver station', function () {
    $driverNB = User::factory()->create(['name' => 'Lái Xe NBA', 'station' => OnDutyLocation::Nba]);
    $driverBN = User::factory()->create(['name' => 'Lái Xe BN', 'station' => OnDutyLocation::Bn]);

    // Xe 1: Tài xế BN, nhưng GPS ở Thái Nguyên -> Group TN, Cột Trực: BN
    $v1 = Vehicle::factory()->create([
        'plate_number' => '99C-GPS-TN',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driverBN->id,
        'gps_address' => 'Sông Công, Thái Nguyên',
        'is_active' => true,
    ]);

    // Xe 2: Tài xế NB, nhưng có chuyến đến Bắc Ninh -> Group BN, Cột Trực: NB
    $locBN = Location::create([
        'code' => 'SEV',
        'name' => 'SEV Bắc Ninh',
        'address' => 'Yên Phong, Bắc Ninh',
    ]);
    $v2 = Vehicle::factory()->create([
        'plate_number' => '29C-TRIP-BN',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driverNB->id,
        'gps_address' => 'Nội Bài, Hà Nội',
        'is_active' => true,
    ]);
    Trip::factory()->create([
        'vehicle_id' => $v2->id,
        'driver_id' => $driverNB->id,
        'status' => TripStatus::Delivering,
        'started_at' => now()->subMinutes(30),
        'end_location_id' => $locBN->id,
    ]);

    $board = new VehicleDispatchBoard;
    $grouped = $board->getGroupedVehicles();

    expect(isset($grouped['TN']))->toBeTrue()
        ->and($grouped['TN']['vehicles']->pluck('plate_number')->all())->toContain('99C-GPS-TN')
        ->and(isset($grouped['BN']))->toBeTrue()
        ->and($grouped['BN']['vehicles']->pluck('plate_number')->all())->toContain('29C-TRIP-BN')
        ->and(VehicleDispatchBoard::getDriverStation($v1))->toBe('BN')
        ->and(VehicleDispatchBoard::getDriverStation($v2))->toBe('NB');
});

test('vehicle with completed past trip is grouped by current GPS location when currently idle', function () {
    $driver = User::factory()->create(['name' => 'Đinh Văn Thanh', 'station' => OnDutyLocation::Bn]);

    // Xe 20C-08846: Chuyến trước đã completed ở Nội Bài, nhưng hiện tại là xe chờ và GPS ở Tam Đa, Bắc Ninh
    $locNB = Location::create([
        'code' => 'ACSV',
        'name' => 'Kho ACSV Nội Bài',
        'address' => 'Sân bay Nội Bài, Sóc Sơn, Hà Nội',
    ]);

    $vehicle = Vehicle::factory()->create([
        'plate_number' => '20C08846',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driver->id,
        'gps_address' => 'Bắc Ninh, Tam Đa, Bắc Ninh, Việt Nam',
        'gps_lat' => 21.194458,
        'gps_lng' => 106.010436,
        'is_active' => true,
    ]);

    // Chuyến đã hoàn thành
    Trip::factory()->create([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subHours(5),
        'completed_at' => now()->subHours(2),
        'end_location_id' => $locNB->id,
    ]);

    $board = new VehicleDispatchBoard;
    $grouped = $board->getGroupedVehicles();

    // Xe không có chuyến đang chạy -> Phải thuộc nhóm BN theo GPS Tam Đa, Bắc Ninh chứ KHÔNG PHẢI nhóm NB!
    expect(VehicleDispatchBoard::resolveVehicleLocationGroup($vehicle))->toBe('BN')
        ->and(isset($grouped['BN']))->toBeTrue()
        ->and($grouped['BN']['vehicles']->pluck('plate_number')->all())->toContain('20C08846');
});

test('getGroupedVehicles sorts vehicles by status priority within each location group by default', function () {
    $driver = User::factory()->create(['name' => 'Lái Xe BN', 'station' => OnDutyLocation::Bn]);

    $locBN = Location::create([
        'code' => 'SEV-SORT',
        'name' => 'SEV Bắc Ninh Sort',
        'address' => 'Yên Phong, Bắc Ninh',
    ]);

    // Xe 1: Đang giao (Delivering - Priority 6), BSX nhỏ nhất
    $vDelivering = Vehicle::factory()->create([
        'plate_number' => '20C-00001',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driver->id,
        'gps_address' => 'Bắc Ninh',
        'is_active' => true,
    ]);
    Trip::factory()->create([
        'vehicle_id' => $vDelivering->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Delivering,
        'started_at' => now()->subMinutes(30),
        'end_location_id' => $locBN->id,
    ]);

    // Xe 2: Chờ chạy (Pending - Priority 3)
    $vPending = Vehicle::factory()->create([
        'plate_number' => '20C-00002',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driver->id,
        'gps_address' => 'Bắc Ninh',
        'is_active' => true,
    ]);
    Trip::factory()->create([
        'vehicle_id' => $vPending->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Pending,
        'started_at' => null,
        'end_location_id' => $locBN->id,
    ]);

    // Xe 3: Xe chờ (No trip - Priority 2), BSX 20C-00003
    $vWaitingB = Vehicle::factory()->create([
        'plate_number' => '20C-00004',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driver->id,
        'gps_address' => 'Bắc Ninh',
        'is_active' => true,
    ]);

    // Xe 4: Xe chờ (No trip - Priority 2), BSX 20C-00003
    $vWaitingA = Vehicle::factory()->create([
        'plate_number' => '20C-00003',
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driver->id,
        'gps_address' => 'Bắc Ninh',
        'is_active' => true,
    ]);

    $board = new VehicleDispatchBoard;
    $grouped = $board->getGroupedVehicles();

    expect(isset($grouped['BN']))->toBeTrue();

    // Mặc định sắp xếp status giảm dần (priority lớn trước), cùng priority thì theo BSX tăng dần
    expect($grouped['BN']['vehicles']->pluck('plate_number')->all())->toBe([
        '20C-00001',
        '20C-00002',
        '20C-00003',
        '20C-00004',
    ]);

    $board->sortDirection = 'asc';

    expect($board->getGroupedVehicles()['BN']['vehicles']->pluck('plate_number')->all())->toBe([
        '20C-00003',
        '20C-00004',
        '20C-00002',
        '20C-00001',
    ]);
});

test('VehicleDispatchBoard sortTable toggles sorting between status and plate_number', function () {
    $component = Livewire::test(VehicleDispatchBoard::class);

    // Mặc định sortBy là status, sortDirection là desc
    expect($component->get('sortBy'))->toBe('status')
        ->and($component->get('sortDirection'))->toBe('desc');

    // Bấm sort BSX
    $component->call('sortTable', 'plate_number');
    expect($component->get('sortBy'))->toBe('plate_number')
        ->and($component->get('sortDirection'))->toBe('asc');

    // Bấm sort BSX lần 2 -> desc
    $component->call('sortTable', 'plate_number');
    expect($component->get('sortBy'))->toBe('plate_number')
        ->and($component->get('sortDirection'))->toBe('desc');

    // Bấm sort status -> status asc
    $component->call('sortTable', 'status');
    expect($component->get('sortBy'))->toBe('status')
        ->and($component->get('sortDirection'))->toBe('asc');
});

test('alert level boundaries for speed and km over', function (float $value, float $light, float $orange, float $red, ?string $expected) {
    expect(VehicleDispatchBoard::getAlertLevel($value, $light, $orange, $red))->toBe($expected);
})->with([
    'speed 54.9' => [54.9, 55, 60, 65, null],
    'speed 55' => [55, 55, 60, 65, 'light'],
    'speed 59.9' => [59.9, 55, 60, 65, 'light'],
    'speed 60' => [60, 55, 60, 65, 'orange'],
    'speed 65' => [65, 55, 60, 65, 'red'],
    'km 2599' => [2599, 2600, 2800, 3000, null],
    'km 2600' => [2600, 2600, 2800, 3000, 'light'],
    'km 2800' => [2800, 2600, 2800, 3000, 'orange'],
    'km 2999' => [2999, 2600, 2800, 3000, 'orange'],
    'km 3000' => [3000, 2600, 2800, 3000, 'red'],
]);

test('speed and km over cells use the alert colors', function () {
    $speeding = Vehicle::factory()->create(['gps_speed' => 64.6]);
    $slow = Vehicle::factory()->create(['gps_speed' => 54.4]);
    $nearLimit = Vehicle::factory()->create(['current_mileage' => 12600, 'monthly_start_km' => 10000]);

    expect(VehicleDispatchBoard::getSpeedAlertClass($speeding))->toContain('bg-rose-500')
        ->and(VehicleDispatchBoard::getSpeedAlertClass($slow))->toBe('')
        ->and(VehicleDispatchBoard::getKmOverData($nearLimit)['status'])->toBe('notice')
        ->and(VehicleDispatchBoard::getKmOverData($nearLimit)['cellClass'])->toContain('bg-yellow-100');
});

test('idle engine turns red only after more than 20 minutes', function () {
    $this->travelTo(Carbon::parse('2026-10-23 10:00:00'));

    expect(VehicleDispatchBoard::isIdleEngineOverdue(null))->toBeFalse()
        ->and(VehicleDispatchBoard::isIdleEngineOverdue(now()->subMinutes(20)))->toBeFalse()
        ->and(VehicleDispatchBoard::isIdleEngineOverdue(now()->subMinutes(20)->subSecond()))->toBeTrue();
});

test('gps sync tracks how long a vehicle idles with engine on', function () {
    config(['services.eup.base_url' => 'http://eup.test']);
    $vehicle = Vehicle::factory()->create(['plate_number' => '29C-IDLE', 'gps_speed' => 0, 'car_status' => 2]);
    $lost = Vehicle::factory()->create(['car_status' => 5]);
    $gpsItem = fn (int $carStatus, int $speed, string $at) => ['result' => [
        ['VehicleNo' => '29C-IDLE', 'Speed' => $speed, 'CarStatus' => $carStatus, 'OperateDate' => $at],
    ]];
    Http::fake(['*' => Http::sequence()
        ->push($gpsItem(0, 0, '2026-10-23 10:00:00'))
        ->push($gpsItem(0, 0, '2026-10-23 10:15:00'))
        ->push($gpsItem(0, 30, '2026-10-23 10:22:00'))]);

    app(EupGpsService::class)->sync();
    app(EupGpsService::class)->sync();

    expect(EupGpsService::idleEngineSince($vehicle->id)?->format('H:i'))->toBe('10:00');

    $this->travelTo(Carbon::parse('2026-10-23 10:21:00'));
    expect(VehicleDispatchBoard::getMovementStatusColor($vehicle->fresh()))->toBe('danger')
        ->and(VehicleDispatchBoard::getMovementStatusColor($lost))->toBe('danger');

    app(EupGpsService::class)->sync();

    expect(EupGpsService::idleEngineSince($vehicle->id))->toBeNull();
});
