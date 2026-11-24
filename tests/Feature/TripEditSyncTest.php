<?php

use App\Enums\CheckpointType;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);
    $this->driver = User::factory()->create();
    $this->driver->assignRole(Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']));
    $this->actingAs(User::factory()->create());

    $vehicle = Vehicle::create(['plate_number' => '29C-SYNC', 'vehicle_type' => VehicleType::Normal, 'owner' => 'CLC', 'is_active' => true, 'status' => VehicleStatus::Running, 'type' => VehicleOwnerType::Company, 'current_mileage' => 1000]);
    $shift = DriverShift::create(['driver_id' => $this->driver->id, 'shift_type' => ShiftType::Full, 'start_time' => now()->subHours(5)]);
    $area = Area::create(['code' => 'NBA', 'name' => 'NBA', 'is_active' => true]);
    $pickup = Location::create(['code' => 'JUSDA', 'name' => 'JUSDA', 'lat' => 21.0, 'lng' => 105.7, 'loc_type' => 'pickup', 'is_active' => true]);

    $this->trip = Trip::create(['trip_code' => 'TRIP-SYNC', 'vehicle_id' => $vehicle->id, 'driver_id' => $this->driver->id, 'shift_id' => $shift->id, 'status' => TripStatus::Started, 'started_at' => now()->subMinutes(90), 'start_km' => 1000]);
    $this->order = Order::create(['order_code' => 'ASG-SYNC', 'type' => OrderType::External, 'area_id' => $area->id, 'customer_id' => Customer::create(['code' => 'C', 'name' => 'C'])->id, 'trip_id' => $this->trip->id, 'pickup_location_id' => $pickup->id, 'status' => OrderStatus::Sent, 'sent_at' => now()->subHours(2), 'created_by' => $this->driver->id]);
    $this->points = collect(['ALSE', 'CN2'])->map(fn (string $code, int $i) => OrderDeliveryPoint::create([
        'order_id' => $this->order->id,
        'location_id' => Location::create(['code' => $code, 'name' => $code, 'lat' => 21.1, 'lng' => 105.8, 'loc_type' => 'delivery', 'is_active' => true, 'area_id' => $area->id])->id,
        'sequence' => $i + 1,
        'status' => OrderDeliveryPointStatus::Pending,
    ]));

    $this->checkpoint = fn (CheckpointType $type, int $km, int $minutesAgo, ?int $pointId = null) => TripCheckpoint::create([
        'trip_id' => $this->trip->id, 'order_id' => $this->order->id, 'delivery_point_id' => $pointId, 'driver_id' => $this->driver->id,
        'shift_id' => $shift->id, 'vehicle_id' => $vehicle->id, 'checkpoint_type' => $type->value, 'km_reading' => $km, 'occurred_at' => now()->subMinutes($minutesAgo),
    ]);
    ($this->checkpoint)(CheckpointType::Started, 1000, 90);
});

function syncRows(Trip $trip, ?callable $keep = null): array
{
    return $trip->checkpoints()->get()->filter($keep ?? fn (): bool => true)->mapWithKeys(fn (TripCheckpoint $c): array => ['record-'.$c->id => [
        'checkpoint_type' => $c->checkpoint_type->value, 'order_id' => $c->order_id, 'delivery_point_id' => $c->delivery_point_id,
        'driver_id' => $c->driver_id, 'km_reading' => $c->km_reading, 'occurred_at' => $c->occurred_at->format('Y-m-d H:i'),
    ]])->all();
}

function saveTripCheckpoints(Trip $trip, array $rows): void
{
    Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all', 'activeStatusFilter' => 'all'])
        ->loadTable()
        ->mountTableAction('edit', $trip->fresh())
        ->set('mountedActions.0.data.checkpoints', $rows)
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();
}

test('admin entering left pickup for the driver moves the order in transit so the app offers arrive delivery', function () {
    ($this->checkpoint)(CheckpointType::ArrivedPickup, 1010, 80);
    $rows = syncRows($this->trip);
    $rows['new'] = ['checkpoint_type' => CheckpointType::LeftPickup->value, 'order_id' => $this->order->id, 'delivery_point_id' => null, 'driver_id' => $this->driver->id, 'km_reading' => 1010, 'occurred_at' => now()->subMinutes(70)->format('Y-m-d H:i')];

    saveTripCheckpoints($this->trip, $rows);

    expect($this->trip->fresh()->status)->toBe(TripStatus::Delivering)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::InTransit);
});

test('deleting the pickup checkpoints sends the order back to sent so the app offers arrive pickup again', function () {
    ($this->checkpoint)(CheckpointType::ArrivedPickup, 1010, 80);
    ($this->checkpoint)(CheckpointType::LeftPickup, 1010, 70);
    $this->trip->update(['status' => TripStatus::Delivering]);
    $this->order->update(['status' => OrderStatus::InTransit]);

    saveTripCheckpoints($this->trip, syncRows($this->trip, fn (TripCheckpoint $c): bool => $c->checkpoint_type === CheckpointType::Started));

    expect($this->trip->fresh()->status)->toBe(TripStatus::Started)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Sent);
});

test('editing the km of a completed point does not close a trip that still has points to deliver', function () {
    ($this->checkpoint)(CheckpointType::ArrivedPickup, 1010, 80);
    ($this->checkpoint)(CheckpointType::LeftPickup, 1010, 70);
    ($this->checkpoint)(CheckpointType::ArrivedDelivery, 1040, 60, $this->points[0]->id);
    ($this->checkpoint)(CheckpointType::Completed, 1040, 50, $this->points[0]->id);
    $this->trip->update(['status' => TripStatus::Delivering]);
    $this->order->update(['status' => OrderStatus::InTransit]);
    $this->points[0]->update(['status' => OrderDeliveryPointStatus::Delivered]);

    $component = Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all', 'activeStatusFilter' => 'all'])
        ->loadTable()
        ->mountTableAction('edit', $this->trip->fresh());
    $completedKey = collect($component->get('mountedActions.0.data.checkpoints'))->search(fn (array $row): bool => ($row['checkpoint_type'] ?? null) === 'completed' || ($row['checkpoint_type'] ?? null) === CheckpointType::Completed);
    $component->set("mountedActions.0.data.checkpoints.{$completedKey}.km_reading", 1045)
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $trip = $this->trip->fresh();
    expect($trip->status)->not->toBe(TripStatus::Completed)
        ->and($trip->end_km)->toBeNull()
        ->and($trip->completed_at)->toBeNull()
        ->and($this->order->fresh()->status)->toBe(OrderStatus::InTransit)
        ->and($trip->checkpoints()->where('checkpoint_type', CheckpointType::Completed->value)->value('km_reading'))->toEqual(1045);
});

test('deleting every checkpoint returns the trip to waiting without start data', function () {
    ($this->checkpoint)(CheckpointType::ArrivedPickup, 1010, 80);
    $this->trip->update(['status' => TripStatus::ArrivedPickup]);

    saveTripCheckpoints($this->trip, []);

    $trip = $this->trip->fresh();
    expect($trip->status)->toBe(TripStatus::Pending)
        ->and($trip->started_at)->toBeNull()
        ->and($trip->start_km)->toBeNull()
        ->and($trip->end_km)->toBeNull()
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Sent);
});
