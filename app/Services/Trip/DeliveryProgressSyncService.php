<?php

namespace App\Services\Trip;

use App\Enums\CheckpointType;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * Đồng bộ trạng thái điểm giao hàng, đơn hàng, chuyến và xe sau khi điều hành chỉnh sửa trên Web
 * (thêm điểm trả mới cho đơn đã hoàn thành hoặc xoá checkpoint hoàn thành/kết thúc).
 */
class DeliveryProgressSyncService
{
    /**
     * Đồng bộ lại đơn hàng (và chuyến chứa đơn nếu có) khi danh sách điểm giao hàng thay đổi từ Web.
     */
    public function syncOrderProgress(Order $order): void
    {
        if (in_array($order->status, [OrderStatus::Draft, OrderStatus::Cancelled], true)) {
            return;
        }

        $order->loadMissing(['trip.checkpoints', 'deliveryPoints', 'tripCheckpoints']);
        $trip = $order->trip;

        if ($trip !== null && in_array($trip->status, [TripStatus::Completed, TripStatus::Cancelled, TripStatus::DriverSwap], true)) {
            return;
        }

        $hasEndCheckpoint = $trip !== null && $trip->checkpoints
            ->contains(fn (TripCheckpoint $cp): bool => $cp->checkpoint_type === CheckpointType::End);

        if ($hasEndCheckpoint) {
            return;
        }

        $hasUndeliveredPoints = $order->deliveryPoints
            ->contains(fn (OrderDeliveryPoint $dp): bool => $dp->status !== OrderDeliveryPointStatus::Delivered);

        if (! $hasUndeliveredPoints) {
            return;
        }

        if ($order->status === OrderStatus::Completed) {
            $checkpoints = $order->tripCheckpoints;
            $hasTransitProgress = $checkpoints->contains(fn (TripCheckpoint $cp): bool => in_array(
                $cp->checkpoint_type,
                [CheckpointType::LeftPickup, CheckpointType::ArrivedDelivery, CheckpointType::Completed],
                true,
            )) || $order->deliveryPoints->contains(fn (OrderDeliveryPoint $dp): bool => $dp->status !== OrderDeliveryPointStatus::Pending);

            $order->update([
                'status' => $hasTransitProgress ? OrderStatus::InTransit : OrderStatus::Sent,
            ]);
        }

        if ($trip !== null) {
            $this->reopenTripIfNeeded($trip);
        }
    }

    /**
     * Đồng bộ lại trạng thái điểm giao hàng, đơn hàng, chuyến và xe từ danh sách checkpoint hiện còn sau khi sửa Trip.
     */
    public function syncTripCheckpointsState(Trip $trip, bool $reconcileCheckpoints = true): void
    {
        $trip->loadMissing(['orders.deliveryPoints', 'orders.tripCheckpoints', 'checkpoints']);

        if (in_array($trip->status, [TripStatus::Cancelled, TripStatus::DriverSwap], true)) {
            return;
        }

        $endCheckpoint = $trip->checkpoints->where('checkpoint_type', CheckpointType::End)->last();
        $hasEndCheckpoint = $endCheckpoint !== null;

        foreach ($trip->orders as $order) {
            if (in_array($order->status, [OrderStatus::Draft, OrderStatus::Cancelled], true)) {
                continue;
            }

            if ($reconcileCheckpoints && ! $hasEndCheckpoint) {
                $this->reconcileOrderPointsAndStatus($order);
            } elseif (! $hasEndCheckpoint) {
                $this->syncOrderProgress($order);
            }
        }

        $this->reopenTripIfNeeded($trip, $reconcileCheckpoints);
    }

    private function reconcileOrderPointsAndStatus(Order $order): void
    {
        $checkpoints = $order->tripCheckpoints()->orderBy('occurred_at')->orderBy('id')->get();
        $deliveryPoints = $order->deliveryPoints()->orderBy('sequence')->get();

        if ($deliveryPoints->isNotEmpty()) {
            foreach ($deliveryPoints as $point) {
                $pointCheckpoints = $checkpoints->where('delivery_point_id', $point->id);
                $completedCp = $pointCheckpoints->where('checkpoint_type', CheckpointType::Completed)->last();
                $arrivedCp = $pointCheckpoints->where('checkpoint_type', CheckpointType::ArrivedDelivery)->last();

                if ($completedCp !== null) {
                    $point->update([
                        'status' => OrderDeliveryPointStatus::Delivered,
                        'arrived_at' => $arrivedCp?->effectiveOccurredAt() ?? $point->arrived_at,
                        'delivered_at' => $completedCp->effectiveOccurredAt() ?? $point->delivered_at ?? now(),
                    ]);
                } elseif ($arrivedCp !== null) {
                    $point->update([
                        'status' => OrderDeliveryPointStatus::Arrived,
                        'arrived_at' => $arrivedCp->effectiveOccurredAt() ?? $point->arrived_at ?? now(),
                        'delivered_at' => null,
                    ]);
                } else {
                    // Điểm chưa có checkpoint arrived_delivery hay completed gắn trực tiếp
                    $hasUnscopedCompleted = $checkpoints
                        ->whereNull('delivery_point_id')
                        ->where('checkpoint_type', CheckpointType::Completed)
                        ->isNotEmpty();

                    if ($hasUnscopedCompleted && $deliveryPoints->count() === 1) {
                        continue;
                    }

                    $point->update([
                        'status' => OrderDeliveryPointStatus::Pending,
                        'arrived_at' => null,
                        'delivered_at' => null,
                    ]);
                }
            }

            $deliveryPoints = $order->deliveryPoints()->orderBy('sequence')->get();
        }

        $hasCompletedCheckpoint = $checkpoints->where('checkpoint_type', CheckpointType::Completed)->isNotEmpty();
        $hasArrivedOrLeft = $checkpoints->contains(fn (TripCheckpoint $cp): bool => in_array(
            $cp->checkpoint_type,
            [CheckpointType::LeftPickup, CheckpointType::ArrivedDelivery, CheckpointType::Completed],
            true,
        ));
        $hasArrivedPickup = $checkpoints->where('checkpoint_type', CheckpointType::ArrivedPickup)->isNotEmpty();

        if ($deliveryPoints->isNotEmpty()) {
            $allDelivered = $deliveryPoints->every(
                fn (OrderDeliveryPoint $dp): bool => $dp->status === OrderDeliveryPointStatus::Delivered
            );

            if ($allDelivered && $hasCompletedCheckpoint) {
                if ($order->status !== OrderStatus::Completed) {
                    $order->update(['status' => OrderStatus::Completed]);
                }

                return;
            }
        } elseif ($hasCompletedCheckpoint) {
            if ($order->status !== OrderStatus::Completed) {
                $order->update(['status' => OrderStatus::Completed]);
            }

            return;
        }

        // Trạng thái đơn đi theo mốc giống App tài xế: App chỉ hiện "Đến lấy hàng" khi đơn Đã gửi
        // và "Đến giao hàng" khi đơn Đang vận chuyển, nên điều hành thêm / xoá mốc thì đơn phải chuyển theo.
        if (! in_array($order->status, [OrderStatus::Assigned, OrderStatus::Sent, OrderStatus::InTransit, OrderStatus::Completed], true)) {
            return;
        }

        $targetStatus = match (true) {
            $hasArrivedOrLeft => OrderStatus::InTransit,
            $hasArrivedPickup || $order->sent_at !== null => OrderStatus::Sent,
            default => OrderStatus::Assigned,
        };

        if ($order->status === $targetStatus) {
            return;
        }

        $updates = ['status' => $targetStatus];
        if ($order->status === OrderStatus::Completed && ! $hasCompletedCheckpoint) {
            $updates['loaded_km'] = null;
        }
        if ($targetStatus !== OrderStatus::Assigned && $order->sent_at === null) {
            $updates['sent_at'] = now();
        }

        $order->update($updates);
    }

    private function reopenTripIfNeeded(Trip $trip, bool $recalculateFromCheckpoints = false): void
    {
        if (in_array($trip->status, [TripStatus::Cancelled, TripStatus::DriverSwap], true)) {
            return;
        }

        $hasEndCheckpoint = $trip->checkpoints()->where('checkpoint_type', CheckpointType::End->value)->exists();

        if ($hasEndCheckpoint) {
            return;
        }

        $activeOrders = $trip->orders()->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Draft->value])->get();
        if ($activeOrders->isEmpty()) {
            if ($recalculateFromCheckpoints) {
                $targetStatus = $this->resolveInProgressTripStatus($trip, $activeOrders);
                if ($trip->status !== $targetStatus || $trip->completed_at !== null) {
                    $trip->update([
                        'status' => $targetStatus,
                        'completed_at' => null,
                    ]);
                }
            }

            return;
        }

        $hasPendingOrders = $activeOrders->contains(fn (Order $order): bool => $order->status !== OrderStatus::Completed);

        if ($hasPendingOrders && in_array($trip->status, [TripStatus::Completed, TripStatus::Delivered], true)) {
            $targetStatus = $this->resolveInProgressTripStatus($trip, $activeOrders);
            $trip->update([
                'status' => $targetStatus,
                'completed_at' => null,
            ]);
        } elseif ($recalculateFromCheckpoints && ! $hasPendingOrders) {
            if ($trip->status !== TripStatus::Delivered) {
                $trip->update(['status' => TripStatus::Delivered]);
            }
        } elseif ($recalculateFromCheckpoints && $hasPendingOrders) {
            $targetStatus = $this->resolveInProgressTripStatus($trip, $activeOrders);
            if ($trip->status !== $targetStatus) {
                $trip->update(['status' => $targetStatus]);
            }
        }

        $this->syncVehicleRunningStatus($trip, $hasPendingOrders);
    }

    /**
     * @param  Collection<int, Order>  $activeOrders
     */
    private function resolveInProgressTripStatus(Trip $trip, Collection $activeOrders): TripStatus
    {
        $hasActiveArrivedDp = $activeOrders->contains(
            fn (Order $order): bool => $order->deliveryPoints()->where('status', OrderDeliveryPointStatus::Arrived->value)->exists()
        );

        if ($hasActiveArrivedDp) {
            return TripStatus::ArrivedDelivery;
        }

        $latestCheckpoint = $trip->checkpoints()
            ->reorder()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        if ($latestCheckpoint === null) {
            return TripStatus::Pending;
        }

        // Nếu vẫn còn điểm giao chưa xong mà checkpoint mới nhất là 'completed' (của điểm trước) → chuyến vẫn đang giao hàng
        if ($latestCheckpoint->checkpoint_type === CheckpointType::Completed) {
            return TripStatus::Delivering;
        }

        return $latestCheckpoint->checkpoint_type?->toTripStatus() ?? TripStatus::Started;
    }

    private function syncVehicleRunningStatus(Trip $trip, bool $hasPendingOrders): void
    {
        if (! $trip->vehicle_id || ! $hasPendingOrders) {
            return;
        }

        $trip->refresh();
        if (in_array($trip->status, [TripStatus::Started, TripStatus::ArrivedPickup, TripStatus::Delivering, TripStatus::ArrivedDelivery], true)) {
            Vehicle::where('id', $trip->vehicle_id)
                ->where('status', VehicleStatus::On->value)
                ->update(['status' => VehicleStatus::Running->value]);
        }
    }
}
