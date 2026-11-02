<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('locations:deduplicate merges duplicate location codes and updates foreign keys', function () {
    $user = User::factory()->create();
    $vehicle = Vehicle::create([
        'plate_number' => '29C-99999',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 1000,
    ]);
    $area = Area::create(['type' => OrderType::Hhhk, 'code' => 'HAN', 'name' => 'Hà Nội']);
    $customer = Customer::create(['code' => 'CUST-01', 'name' => 'Khách A', 'is_active' => true]);

    $canonical = Location::create([
        'code' => 'ALSC',
        'name' => 'Kho ALSC Chuẩn',
        'lat' => 21.2116624,
        'lng' => 105.8084099,
        'geofence_radius_m' => 800,
        'loc_type' => 'warehouse',
        'area_id' => $area->id,
        'is_active' => true,
    ]);

    // Insert duplicate directly via DB to simulate legacy duplicate codes in production
    $dupId = DB::table('locations')->insertGetId([
        'code' => ' alsc ',
        'name' => 'Kho ALSC Cũ',
        'lat' => null,
        'lng' => null,
        'loc_type' => 'warehouse',
        'area_id' => $area->id,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-DUP-01',
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'pickup_location_id' => $dupId,
        'status' => OrderStatus::Sent,
        'created_by' => $user->id,
    ]);

    $dp = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $dupId,
        'sequence' => 1,
        'address' => 'ALSC',
        'status' => 'pending',
    ]);

    $trip = Trip::create([
        'trip_code' => 'CD-DUP-01',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $user->id,
        'status' => TripStatus::Pending,
        'start_location_id' => $dupId,
        'end_location_id' => $dupId,
    ]);

    // Dry run does not delete duplicate
    $this->artisan('locations:deduplicate --dry-run')->assertSuccessful();
    expect(DB::table('locations')->where('id', $dupId)->exists())->toBeTrue();

    // Actual run merges foreign keys and deletes duplicate
    $this->artisan('locations:deduplicate')->assertSuccessful();

    expect(DB::table('locations')->where('id', $dupId)->exists())->toBeFalse()
        ->and($order->fresh()->pickup_location_id)->toBe($canonical->id)
        ->and($dp->fresh()->location_id)->toBe($canonical->id)
        ->and($trip->fresh()->start_location_id)->toBe($canonical->id)
        ->and($trip->fresh()->end_location_id)->toBe($canonical->id);
});
