<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driverRole = Role::create([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);

    $this->area = Area::create([
        'type' => 'HHHK',
        'code' => 'NBA',
        'name' => 'Nội Bài',
    ]);

    $this->customer = Customer::create([
        'code' => 'CUST-001',
        'name' => 'Customer 001',
        'is_active' => true,
    ]);
});

test('completing a trip completes remaining orders, resets idle vehicle to On, and clears transport card cache', function () {
    $driver = User::factory()->create(['is_active' => true]);
    $driver->assignRole($this->driverRole);

    $vehicle = Vehicle::create([
        'plate_number' => '29H-723.38',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::Running,
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driver->id,
    ]);

    $trip = Trip::create([
        'trip_code' => 'CD-TEST-1',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Delivering,
        'start_km' => 100000,
        'started_at' => now()->subHour(),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-SYNC-1',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::InTransit,
        'created_by' => $driver->id,
    ]);

    $probe = new class extends CreatesOrderTransportCards
    {
        public static function vehicleCards(): array
        {
            return self::resolveVehicleCards(null, null);
        }

        public static function driverCards(): array
        {
            return self::resolveDriverCards();
        }
    };

    // Warm caches while trip is still active
    $beforeVehicle = collect($probe::vehicleCards())->firstWhere('value', $vehicle->id);
    $beforeDriver = collect($probe::driverCards())->firstWhere('value', $driver->id);

    expect($beforeVehicle['badge'])->toBe('Đang chạy')
        ->and($beforeDriver['badge'])->toBe('Đang chạy (1)');

    $trip->complete(endKm: 100050);

    expect($order->fresh()->status)->toBe(OrderStatus::Completed)
        ->and($vehicle->fresh()->status)->toBe(VehicleStatus::On);

    // Cached transport cards must immediately reflect idle state
    $afterVehicle = collect($probe::vehicleCards())->firstWhere('value', $vehicle->id);
    $afterDriver = collect($probe::driverCards())->firstWhere('value', $driver->id);

    expect($afterVehicle['badge'])->toBe('Sẵn sàng')
        ->and($afterDriver['badge'])->toBe('Sẵn sàng');
});

test('transport cards ignore lingering non-completed orders attached to already completed trips', function () {
    $driver = User::factory()->create(['is_active' => true]);
    $driver->assignRole($this->driverRole);

    $vehicle = Vehicle::create([
        'plate_number' => '29H-999.99',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driver->id,
    ]);

    $completedTrip = Trip::create([
        'trip_code' => 'CD-TEST-ORPHAN',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Completed,
        'start_km' => 100000,
        'end_km' => 100020,
        'started_at' => now()->subHours(3),
        'completed_at' => now()->subHours(2),
    ]);

    // Simulate an orphaned order still left as Sent on an already-completed trip
    Order::create([
        'order_code' => 'ORD-ORPHAN-1',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $completedTrip->id,
        'status' => OrderStatus::Sent,
        'created_by' => $driver->id,
    ]);

    CreatesOrderTransportCards::clearTransportCardsCache();

    $probe = new class extends CreatesOrderTransportCards
    {
        public static function vehicleCards(): array
        {
            return self::resolveVehicleCards(null, null);
        }

        public static function driverCards(): array
        {
            return self::resolveDriverCards();
        }
    };

    $vehicleCard = collect($probe::vehicleCards())->firstWhere('value', $vehicle->id);
    $driverCard = collect($probe::driverCards())->firstWhere('value', $driver->id);

    expect($vehicleCard['badge'])->toBe('Sẵn sàng')
        ->and(collect($vehicleCard['details'])->firstWhere('label', 'Đơn đang chạy'))->toBeNull()
        ->and($driverCard['badge'])->toBe('Sẵn sàng');
});
