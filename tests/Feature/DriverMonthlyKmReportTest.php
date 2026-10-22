<?php

use App\Enums\CheckpointType;
use App\Enums\DriverSwapReason;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleType;
use App\Filament\Pages\DriverMonthlyKmReport;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\DriverSwap;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DriverMonthlyKmReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);
    Role::create(['name' => 'driver', 'guard_name' => 'web']);

    $this->driverA = User::factory()->create(['name' => 'Tài xế A']);
    $this->driverB = User::factory()->create(['name' => 'Tài xế B']);
    $this->idleDriver = User::factory()->create(['name' => 'Tài xế Nghỉ']);
    collect([$this->driverA, $this->driverB, $this->idleDriver])->each->assignRole('driver');

    $this->vehicle = Vehicle::create([
        'plate_number' => '29C-MONTH',
        'owner' => 'ASGL',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'is_active' => true,
    ]);

    $this->month = Carbon::parse('2026-10-01');
});

function monthlyShift(User $driver, bool $ended = true): DriverShift
{
    return DriverShift::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::Full,
        'start_km' => 1000,
        'start_time' => Carbon::parse('2026-10-01 06:00'),
        'end_time' => $ended ? Carbon::parse('2026-10-01 18:00') : null,
    ]);
}

function monthlyTrip(object $test, User $driver, string $startedAt, float $total, float $loaded, TripStatus $status = TripStatus::Completed, ?DriverShift $shift = null): Trip
{
    return Trip::create([
        'trip_code' => 'CD-'.fake()->unique()->numerify('####'),
        'vehicle_id' => $test->vehicle->id,
        'driver_id' => $driver->id,
        'shift_id' => ($shift ?? monthlyShift($driver))->id,
        'status' => $status,
        'started_at' => Carbon::parse($startedAt),
        'completed_at' => Carbon::parse($startedAt)->addHour(),
        'start_km' => 1000,
        'end_km' => 1000 + $total,
        'total_km' => $total,
        'total_km_loaded' => $loaded,
        'total_km_empty' => $total - $loaded,
    ]);
}

/**
 * Chuyến 2.000 → 2.100: A bốc hàng ở 2.010, bàn giao cho B ở 2.060, B giao xong ở 2.080.
 * A: 60 km (50 có hàng), B: 40 km (20 có hàng).
 */
function monthlySwapTrip(object $test, bool $driverBShiftEnded = true): Trip
{
    $shiftA = monthlyShift($test->driverA);
    $shiftB = monthlyShift($test->driverB, $driverBShiftEnded);

    $trip = Trip::create([
        'trip_code' => 'CD-SWAP',
        'vehicle_id' => $test->vehicle->id,
        'driver_id' => $test->driverB->id,
        'shift_id' => $shiftB->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-10-15 08:00'),
        'completed_at' => Carbon::parse('2026-10-15 12:00'),
        'start_km' => 2000,
        'end_km' => 2100,
        'total_km' => 100,
        'total_km_loaded' => 70,
        'total_km_empty' => 30,
    ]);

    $area = Area::create(['type' => OrderType::Hhhk, 'code' => 'MON', 'name' => 'Month']);
    $customer = Customer::create(['code' => 'MON', 'name' => 'Month', 'is_active' => true]);
    $order = Order::create([
        'order_code' => 'ORD-MON',
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Completed,
        'created_by' => $test->driverA->id,
    ]);

    $shifts = [$test->driverA->id => $shiftA->id, $test->driverB->id => $shiftB->id];
    $checkpoint = fn (CheckpointType $type, float $km, User $driver, int $minute, bool $withOrder = false): TripCheckpoint => TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $withOrder ? $order->id : null,
        'driver_id' => $driver->id,
        'shift_id' => $shifts[$driver->id],
        'checkpoint_type' => $type,
        'km_reading' => $km,
        'occurred_at' => Carbon::parse('2026-10-15 08:00')->addMinutes($minute),
    ]);

    $checkpoint(CheckpointType::Started, 2000, $test->driverA, 0);
    $checkpoint(CheckpointType::ArrivedPickup, 2010, $test->driverA, 10, withOrder: true);
    $swapCheckpoint = $checkpoint(CheckpointType::DriverSwap, 2060, $test->driverA, 60);
    $checkpoint(CheckpointType::Completed, 2080, $test->driverB, 80, withOrder: true);
    $checkpoint(CheckpointType::End, 2100, $test->driverB, 100);

    DriverSwap::create([
        'trip_id' => $trip->id,
        'checkpoint_id' => $swapCheckpoint->id,
        'from_driver_id' => $test->driverA->id,
        'to_driver_id' => $test->driverB->id,
        'from_shift_id' => $shiftA->id,
        'to_shift_id' => $shiftB->id,
        'handover_km' => 2060,
        'reason' => DriverSwapReason::CargoNotUnloaded,
        'created_by' => $test->driverA->id,
    ]);

    return $trip;
}

test('sums completed trips of the month per driver', function () {
    monthlyTrip($this, $this->driverA, '2026-10-02 08:00', 100, 60);
    monthlyTrip($this, $this->driverA, '2026-10-31 22:00', 50, 10);
    monthlyTrip($this, $this->driverB, '2026-10-10 08:00', 80, 80);

    $rows = app(DriverMonthlyKmReportService::class)->driverRows($this->month, $this->month->copy()->endOfMonth())->keyBy('driver_id');

    expect($rows[$this->driverA->id])->toMatchArray(['trip_count' => 2, 'km_total' => 150.0, 'km_loaded' => 70.0, 'km_empty' => 80.0])
        ->and($rows[$this->driverB->id])->toMatchArray(['trip_count' => 1, 'km_total' => 80.0, 'km_loaded' => 80.0, 'km_empty' => 0.0]);
});

test('ignores trips that are not completed or fall outside the month', function () {
    monthlyTrip($this, $this->driverA, '2026-10-05 08:00', 40, 20);
    monthlyTrip($this, $this->driverA, '2026-10-06 08:00', 99, 99, TripStatus::Cancelled);
    monthlyTrip($this, $this->driverA, '2026-10-07 08:00', 99, 99, TripStatus::Delivering);
    monthlyTrip($this, $this->driverA, '2026-09-30 23:00', 99, 99);
    monthlyTrip($this, $this->driverA, '2026-11-01 00:00', 99, 99);

    $row = app(DriverMonthlyKmReportService::class)->driverRows($this->month, $this->month->copy()->endOfMonth())->firstWhere('driver_id', $this->driverA->id);

    expect($row)->toMatchArray(['trip_count' => 1, 'km_total' => 40.0, 'km_loaded' => 20.0]);
});

test('splits a swapped trip between drivers and counts it for each of them', function () {
    monthlySwapTrip($this);

    $rows = app(DriverMonthlyKmReportService::class)->driverRows($this->month, $this->month->copy()->endOfMonth())->keyBy('driver_id');

    expect($rows[$this->driverA->id])->toMatchArray(['trip_count' => 1, 'km_total' => 60.0, 'km_loaded' => 50.0, 'km_empty' => 10.0])
        ->and($rows[$this->driverB->id])->toMatchArray(['trip_count' => 1, 'km_total' => 40.0, 'km_loaded' => 20.0, 'km_empty' => 20.0]);
});

test('skips trips whose shift has not ended yet', function () {
    monthlyTrip($this, $this->driverA, '2026-10-02 08:00', 100, 60);
    monthlyTrip($this, $this->driverA, '2026-10-03 08:00', 77, 7, shift: monthlyShift($this->driverA, ended: false));

    $row = app(DriverMonthlyKmReportService::class)->driverRows($this->month, $this->month->copy()->endOfMonth())->firstWhere('driver_id', $this->driverA->id);

    expect($row)->toMatchArray(['trip_count' => 1, 'km_total' => 100.0, 'km_loaded' => 60.0]);
});

test('counts only the swap segments whose shift has ended', function () {
    monthlySwapTrip($this, driverBShiftEnded: false);

    $rows = app(DriverMonthlyKmReportService::class)->driverRows($this->month, $this->month->copy()->endOfMonth())->keyBy('driver_id');

    expect($rows[$this->driverA->id])->toMatchArray(['trip_count' => 1, 'km_total' => 60.0, 'km_loaded' => 50.0])
        ->and($rows[$this->driverB->id])->toMatchArray(['trip_count' => 0, 'km_total' => 0.0]);
});

test('lists active drivers without trips with zero km', function () {
    monthlyTrip($this, $this->driverA, '2026-10-02 08:00', 100, 60);

    $row = app(DriverMonthlyKmReportService::class)->driverRows($this->month, $this->month->copy()->endOfMonth())->firstWhere('driver_id', $this->idleDriver->id);

    expect($row)->toMatchArray(['trip_count' => 0, 'km_total' => 0.0, 'km_loaded' => 0.0, 'km_empty' => 0.0]);
});

test('limits trips to the requested date range', function () {
    monthlyTrip($this, $this->driverA, '2026-10-02 08:00', 100, 60);
    monthlyTrip($this, $this->driverA, '2026-10-03 08:00', 30, 10);

    $row = app(DriverMonthlyKmReportService::class)
        ->driverRows(Carbon::parse('2026-10-02')->startOfDay(), Carbon::parse('2026-10-02')->endOfDay())
        ->firstWhere('driver_id', $this->driverA->id);

    expect($row)->toMatchArray(['trip_count' => 1, 'km_total' => 100.0]);
});

test('page resolves today, week and month periods from the anchor date', function (string $period, string $from, string $to) {
    $this->actingAs($this->driverA);

    $range = Livewire::withQueryParams(['period' => $period, 'date' => '2026-10-22'])
        ->test(DriverMonthlyKmReport::class)
        ->instance()
        ->periodRange();

    expect($range[0]->toDateTimeString())->toBe($from)
        ->and($range[1]->toDateTimeString())->toBe($to);
})->with([
    'today' => ['today', '2026-10-22 00:00:00', '2026-10-22 23:59:59'],
    'week' => ['week', '2026-10-19 00:00:00', '2026-10-25 23:59:59'],
    'month' => ['month', '2026-10-01 00:00:00', '2026-10-31 23:59:59'],
]);

test('page lists drivers and shows the trips of the selected driver', function () {
    $this->actingAs($this->driverA);
    $trip = monthlyTrip($this, $this->driverA, '2026-10-02 08:00', 123, 100);
    monthlyTrip($this, $this->driverB, '2026-10-03 08:00', 10, 0);

    Livewire::withQueryParams(['period' => 'month', 'date' => '2026-10-15'])
        ->test(DriverMonthlyKmReport::class)
        ->assertSuccessful()
        ->assertSee('Tài xế A')
        ->assertSee('Tài xế Nghỉ')
        ->assertDontSee($trip->trip_code)
        ->call('selectDriver', $this->driverA->id)
        ->assertSee([$trip->trip_code, '123.0', '1 chuyến']);
});
