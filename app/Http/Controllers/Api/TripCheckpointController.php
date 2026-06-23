<?php

namespace App\Http\Controllers\Api;

use App\Enums\CheckpointType;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\TripCheckpointRequest;
use App\Http\Resources\TripCheckpointResource;
use App\Models\DriverShift;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripPhoto;
use App\Models\Vehicle;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TripCheckpointController extends Controller
{
    public function checkpoint(TripCheckpointRequest $request, Trip $trip): JsonResponse
    {
        $user = $request->user();

        if ($trip->driver_id !== $user->id) {
            return response()->json(['message' => 'Bạn không phải tài xế được gán cho chuyến này'], 403);
        }

        $payload = $request->validated();

        $checkpointType = CheckpointType::from($payload['checkpoint_type']);

        if (in_array($checkpointType, [CheckpointType::ArrivedDelivery, CheckpointType::Completed], true)) {
            $order = Order::findOrFail($payload['order_id']);

            if ($order->trip_id !== $trip->id) {
                return response()->json(['message' => 'Order không thuộc chuyến này'], 422);
            }
        }

        DB::beginTransaction();
        try {
            if ($trip->shift_id === null) {
                $activeShift = DriverShift::where('driver_id', $user->id)
                    ->whereNull('end_time')
                    ->first();

                if ($activeShift !== null) {
                    $trip->shift_id = $activeShift->id;
                    $trip->save();
                }
            }

            $this->resolveDeliveryPoint($payload);

            $checkpoint = $this->createCheckpoint($trip, $payload, $checkpointType);

            $this->updateVehicleFromCheckpoint($trip, $payload);

            if ($request->hasFile('photos')) {
                $files = Arr::wrap($request->file('photos'));
                foreach ($files as $file) {
                    if ($file === null) {
                        continue;
                    }

                    $path = $file->store('trip_photos', 'public');
                    /** @var FilesystemAdapter $disk */
                    $disk = Storage::disk('public');
                    TripPhoto::create([
                        'trip_checkpoint_id' => $checkpoint->id,
                        'photo_path' => $path,
                        'photo_url' => $disk->url($path),
                    ]);
                }
            }

            match ($checkpointType) {
                CheckpointType::Started => $this->handleStarted($trip, $payload),
                CheckpointType::ArrivedPickup => $this->handleArrivedPickup($trip),
                CheckpointType::LeftPickup => $this->handleLeftPickup($trip),
                CheckpointType::ArrivedDelivery => $this->handleArrivedDelivery($trip, $payload),
                CheckpointType::Completed => $this->handleCompleted($trip, $payload),
                CheckpointType::DriverSwap => null,
            };

            DB::commit();

            $checkpoint->load('photos');

            return response()->json(['checkpoint' => TripCheckpointResource::make($checkpoint)]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => 'Unable to record checkpoint', 'error' => $e->getMessage()], 500);
        }
    }

    private function createCheckpoint(Trip $trip, array $payload, CheckpointType $type): TripCheckpoint
    {
        $shiftId = $trip->shift_id;
        $occurredAt = $payload['occurred_at'] ?? now();

        if (in_array($type, [CheckpointType::Started, CheckpointType::ArrivedPickup, CheckpointType::LeftPickup], true)) {
            $checkpoint = null;
            $orders = $trip->orders;

            if ($orders->isEmpty()) {
                throw new \RuntimeException('Trip không có đơn hàng nào để tạo checkpoint');
            }

            foreach ($orders as $order) {
                $checkpoint = TripCheckpoint::create([
                    'trip_id' => $trip->id,
                    'order_id' => $order->id,
                    'delivery_point_id' => $payload['delivery_point_id'] ?? null,
                    'driver_id' => $trip->driver_id,
                    'shift_id' => $shiftId,
                    'checkpoint_type' => $type->value,
                    'occurred_at' => $occurredAt,
                    'km_reading' => $payload['km_reading'] ?? null,
                    'gps_lat' => $payload['gps_lat'] ?? null,
                    'gps_lng' => $payload['gps_lng'] ?? null,
                    'voice_note' => $payload['voice_note'] ?? null,
                ]);
            }

            return $checkpoint;
        }

        // ArrivedDelivery / Completed: nếu nhiều orders chung delivery location,
        // tạo checkpoint cho tất cả
        $targetOrderIds = collect([$payload['order_id'] ?? null])->filter();

        if (! empty($payload['delivery_point_id'])) {
            $deliveryPoint = OrderDeliveryPoint::find($payload['delivery_point_id']);
            if ($deliveryPoint?->location_id !== null) {
                $sameLocationOrderIds = OrderDeliveryPoint::where('location_id', $deliveryPoint->location_id)
                    ->whereHas('order', fn ($q) => $q->where('trip_id', $trip->id))
                    ->pluck('order_id');

                $targetOrderIds = $sameLocationOrderIds;
            }
        }

        $checkpoint = null;
        foreach ($targetOrderIds as $oid) {
            $existing = TripCheckpoint::where('trip_id', $trip->id)
                ->where('order_id', $oid)
                ->where('checkpoint_type', $type->value)
                ->exists();

            if ($existing) {
                continue;
            }

            $checkpoint = TripCheckpoint::create([
                'trip_id' => $trip->id,
                'order_id' => $oid,
                'delivery_point_id' => $payload['delivery_point_id'] ?? null,
                'driver_id' => $trip->driver_id,
                'shift_id' => $shiftId,
                'checkpoint_type' => $type->value,
                'occurred_at' => $occurredAt,
                'km_reading' => $payload['km_reading'] ?? null,
                'gps_lat' => $payload['gps_lat'] ?? null,
                'gps_lng' => $payload['gps_lng'] ?? null,
                'voice_note' => $payload['voice_note'] ?? null,
            ]);
        }

        return $checkpoint ?? TripCheckpoint::where('trip_id', $trip->id)
            ->where('checkpoint_type', $type->value)
            ->latest('occurred_at')
            ->firstOrFail();
    }

    private function resolveDeliveryPoint(array &$payload): void
    {
        if (! empty($payload['delivery_point_id'])) {
            return;
        }

        $newLocationId = $payload['new_delivery_location_id'] ?? null;
        if ($newLocationId === null) {
            return;
        }

        $location = Location::find($newLocationId);
        if ($location === null) {
            return;
        }

        $orderId = $payload['order_id'] ?? null;
        if ($orderId === null) {
            return;
        }

        $maxSeq = OrderDeliveryPoint::where('order_id', $orderId)->max('sequence') ?? 0;

        $deliveryPoint = OrderDeliveryPoint::create([
            'order_id' => $orderId,
            'location_id' => $location->id,
            'sequence' => $maxSeq + 1,
            'address' => $location->address ?? $location->name,
            'contact_person' => $location->contact_person,
            'contact_phone' => $location->contact_phone,
            'total_packages' => 0,
            'total_weight' => 0,
            'status' => OrderDeliveryPointStatus::Pending,
        ]);

        $payload['delivery_point_id'] = $deliveryPoint->id;
    }

    private function handleStarted(Trip $trip, array $payload): void
    {
        if ($trip->isPending()) {
            $vehicle = $trip->vehicle;
            $trip->status = TripStatus::Started;
            $trip->started_at = $payload['occurred_at'] ?? now();
            $trip->start_km = $vehicle?->current_mileage ?? $trip->start_km;
            $trip->save();
        }

        $occurredAt = $payload['occurred_at'] ?? now();
        $trip->orders()
            ->where('status', OrderStatus::Sent)
            ->whereNull('sent_at')
            ->update(['sent_at' => $occurredAt]);
    }

    private function handleArrivedPickup(Trip $trip): void
    {
        $trip->status = TripStatus::ArrivedPickup;
        $trip->save();
    }

    private function handleLeftPickup(Trip $trip): void
    {
        $trip->status = TripStatus::Delivering;
        $trip->save();
    }

    private function handleArrivedDelivery(Trip $trip, array $payload): void
    {
        $trip->status = TripStatus::ArrivedDelivery;
        $trip->save();

        $this->updateDeliveryPoint($payload, OrderDeliveryPointStatus::Arrived);
    }

    private function handleCompleted(Trip $trip, array $payload): void
    {
        $this->updateDeliveryPoint($payload, OrderDeliveryPointStatus::Delivered);

        $order = Order::findOrFail($payload['order_id']);
        $order->status = OrderStatus::Completed;
        $order->save();

        $hasMoreActiveInTrip = $trip->orders()
            ->where('id', '!=', $order->id)
            ->whereIn('status', [OrderStatus::Assigned, OrderStatus::Sent])
            ->exists();

        if (! $hasMoreActiveInTrip) {
            $trip->complete(
                endKm: $payload['km_reading'] ?? null,
                completedAt: $payload['occurred_at'] ?? now(),
            );
        }

        $hasMoreActiveOnVehicle = Order::whereHas('trip', fn ($q) => $q->where('vehicle_id', $trip->vehicle_id))
            ->where('id', '!=', $order->id)
            ->whereIn('status', [OrderStatus::Assigned, OrderStatus::Sent])
            ->exists();

        if (! $hasMoreActiveOnVehicle) {
            Vehicle::where('id', $trip->vehicle_id)->update(['status' => VehicleStatus::On]);
        }
    }

    private function updateVehicleFromCheckpoint(Trip $trip, array $payload): void
    {
        $vehicle = $trip->vehicle;
        if ($vehicle === null) {
            return;
        }

        $dirty = false;
        if (isset($payload['km_reading'])) {
            $vehicle->current_mileage = $payload['km_reading'];
            $dirty = true;
        }
        if (isset($payload['gps_lat'])) {
            $vehicle->gps_lat = $payload['gps_lat'];
            $dirty = true;
        }
        if (isset($payload['gps_lng'])) {
            $vehicle->gps_lng = $payload['gps_lng'];
            $dirty = true;
        }
        if ($dirty) {
            $vehicle->save();
        }
    }

    private function updateDeliveryPoint(array $payload, OrderDeliveryPointStatus $status): void
    {
        $deliveryPointId = $payload['delivery_point_id'] ?? null;
        if ($deliveryPointId === null) {
            return;
        }

        $point = OrderDeliveryPoint::find($deliveryPointId);
        if ($point === null) {
            return;
        }

        if ($status === OrderDeliveryPointStatus::Arrived && $point->status !== OrderDeliveryPointStatus::Pending) {
            return;
        }
        if ($status === OrderDeliveryPointStatus::Delivered && $point->status === OrderDeliveryPointStatus::Delivered && $point->delivered_at !== null) {
            return;
        }

        $point->status = $status;
        if ($status === OrderDeliveryPointStatus::Arrived) {
            $point->arrived_at = $payload['occurred_at'] ?? now();
        } elseif ($status === OrderDeliveryPointStatus::Delivered) {
            $point->delivered_at = $payload['occurred_at'] ?? now();
        }
        $point->save();
    }
}
