<?php

use App\Enums\CheckpointType;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Orders\Actions\CancelOrderAction;
use App\Filament\Resources\Orders\Actions\UnsendOrderAction;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Trips\Actions\CancelTripAction;
use App\Filament\Resources\Trips\Actions\SendTripAction;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Trip\TripStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->admin = User::factory()->create();
    $this->actingAs($this->admin);

    $this->vehicle = Vehicle::create([
        'plate_number' => '51C-555.55',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::Running,
        'type' => VehicleOwnerType::Company,
    ]);

    $this->trip = Trip::create([
        'trip_code' => 'TRIP-ADMIN-1',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => User::factory()->create()->id,
        'status' => TripStatus::Pending,
    ]);

    $this->area = Area::create(['type' => OrderType::Hhhk, 'code' => 'ADM', 'name' => 'Admin Area']);
    $this->customer = Customer::create(['code' => 'CUST-ADM', 'name' => 'Admin Customer', 'is_active' => true]);

    $this->makeOrder = function (OrderStatus $status, ?Trip $trip = null): Order {
        $order = Order::create([
            'order_code' => 'ORD-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'type' => OrderType::Hhhk,
            'area_id' => $this->area->id,
            'customer_id' => $this->customer->id,
            'trip_id' => ($trip ?? $this->trip)->id,
            'pickup_address' => 'Pickup',
            'status' => $status,
            'created_by' => $this->admin->id,
        ]);

        OrderDeliveryPoint::create([
            'order_id' => $order->id,
            'sequence' => 1,
            'address' => 'Delivery',
            'status' => OrderDeliveryPointStatus::Pending,
        ]);

        return $order;
    };
});

test('cancel order is available for a sent order and cancelling the last open order cancels the trip', function () {
    $order = ($this->makeOrder)(OrderStatus::Sent);

    $action = CancelOrderAction::make()->record($order);
    expect($action->isVisible())->toBeTrue();

    $action->call(['data' => ['cancel_reason' => 'Khách huỷ']]);

    $this->trip->refresh();
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($this->trip->status)->toBe(TripStatus::Cancelled)
        ->and($this->trip->cancelled_at)->not->toBeNull()
        ->and($this->trip->checkpoints()->where('checkpoint_type', CheckpointType::Cancelled->value)->exists())->toBeTrue();
});

test('recall is available for a sent order before the trip leaves pickup and returns it to assigned', function () {
    $order = ($this->makeOrder)(OrderStatus::Sent);
    $order->update(['sent_at' => now()]);

    $action = UnsendOrderAction::make()->record($order);
    expect($action->isVisible())->toBeTrue();

    $action->call();

    expect($order->fresh()->status)->toBe(OrderStatus::Assigned)
        ->and($order->fresh()->sent_at)->toBeNull();
});

test('recall is hidden once the trip has left pickup', function () {
    $this->trip->update(['status' => TripStatus::Delivering]);
    $order = ($this->makeOrder)(OrderStatus::Sent);

    expect(UnsendOrderAction::make()->record($order)->isVisible())->toBeFalse();
});

test('send trip only sends assigned orders and leaves draft orders untouched', function () {
    $assigned = ($this->makeOrder)(OrderStatus::Assigned);
    $draft = ($this->makeOrder)(OrderStatus::Draft);

    SendTripAction::make()->record($this->trip)->call();

    expect($assigned->fresh()->status)->toBe(OrderStatus::Sent)
        ->and($assigned->fresh()->sent_at)->not->toBeNull()
        ->and($draft->fresh()->status)->toBe(OrderStatus::Draft);
});

test('cancel trip is hidden once the trip has arrived at delivery', function () {
    $this->trip->update(['status' => TripStatus::ArrivedDelivery]);

    expect(CancelTripAction::make()->record($this->trip)->isVisible())->toBeFalse();
});

test('replicating an order resets delivery point status to pending', function () {
    $order = ($this->makeOrder)(OrderStatus::Draft);
    $order->update(['trip_id' => null]);
    $order->deliveryPoints()->update(['status' => OrderDeliveryPointStatus::Delivered->value, 'delivered_at' => now()]);

    Livewire::test(ListOrders::class, ['showMineOnly' => false, 'activePlaceFilter' => 'all'])
        ->callTableAction('replicate', $order, data: ['planned_loading_at' => now()->toDateTimeString()])
        ->assertHasNoTableActionErrors();

    $replica = Order::where('id', '!=', $order->id)->latest('id')->firstOrFail();

    expect($replica->status)->toBe(OrderStatus::Draft)
        ->and($replica->deliveryPoints()->pluck('status')->all())->toBe([OrderDeliveryPointStatus::Pending]);
});

test('completing an external trip closes its orders and delivery points', function () {
    $this->vehicle->update(['type' => VehicleOwnerType::Rent]);
    $order = ($this->makeOrder)(OrderStatus::Assigned);
    $this->trip->update(['completed_at' => now()]);

    app(TripStateMachine::class)->completeExternalTrip($this->trip->fresh());

    expect($this->trip->fresh()->status)->toBe(TripStatus::Completed)
        ->and($order->fresh()->status)->toBe(OrderStatus::Completed)
        ->and($order->deliveryPoints()->first()->status)->toBe(OrderDeliveryPointStatus::Delivered);
});
