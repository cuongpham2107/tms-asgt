<?php

namespace App\Services\Trip;

use App\Enums\CheckpointType;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Exceptions\InvalidTransitionException;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TripStateMachine
{
    /**
     * @param  Collection<int, mixed>  $checkpoints
     */
    public function applyCheckpoint(Trip $trip, CheckpointType $checkpointType, Collection $checkpoints, ?CarbonInterface $occurredAt = null): Trip
    {
        if ($trip->status === TripStatus::Cancelled) {
            throw new InvalidTransitionException('Không thể cập nhật chuyến đã bị huỷ.');
        }

        if ($trip->status === TripStatus::Completed) {
            throw new InvalidTransitionException('Không thể cập nhật chuyến đã hoàn thành.');
        }

        if ($trip->status === TripStatus::DriverSwap) {
            throw new InvalidTransitionException('Chuyến đang ở trạng thái đảo lái, vui lòng gán lái xe trước khi tiếp tục.');
        }

        return DB::transaction(function () use ($trip, $checkpointType, $checkpoints, $occurredAt) {
            // Auto-start nếu trip đang pending và checkpoint không phải Started
            if ($trip->status === TripStatus::Pending && $checkpointType !== CheckpointType::Started) {
                $this->transitionToStarted($trip, $occurredAt);
            }

            match ($checkpointType) {
                CheckpointType::Started => $this->handleStartedCheckpoint($trip, $occurredAt),
                CheckpointType::ArrivedPickup => $this->handleArrivedPickupCheckpoint($trip),
                CheckpointType::LeftPickup => $this->handleLeftPickupCheckpoint($trip),
                CheckpointType::ArrivedDelivery => $this->handleArrivedDeliveryCheckpoint($trip, $checkpoints),
                CheckpointType::Completed => $this->handleCompletedCheckpoint($trip, $checkpoints),
                CheckpointType::End => $this->handleEndCheckpoint($trip, $checkpoints, $occurredAt),
                CheckpointType::DriverSwap => $this->requestSwap($trip),
                CheckpointType::Cancelled => throw new InvalidTransitionException('Không dùng checkpoint để huỷ chuyến.'),
            };

            return $trip->refresh();
        });
    }

    private function handleStartedCheckpoint(Trip $trip, ?CarbonInterface $occurredAt): void
    {
        if ($trip->status !== TripStatus::Pending) {
            throw new InvalidTransitionException("Không thể bắt đầu chuyến từ trạng thái {$trip->status->getLabel()}.");
        }

        $this->transitionToStarted($trip, $occurredAt);
    }

    private function transitionToStarted(Trip $trip, ?CarbonInterface $occurredAt = null): void
    {
        $occurredAt ??= now();

        $trip->status = TripStatus::Started;
        if ($trip->started_at === null) {
            $trip->started_at = $occurredAt;
        }
        $trip->save();

        if ($trip->vehicle) {
            $trip->vehicle->status = VehicleStatus::Running;
            $trip->vehicle->save();
        }

        foreach ($trip->orders as $order) {
            if ($order->status === OrderStatus::Assigned) {
                $order->status = OrderStatus::Sent;
                if ($order->sent_at === null) {
                    $order->sent_at = $occurredAt;
                }
                $order->save();
            }
        }
    }

    private function handleArrivedPickupCheckpoint(Trip $trip): void
    {
        if ($trip->status !== TripStatus::Started) {
            throw new InvalidTransitionException("Không thể cập nhật Đến lấy hàng từ trạng thái {$trip->status->getLabel()}.");
        }

        $trip->status = TripStatus::ArrivedPickup;
        $trip->save();
    }

    private function handleLeftPickupCheckpoint(Trip $trip): void
    {
        if ($trip->status !== TripStatus::ArrivedPickup) {
            throw new InvalidTransitionException("Không thể cập nhật Rời điểm lấy hàng từ trạng thái {$trip->status->getLabel()}.");
        }

        $trip->status = TripStatus::Delivering;
        $trip->save();

        foreach ($trip->orders as $order) {
            if ($order->status === OrderStatus::Sent) {
                $order->status = OrderStatus::InTransit;
                $order->save();
            }
        }
    }

    /**
     * @param  Collection<int, mixed>  $checkpoints
     */
    private function handleArrivedDeliveryCheckpoint(Trip $trip, Collection $checkpoints): void
    {
        if (! in_array($trip->status, [TripStatus::Delivering, TripStatus::ArrivedDelivery], true)) {
            throw new InvalidTransitionException("Không thể cập nhật Đến giao hàng từ trạng thái {$trip->status->getLabel()}.");
        }

        $trip->status = TripStatus::ArrivedDelivery;
        $trip->save();

        foreach ($checkpoints as $cp) {
            $deliveryPointId = is_array($cp) ? ($cp['delivery_point_id'] ?? null) : ($cp->delivery_point_id ?? null);
            if ($deliveryPointId) {
                $dp = OrderDeliveryPoint::find($deliveryPointId);
                if ($dp && $dp->status === OrderDeliveryPointStatus::Pending) {
                    $dp->status = OrderDeliveryPointStatus::Arrived;
                    $occurredAt = is_array($cp) ? ($cp['occurred_at'] ?? now()) : ($cp->occurred_at ?? now());
                    $dp->arrived_at = $occurredAt;
                    $dp->save();
                }
            }
        }
    }

    /**
     * @param  Collection<int, mixed>  $checkpoints
     */
    private function handleCompletedCheckpoint(Trip $trip, Collection $checkpoints): void
    {
        if (! in_array($trip->status, [TripStatus::Delivering, TripStatus::ArrivedDelivery], true)) {
            throw new InvalidTransitionException("Không thể cập nhật Giao xong từ trạng thái {$trip->status->getLabel()}.");
        }

        foreach ($checkpoints as $cp) {
            $deliveryPointId = is_array($cp) ? ($cp['delivery_point_id'] ?? null) : ($cp->delivery_point_id ?? null);
            $orderId = is_array($cp) ? ($cp['order_id'] ?? null) : ($cp->order_id ?? null);
            $occurredAt = is_array($cp) ? ($cp['occurred_at'] ?? now()) : ($cp->occurred_at ?? now());

            if ($deliveryPointId) {
                $dp = OrderDeliveryPoint::find($deliveryPointId);
                if ($dp && $dp->status !== OrderDeliveryPointStatus::Delivered) {
                    $dp->status = OrderDeliveryPointStatus::Delivered;
                    $dp->delivered_at = $occurredAt;
                    if ($dp->arrived_at === null) {
                        $dp->arrived_at = $occurredAt;
                    }
                    $dp->save();
                }
            }

            if ($orderId) {
                $order = Order::find($orderId);
                if ($order && ! in_array($order->status, OrderStatus::closedStatuses(), true)) {
                    $hasUnfinishedPoints = $order->deliveryPoints()
                        ->where('status', '!=', OrderDeliveryPointStatus::Delivered->value)
                        ->exists();

                    if (! $hasUnfinishedPoints) {
                        $order->status = OrderStatus::Completed;
                        $order->save();
                    }
                }
            }
        }

        $trip->load('orders.deliveryPoints');

        if ($trip->is_empty_run || $trip->orders->isEmpty()) {
            $trip->status = TripStatus::Delivered;
        } else {
            $allClosed = $trip->orders->every(fn (Order $o) => in_array($o->status, OrderStatus::closedStatuses(), true));
            $hasCompleted = $trip->orders->contains(fn (Order $o) => $o->status === OrderStatus::Completed);

            if ($allClosed && $hasCompleted) {
                $trip->status = TripStatus::Delivered;
            } else {
                $trip->status = TripStatus::Delivering;
            }
        }

        $trip->save();
    }

    /**
     * "Kết thúc đơn hàng" được gửi theo từng đơn. Trip chỉ hoàn thành khi đã Delivered;
     * nếu trip còn đơn khác đang giao thì chỉ ghi nhận checkpoint cho đơn đã đóng.
     *
     * @param  Collection<int, mixed>  $checkpoints
     */
    private function handleEndCheckpoint(Trip $trip, Collection $checkpoints, ?CarbonInterface $occurredAt): void
    {
        $canEnd = $trip->status === TripStatus::Delivered
            || ($trip->is_empty_run && in_array($trip->status, [TripStatus::Started, TripStatus::Delivered], true));

        if ($canEnd) {
            if ($occurredAt !== null && $trip->completed_at === null) {
                $trip->completed_at = $occurredAt;
            }

            $this->complete($trip);

            return;
        }

        $orderIds = $checkpoints
            ->map(fn ($cp) => is_array($cp) ? ($cp['order_id'] ?? null) : ($cp->order_id ?? null))
            ->filter()
            ->unique();

        $endedOrdersAreClosed = $orderIds->isNotEmpty()
            && Order::whereIn('id', $orderIds)
                ->whereNotIn('status', OrderStatus::closedStatuses())
                ->doesntExist();

        if (! $endedOrdersAreClosed) {
            throw new InvalidTransitionException("Không thể kết thúc chuyến khi chưa giao xong toàn bộ đơn hàng (trạng thái hiện tại: {$trip->status->getLabel()}).");
        }
    }

    public function requestSwap(Trip $trip, ?User $by = null, ?string $reason = null): Trip
    {
        if (! in_array($trip->status, [
            TripStatus::Started,
            TripStatus::ArrivedPickup,
            TripStatus::Delivering,
            TripStatus::ArrivedDelivery,
        ], true)) {
            throw new InvalidTransitionException("Không thể yêu cầu đảo lái khi chuyến ở trạng thái {$trip->status->getLabel()}.");
        }

        return DB::transaction(function () use ($trip) {
            $trip->status_before_swap = $trip->status;
            $trip->status = TripStatus::DriverSwap;
            $trip->save();

            foreach ($trip->orders as $order) {
                if (in_array($order->status, [OrderStatus::Sent, OrderStatus::InTransit], true)) {
                    $order->status = OrderStatus::DriverSwap;
                    $order->save();
                }
            }

            return $trip->refresh();
        });
    }

    public function restoreAfterSwap(Trip $trip): Trip
    {
        if ($trip->status !== TripStatus::DriverSwap) {
            throw new InvalidTransitionException('Chuyến không ở trạng thái đảo lái.');
        }

        return DB::transaction(function () use ($trip) {
            $targetStatus = $trip->status_before_swap ?? TripStatus::Started;

            $trip->status = $targetStatus;
            $trip->status_before_swap = null;
            $trip->save();

            foreach ($trip->orders as $order) {
                if ($order->status === OrderStatus::DriverSwap) {
                    $order->status = in_array($targetStatus, [TripStatus::Delivering, TripStatus::ArrivedDelivery], true)
                        ? OrderStatus::InTransit
                        : OrderStatus::Sent;
                    $order->save();
                }
            }

            return $trip->refresh();
        });
    }

    public function cancelTrip(Trip $trip, ?User $by = null, ?string $reason = null): Trip
    {
        if (! $trip->status->canCancel()) {
            throw new InvalidTransitionException("Không thể huỷ chuyến ở trạng thái {$trip->status->getLabel()}.");
        }

        return DB::transaction(function () use ($trip, $reason) {
            $trip->status = TripStatus::Cancelled;
            $trip->cancelled_at = now();
            $trip->save();

            $trip->checkpoints()->create([
                'checkpoint_type' => CheckpointType::Cancelled->value,
                'occurred_at' => $trip->cancelled_at,
                'driver_id' => $trip->driver_id,
                'shift_id' => $trip->shift_id,
            ]);

            foreach ($trip->orders as $order) {
                if (! in_array($order->status, OrderStatus::closedStatuses(), true)) {
                    $order->status = OrderStatus::Cancelled;
                    $order->cancelled_at = now();
                    $order->cancel_reason = $reason ?? 'Chuyến đi bị huỷ';
                    $order->save();
                }
            }

            $this->syncVehicleStatus($trip->vehicle);

            return $trip->refresh();
        });
    }

    public function cancelOrder(Order $order, ?User $by = null, ?string $reason = null): Order
    {
        if ($order->status->isClosed()) {
            throw new InvalidTransitionException('Đơn hàng đã đóng, không thể huỷ.');
        }

        $hasArrived = $order->deliveryPoints()
            ->whereIn('status', [OrderDeliveryPointStatus::Arrived->value, OrderDeliveryPointStatus::Delivered->value])
            ->exists();

        if ($hasArrived) {
            throw new InvalidTransitionException('Không thể huỷ đơn hàng khi đã đến điểm giao.');
        }

        return DB::transaction(function () use ($order, $by, $reason) {
            $order->status = OrderStatus::Cancelled;
            $order->cancelled_at = now();
            $order->cancel_reason = $reason ?? 'Huỷ đơn hàng';
            $order->save();

            if ($order->trip_id) {
                $trip = $order->trip;
                if ($trip && ! in_array($trip->status, [TripStatus::Completed, TripStatus::Cancelled], true)) {
                    $trip->load('orders');
                    $allCancelled = $trip->orders->every(fn (Order $o) => $o->status === OrderStatus::Cancelled);

                    if ($allCancelled) {
                        $this->cancelTrip($trip, $by, 'Tất cả đơn hàng của chuyến đã bị huỷ');
                    } else {
                        $allClosed = $trip->orders->every(fn (Order $o) => in_array($o->status, OrderStatus::closedStatuses(), true));
                        $hasCompleted = $trip->orders->contains(fn (Order $o) => $o->status === OrderStatus::Completed);

                        if ($allClosed && $hasCompleted) {
                            if (in_array($trip->status, [TripStatus::Delivering, TripStatus::ArrivedDelivery], true)) {
                                $trip->status = TripStatus::Delivered;
                                $trip->save();
                            }
                        }
                    }
                }
            }

            return $order->refresh();
        });
    }

    public function sendOrder(Order $order): Order
    {
        if ($order->status !== OrderStatus::Assigned) {
            throw new InvalidTransitionException('Chỉ có thể gửi đơn hàng ở trạng thái Đã gán xe.');
        }

        $order->status = OrderStatus::Sent;
        $order->sent_at = now();
        $order->save();

        return $order->refresh();
    }

    public function recallOrder(Order $order): Order
    {
        if ($order->status !== OrderStatus::Sent) {
            throw new InvalidTransitionException('Chỉ có thể thu hồi đơn hàng ở trạng thái Đã gửi.');
        }

        if ($order->trip_id) {
            $trip = $order->trip;
            if ($trip && in_array($trip->status, [
                TripStatus::Delivering,
                TripStatus::ArrivedDelivery,
                TripStatus::Delivered,
                TripStatus::Completed,
            ], true)) {
                throw new InvalidTransitionException('Không thể thu hồi đơn khi xe đã rời điểm lấy hàng.');
            }
        }

        $order->status = OrderStatus::Assigned;
        $order->sent_at = null;
        $order->save();

        return $order->refresh();
    }

    public function complete(Trip $trip): Trip
    {
        if ($trip->status === TripStatus::Completed) {
            return $trip;
        }

        if ($trip->status === TripStatus::Cancelled) {
            throw new InvalidTransitionException('Không thể hoàn thành chuyến đã huỷ.');
        }

        $hasOpenOrders = $trip->orders()->whereNotIn('status', OrderStatus::closedStatuses())->exists();
        if ($hasOpenOrders) {
            throw new InvalidTransitionException('Còn đơn hàng chưa giao, không thể hoàn thành chuyến.');
        }

        return DB::transaction(function () use ($trip) {
            $trip->status = TripStatus::Completed;
            if ($trip->completed_at === null) {
                $trip->completed_at = now();
            }
            $trip->save();

            $completedOrderIds = $trip->orders()
                ->where('status', OrderStatus::Completed->value)
                ->pluck('id');

            if ($completedOrderIds->isNotEmpty()) {
                $existingEndOrderIds = TripCheckpoint::whereIn('order_id', $completedOrderIds)
                    ->where('checkpoint_type', CheckpointType::End->value)
                    ->pluck('order_id');

                $missingOrderIds = $completedOrderIds->diff($existingEndOrderIds);

                foreach ($missingOrderIds as $orderId) {
                    TripCheckpoint::create([
                        'checkpoint_type' => CheckpointType::End->value,
                        'trip_id' => $trip->id,
                        'order_id' => $orderId,
                        'occurred_at' => $trip->completed_at,
                        'driver_id' => $trip->driver_id,
                        'shift_id' => $trip->shift_id,
                    ]);
                }
            }

            $this->syncVehicleStatus($trip->vehicle);

            return $trip->refresh();
        });
    }

    /**
     * Xe thuê ngoài không dùng app: điều hành nhập giờ hoàn thành thì đóng mọi đơn và điểm giao rồi hoàn thành chuyến.
     */
    public function completeExternalTrip(Trip $trip): Trip
    {
        if ($trip->status === TripStatus::Cancelled) {
            throw new InvalidTransitionException('Không thể hoàn thành chuyến đã huỷ.');
        }

        return DB::transaction(function () use ($trip) {
            $completedAt = $trip->completed_at ?? now();

            foreach ($trip->orders()->with('deliveryPoints')->get() as $order) {
                if ($order->status->isClosed()) {
                    continue;
                }

                foreach ($order->deliveryPoints as $point) {
                    if ($point->status !== OrderDeliveryPointStatus::Delivered) {
                        $point->status = OrderDeliveryPointStatus::Delivered;
                        $point->arrived_at ??= $completedAt;
                        $point->delivered_at = $completedAt;
                        $point->save();
                    }
                }

                $order->status = OrderStatus::Completed;
                $order->save();
            }

            $trip->completed_at = $completedAt;

            return $this->complete($trip);
        });
    }

    public function syncVehicleStatus(?Vehicle $vehicle): void
    {
        if ($vehicle === null) {
            return;
        }

        $vehicle->refresh();

        $busyStatuses = array_map(fn ($s) => $s instanceof \BackedEnum ? $s->value : $s, TripStatus::busyStatuses());

        $hasBusyTrips = Trip::where('vehicle_id', $vehicle->id)
            ->whereIn('status', $busyStatuses)
            ->exists();

        if (! $hasBusyTrips && $vehicle->status === VehicleStatus::Running) {
            $vehicle->status = VehicleStatus::On;
            $vehicle->save();
        }
    }

    /**
     * @return array<int, string>
     */
    public function availableActions(Trip $trip, ?User $driver = null): array
    {
        return match ($trip->status) {
            TripStatus::Pending => ['started'],
            TripStatus::Started => ['arrived_pickup', 'request_swap'],
            TripStatus::ArrivedPickup => ['left_pickup', 'request_swap'],
            TripStatus::Delivering => ['arrived_delivery', 'completed', 'request_swap'],
            TripStatus::ArrivedDelivery => ['arrived_delivery', 'completed', 'request_swap'],
            TripStatus::Delivered => ['end'],
            default => [],
        };
    }
}
