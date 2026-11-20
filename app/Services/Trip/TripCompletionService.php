<?php

namespace App\Services\Trip;

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use App\Services\Km\TripKmService;
use Illuminate\Support\Facades\DB;

/**
 * Hoàn thành / mở lại chuyến: đóng chuyến (km, đơn, mốc Kết thúc, xe rảnh)
 * và chuyển đơn sang Hoàn thành. Observer của Trip chỉ gọi `completeOrders()` làm lưới an toàn
 * cho các đường đặt trạng thái Completed gián tiếp (sửa chuyến, đồng bộ km theo mốc).
 */
class TripCompletionService
{
    public function __construct(
        private readonly TripKmService $tripKm,
        private readonly CheckpointGeofenceService $geofence,
    ) {}

    /**
     * Kết thúc chuyến từ App tài xế / mốc Kết thúc.
     *
     * @param  array<string, mixed>  $checkpointAttributes
     */
    public function complete(Trip $trip, ?float $endKm = null, ?string $completedAt = null, array $checkpointAttributes = []): void
    {
        DB::transaction(function () use ($trip, $endKm, $completedAt, $checkpointAttributes): void {
            $trip->status = TripStatus::Completed;
            $trip->completed_at = $completedAt ?? now();
            $trip->end_km = $endKm ?? $trip->end_km;

            $startKm = (float) ($trip->start_km ?? 0);
            $endKmValue = (float) ($trip->end_km ?? 0);
            $trip->total_km = max(0, $endKmValue - $startKm);

            $trip->save();

            // Cập nhật km xe theo km kết thúc chuyến
            if ($endKmValue > 0 && $trip->vehicle) {
                $trip->vehicle->current_mileage = $endKmValue;
                $trip->vehicle->save();
            }

            $this->tripKm->recalculate($trip);

            $this->completeOrders($trip);

            $this->createMissingEndCheckpoints($trip, $endKmValue, (string) $trip->completed_at, $checkpointAttributes);

            $this->syncPendingEmptyRunsStartKm($trip, $endKmValue);
        });
    }

    /**
     * Chuyến đã đóng → mọi đơn chưa huỷ thành Hoàn thành, xe về rảnh nếu không còn đơn nào khác.
     */
    public function completeOrders(Trip $trip): void
    {
        $this->markOrdersCompleted($trip);

        Vehicle::syncIdleStatus($trip->vehicle_id, $trip->id);
    }

    /**
     * Chỉ chuyển các đơn chưa huỷ của chuyến sang Hoàn thành (không đụng trạng thái xe).
     */
    public function markOrdersCompleted(Trip $trip): void
    {
        $trip->orders()
            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Completed->value])
            ->get()
            ->each(fn (Order $order) => $order->update(['status' => OrderStatus::Completed]));
    }

    /**
     * Điều hành xoá mốc Kết thúc: bỏ giờ / km kết thúc rồi đồng bộ lại đơn, điểm giao, chuyến theo các mốc còn lại.
     */
    public function reopen(Trip $trip): void
    {
        $trip->update([
            'completed_at' => null,
            'end_km' => null,
        ]);

        app(DeliveryProgressSyncService::class)->syncTripCheckpointsState($trip->refresh(), reconcileCheckpoints: true);
    }

    /**
     * @param  array<string, mixed>  $checkpointAttributes
     */
    private function createMissingEndCheckpoints(Trip $trip, float $endKm, string $occurredAt, array $checkpointAttributes = []): void
    {
        $completedOrderIds = $trip->orders()
            ->where('status', OrderStatus::Completed->value)
            ->pluck('id');

        if ($completedOrderIds->isEmpty()) {
            return;
        }

        $existingEndOrderIds = TripCheckpoint::whereIn('order_id', $completedOrderIds)
            ->where('checkpoint_type', CheckpointType::End->value)
            ->pluck('order_id');

        $missingOrderIds = $completedOrderIds->diff($existingEndOrderIds);

        if ($missingOrderIds->isEmpty()) {
            return;
        }

        if (! isset($checkpointAttributes['vehicle_geo_status'])) {
            $checkpointAttributes = [
                ...$checkpointAttributes,
                ...$this->geofence->check($trip, CheckpointType::End, $checkpointAttributes)->toCheckpointAttributes(),
            ];
        }

        foreach ($missingOrderIds as $orderId) {
            TripCheckpoint::create([
                'checkpoint_type' => CheckpointType::End->value,
                'trip_id' => $trip->id,
                'order_id' => $orderId,
                'km_reading' => $endKm,
                'occurred_at' => $occurredAt,
                'driver_id' => $trip->driver_id,
                'shift_id' => $trip->shift_id,
                'vehicle_id' => $trip->vehicle_id,
                ...$checkpointAttributes,
            ]);
        }
    }

    /**
     * Chuyến có hàng đóng → chuyến không hàng (chiều về) đang chờ của xe bắt đầu từ km kết thúc này.
     */
    private function syncPendingEmptyRunsStartKm(Trip $trip, float $endKm): void
    {
        if ($trip->is_empty_run || $trip->vehicle_id === null || $endKm <= 0) {
            return;
        }

        $pendingEmptyRuns = Trip::query()
            ->where('vehicle_id', $trip->vehicle_id)
            ->where('id', '!=', $trip->id)
            ->where('is_empty_run', true)
            ->where('status', TripStatus::ReturnTrip)
            ->where(fn ($query) => $query->whereNull('start_km')->orWhere('start_km', '<', $endKm))
            ->get();

        foreach ($pendingEmptyRuns as $emptyRun) {
            $emptyRun->start_km = $endKm;
            $emptyRun->save();

            TripCheckpoint::query()
                ->where('trip_id', $emptyRun->id)
                ->where('checkpoint_type', CheckpointType::Started->value)
                ->where(fn ($query) => $query->whereNull('km_reading')->orWhere('km_reading', '<', $endKm))
                ->get()
                ->each->update(['km_reading' => $endKm]);
        }
    }
}
