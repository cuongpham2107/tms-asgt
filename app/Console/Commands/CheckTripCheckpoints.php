<?php

namespace App\Console\Commands;

use App\Enums\CargoType;
use App\Enums\CheckpointType;
use App\Enums\LocationType;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Priority;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
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
use Illuminate\Console\Command;

class CheckTripCheckpoints extends Command
{
    protected $signature = 'check:trip-checkpoints';

    protected $description = 'Test các trường hợp gửi TripCheckpoint với dữ liệu thật';

    public function handle(): void
    {
        $this->info('=== KIỂM TRA CÁC TRƯỜNG HỢP TRIP CHECKPOINT ===');
        $this->newLine();

        $area = Area::where('type', OrderType::Hhhk)->first();
        $customer = Customer::where('is_active', true)->first();
        $pickupLocation = Location::where('loc_type', LocationType::Pickup)->where('is_active', true)->first();
        $deliveryLocation = Location::where('loc_type', LocationType::Delivery)->where('is_active', true)
            ->where('code', 'NOT LIKE', 'KM-%')->first();
        $driver = User::whereHas('roles', fn ($q) => $q->where('name', 'driver'))->first();
        $vehicle = Vehicle::where('is_active', true)->where('current_driver_id', $driver->id)->first();

        if (! $area || ! $customer || ! $pickupLocation || ! $deliveryLocation || ! $driver || ! $vehicle) {
            $this->error('Thiếu dữ liệu thật. Kiểm tra: area, customer, pickupLocation, deliveryLocation, driver, vehicle');

            return;
        }

        $this->line("Area: {$area->name} ({$area->code})");
        $this->line("Customer: {$customer->name}");
        $this->line("Pickup: {$pickupLocation->name}");
        $this->line("Delivery: {$deliveryLocation->name}");
        $this->line("Driver: {$driver->name}");
        $this->line("Vehicle: {$vehicle->plate_number}");
        $this->newLine();

        // Tạo delivery location thứ 2 cho case 3 (khác điểm đến)
        $deliveryLocation2 = Location::firstOrCreate(
            ['code' => 'KHO_C', 'loc_type' => LocationType::Delivery],
            ['name' => 'Kho C', 'address' => 'Địa chỉ C', 'lat' => 10.9, 'lng' => 106.7, 'is_active' => true]
        );

        // ─────────────────────────────────────────
        // CASE 1: Cùng điểm đến
        // ─────────────────────────────────────────
        $this->warn('═══ CASE 1: 2 orders cùng điểm đến ═══');
        $this->newLine();

        $trip1 = Trip::create([
            'trip_code' => 'CHECK-TC1-'.now()->timestamp,
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
        ]);

        $order1a = Order::create([
            'order_code' => 'TC1-A-'.now()->timestamp,
            'type' => OrderType::Hhhk, 'area_id' => $area->id, 'customer_id' => $customer->id,
            'cargo_name' => 'Hàng hóa A', 'cargo_type' => CargoType::Gcr,
            'total_packages' => 5, 'total_weight' => 50,
            'pickup_location_id' => $pickupLocation->id, 'pickup_address' => $pickupLocation->address,
            'pickup_contact' => 'Contact A', 'pickup_phone' => '0909000001',
            'priority' => Priority::High, 'status' => OrderStatus::Sent, 'sent_at' => now(),
            'trip_id' => $trip1->id, 'trip_sequence' => 1, 'created_by' => $driver->id,
        ]);
        $order1b = Order::create([
            'order_code' => 'TC1-B-'.now()->timestamp,
            'type' => OrderType::Hhhk, 'area_id' => $area->id, 'customer_id' => $customer->id,
            'cargo_name' => 'Hàng hóa B', 'cargo_type' => CargoType::Gcr,
            'total_packages' => 10, 'total_weight' => 100,
            'pickup_location_id' => $pickupLocation->id, 'pickup_address' => $pickupLocation->address,
            'pickup_contact' => 'Contact B', 'pickup_phone' => '0909000002',
            'priority' => Priority::High, 'status' => OrderStatus::Sent, 'sent_at' => now(),
            'trip_id' => $trip1->id, 'trip_sequence' => 2, 'created_by' => $driver->id,
        ]);

        // 2 order chung điểm đến
        $dp1a = OrderDeliveryPoint::create([
            'order_id' => $order1a->id, 'location_id' => $deliveryLocation->id,
            'sequence' => 1, 'address' => $deliveryLocation->address,
            'contact_person' => 'Recipient A', 'contact_phone' => '0909999001',
            'total_packages' => 5, 'total_weight' => 50, 'status' => OrderDeliveryPointStatus::Pending,
        ]);
        $dp1b = OrderDeliveryPoint::create([
            'order_id' => $order1b->id, 'location_id' => $deliveryLocation->id,
            'sequence' => 1, 'address' => $deliveryLocation->address,
            'contact_person' => 'Recipient B', 'contact_phone' => '0909999002',
            'total_packages' => 10, 'total_weight' => 100, 'status' => OrderDeliveryPointStatus::Pending,
        ]);

        // Start shift
        $shift1 = $this->startShift($driver, $vehicle);
        $trip1->update(['shift_id' => $shift1->id]);

        // Post checkpoints
        $this->checkpoint($trip1, $driver, ['checkpoint_type' => CheckpointType::Started->value, 'occurred_at' => now()], CheckpointType::Started);
        $this->checkpoint($trip1, $driver, ['checkpoint_type' => CheckpointType::ArrivedPickup->value, 'km_reading' => 1000, 'occurred_at' => now()], CheckpointType::ArrivedPickup);
        $this->checkpoint($trip1, $driver, ['checkpoint_type' => CheckpointType::LeftPickup->value, 'km_reading' => 1005, 'occurred_at' => now()], CheckpointType::LeftPickup);
        $this->checkpoint($trip1, $driver, ['checkpoint_type' => CheckpointType::ArrivedDelivery->value, 'order_id' => $order1a->id, 'delivery_point_id' => $dp1a->id, 'km_reading' => 1050, 'occurred_at' => now()], CheckpointType::ArrivedDelivery);

        // completed cho order A → kiểm tra checkpoint có auto-tạo cho order B không?
        $this->checkpoint($trip1, $driver, ['checkpoint_type' => CheckpointType::Completed->value, 'order_id' => $order1a->id, 'delivery_point_id' => $dp1a->id, 'km_reading' => 1060, 'occurred_at' => now()], CheckpointType::Completed);
        $trip1->refresh();

        $completedA = TripCheckpoint::where('trip_id', $trip1->id)->where('checkpoint_type', 'completed')->where('order_id', $order1a->id)->exists();
        $completedB = TripCheckpoint::where('trip_id', $trip1->id)->where('checkpoint_type', 'completed')->where('order_id', $order1b->id)->exists();
        $this->line('  Order A completed checkpoint: '.($completedA ? '✅' : '❌'));
        $this->line('  Order B completed checkpoint: '.($completedB ? '✅ (auto)' : '❌'));
        $this->line("  Order A status: {$order1a->fresh()->status->value}");
        $this->line("  Order B status: {$order1b->fresh()->status->value} (phải là sent)");
        $this->line("  Trip status: {$trip1->status->value} (phải là delivering)");
        $this->newLine();

        // ─────────────────────────────────────────
        // CASE 2: Order chưa có điểm đến
        // ─────────────────────────────────────────
        $this->warn('═══ CASE 2: Order chưa có điểm đến ═══');
        $this->newLine();

        $trip2 = Trip::create([
            'trip_code' => 'CHECK-TC2-'.now()->timestamp,
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
        ]);

        $order2 = Order::create([
            'order_code' => 'TC2-'.now()->timestamp,
            'type' => OrderType::Hhhk, 'area_id' => $area->id, 'customer_id' => $customer->id,
            'cargo_name' => 'Hàng không điểm đến', 'cargo_type' => CargoType::Gcr,
            'total_packages' => 3, 'total_weight' => 30,
            'pickup_location_id' => $pickupLocation->id, 'pickup_address' => $pickupLocation->address,
            'pickup_contact' => 'Contact', 'pickup_phone' => '0909000003',
            'priority' => Priority::High, 'status' => OrderStatus::Sent, 'sent_at' => now(),
            'trip_id' => $trip2->id, 'trip_sequence' => 1, 'created_by' => $driver->id,
        ]);
        // KHÔNG tạo OrderDeliveryPoint

        $shift2 = $this->startShift($driver, $vehicle);
        $trip2->update(['shift_id' => $shift2->id]);

        $this->checkpoint($trip2, $driver, ['checkpoint_type' => CheckpointType::Started->value, 'occurred_at' => now()], CheckpointType::Started);
        $this->checkpoint($trip2, $driver, ['checkpoint_type' => CheckpointType::ArrivedPickup->value, 'km_reading' => 2000, 'occurred_at' => now()], CheckpointType::ArrivedPickup);
        $this->checkpoint($trip2, $driver, ['checkpoint_type' => CheckpointType::LeftPickup->value, 'km_reading' => 2005, 'occurred_at' => now()], CheckpointType::LeftPickup);

        // completed với new_delivery_location_id — phải tự tạo OrderDeliveryPoint
        $this->checkpoint($trip2, $driver, [
            'checkpoint_type' => CheckpointType::Completed->value,
            'order_id' => $order2->id,
            'km_reading' => 2060,
            'occurred_at' => now(),
            'new_delivery_location_id' => $deliveryLocation->id,
        ], CheckpointType::Completed);

        $autoDp = $order2->deliveryPoints()->latest()->first();
        if ($autoDp) {
            $this->line("  ✅ Auto-create OrderDeliveryPoint: id={$autoDp->id}, location_id={$autoDp->location_id}");
        } else {
            $this->error('  ❌ Không tạo được OrderDeliveryPoint!');
        }
        $this->line("  Order status: {$order2->fresh()->status->value}");
        $this->newLine();

        // ─────────────────────────────────────────
        // CASE 3: Khác điểm đến
        // ─────────────────────────────────────────
        $this->warn('═══ CASE 3: 2 orders khác điểm đến ═══');
        $this->newLine();

        $trip3 = Trip::create([
            'trip_code' => 'CHECK-TC3-'.now()->timestamp,
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
        ]);

        $order3a = Order::create([
            'order_code' => 'TC3-A-'.now()->timestamp,
            'type' => OrderType::Hhhk, 'area_id' => $area->id, 'customer_id' => $customer->id,
            'cargo_name' => 'Hàng đến Kho B', 'cargo_type' => CargoType::Gcr,
            'total_packages' => 5, 'total_weight' => 50,
            'pickup_location_id' => $pickupLocation->id, 'pickup_address' => $pickupLocation->address,
            'pickup_contact' => 'Contact A', 'pickup_phone' => '0909000004',
            'priority' => Priority::High, 'status' => OrderStatus::Sent, 'sent_at' => now(),
            'trip_id' => $trip3->id, 'trip_sequence' => 1, 'created_by' => $driver->id,
        ]);
        $order3b = Order::create([
            'order_code' => 'TC3-B-'.now()->timestamp,
            'type' => OrderType::Hhhk, 'area_id' => $area->id, 'customer_id' => $customer->id,
            'cargo_name' => 'Hàng đến Kho C', 'cargo_type' => CargoType::Gcr,
            'total_packages' => 8, 'total_weight' => 80,
            'pickup_location_id' => $pickupLocation->id, 'pickup_address' => $pickupLocation->address,
            'pickup_contact' => 'Contact B', 'pickup_phone' => '0909000005',
            'priority' => Priority::High, 'status' => OrderStatus::Sent, 'sent_at' => now(),
            'trip_id' => $trip3->id, 'trip_sequence' => 2, 'created_by' => $driver->id,
        ]);

        // 2 order KHÁC điểm đến
        $dp3a = OrderDeliveryPoint::create([
            'order_id' => $order3a->id, 'location_id' => $deliveryLocation->id,
            'sequence' => 1, 'address' => $deliveryLocation->address,
            'contact_person' => 'Recipient A', 'contact_phone' => '0909999003',
            'total_packages' => 5, 'total_weight' => 50, 'status' => OrderDeliveryPointStatus::Pending,
        ]);
        $dp3b = OrderDeliveryPoint::create([
            'order_id' => $order3b->id, 'location_id' => $deliveryLocation2->id,
            'sequence' => 1, 'address' => $deliveryLocation2->address,
            'contact_person' => 'Recipient B', 'contact_phone' => '0909999004',
            'total_packages' => 8, 'total_weight' => 80, 'status' => OrderDeliveryPointStatus::Pending,
        ]);

        $shift3 = $this->startShift($driver, $vehicle);
        $trip3->update(['shift_id' => $shift3->id]);

        $this->checkpoint($trip3, $driver, ['checkpoint_type' => CheckpointType::Started->value, 'occurred_at' => now()], CheckpointType::Started);
        $this->checkpoint($trip3, $driver, ['checkpoint_type' => CheckpointType::ArrivedPickup->value, 'km_reading' => 3000, 'occurred_at' => now()], CheckpointType::ArrivedPickup);
        $this->checkpoint($trip3, $driver, ['checkpoint_type' => CheckpointType::LeftPickup->value, 'km_reading' => 3005, 'occurred_at' => now()], CheckpointType::LeftPickup);

        // completed cho order A (Kho B) — chỉ checkpoint cho A, không ảnh hưởng B
        $this->checkpoint($trip3, $driver, ['checkpoint_type' => CheckpointType::Completed->value, 'order_id' => $order3a->id, 'delivery_point_id' => $dp3a->id, 'km_reading' => 3060, 'occurred_at' => now()], CheckpointType::Completed);
        $trip3->refresh();

        $completed3a = TripCheckpoint::where('trip_id', $trip3->id)->where('checkpoint_type', 'completed')->where('order_id', $order3a->id)->exists();
        $completed3b = TripCheckpoint::where('trip_id', $trip3->id)->where('checkpoint_type', 'completed')->where('order_id', $order3b->id)->exists();
        $this->line('  Order A (Kho B) completed checkpoint: '.($completed3a ? '✅' : '❌'));
        $this->line('  Order B (Kho C) completed checkpoint: '.($completed3b ? '❌ LỖI: bị auto-tạo' : '✅ không bị ảnh hưởng'));
        $this->line("  Order A status: {$order3a->fresh()->status->value}");
        $this->line("  Order B status: {$order3b->fresh()->status->value} (phải là sent)");
        $this->newLine();

        // ─────────────────────────────────────────
        // Tổng kết
        // ─────────────────────────────────────────
        $this->warn('=== KẾT LUẬN ===');
        $this->newLine();
        $this->line('Case 1 (cùng điểm đến): Checkpoint auto-tạo cho order cùng location, nhưng không auto-complete');

        $msg2 = $autoDp ? '✅ OrderDeliveryPoint tự động tạo khi gửi new_delivery_location_id' : '❌ LỖI';
        $this->line("Case 2 (chưa có điểm đến): {$msg2}");

        $msg3 = $completed3b ? '❌ completed cho order A đã auto-tạo cho order B (sai)' : '✅ Mỗi order chỉ nhận checkpoint của chính nó';
        $this->line("Case 3 (khác điểm đến): {$msg3}");

        $this->newLine();
        $this->info('=== HOÀN TẤT ===');
    }

    private function startShift(User $driver, Vehicle $vehicle): DriverShift
    {
        $vehicle->current_mileage ??= 0;
        $vehicle->save();

        return DriverShift::create([
            'driver_id' => $driver->id,
            'shift_type' => ShiftType::Full,
            'start_time' => now(),
            'start_km' => $vehicle->current_mileage,
        ]);
    }

    private function checkpoint(Trip $trip, User $user, array $payload, CheckpointType $type): void
    {
        $shiftId = $trip->shift_id;

        if (in_array($type, [CheckpointType::Started, CheckpointType::ArrivedPickup, CheckpointType::LeftPickup], true)) {
            foreach ($trip->orders as $order) {
                TripCheckpoint::create([
                    'trip_id' => $trip->id, 'order_id' => $order->id,
                    'delivery_point_id' => $payload['delivery_point_id'] ?? null,
                    'driver_id' => $trip->driver_id, 'shift_id' => $shiftId,
                    'checkpoint_type' => $type->value,
                    'occurred_at' => $payload['occurred_at'], 'km_reading' => $payload['km_reading'] ?? null,
                    'gps_lat' => $payload['gps_lat'] ?? null, 'gps_lng' => $payload['gps_lng'] ?? null,
                ]);
            }
        } elseif ($type === CheckpointType::Completed && ! empty($payload['new_delivery_location_id'])) {
            // Mô phỏng resolveDeliveryPoint + createCheckpoint
            $location = Location::find($payload['new_delivery_location_id']);
            if ($location && ! empty($payload['order_id'])) {
                $maxSeq = OrderDeliveryPoint::where('order_id', $payload['order_id'])->max('sequence') ?? 0;
                $dp = OrderDeliveryPoint::create([
                    'order_id' => $payload['order_id'], 'location_id' => $location->id,
                    'sequence' => $maxSeq + 1, 'address' => $location->address ?? $location->name,
                    'contact_person' => $location->contact_person, 'contact_phone' => $location->contact_phone,
                    'total_packages' => 0, 'total_weight' => 0, 'status' => OrderDeliveryPointStatus::Pending,
                ]);
                $payload['delivery_point_id'] = $dp->id;
            }

            $oid = $payload['order_id'];
            TripCheckpoint::create([
                'trip_id' => $trip->id, 'order_id' => $oid,
                'delivery_point_id' => $payload['delivery_point_id'] ?? null,
                'driver_id' => $trip->driver_id, 'shift_id' => $shiftId,
                'checkpoint_type' => $type->value,
                'occurred_at' => $payload['occurred_at'], 'km_reading' => $payload['km_reading'],
                'gps_lat' => $payload['gps_lat'] ?? null, 'gps_lng' => $payload['gps_lng'] ?? null,
            ]);
        } else {
            // Order-level events (arrived_delivery / completed) — kiểm tra same-location
            $targetOrderIds = collect([$payload['order_id'] ?? null])->filter();

            if (! empty($payload['delivery_point_id']) && $type !== CheckpointType::ArrivedPickup && $type !== CheckpointType::LeftPickup) {
                $dp = OrderDeliveryPoint::find($payload['delivery_point_id']);
                if ($dp?->location_id !== null) {
                    $sameLocationOrderIds = OrderDeliveryPoint::where('location_id', $dp->location_id)
                        ->whereHas('order', fn ($q) => $q->where('trip_id', $trip->id))
                        ->pluck('order_id');
                    $targetOrderIds = $sameLocationOrderIds;
                }
            }

            foreach ($targetOrderIds as $oid) {
                $existing = TripCheckpoint::where('trip_id', $trip->id)
                    ->where('order_id', $oid)->where('checkpoint_type', $type->value)->exists();
                if ($existing) {
                    continue;
                }
                TripCheckpoint::create([
                    'trip_id' => $trip->id, 'order_id' => $oid,
                    'delivery_point_id' => $payload['delivery_point_id'] ?? null,
                    'driver_id' => $trip->driver_id, 'shift_id' => $shiftId,
                    'checkpoint_type' => $type->value,
                    'occurred_at' => $payload['occurred_at'], 'km_reading' => $payload['km_reading'] ?? null,
                    'gps_lat' => $payload['gps_lat'] ?? null, 'gps_lng' => $payload['gps_lng'] ?? null,
                ]);
            }
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
        $order = Order::find($payload['order_id']);
        if ($order) {
            $order->update(['status' => OrderStatus::Completed]);
        }

        $hasMoreActive = $trip->orders()
            ->where('id', '!=', $payload['order_id'])
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
