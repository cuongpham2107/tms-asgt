<?php

namespace App\Console\Commands;

use App\Enums\CargoType;
use App\Enums\CheckpointType;
use App\Enums\DriverSwapReason;
use App\Enums\LocationType;
use App\Enums\OrderDeliveryPointStatus;
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
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ShiftKmCalculatorService;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

class CheckKmCalculation extends Command
{
    protected $signature = 'check:km-calculation';

    protected $description = 'Demo full flow: shift → trip → checkpoints → km calculation';

    private string $ts;

    private Area $area;

    private Customer $customer;

    private Location $pickup;

    private Location $delivery;

    private Role $role;

    public function handle(): void
    {
        $this->info('=== KIỂM TRA CÔNG THỨC TÍNH KM ===');
        $this->newLine();

        $this->ts = (string) now()->timestamp;
        $this->area = Area::firstOrCreate(
            ['type' => OrderType::Hhhk, 'code' => 'SOUTH'],
            ['name' => 'South']
        );
        $this->customer = Customer::firstOrCreate(
            ['code' => 'KM-CUST-'.$this->ts],
            ['name' => 'Customer KM', 'is_active' => true]
        );
        $this->pickup = Location::firstOrCreate(
            ['code' => 'KM-PICKUP-'.$this->ts],
            ['name' => 'Kho A', 'address' => 'Địa chỉ A',
                'lat' => 10.8, 'lng' => 106.6, 'loc_type' => LocationType::Pickup, 'is_active' => true,
            ]
        );
        $this->delivery = Location::firstOrCreate(
            ['code' => 'KM-DELIV-'.$this->ts],
            ['name' => 'Kho B', 'address' => 'Địa chỉ B',
                'lat' => 10.7, 'lng' => 106.7, 'loc_type' => LocationType::Delivery, 'is_active' => true,
            ]
        );
        $this->role = Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);

        $this->scenarioA();
        $this->scenarioB();
        $this->scenarioC();

        $this->info('=== HOÀN TẤT ===');
    }

    // ──────────────────────────────────────────────
    // Scenario A: 2 orders, 1 shift (existing)
    // ──────────────────────────────────────────────

    private function scenarioA(): void
    {
        $label = 'A — 2 orders, 1 shift';
        $this->warn("═══ SCENARIO {$label} ═══");
        $this->newLine();

        $vehicle = $this->makeVehicle('A', 20000);
        $driver = $this->makeDriver('Tài xế A');

        $trip = Trip::create(['trip_code' => 'T-A-'.$this->ts, 'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id]);

        $orders = [];
        foreach ([1, 2] as $seq) {
            $order = $this->makeOrder($trip, $driver, 'A-'.$this->ts.'-'.$seq, $seq);
            $this->makeDeliveryPoint($order);
            $orders[] = $order;
        }

        $shift = $this->startShift($driver, 20000);
        $trip->update(['shift_id' => $shift->id]);

        $dp1 = $orders[0]->deliveryPoints()->first();
        $dp2 = $orders[1]->deliveryPoints()->first();

        $this->postCheckpoint($trip, $driver, ['checkpoint_type' => CheckpointType::Started->value, 'occurred_at' => now()->toIso8601String(), 'km_reading' => null, 'gps_lat' => 10.8, 'gps_lng' => 106.6], CheckpointType::Started);
        $this->postCheckpoint($trip, $driver, ['checkpoint_type' => CheckpointType::ArrivedPickup->value, 'occurred_at' => now()->toIso8601String(), 'km_reading' => 20010, 'gps_lat' => 10.8, 'gps_lng' => 106.6], CheckpointType::ArrivedPickup);
        $this->postCheckpoint($trip, $driver, ['checkpoint_type' => CheckpointType::LeftPickup->value, 'occurred_at' => now()->toIso8601String(), 'km_reading' => 20015, 'gps_lat' => 10.8, 'gps_lng' => 106.6], CheckpointType::LeftPickup);
        $this->postCheckpoint($trip, $driver, ['checkpoint_type' => CheckpointType::ArrivedDelivery->value, 'occurred_at' => now()->toIso8601String(), 'km_reading' => 20055, 'gps_lat' => 10.8, 'gps_lng' => 106.6, 'order_id' => $orders[0]->id, 'delivery_point_id' => $dp1->id], CheckpointType::ArrivedDelivery);
        $this->postCheckpoint($trip, $driver, ['checkpoint_type' => CheckpointType::Completed->value, 'occurred_at' => now()->toIso8601String(), 'km_reading' => 20060, 'gps_lat' => 10.8, 'gps_lng' => 106.6, 'order_id' => $orders[0]->id, 'delivery_point_id' => $dp1->id], CheckpointType::Completed);
        $this->postCheckpoint($trip, $driver, ['checkpoint_type' => CheckpointType::ArrivedDelivery->value, 'occurred_at' => now()->toIso8601String(), 'km_reading' => 20060, 'gps_lat' => 10.8, 'gps_lng' => 106.6, 'order_id' => $orders[1]->id, 'delivery_point_id' => $dp2->id], CheckpointType::ArrivedDelivery);
        $this->postCheckpoint($trip, $driver, ['checkpoint_type' => CheckpointType::Completed->value, 'occurred_at' => now()->toIso8601String(), 'km_reading' => 20070, 'gps_lat' => 10.8, 'gps_lng' => 106.6, 'order_id' => $orders[1]->id, 'delivery_point_id' => $dp2->id], CheckpointType::Completed);

        $this->endShift($shift, $driver, 20100, $trip);
        app(ShiftKmCalculatorService::class)->calculate($shift);

        $this->showResult($label, $shift, [
            'total_km = 100' => 100,
            'loaded = 50 + 10 = 60 (union, not sum)' => 60,
            'empty = 100 - 60 = 40' => 40,
        ]);
    }

    // ──────────────────────────────────────────────
    // Scenario B: Driver swap
    // ──────────────────────────────────────────────

    private function scenarioB(): void
    {
        $label = 'B — Driver swap, 1 order';
        $this->warn("═══ SCENARIO {$label} ═══");
        $this->newLine();

        $vehicle = $this->makeVehicle('B', 21000);
        $driver1 = $this->makeDriver('Tài xế B1');
        $driver2 = $this->makeDriver('Tài xế B2');

        $trip = Trip::create(['trip_code' => 'T-B-'.$this->ts, 'vehicle_id' => $vehicle->id, 'driver_id' => $driver1->id]);
        $order = $this->makeOrder($trip, $driver1, 'B-'.$this->ts, 1);
        $this->makeDeliveryPoint($order);
        $dp = $order->deliveryPoints()->first();

        // Shift 1: driver1 does started → arrived_pickup → left_pickup → swap
        $shift1 = $this->startShift($driver1, 21000);
        $trip->update(['shift_id' => $shift1->id]);

        $this->postCheckpoint($trip, $driver1, ['checkpoint_type' => CheckpointType::Started->value, 'occurred_at' => now()->toIso8601String(), 'km_reading' => null, 'gps_lat' => 10.8, 'gps_lng' => 106.6], CheckpointType::Started);
        $this->postCheckpoint($trip, $driver1, ['checkpoint_type' => CheckpointType::ArrivedPickup->value, 'occurred_at' => now()->toIso8601String(), 'km_reading' => 21010, 'gps_lat' => 10.8, 'gps_lng' => 106.6], CheckpointType::ArrivedPickup);
        $this->postCheckpoint($trip, $driver1, ['checkpoint_type' => CheckpointType::LeftPickup->value, 'occurred_at' => now()->toIso8601String(), 'km_reading' => 21015, 'gps_lat' => 10.8, 'gps_lng' => 106.6], CheckpointType::LeftPickup);

        // Driver swap: end shift 1, clear trip.shift_id
        $this->endShift($shift1, $driver1, 21015, null);
        DriverSwap::create([
            'trip_id' => $trip->id,
            'from_driver_id' => $driver1->id,
            'to_driver_id' => $driver2->id,
            'from_shift_id' => $shift1->id,
            'to_shift_id' => null,
            'reason' => DriverSwapReason::ShiftHandover,
            'created_by' => $driver1->id,
        ]);
        $trip->update(['status' => TripStatus::DriverSwap, 'shift_id' => null]);

        // Shift 2: driver2 continues → arrived_delivery → completed
        $shift2 = $this->startShift($driver2, 21015);
        $trip->update(['shift_id' => $shift2->id, 'driver_id' => $driver2->id]);

        $this->postCheckpoint($trip, $driver2, ['checkpoint_type' => CheckpointType::ArrivedDelivery->value, 'occurred_at' => now()->toIso8601String(), 'km_reading' => 21055, 'gps_lat' => 10.8, 'gps_lng' => 106.6, 'order_id' => $order->id, 'delivery_point_id' => $dp->id], CheckpointType::ArrivedDelivery);
        $this->postCheckpoint($trip, $driver2, ['checkpoint_type' => CheckpointType::Completed->value, 'occurred_at' => now()->toIso8601String(), 'km_reading' => 21060, 'gps_lat' => 10.8, 'gps_lng' => 106.6, 'order_id' => $order->id, 'delivery_point_id' => $dp->id], CheckpointType::Completed);

        $this->endShift($shift2, $driver2, 21060, $trip);
        app(ShiftKmCalculatorService::class)->calculate($shift1);
        app(ShiftKmCalculatorService::class)->calculate($shift2);
        $shift1->refresh();
        $shift2->refresh();

        $this->line("{$label}: Shift 1 (driver1)  start=21000  end=21015  total={$shift1->total_km}  loaded={$shift1->total_km_loaded}  empty={$shift1->total_km_empty}");
        $this->line("{$label}: Shift 2 (driver2)  start=21015  end=21060  total={$shift2->total_km}  loaded={$shift2->total_km_loaded}  empty={$shift2->total_km_empty}");
        $combinedLoaded = ($shift1->total_km_loaded ?? 0) + ($shift2->total_km_loaded ?? 0);
        $this->line("{$label}: Combined loaded = {$combinedLoaded}  (expected: 21060-21010 = 50)");
        $this->newLine();
    }

    // ──────────────────────────────────────────────
    // Scenario C: Order without delivery point
    // ──────────────────────────────────────────────

    private function scenarioC(): void
    {
        $label = 'C — Order không có điểm đến';
        $this->warn("═══ SCENARIO {$label} ═══");
        $this->newLine();

        $vehicle = $this->makeVehicle('C', 22000);
        $driver = $this->makeDriver('Tài xế C');

        $trip = Trip::create(['trip_code' => 'T-C-'.$this->ts, 'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id]);
        $order = $this->makeOrder($trip, $driver, 'C-'.$this->ts, 1);
        // KHÔNG tạo OrderDeliveryPoint — giả lập đơn chưa có điểm đến

        $shift = $this->startShift($driver, 22000);
        $trip->update(['shift_id' => $shift->id]);

        $this->postCheckpoint($trip, $driver, ['checkpoint_type' => CheckpointType::Started->value, 'occurred_at' => now()->toIso8601String(), 'km_reading' => null, 'gps_lat' => 10.8, 'gps_lng' => 106.6], CheckpointType::Started);
        $this->postCheckpoint($trip, $driver, ['checkpoint_type' => CheckpointType::ArrivedPickup->value, 'occurred_at' => now()->toIso8601String(), 'km_reading' => 22010, 'gps_lat' => 10.8, 'gps_lng' => 106.6], CheckpointType::ArrivedPickup);
        $this->postCheckpoint($trip, $driver, ['checkpoint_type' => CheckpointType::LeftPickup->value, 'occurred_at' => now()->toIso8601String(), 'km_reading' => 22015, 'gps_lat' => 10.8, 'gps_lng' => 106.6], CheckpointType::LeftPickup);

        // Completed với new_delivery_location_id — controller/resolveDeliveryPoint sẽ auto tạo OrderDeliveryPoint
        $this->postCheckpoint($trip, $driver, [
            'checkpoint_type' => CheckpointType::Completed->value,
            'occurred_at' => now()->toIso8601String(),
            'km_reading' => 22060,
            'gps_lat' => 10.8, 'gps_lng' => 106.6,
            'order_id' => $order->id,
            'new_delivery_location_id' => $this->delivery->id,
        ], CheckpointType::Completed);

        // Kiểm tra: OrderDeliveryPoint đã được tạo tự động?
        $newDp = $order->deliveryPoints()->latest()->first();
        if ($newDp) {
            $this->line("  ✅ OrderDeliveryPoint tự động tạo: id={$newDp->id}, location_id={$newDp->location_id}, address={$newDp->address}");
            $this->line("  ✅ completed checkpoint dùng delivery_point_id={$newDp->id}");
        } else {
            $this->error('  ❌ Không tìm thấy OrderDeliveryPoint sau completed!');
        }

        $this->endShift($shift, $driver, 22100, $trip);
        app(ShiftKmCalculatorService::class)->calculate($shift);

        $this->showResult($label, $shift, [
            'total_km = 22100 - 22000 = 100' => 100,
            'loaded = 22060 - 22010 = 50' => 50,
            'empty = 100 - 50 = 50' => 50,
        ]);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function makeVehicle(string $tag, int $mileage): Vehicle
    {
        return Vehicle::create([
            'plate_number' => 'KM-'.$tag.'-'.$this->ts,
            'vehicle_type' => VehicleType::Normal,
            'owner' => 'ASGT',
            'is_active' => true,
            'status' => VehicleStatus::On,
            'type' => VehicleOwnerType::Company,
            'current_mileage' => $mileage,
        ]);
    }

    private function makeDriver(string $name): User
    {
        $driver = User::factory()->create(['name' => $name]);
        $driver->assignRole($this->role);

        return $driver;
    }

    private function makeOrder(Trip $trip, User $driver, string $tag, int $seq): Order
    {
        return Order::create([
            'order_code' => 'ORD-'.$tag,
            'type' => OrderType::Hhhk, 'area_id' => $this->area->id, 'customer_id' => $this->customer->id,
            'cargo_name' => 'Hàng hóa', 'cargo_type' => CargoType::Gcr,
            'total_packages' => 10, 'total_weight' => 100,
            'pickup_location_id' => $this->pickup->id, 'pickup_address' => $this->pickup->address,
            'pickup_contact' => 'Contact', 'pickup_phone' => '0909123456',
            'priority' => Priority::High,
            'status' => OrderStatus::Sent, 'sent_at' => now(),
            'trip_id' => $trip->id, 'trip_sequence' => $seq,
            'created_by' => $driver->id,
        ]);
    }

    private function makeDeliveryPoint(Order $order): OrderDeliveryPoint
    {
        return OrderDeliveryPoint::create([
            'order_id' => $order->id, 'location_id' => $this->delivery->id,
            'sequence' => 1, 'address' => $this->delivery->address,
            'contact_person' => 'Recipient', 'contact_phone' => '0909987654',
            'total_packages' => 10, 'total_weight' => 100,
            'status' => OrderDeliveryPointStatus::Pending,
        ]);
    }

    private function startShift(User $driver, int $startKm): DriverShift
    {
        return DriverShift::create([
            'driver_id' => $driver->id, 'shift_type' => ShiftType::Full,
            'start_time' => now(), 'start_km' => $startKm,
        ]);
    }

    private function endShift(DriverShift $shift, User $driver, int $endKm, ?Trip $trip): void
    {
        $shift->end_time = now();
        $shift->end_km = $endKm;
        $shift->save();

        if ($trip !== null) {
            $incompleteTrips = collect();
            if ($trip->status !== TripStatus::Completed) {
                $incompleteTrips = collect([$trip]);
            }

            $vehicle = $incompleteTrips->first()?->vehicle ?? $driver->vehiclesAsDriver()->first();
            if ($vehicle) {
                $vehicle->current_mileage = $endKm;
                $vehicle->save();
            }
        }
    }

    private function showResult(string $label, DriverShift $shift, array $expectations): void
    {
        $this->line("{$label}: total_km={$shift->total_km}  loaded={$shift->total_km_loaded}  empty={$shift->total_km_empty}");
        $this->newLine();
    }

    private function postCheckpoint(Trip $trip, User $user, array $payload, CheckpointType $type): void
    {
        $shiftId = $trip->shift_id;

        if (in_array($type, [CheckpointType::Started, CheckpointType::ArrivedPickup, CheckpointType::LeftPickup], true)) {
            foreach ($trip->orders as $order) {
                TripCheckpoint::create([
                    'trip_id' => $trip->id,
                    'order_id' => $order->id,
                    'delivery_point_id' => $payload['delivery_point_id'] ?? null,
                    'driver_id' => $trip->driver_id,
                    'shift_id' => $shiftId,
                    'checkpoint_type' => $type->value,
                    'occurred_at' => $payload['occurred_at'],
                    'km_reading' => $payload['km_reading'],
                    'gps_lat' => $payload['gps_lat'],
                    'gps_lng' => $payload['gps_lng'],
                ]);
            }
        } else {
            TripCheckpoint::create([
                'trip_id' => $trip->id,
                'order_id' => $payload['order_id'] ?? null,
                'delivery_point_id' => $payload['delivery_point_id'] ?? null,
                'driver_id' => $trip->driver_id,
                'shift_id' => $shiftId,
                'checkpoint_type' => $type->value,
                'occurred_at' => $payload['occurred_at'],
                'km_reading' => $payload['km_reading'],
                'gps_lat' => $payload['gps_lat'],
                'gps_lng' => $payload['gps_lng'],
            ]);
        }

        match ($type) {
            CheckpointType::Started => $this->handleStarted($trip, $payload),
            CheckpointType::ArrivedPickup => $trip->update(['status' => TripStatus::ArrivedPickup]),
            CheckpointType::LeftPickup => $trip->update(['status' => TripStatus::Delivering]),
            CheckpointType::ArrivedDelivery => $trip->update(['status' => TripStatus::ArrivedDelivery]),
            CheckpointType::Completed => $this->handleCompleted($trip, $payload),
            default => null,
        };

        $vehicle = $trip->vehicle;
        if ($vehicle && isset($payload['km_reading'])) {
            $vehicle->current_mileage = $payload['km_reading'];
            $vehicle->save();
        }
    }

    private function handleStarted(Trip $trip, array $payload): void
    {
        $trip->status = TripStatus::Started;
        $trip->started_at = $payload['occurred_at'] ?? now();
        $trip->start_km = $trip->vehicle?->current_mileage ?? $trip->start_km;
        $trip->save();
    }

    private function handleCompleted(Trip $trip, array $payload): void
    {
        $order = Order::findOrFail($payload['order_id']);
        $order->update(['status' => OrderStatus::Completed]);

        // Handle new_delivery_location_id: tạo delivery point
        if (empty($payload['delivery_point_id']) && ! empty($payload['new_delivery_location_id'])) {
            $location = Location::find($payload['new_delivery_location_id']);
            if ($location) {
                $maxSeq = OrderDeliveryPoint::where('order_id', $order->id)->max('sequence') ?? 0;
                OrderDeliveryPoint::create([
                    'order_id' => $order->id,
                    'location_id' => $location->id,
                    'sequence' => $maxSeq + 1,
                    'address' => $location->address ?? $location->name,
                    'total_packages' => 0,
                    'total_weight' => 0,
                    'status' => OrderDeliveryPointStatus::Delivered,
                ]);
            }
        }

        $hasMoreActive = $trip->orders()
            ->where('id', '!=', $order->id)
            ->whereIn('status', [OrderStatus::Assigned, OrderStatus::Sent])
            ->exists();

        if (! $hasMoreActive) {
            $trip->complete(
                endKm: $payload['km_reading'] ?? null,
                completedAt: $payload['occurred_at'] ?? now(),
            );
        }
    }
}
