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
use App\Services\Km\TripKmAdjustmentService;
use App\Services\Notification\DriverNotificationService;
use App\Services\Trip\Handlers\CompletedHandler;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

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

        $latestCheckpoint = $trip->checkpoints()
            ->reorder()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        if ($recalculateFromCheckpoints && $latestCheckpoint?->checkpoint_type === CheckpointType::DriverSwap) {
            if ($trip->status !== TripStatus::DriverSwap || $trip->completed_at !== null) {
                $trip->update([
                    'status' => TripStatus::DriverSwap,
                    'completed_at' => null,
                ]);
            }

            $trip->orders()
                ->whereIn('status', [
                    OrderStatus::Assigned->value,
                    OrderStatus::Sent->value,
                    OrderStatus::InTransit->value,
                ])
                ->get()
                ->each->update(['status' => OrderStatus::DriverSwap]);

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
        $latestCheckpoint = $trip->checkpoints()
            ->reorder()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        if ($latestCheckpoint === null) {
            return TripStatus::Pending;
        }

        if ($latestCheckpoint->checkpoint_type === CheckpointType::DriverSwap) {
            return TripStatus::DriverSwap;
        }

        $hasActiveArrivedDp = $activeOrders->contains(
            fn (Order $order): bool => $order->deliveryPoints()->where('status', OrderDeliveryPointStatus::Arrived->value)->exists()
        );

        if ($hasActiveArrivedDp) {
            return TripStatus::ArrivedDelivery;
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

    /**
     * Đồng bộ sau khi điều hành lưu Sửa chuyến: điểm giao theo mốc, giờ / km bắt đầu – kết thúc,
     * trạng thái chuyến / đơn / xe theo các mốc còn lại, km chuyến – ca – xe và báo cáo km đang chờ.
     *
     * @param  array<string, mixed>|null  $kmSnapshotBeforeEdit  ảnh chụp km / mốc trước khi lưu (TripKmAdjustmentService::kmSnapshot)
     */
    public function syncAfterTripEdit(Trip $trip, ?array $kmSnapshotBeforeEdit): void
    {
        $trip->refresh();
        $this->syncOrderDeliveryPointsFromCheckpoints($trip);
        $trip->refresh();
        $trip->loadMissing(['checkpoints', 'vehicle', 'orders']);

        $startedCheckpoint = $trip->checkpoints()
            ->where('checkpoint_type', CheckpointType::Started->value)
            ->whereNotNull('occurred_at')
            ->reorder()
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->first();

        if ($startedCheckpoint !== null && $trip->started_at?->format('Y-m-d H:i') !== $startedCheckpoint->occurred_at?->format('Y-m-d H:i')) {
            $trip->update(['started_at' => $startedCheckpoint->occurred_at]);
        }

        $endCheckpoint = $trip->checkpoints()
            ->where('checkpoint_type', CheckpointType::End->value)
            ->reorder()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        $hadEndCheckpointBefore = collect($kmSnapshotBeforeEdit['checkpoints'] ?? [])
            ->contains(fn (array $cp): bool => ($cp['checkpoint_type'] ?? null) === CheckpointType::End->value);

        $latestCheckpoint = $trip->checkpoints()
            ->reorder()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        $beforeCheckpoints = collect($kmSnapshotBeforeEdit['checkpoints'] ?? []);
        $currentCheckpoints = $trip->checkpoints;
        $checkpointsChanged = $beforeCheckpoints->count() !== $currentCheckpoints->count()
            || $beforeCheckpoints->contains(function (array $before) use ($currentCheckpoints): bool {
                $current = $currentCheckpoints->firstWhere('id', $before['id']);

                return $current === null || $current->getRawOriginal('checkpoint_type') !== ($before['checkpoint_type'] ?? null);
            });

        $syncService = $this;

        if ($endCheckpoint !== null) {
            $updates = [];

            if ($trip->status !== TripStatus::Completed) {
                $updates['status'] = TripStatus::Completed;
            }

            if ($endCheckpoint->occurred_at !== null && $trip->completed_at?->format('Y-m-d H:i') !== $endCheckpoint->occurred_at->format('Y-m-d H:i')) {
                $updates['completed_at'] = $endCheckpoint->occurred_at;
            }

            if ($endCheckpoint->km_reading !== null && (float) $trip->end_km !== (float) $endCheckpoint->km_reading) {
                $updates['end_km'] = $endCheckpoint->km_reading;
            }

            if ($updates !== []) {
                $trip->update($updates);
            }

            app(TripCompletionService::class)->markOrdersCompleted($trip);
        } elseif ($hadEndCheckpointBefore && ! in_array($trip->status, [TripStatus::Cancelled, TripStatus::DriverSwap], true)) {
            app(TripCompletionService::class)->reopen($trip);
        } elseif (! in_array($trip->status, [TripStatus::Completed, TripStatus::Cancelled, TripStatus::DriverSwap], true)) {
            $wasPending = ($kmSnapshotBeforeEdit['trip']['status'] ?? null) === TripStatus::Pending->value;

            if ($checkpointsChanged) {
                $syncService->syncTripCheckpointsState($trip, reconcileCheckpoints: true);

                $trip->refresh();
                if ($trip->started_at === null && $trip->checkpoints->isNotEmpty()) {
                    $firstCheckpoint = $trip->checkpoints()->reorder()->orderBy('occurred_at')->orderBy('id')->first();
                    $trip->update(['started_at' => $startedCheckpoint?->occurred_at ?? $firstCheckpoint?->occurred_at ?? now()]);
                }

                if ($wasPending && ! $trip->isPending()) {
                    foreach ($trip->orders()->where('status', OrderStatus::Assigned)->get() as $order) {
                        $order->update([
                            'status' => OrderStatus::Sent,
                            'sent_at' => $trip->started_at ?? now(),
                        ]);
                    }
                }
            } elseif ($latestCheckpoint !== null) {
                if ($this->applyCompletedCheckpoints($trip)) {
                    if ($trip->status !== TripStatus::Delivered) {
                        $trip->update(['status' => TripStatus::Delivered]);
                    }
                } else {
                    if ($wasPending) {
                        $newStatus = $latestCheckpoint->checkpoint_type?->toTripStatus() ?? TripStatus::Started;
                        $updates = [];

                        if ($trip->status !== $newStatus) {
                            $updates['status'] = $newStatus;
                        }

                        if ($trip->started_at === null) {
                            $firstCheckpoint = $trip->checkpoints()->reorder()->orderBy('occurred_at')->orderBy('id')->first();
                            $updates['started_at'] = $startedCheckpoint?->occurred_at ?? $firstCheckpoint?->occurred_at ?? now();
                        }

                        if (! empty($updates)) {
                            $trip->update($updates);
                        }

                        if (! $trip->isPending()) {
                            foreach ($trip->orders()->where('status', OrderStatus::Assigned)->get() as $order) {
                                $order->update([
                                    'status' => OrderStatus::Sent,
                                    'sent_at' => $trip->started_at ?? now(),
                                ]);
                            }
                        }
                    }

                    $syncService->syncTripCheckpointsState($trip, reconcileCheckpoints: false);
                }
            }
        }

        $service = app(TripKmAdjustmentService::class);

        if ($kmSnapshotBeforeEdit !== $service->kmSnapshot($trip)) {
            $service->recalculateAfterEdit($trip);
            $service->closePendingReportsAfterEdit($trip, Auth::id());

            try {
                app(DriverNotificationService::class)->sendTripKmUpdated($trip);
            } catch (\Throwable) {
            }
        }

        // Xoá hết mốc: chuyến quay về chờ chạy như lúc mới gán xe, không giữ giờ / km bắt đầu cũ.
        $trip->refresh();
        if ($trip->isPending() && $trip->checkpoints()->doesntExist()) {
            $trip->update([
                'started_at' => null,
                'completed_at' => null,
                'start_km' => null,
                'end_km' => null,
                'total_km' => null,
                'total_km_loaded' => null,
                'total_km_empty' => null,
            ]);
        }
    }

    public function applyCompletedCheckpoints(Trip $record): bool
    {
        if (in_array($record->status, [TripStatus::Completed, TripStatus::Cancelled, TripStatus::DriverSwap], true)) {
            return false;
        }

        $handler = app(CompletedHandler::class);

        $record->checkpoints()
            ->where('checkpoint_type', CheckpointType::Completed->value)
            ->get()
            ->each(fn (TripCheckpoint $checkpoint) => $handler->handle(
                $record,
                ['occurred_at' => $checkpoint->occurred_at->toDateTimeString()],
                collect([$checkpoint]),
            ));

        $activeOrders = $record->orders()->where('status', '!=', OrderStatus::Cancelled->value);

        return $activeOrders->exists()
            && (clone $activeOrders)->where('status', '!=', OrderStatus::Completed->value)->doesntExist();
    }

    /**
     * Đồng bộ điểm giao (`order_delivery_points`) của đơn hàng theo điểm đã chỉnh trên mốc hành trình.
     * Khi điều hành sửa `Điểm` sang kho khác (dropdown chọn từ `order_delivery_points` dùng chung trong khu vực),
     * cập nhật lại điểm của chính đơn đó (hoặc tạo mới nếu chưa có) và gán lại `delivery_point_id` trên checkpoint.
     */
    public function syncOrderDeliveryPointsFromCheckpoints(Trip $trip): void
    {
        $trip->loadMissing([
            'orders.deliveryPoints',
            'checkpoints.deliveryPoint',
        ]);

        foreach ($trip->orders as $order) {
            if (! $order instanceof Order) {
                continue;
            }

            $deliveryCheckpoints = $trip->checkpoints
                ->where('order_id', $order->id)
                ->filter(fn (TripCheckpoint $cp): bool => $cp->delivery_point_id !== null
                    && in_array($cp->checkpoint_type, [
                        CheckpointType::ArrivedDelivery,
                        CheckpointType::Completed,
                        CheckpointType::End,
                    ], true))
                ->sortBy(fn (TripCheckpoint $cp): array => [$cp->occurred_at?->getTimestamp() ?? 0, $cp->id])
                ->values();

            if ($deliveryCheckpoints->isEmpty()) {
                continue;
            }

            $ownPoints = $order->deliveryPoints()->orderBy('sequence')->orderBy('id')->get()->keyBy('id');
            $referencedOwnIds = $deliveryCheckpoints
                ->pluck('delivery_point_id')
                ->filter(fn ($dpId): bool => $dpId !== null && $ownPoints->has((int) $dpId))
                ->map(fn ($dpId): int => (int) $dpId)
                ->unique()
                ->values()
                ->all();

            $orphanOwnPoints = $ownPoints
                ->reject(fn (OrderDeliveryPoint $dp): bool => in_array($dp->id, $referencedOwnIds, true))
                ->values();

            $foreignGroups = $deliveryCheckpoints
                ->filter(fn (TripCheckpoint $cp): bool => ! $ownPoints->has((int) $cp->delivery_point_id))
                ->groupBy(fn (TripCheckpoint $cp): int => (int) $cp->delivery_point_id);

            $orderTouched = false;

            foreach ($foreignGroups as $foreignDpId => $checkpointsForPoint) {
                $foreignDp = $checkpointsForPoint->first()?->deliveryPoint ?? OrderDeliveryPoint::with('location')->find($foreignDpId);
                if (! $foreignDp) {
                    continue;
                }

                $targetOwnPoint = $ownPoints->first(function (OrderDeliveryPoint $dp) use ($foreignDp): bool {
                    if ($foreignDp->location_id !== null && $dp->location_id !== null) {
                        return (int) $dp->location_id === (int) $foreignDp->location_id;
                    }

                    return filled($foreignDp->address)
                        && mb_strtolower(trim((string) $dp->address)) === mb_strtolower(trim((string) $foreignDp->address));
                });

                $arrivedCp = $checkpointsForPoint->firstWhere('checkpoint_type', CheckpointType::ArrivedDelivery);
                $completedCp = $checkpointsForPoint->where('checkpoint_type', CheckpointType::Completed)->last();

                if ($targetOwnPoint === null && $orphanOwnPoints->isNotEmpty()) {
                    /** @var OrderDeliveryPoint $orphan */
                    $orphan = $orphanOwnPoints->shift();
                    $foreignDp->loadMissing('location');
                    $orphan->update([
                        'location_id' => $foreignDp->location_id,
                        'address' => $foreignDp->location?->address ?? $foreignDp->location?->name ?? $foreignDp->address,
                        'status' => $completedCp !== null
                            ? OrderDeliveryPointStatus::Delivered
                            : ($arrivedCp !== null ? OrderDeliveryPointStatus::Arrived : $orphan->status),
                        'arrived_at' => $arrivedCp?->effectiveOccurredAt() ?? $orphan->arrived_at,
                        'delivered_at' => $completedCp?->effectiveOccurredAt() ?? $orphan->delivered_at,
                    ]);
                    $targetOwnPoint = $orphan->fresh();
                    $orderTouched = true;
                } elseif ($targetOwnPoint === null) {
                    $foreignDp->loadMissing('location');
                    $nextSeq = (int) ($order->deliveryPoints()->max('sequence') ?? 0) + 1;
                    $targetOwnPoint = OrderDeliveryPoint::create([
                        'order_id' => $order->id,
                        'location_id' => $foreignDp->location_id,
                        'sequence' => $nextSeq,
                        'address' => $foreignDp->location?->address ?? $foreignDp->location?->name ?? $foreignDp->address,
                        'status' => $completedCp !== null
                            ? OrderDeliveryPointStatus::Delivered
                            : ($arrivedCp !== null ? OrderDeliveryPointStatus::Arrived : OrderDeliveryPointStatus::Pending),
                        'arrived_at' => $arrivedCp?->effectiveOccurredAt(),
                        'delivered_at' => $completedCp?->effectiveOccurredAt(),
                    ]);
                    $ownPoints->put($targetOwnPoint->id, $targetOwnPoint);
                    $orderTouched = true;
                }

                $trip->checkpoints()
                    ->where('order_id', $order->id)
                    ->where('delivery_point_id', $foreignDpId)
                    ->update(['delivery_point_id' => $targetOwnPoint->id]);

                $orderTouched = true;
            }

            if ($orderTouched) {
                $order->touch();
            }
        }
    }
}
