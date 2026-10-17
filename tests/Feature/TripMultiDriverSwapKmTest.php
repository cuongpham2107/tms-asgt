<?php

use App\Enums\CheckpointType;
use App\Enums\DriverSwapReason;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
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
use App\Services\ShiftKmCalculatorService;
use App\Services\TripKmSplitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * Chuyến 1.000 → 1.300 km, 1 đơn: bốc ở 1.010, giao xong ở 1.150.
 * 5 lần bàn giao: A→B (1.050), B→C (1.100), C→A (1.200), A→D (1.230), D→E (1.260).
 *
 * Mỗi lần bàn giao giống luồng thật "Bàn giao chuyến chưa xong" + điều hành gán lại:
 * mốc driver_swap giữ km/giờ (driver_id = người bấm bàn giao), bản ghi driver_swaps giữ ai → ai
 * và trỏ về mốc qua checkpoint_id.
 */
beforeEach(function () {
    $this->drivers = collect(['A', 'B', 'C', 'D', 'E'])
        ->mapWithKeys(fn (string $name): array => [$name => User::factory()->create(['name' => "Driver {$name}"])]);

    $this->shifts = $this->drivers->map(fn (User $driver): DriverShift => DriverShift::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::Full,
        'start_km' => 1000,
        'start_time' => now()->subHours(12),
    ]));

    $vehicle = Vehicle::create([
        'plate_number' => 'SWAP5-001',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 1300,
    ]);

    $this->trip = Trip::create([
        'trip_code' => 'TRIP-SWAP5',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $this->drivers['E']->id,
        'shift_id' => $this->shifts['A']->id,
        'status' => TripStatus::Completed,
        'start_km' => 1000,
        'end_km' => 1300,
        'started_at' => now()->subHours(10),
    ]);

    $area = Area::create(['type' => OrderType::Hhhk, 'code' => 'SWAP5', 'name' => 'Swap 5']);
    $customer = Customer::create(['code' => 'SWAP5', 'name' => 'Swap 5', 'is_active' => true]);
    $this->order = Order::create([
        'order_code' => 'ORD-SWAP5',
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'trip_id' => $this->trip->id,
        'status' => OrderStatus::Completed,
        'created_by' => $this->drivers['A']->id,
    ]);

    $this->tripStart = now()->subHours(10);
    $this->handovers = [];

    swapTestCheckpoint($this, CheckpointType::Started, 1000, 'A', 0);
    swapTestCheckpoint($this, CheckpointType::ArrivedPickup, 1010, 'A', 10, withOrder: true);
    swapTestCheckpoint($this, CheckpointType::LeftPickup, 1012, 'A', 12, withOrder: true);
    $this->handovers['A->B'] = swapTestHandover($this, 'A', 'B', 1050, 50);
    $this->handovers['B->C'] = swapTestHandover($this, 'B', 'C', 1100, 100);
    swapTestCheckpoint($this, CheckpointType::ArrivedDelivery, 1145, 'C', 145, withOrder: true);
    swapTestCheckpoint($this, CheckpointType::Completed, 1150, 'C', 150, withOrder: true);
    $this->handovers['C->A'] = swapTestHandover($this, 'C', 'A', 1200, 200);
    $this->handovers['A->D'] = swapTestHandover($this, 'A', 'D', 1230, 230);
    $this->handovers['D->E'] = swapTestHandover($this, 'D', 'E', 1260, 260);
    swapTestCheckpoint($this, CheckpointType::End, 1300, 'E', 300);
});

/**
 * Tạo một mốc hành trình do tài xế $driver nhập ở phút thứ $minute của chuyến.
 */
function swapTestCheckpoint(object $test, CheckpointType $type, float $km, string $driver, int $minute, bool $withOrder = false): TripCheckpoint
{
    return TripCheckpoint::create([
        'trip_id' => $test->trip->id,
        'order_id' => $withOrder ? $test->order->id : null,
        'driver_id' => $test->drivers[$driver]->id,
        'shift_id' => $test->shifts[$driver]->id,
        'checkpoint_type' => $type,
        'km_reading' => $km,
        'occurred_at' => $test->tripStart->copy()->addMinutes($minute),
    ]);
}

/**
 * @return array<string, array{total: float, loaded: float, empty: float}>
 */
function swapTestKmByDriver(object $test): array
{
    $trip = $test->trip->fresh();

    return $test->drivers->map(function (User $driver) use ($trip): array {
        $km = TripKmSplitService::driverKm($trip, $driver->id);

        return [
            'total' => $km['total_km'],
            'loaded' => $km['total_km_loaded'],
            'empty' => $km['total_km_empty'],
        ];
    })->all();
}

/**
 * @return array<string, array{total: float, loaded: float, empty: float}>
 */
function swapTestExpected(array $rows): array
{
    return collect($rows)->map(fn (array $row): array => [
        'total' => (float) $row[0],
        'loaded' => (float) $row[1],
        'empty' => (float) ($row[0] - $row[1]),
    ])->all();
}

/**
 * Bàn giao từ $from sang $to: mốc driver_swap (km/giờ) + bản ghi driver_swaps (ai → ai) trỏ về mốc.
 */
function swapTestHandover(object $test, string $from, string $to, float $km, int $minute): TripCheckpoint
{
    $checkpoint = swapTestCheckpoint($test, CheckpointType::DriverSwap, $km, $from, $minute);

    DriverSwap::create([
        'trip_id' => $test->trip->id,
        'checkpoint_id' => $checkpoint->id,
        'from_driver_id' => $test->drivers[$from]->id,
        'to_driver_id' => $test->drivers[$to]->id,
        'from_shift_id' => $test->shifts[$from]->id,
        'to_shift_id' => $test->shifts[$to]->id,
        'handover_km' => $km,
        'reason' => DriverSwapReason::CargoNotUnloaded,
        'created_by' => $test->drivers[$from]->id,
    ]);

    return $checkpoint;
}

test('splits km per driver across five handovers', function () {
    $byDriver = swapTestKmByDriver($this);

    expect($byDriver)->toEqual(swapTestExpected([
        'A' => [80, 40],
        'B' => [50, 50],
        'C' => [100, 50],
        'D' => [30, 0],
        'E' => [40, 0],
    ]))->and(array_sum(array_column($byDriver, 'total')))->toEqual(300.0);
});

test('correcting a handover checkpoint km moves the split without creating phantom swaps', function () {
    $this->handovers['B->C']->update(['km_reading' => 1105]);

    $byDriver = swapTestKmByDriver($this);

    expect($byDriver)->toEqual(swapTestExpected([
        'A' => [80, 40],
        'B' => [55, 55],
        'C' => [95, 45],
        'D' => [30, 0],
        'E' => [40, 0],
    ]))
        ->and(array_sum(array_column($byDriver, 'total')))->toEqual(300.0)
        ->and(DriverSwap::where('trip_id', $this->trip->id)->count())->toBe(5);
});

test('handover km comes from the checkpoint, not from a stale km on the swap record', function () {
    // Điều hành từng gõ km bàn giao lần 2 khác km tài xế đã nhập trên mốc
    DriverSwap::query()
        ->where('trip_id', $this->trip->id)
        ->where('from_driver_id', $this->drivers['A']->id)
        ->where('to_driver_id', $this->drivers['D']->id)
        ->update(['handover_km' => 1235]);

    expect(swapTestKmByDriver($this))->toEqual(swapTestExpected([
        'A' => [80, 40],
        'B' => [50, 50],
        'C' => [100, 50],
        'D' => [30, 0],
        'E' => [40, 0],
    ]));
});

test('shift km of each driver follows a corrected handover checkpoint', function () {
    $this->handovers['B->C']->update(['km_reading' => 1105]);

    app(ShiftKmCalculatorService::class)->calculateForTrip($this->trip->fresh());

    $shiftKm = $this->shifts->map(fn (DriverShift $shift): array => [
        (float) $shift->fresh()->total_km,
        (float) $shift->fresh()->total_km_loaded,
    ])->all();

    expect($shiftKm)->toEqual([
        'A' => [80.0, 40.0],
        'B' => [55.0, 55.0],
        'C' => [95.0, 45.0],
        'D' => [30.0, 0.0],
        'E' => [40.0, 0.0],
    ]);
});

test('swap record links to its handover checkpoint', function () {
    $swap = DriverSwap::query()
        ->where('trip_id', $this->trip->id)
        ->where('from_driver_id', $this->drivers['B']->id)
        ->first();

    expect($swap->checkpoint)->not->toBeNull()
        ->and($swap->checkpoint->is($this->handovers['B->C']))->toBeTrue();
});

test('legacy swap without checkpoint is ordered by its UTC created_at converted to app time', function () {
    $trip = $this->trip;
    DriverSwap::where('trip_id', $trip->id)->delete();
    TripCheckpoint::where('trip_id', $trip->id)->where('checkpoint_type', CheckpointType::DriverSwap)->delete();

    // Mốc bàn giao B->C ở phút 120, có swap gắn mốc
    $linked = swapTestCheckpoint($this, CheckpointType::DriverSwap, 1070, 'B', 120);
    $swapAttributes = fn (string $from, string $to, float $km): array => [
        'trip_id' => $trip->id,
        'from_driver_id' => $this->drivers[$from]->id,
        'to_driver_id' => $this->drivers[$to]->id,
        'from_shift_id' => $this->shifts[$from]->id,
        'to_shift_id' => $this->shifts[$to]->id,
        'handover_km' => $km,
        'reason' => DriverSwapReason::CargoNotUnloaded,
        'created_by' => $this->drivers[$from]->id,
    ];
    DriverSwap::create($swapAttributes('B', 'C', 1070) + ['checkpoint_id' => $linked->id]);

    // Swap cũ C->D không có mốc: thực tế xảy ra ở phút 180 (sau mốc trên) nhưng DB lưu UTC,
    // nên số giờ ghi trong created_at nhỏ hơn giờ app 7 tiếng. Nếu hiểu nhầm là giờ app nó sẽ bị xếp trước mốc phút 120.
    $legacy = DriverSwap::create($swapAttributes('C', 'D', 1090));
    DB::table('driver_swaps')->where('id', $legacy->id)->update([
        'created_at' => $this->tripStart->copy()->addMinutes(180)->setTimezone('UTC')->format('Y-m-d H:i:s'),
    ]);

    $segments = TripKmSplitService::segments($trip->fresh());

    expect(array_map(fn (array $s): array => [$s[0], $s[1], $s[3]], $segments))->toEqual([
        [1000.0, 1070.0, $this->drivers['B']->id],
        [1070.0, 1090.0, $this->drivers['C']->id],
        [1090.0, 1300.0, $this->drivers['D']->id],
    ]);
});

test('editing one handover row updates its companion rows of the same handover', function () {
    $secondOrder = Order::create([
        'order_code' => 'ORD-SWAP5-B',
        'type' => OrderType::Hhhk,
        'area_id' => $this->order->area_id,
        'customer_id' => $this->order->customer_id,
        'trip_id' => $this->trip->id,
        'status' => OrderStatus::Completed,
        'created_by' => $this->drivers['A']->id,
    ]);

    $companion = TripCheckpoint::create([
        ...$this->handovers['B->C']->only(['trip_id', 'driver_id', 'shift_id', 'checkpoint_type', 'km_reading', 'occurred_at']),
        'order_id' => $secondOrder->id,
    ]);

    $this->handovers['B->C']->update(['km_reading' => 1105]);

    expect((float) $companion->fresh()->km_reading)->toBe(1105.0);
});
