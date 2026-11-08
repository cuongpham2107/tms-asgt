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
use App\Filament\Widgets\DriverMonthlyKmTable;
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
use Livewire\Features\SupportTesting\Testable;
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

test('counts finished trips like the driver app and skips unfinished or out of range ones', function () {
    monthlyTrip($this, $this->driverA, '2026-10-05 08:00', 40, 20);
    monthlyTrip($this, $this->driverA, '2026-10-06 08:00', 30, 10, TripStatus::Cancelled);
    monthlyTrip($this, $this->driverA, '2026-10-06 12:00', 25, 5, TripStatus::DriverSwap);
    monthlyTrip($this, $this->driverA, '2026-10-07 08:00', 99, 99, TripStatus::Delivering);
    monthlyTrip($this, $this->driverA, '2026-09-30 23:00', 99, 99);
    monthlyTrip($this, $this->driverA, '2026-11-01 00:00', 99, 99);

    $service = app(DriverMonthlyKmReportService::class);
    $lines = $service->tripLines($this->month, $this->month->copy()->endOfMonth());
    $row = $service->summarize($lines)->firstWhere('driver_id', $this->driverA->id);

    // Hoàn thành + huỷ (xe đã chạy) + đảo lái đều được cộng km, như màn thống kê của app lái xe
    expect($row)->toMatchArray(['trip_count' => 3, 'km_total' => 95.0, 'km_loaded' => 35.0])
        ->and($lines->pluck('status')->map->value->all())->toBe(['completed', 'cancelled', 'driver_swap']);
});

test('lists the order code of each trip and ignores draft orders', function () {
    $area = Area::create(['type' => OrderType::Hhhk, 'code' => 'OC', 'name' => 'Order codes']);
    $customer = Customer::create(['code' => 'OC', 'name' => 'Order codes', 'is_active' => true]);
    $trips = [];
    foreach (['ASG-101' => OrderStatus::Completed, 'ASG-999' => OrderStatus::Draft] as $code => $status) {
        $trips[$code] = monthlyTrip($this, $this->driverA, '2026-10-02 08:00', 50, 40);
        Order::create([
            'order_code' => $code,
            'type' => OrderType::Hhhk,
            'area_id' => $area->id,
            'customer_id' => $customer->id,
            'trip_id' => $trips[$code]->id,
            'status' => $status,
            'created_by' => $this->driverA->id,
        ]);
    }

    $lines = app(DriverMonthlyKmReportService::class)
        ->tripLines($this->month, $this->month->copy()->endOfMonth())
        ->keyBy('trip_id');

    expect($lines[$trips['ASG-101']->id]['order_codes'])->toBe(['ASG-101'])
        ->and($lines[$trips['ASG-101']->id]['is_empty'])->toBeFalse()
        ->and($lines[$trips['ASG-999']->id]['order_codes'])->toBe([]);
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

test('counts trips without orders as empty trips', function () {
    monthlyTrip($this, $this->driverA, '2026-10-02 08:00', 100, 60);
    monthlyTrip($this, $this->driverA, '2026-10-03 08:00', 30, 0)->update(['is_empty_run' => true]);
    monthlySwapTrip($this);

    $service = app(DriverMonthlyKmReportService::class);
    $lines = $service->tripLines($this->month, $this->month->copy()->endOfMonth());
    $rows = $service->summarize($lines)->keyBy('driver_id');

    // Hai chuyến đầu không gắn đơn → không hàng; chuyến đảo lái có đơn → có hàng
    expect($lines->where('driver_id', $this->driverA->id)->pluck('is_empty', 'trip_code')->values()->all())->toBe([true, true, false])
        ->and($rows[$this->driverA->id])->toMatchArray(['trip_count' => 3, 'empty_trip_count' => 2])
        ->and($rows[$this->driverB->id])->toMatchArray(['trip_count' => 1, 'empty_trip_count' => 0]);
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

test('page renders the react report widget for the selected period', function () {
    $this->actingAs($this->driverA);

    Livewire::withQueryParams(['period' => 'month', 'date' => '2026-10-15'])
        ->test(DriverMonthlyKmReport::class)
        ->assertSuccessful()
        ->assertSeeLivewire(DriverMonthlyKmTable::class);
});

test('report widget lists drivers with their trips of the period', function () {
    $trip = monthlyTrip($this, $this->driverA, '2026-10-02 08:00', 123, 100);
    monthlyTrip($this, $this->driverB, '2026-10-03 08:00', 10, 0);

    $config = monthlyKmWidget()->instance()->getRendererConfiguration();
    $driver = collect($config['drivers'])->firstWhere('id', $this->driverA->id);
    $tripLine = collect($config['trips'])->firstWhere('id', $trip->id);

    expect($config)->toMatchArray(['period' => 'month', 'date' => '2026-10-15', 'periodLabel' => 'Tháng 10/2026', 'isCurrent' => false, 'hasNext' => true])
        ->and(collect($config['drivers'])->pluck('name'))->toContain('Tài xế A', 'Tài xế Nghỉ')
        ->and($driver)->toMatchArray(['trips' => 1, 'emptyTrips' => 1, 'kmTotal' => 123.0, 'kmLoaded' => 100.0])
        ->and($tripLine)->toMatchArray(['driverId' => $this->driverA->id, 'code' => $trip->trip_code, 'isEmpty' => true, 'day' => '2026-10-02', 'time' => '08:00'])
        ->and($config['totals']['trips'])->toBe(2);
});

test('statement rows list completed trips with start and end times from checkpoints and no charge data', function () {
    $trip = monthlySwapTrip($this);
    monthlyTrip($this, $this->driverA, '2026-10-03 08:00', 10, 0);
    monthlyTrip($this, $this->driverA, '2026-10-04 08:00', 10, 0, TripStatus::Cancelled);
    monthlyTrip($this, $this->driverA, '2026-11-02 08:00', 10, 0);

    $service = app(DriverMonthlyKmReportService::class);
    $rows = $service->statementRows($this->month, $this->month->copy()->endOfMonth());
    $row = $rows->firstWhere('trip_id', $trip->id);

    expect($rows)->toHaveCount(2)
        ->and($row['code'])->toBe('ORD-MON')
        ->and($row['trip_started_at']->format('Y-m-d H:i'))->toBe('2026-10-15 08:00')
        ->and($row['trip_ended_at']->format('Y-m-d H:i'))->toBe('2026-10-15 09:40')
        ->and(array_keys($row))->not->toContain('charge', 'total', 'base_price')
        ->and($service->statementRows($this->month, $this->month->copy()->endOfMonth(), $this->driverB->id)->pluck('trip_id')->all())->toBe([$trip->id]);
});

test('report widget loads the read-only statement of the period on demand', function () {
    $trip = monthlySwapTrip($this);
    foreach (range(1, 26) as $day) {
        monthlyTrip($this, $this->driverA, sprintf('2026-10-%02d 10:00', $day), 10, 0);
    }

    $rows = collect(monthlyKmWidget()->instance()->loadDetails('month', '2026-10-15')['statement']);
    $row = $rows->firstWhere('code', 'ORD-MON');

    // Cả kỳ trả về một lần, phân trang do React lo.
    expect($rows)->toHaveCount(27)
        ->and($row)->toMatchArray(['driverId' => $trip->driver_id, 'started' => '08:00 15/10', 'ended' => '09:40 15/10'])
        ->and(array_keys($row))->not->toContain('charge', 'total', 'base_price');
});

function monthlyKmWidget(): Testable
{
    return Livewire::test(DriverMonthlyKmTable::class, [
        'isReady' => true,
        'period' => 'month',
        'date' => '2026-10-15',
    ]);
}

test('report widget loads another period without re-rendering and falls back on bad input', function () {
    monthlyTrip($this, $this->driverA, '2026-10-02 08:00', 123, 100);
    $widget = monthlyKmWidget()->instance();

    $day = $widget->loadReport('today', '2026-10-02');
    $other = $widget->loadReport('today', '2026-10-03');
    $invalid = $widget->loadReport('year', 'not-a-date');

    expect($day)->toMatchArray(['periodLabel' => 'Ngày 02/10/2026'])
        ->and($day['totals']['trips'])->toBe(1)
        ->and($other['totals']['trips'])->toBe(0)
        ->and($invalid)->toMatchArray(['period' => 'month', 'date' => now()->toDateString(), 'isCurrent' => true, 'hasNext' => false]);
});

test('evaluation compares each driver with the route median and skips implausible trips', function () {
    $row = fn (int $tripId, int $driverId, ?int $orderId, string $journey, int $completion, ?int $loading, ?int $unloading): array => [
        'trip_id' => $tripId,
        'order_id' => $orderId,
        'driver_id' => $driverId,
        'driver_name' => "Tài xế {$driverId}",
        'code' => $orderId ? "ORD-{$orderId}" : "CD-{$tripId}",
        'journey' => $journey,
        'time_loading' => $loading,
        'time_unloading' => $unloading,
        'trip_started_at' => Carbon::parse('2026-10-02 08:00')->addDays($tripId),
        'trip_ended_at' => Carbon::parse('2026-10-02 08:00')->addDays($tripId)->addMinutes($completion),
    ];

    $evaluation = app(DriverMonthlyKmReportService::class)->evaluate(collect([
        $row(1, 1, 11, 'ALSC -> ASGL', 60, 10, 20),
        $row(2, 2, 21, 'ALSC -> ASGL', 120, 60, 30),
        $row(3, 2, 31, 'ALSC -> ASGL', 90, 40, 20),
        $row(4, 1, 41, 'ALSC -> ASGL', 2, 1, 1),
        $row(5, 1, 51, 'ALSC -> ASGL', 800, 30, 30),
        $row(6, 2, null, 'ASGL -> NBA', 45, null, null),
    ]));
    $drivers = collect($evaluation['drivers'])->keyBy('driver_id');
    $trips = collect($evaluation['trips'])->keyBy('trip_id');

    // Chuẩn tuyến ALSC -> ASGL = trung vị của 3 chuyến hợp lệ: hoàn thành 90', chờ 60'. Chuyến 2' và 800' bị loại.
    expect($trips[1])->toMatchArray(['completion' => 60, 'wait' => 30, 'route_completion' => 90.0, 'route_wait' => 60.0, 'route_samples' => 3, 'flag' => null])
        ->and($trips[4]['flag'])->toContain('dưới 5 phút')
        ->and($trips[5]['flag'])->toContain('quên bấm kết thúc')
        ->and($trips[5]['route_completion'])->toBeNull()
        ->and($trips[6])->toMatchArray(['is_empty' => true, 'wait' => null, 'route_completion' => null])
        ->and($drivers[1])->toMatchArray(['trip_count' => 1, 'flagged_count' => 2, 'avg_completion' => 60, 'completion_diff' => -30, 'completion_diff_percent' => -33, 'wait_diff' => -30, 'wait_diff_percent' => -50])
        ->and($drivers[2])->toMatchArray(['trip_count' => 3, 'flagged_count' => 0, 'avg_completion' => 85, 'completion_compared' => 2, 'completion_diff' => 15, 'completion_diff_percent' => 17, 'wait_diff' => 15, 'wait_diff_percent' => 25]);
});

test('report widget evaluation lists drivers and their trips of the period', function () {
    monthlySwapTrip($this);

    $evaluation = monthlyKmWidget()->instance()->loadDetails('month', '2026-10-15')['evaluation'];

    expect($evaluation['drivers'])->toHaveCount(1)
        ->and($evaluation['drivers'][0])->toMatchArray(['id' => $this->driverB->id, 'trips' => 1, 'flagged' => 0, 'completion' => 100, 'completionDiff' => null])
        ->and($evaluation['trips'][0])->toMatchArray(['codes' => ['ORD-MON'], 'isEmpty' => false, 'time' => '08:00', 'flag' => null, 'routeCompletion' => null]);
});

test('page opens the timeline popup of a clicked trip', function () {
    $this->actingAs($this->driverA);
    $trip = monthlyTrip($this, $this->driverA, '2026-10-02 08:00', 123, 100);

    Livewire::withQueryParams(['period' => 'month', 'date' => '2026-10-15'])
        ->test(DriverMonthlyKmReport::class)
        ->call('openTripTimeline', $trip->id)
        ->assertSet('timelineTripId', $trip->id)
        ->assertDispatched('open-modal', id: 'driver-trip-timeline')
        ->assertSee(['Hành trình', $trip->trip_code]);
});

test('report widget first render sends only the period so React loads the numbers itself', function () {
    $config = Livewire::test(DriverMonthlyKmTable::class, ['period' => 'month', 'date' => '2026-10-15'])
        ->instance()
        ->getRendererConfiguration();

    expect($config['loaded'])->toBeFalse()
        ->and($config['period'])->toBe('month')
        ->and($config['periodLabel'])->not->toBe('')
        ->and($config['drivers'])->toBe([])
        ->and($config['trips'])->toBe([]);
});
