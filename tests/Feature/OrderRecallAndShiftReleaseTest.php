<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Orders\Actions\UnsendOrderAction;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Order;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Shift\AutoEndShiftService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);
    $this->admin = User::factory()->create();
    $this->actingAs($this->admin);
    $this->driver = User::factory()->create();
    $this->vehicle = Vehicle::create(['plate_number' => '29C-RECALL', 'vehicle_type' => VehicleType::Normal, 'owner' => 'CLC', 'is_active' => true, 'status' => VehicleStatus::Running, 'type' => VehicleOwnerType::Company, 'current_mileage' => 1000]);
    $this->area = Area::create(['code' => 'NBA', 'name' => 'NBA', 'is_active' => true]);
    $this->customer = Customer::create(['code' => 'C', 'name' => 'C']);
    $this->makeOrder = function (TripStatus $tripStatus, ?int $shiftId = null): Order {
        $trip = Trip::create(['trip_code' => 'T-'.fake()->unique()->numberBetween(1000, 9999), 'vehicle_id' => $this->vehicle->id, 'driver_id' => $this->driver->id, 'shift_id' => $shiftId, 'status' => $tripStatus]);

        return Order::create(['order_code' => 'ASG-'.fake()->unique()->numberBetween(1000, 9999), 'type' => OrderType::Hhhk, 'area_id' => $this->area->id, 'customer_id' => $this->customer->id, 'trip_id' => $trip->id, 'status' => OrderStatus::Sent, 'sent_at' => now(), 'created_by' => $this->admin->id]);
    };
});

test('a sent order can be recalled only while its trip is still waiting', function (TripStatus $tripStatus, bool $expected) {
    $order = ($this->makeOrder)($tripStatus);

    expect(UnsendOrderAction::canRecall($order))->toBe($expected);
})->with([
    'trip waiting' => [TripStatus::Pending, true],
    'trip started' => [TripStatus::Started, false],
    'driver at pickup' => [TripStatus::ArrivedPickup, false],
]);

test('recalling removes the empty trip and frees the vehicle', function () {
    $order = ($this->makeOrder)(TripStatus::Pending);
    $tripId = $order->trip_id;

    $action = UnsendOrderAction::make();
    $action->record($order);
    $action->call();

    expect($order->fresh()->status)->toBe(OrderStatus::Draft)
        ->and($order->fresh()->trip_id)->toBeNull()
        ->and(Trip::query()->find($tripId))->toBeNull()
        ->and($this->vehicle->fresh()->status)->toBe(VehicleStatus::On);
});

test('the order list offers recall and no longer offers cancel order', function () {
    $order = ($this->makeOrder)(TripStatus::Pending);

    Livewire::test(ListOrders::class, ['showMineOnly' => false, 'activePlaceFilter' => 'all', 'activeOrderTypeFilter' => 'all'])
        ->loadTable()
        ->assertTableActionVisible('unsend_order', $order)
        ->assertTableActionDoesNotExist('cancel_order');
});

test('auto shift end releases waiting trips so the next shift can pick them up', function () {
    Carbon::setTestNow('2026-10-21 08:30:00');
    $shift = DriverShift::create(['driver_id' => $this->driver->id, 'shift_type' => ShiftType::MorningHalf, 'start_time' => now(), 'start_km' => 1000]);
    $waiting = ($this->makeOrder)(TripStatus::Pending, $shift->id)->trip;

    app(AutoEndShiftService::class)->autoEndEligibleShifts(Carbon::parse('2026-10-21 20:05:00'));

    expect($shift->fresh()->end_time)->not->toBeNull()
        ->and($waiting->fresh()->shift_id)->toBeNull();

    Carbon::setTestNow();
});
