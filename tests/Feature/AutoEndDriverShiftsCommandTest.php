<?php

use App\Enums\CargoType;
use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Priority;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\DriverSwap;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driverRole = Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);

    $this->driver = User::factory()->create(['name' => 'Nguyễn Văn Lái']);
    $this->driver->assignRole($this->driverRole);

    $this->vehicle = Vehicle::create([
        'plate_number' => '29C-123.45',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $this->driver->id,
        'current_mileage' => 1000.0,
    ]);

    $this->area = Area::create([
        'code' => 'HN',
        'name' => 'Hà Nội',
        'is_active' => true,
    ]);
});

test('it does not auto-end shift before 5-minute grace period has passed', function () {
    Carbon::setTestNow('2026-10-21 08:30:00');

    $shift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'shift_type' => ShiftType::MorningHalf,
        'start_time' => now(),
        'start_km' => 1000.0,
    ]);

    // Ca X/2 mốc kết thúc là 20:00 cùng ngày. Lúc 20:04 vẫn chưa quá 5 phút ân hạn (20:05)
    Carbon::setTestNow('2026-10-21 20:04:59');

    $this->artisan('shifts:auto-end')
        ->assertSuccessful();

    $shift->refresh();
    expect($shift->end_time)->toBeNull();
});

test('it auto-ends MorningHalf shift (Ca X/2) after 20:05 when driver has no incomplete orders', function () {
    Carbon::setTestNow('2026-10-21 08:30:00');

    $shift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'shift_type' => ShiftType::MorningHalf,
        'start_time' => now(),
        'start_km' => 1000.0,
    ]);

    // Tại thời điểm 20:06 (sau 20:05), hệ thống tự động thoát ca
    Carbon::setTestNow('2026-10-21 20:06:00');

    $this->artisan('shifts:auto-end')
        ->assertSuccessful();

    $shift->refresh();
    expect($shift->end_time)->not->toBeNull();
    // Ghi nhận đúng mốc 20:00:00
    expect($shift->end_time->format('Y-m-d H:i:s'))->toBe('2026-10-21 20:00:00');
    expect((float) $shift->end_km)->toBe(1000.0);
    expect((float) $shift->total_km)->toBe(0.0);
});

test('it auto-ends Full shift (Ca X) after next day 08:05', function () {
    Carbon::setTestNow('2026-10-21 08:15:00');

    $shift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now(),
        'start_km' => 2000.0,
    ]);

    // Ngày hôm sau lúc 08:04: Chưa out ca
    Carbon::setTestNow('2026-10-22 08:04:00');
    $this->artisan('shifts:auto-end')->assertSuccessful();
    expect($shift->fresh()->end_time)->toBeNull();

    // Ngày hôm sau lúc 08:06: Đã qua mốc ân hạn 08:05 -> Auto out ca
    Carbon::setTestNow('2026-10-22 08:06:00');
    $this->artisan('shifts:auto-end')->assertSuccessful();

    $shift->refresh();
    expect($shift->end_time)->not->toBeNull();
    expect($shift->end_time->format('Y-m-d H:i:s'))->toBe('2026-10-22 08:00:00');
});

test('it auto-ends NightHalf shift (Ca Y/2) started in evening after next day 08:05', function () {
    Carbon::setTestNow('2026-10-21 20:30:00');

    $shift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'shift_type' => ShiftType::NightHalf,
        'start_time' => now(),
        'start_km' => 3000.0,
    ]);

    // Sáng hôm sau lúc 08:06
    Carbon::setTestNow('2026-10-22 08:06:00');
    $this->artisan('shifts:auto-end')->assertSuccessful();

    $shift->refresh();
    expect($shift->end_time)->not->toBeNull();
    expect($shift->end_time->format('Y-m-d H:i:s'))->toBe('2026-10-22 08:00:00');
});

test('it auto-ends NightHalf shift (Ca Y/2) started after midnight on same day 08:05', function () {
    Carbon::setTestNow('2026-10-22 01:30:00');

    $shift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'shift_type' => ShiftType::NightHalf,
        'start_time' => now(),
        'start_km' => 3500.0,
    ]);

    // Cùng ngày lúc 08:06 sáng
    Carbon::setTestNow('2026-10-22 08:06:00');
    $this->artisan('shifts:auto-end')->assertSuccessful();

    $shift->refresh();
    expect($shift->end_time)->not->toBeNull();
    expect($shift->end_time->format('Y-m-d H:i:s'))->toBe('2026-10-22 08:00:00');
});

test('it does not auto-end if driver has an incomplete order in progress', function () {
    Carbon::setTestNow('2026-10-21 08:00:00');

    $shift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'shift_type' => ShiftType::MorningHalf,
        'start_time' => now(),
        'start_km' => 1000.0,
    ]);

    $customer = Customer::create(['code' => 'CUST-01', 'name' => 'Công ty Test']);

    $trip = Trip::create([
        'trip_code' => 'TRIP-001',
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'shift_id' => $shift->id,
        'status' => TripStatus::Delivering,
        'start_km' => 1000.0,
        'started_at' => now(),
    ]);

    Order::create([
        'area_id' => $this->area->id,
        'created_by' => $this->driver->id,
        'order_code' => 'ORD-001',
        'trip_id' => $trip->id,
        'customer_id' => $customer->id,
        'cargo_name' => 'Hàng mẫu',
        'cargo_type' => CargoType::Gcr,
        'type' => OrderType::Hhhk,
        'priority' => Priority::Medium,
        'status' => OrderStatus::InTransit,
    ]);

    // Tại thời điểm 20:10 (sau mốc 20:05)
    Carbon::setTestNow('2026-10-21 20:10:00');
    $this->artisan('shifts:auto-end')->assertSuccessful();

    // Chặn out ca vì có đơn InTransit
    $shift->refresh();
    expect($shift->end_time)->toBeNull();
});

test('it auto-ends if driver active trip orders were driver-swapped (bàn giao đảo lái)', function () {
    Carbon::setTestNow('2026-10-21 08:00:00');

    $shift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'shift_type' => ShiftType::MorningHalf,
        'start_time' => now(),
        'start_km' => 1000.0,
    ]);

    $customer = Customer::create(['code' => 'CUST-01', 'name' => 'Công ty Test']);

    // Chuyến đi đã đảo lái
    $trip = Trip::create([
        'trip_code' => 'TRIP-SWAP',
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'shift_id' => $shift->id,
        'status' => TripStatus::DriverSwap,
        'start_km' => 1000.0,
        'end_km' => 1050.0,
        'started_at' => now(),
    ]);

    Order::create([
        'area_id' => $this->area->id,
        'created_by' => $this->driver->id,
        'order_code' => 'ORD-SWAP',
        'trip_id' => $trip->id,
        'customer_id' => $customer->id,
        'cargo_name' => 'Hàng đảo lái',
        'cargo_type' => CargoType::Gcr,
        'type' => OrderType::Hhhk,
        'priority' => Priority::Medium,
        'status' => OrderStatus::DriverSwap,
    ]);

    // Đã có checkpoint driver_swap
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'shift_id' => $shift->id,
        'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::DriverSwap,
        'km_reading' => 1050.0,
        'occurred_at' => now()->copy()->addHours(2),
    ]);

    Carbon::setTestNow('2026-10-21 20:10:00');
    $this->artisan('shifts:auto-end')->assertSuccessful();

    // Vẫn được tự out ca như thường vì đã bàn giao đảo lái
    $shift->refresh();
    expect($shift->end_time)->not->toBeNull();
    expect((float) $shift->end_km)->toBe(1050.0);
    // Link shift_id của trip DriverSwap được dọn dẹp về null
    expect($trip->fresh()->shift_id)->toBeNull();
});

test('it resolves end_km prioritizing End checkpoint or completed trip km', function () {
    Carbon::setTestNow('2026-10-21 08:00:00');

    $shift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'shift_type' => ShiftType::MorningHalf,
        'start_time' => now(),
        'start_km' => 1000.0,
    ]);

    $customer = Customer::create(['code' => 'CUST-01', 'name' => 'Công ty Test']);

    $trip = Trip::create([
        'trip_code' => 'TRIP-DONE',
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'shift_id' => $shift->id,
        'status' => TripStatus::Completed,
        'start_km' => 1000.0,
        'end_km' => 1080.0,
        'total_km' => 80.0,
        'started_at' => now(),
        'completed_at' => now()->copy()->addHours(4),
    ]);

    Order::create([
        'area_id' => $this->area->id,
        'created_by' => $this->driver->id,
        'order_code' => 'ORD-DONE',
        'trip_id' => $trip->id,
        'customer_id' => $customer->id,
        'cargo_name' => 'Hàng hoàn thành',
        'cargo_type' => CargoType::Gcr,
        'type' => OrderType::Hhhk,
        'priority' => Priority::Medium,
        'status' => OrderStatus::Completed,
    ]);

    // Tài xế quên bấm rời xe -> Không có checkpoint 'end'
    Carbon::setTestNow('2026-10-21 20:10:00');
    $this->artisan('shifts:auto-end')->assertSuccessful();

    $shift->refresh();
    expect($shift->end_time)->not->toBeNull();
    // Lấy end_km từ chuyến hoàn thành cuối cùng (1080.0)
    expect((float) $shift->end_km)->toBe(1080.0);
    expect((float) $shift->total_km)->toBe(80.0);

    // Hệ thống tự tạo checkpoint End
    $endCheckpoint = TripCheckpoint::where('shift_id', $shift->id)
        ->where('checkpoint_type', CheckpointType::End)
        ->first();
    expect($endCheckpoint)->not->toBeNull();
    expect((float) $endCheckpoint->km_reading)->toBe(1080.0);
});

test('it does not modify shifts that driver already manually ended', function () {
    Carbon::setTestNow('2026-10-21 08:00:00');

    $manualEndTime = Carbon::parse('2026-10-21 18:30:00');

    $shift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'shift_type' => ShiftType::MorningHalf,
        'start_time' => now(),
        'end_time' => $manualEndTime,
        'start_km' => 1000.0,
        'end_km' => 1040.0,
        'total_km' => 40.0,
    ]);

    Carbon::setTestNow('2026-10-21 20:10:00');
    $this->artisan('shifts:auto-end')->assertSuccessful();

    $shift->refresh();
    // Giữ nguyên giờ và km tài xế đã bấm
    expect($shift->end_time->format('Y-m-d H:i:s'))->toBe('2026-10-21 18:30:00');
    expect((float) $shift->end_km)->toBe(1040.0);
});

test('it does not persist changes when running in dry-run mode', function () {
    Carbon::setTestNow('2026-10-21 08:00:00');

    $shift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'shift_type' => ShiftType::MorningHalf,
        'start_time' => now(),
        'start_km' => 1000.0,
    ]);

    Carbon::setTestNow('2026-10-21 20:10:00');
    $this->artisan('shifts:auto-end', ['--dry-run' => true])
        ->expectsOutputToContain('Chế độ DRY-RUN: không có thay đổi nào được lưu.')
        ->assertSuccessful();

    // Trong DB vẫn là null (không đổi)
    $shift->refresh();
    expect($shift->end_time)->toBeNull();
});
