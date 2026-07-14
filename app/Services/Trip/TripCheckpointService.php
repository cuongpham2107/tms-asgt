<?php

namespace App\Services\Trip;

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Services\ShiftKmCalculatorService;
use App\Services\Trip\Handlers\ArrivedDeliveryHandler;
use App\Services\Trip\Handlers\ArrivedPickupHandler;
use App\Services\Trip\Handlers\CheckpointEndHandler;
use App\Services\Trip\Handlers\CompletedHandler;
use App\Services\Trip\Handlers\LeftPickupHandler;
use App\Services\Trip\Handlers\StartedHandler;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TripCheckpointService
{
    public function __construct(
        private readonly TripShiftResolver $shiftResolver,
        private readonly DeliveryPointResolver $deliveryPointResolver,
        private readonly CheckpointFactory $checkpointFactory,
        private readonly TripPhotoAttacher $photoAttacher,
        private readonly VehicleUpdater $vehicleUpdater,
        private readonly StartedHandler $startedHandler,
        private readonly ArrivedPickupHandler $arrivedPickupHandler,
        private readonly LeftPickupHandler $leftPickupHandler,
        private readonly ArrivedDeliveryHandler $arrivedDeliveryHandler,
        private readonly CompletedHandler $completedHandler,
        private readonly CheckpointEndHandler $checkpointEndHandler,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  UploadedFile[]|null  $photos
     * @return Collection<int, TripCheckpoint>
     *
     * @throws \Throwable
     */
    public function recordCheckpoint(Trip $trip, array $payload, ?array $photos = null): Collection
    {
        $checkpointType = CheckpointType::from($payload['checkpoint_type']);

        // Return trip: no orders, just update existing checkpoint km_reading
        if ($trip->status === TripStatus::ReturnTrip && in_array($checkpointType, [CheckpointType::Started, CheckpointType::End], true)) {
            $existing = TripCheckpoint::where('trip_id', $trip->id)
                ->where('checkpoint_type', $checkpointType->value)
                ->first();

            if ($existing && isset($payload['km_reading'])) {
                $existing->km_reading = $payload['km_reading'];
                $existing->save();
            }

            return collect($existing ? [$existing] : []);
        }

        $this->validateOrderBelongsToTrip($trip, $payload, $checkpointType);
        $this->validateNoActiveTrip($trip, $checkpointType);

        return DB::transaction(function () use ($trip, $payload, $photos, $checkpointType) {
            // Auto-start trip if driver submits a non-started checkpoint on a pending trip.
            // The started checkpoint gets vehicle's current mileage; the actual checkpoint gets the driver's entered km.
            $startedCheckpoints = collect();
            if ($checkpointType !== CheckpointType::Started && $trip->isPending()) {
                $startedCheckpoints = $this->autoStartTrip($trip, $payload);
            }

            $this->shiftResolver->resolveForTrip($trip);

            $this->deliveryPointResolver->resolve($payload);

            $checkpoints = $this->checkpointFactory->create($trip, $payload, $checkpointType);

            $this->vehicleUpdater->updateFromPayload($trip, $payload);

            if (! empty($photos)) {
                $this->photoAttacher->attach($checkpoints, $photos);
            }

            $this->dispatchHandler($checkpointType, $trip, $payload, $checkpoints);

            // Recalculate shift km so dashboard shows up-to-date totals
            // including in-progress trips using latest checkpoint km
            $trip->refresh();
            if ($trip->shift_id) {
                app(ShiftKmCalculatorService::class)->calculate($trip->shift);
            }

            $checkpoints->each->load('photos');
            $startedCheckpoints->each->load('photos');

            return $startedCheckpoints->merge($checkpoints);
        });
    }

    /**
     * Khi bắt đầu chuyến mới (Started), kiểm tra tài xế không có chuyến nào
     * đang chạy trong ca hiện tại. Nếu có → từ chối để tránh nhập nhầm số km.
     *
     * @throws ValidationException
     */
    private function validateNoActiveTrip(Trip $trip, CheckpointType $type): void
    {
        if ($type !== CheckpointType::Started) {
            return;
        }

        // Trip này đã started rồi (re-send checkpoint) → không cần check
        if (! $trip->isPending()) {
            return;
        }

        $activeTrip = Trip::where('driver_id', $trip->driver_id)
            ->where('id', '!=', $trip->id)
            ->whereIn('status', array_filter(
                TripStatus::activeStatuses(),
                fn (TripStatus $s) => ! in_array($s, [TripStatus::Pending, TripStatus::DriverSwap]),
            ))
            ->first();

        if ($activeTrip === null) {
            return;
        }

        throw ValidationException::withMessages([
            'checkpoint_type' => sprintf(
                'Tài xế đang có chuyến #%s chưa hoàn thành (trạng thái: %s). Vui lòng hoàn tất chuyến hiện tại trước khi bắt đầu chuyến mới.',
                $activeTrip->id,
                $activeTrip->status->label(),
            ),
        ]);
    }

    private function validateOrderBelongsToTrip(Trip $trip, array $payload, CheckpointType $type): void
    {
        if (! in_array($type, [CheckpointType::ArrivedDelivery, CheckpointType::Completed], true)) {
            return;
        }

        $orderId = $payload['order_id'] ?? null;
        if ($orderId === null) {
            return;
        }

        $belongs = $trip->orders()->where('id', $orderId)->exists();
        if (! $belongs) {
            abort(422, 'Order không thuộc chuyến này');
        }
    }

    private function dispatchHandler(
        CheckpointType $type,
        Trip $trip,
        array $payload,
        Collection $checkpoints,
    ): void {
        if ($trip->isPending() && $type !== CheckpointType::Started) {
            $this->startedHandler->handle($trip, $payload);
        }

        match ($type) {
            CheckpointType::Started => $this->startedHandler->handle($trip, $payload),
            CheckpointType::ArrivedPickup => $this->arrivedPickupHandler->handle($trip),
            CheckpointType::LeftPickup => $this->leftPickupHandler->handle($trip),
            CheckpointType::ArrivedDelivery => $this->arrivedDeliveryHandler->handle($trip, $payload, $checkpoints),
            CheckpointType::Completed => $this->completedHandler->handle($trip, $payload, $checkpoints),
            CheckpointType::DriverSwap => null,
            CheckpointType::End => $this->checkpointEndHandler->handle($trip, $payload),
        };
    }

    /**
     * Tự động bắt đầu chuyến khi tài xế gửi checkpoint đầu tiên (vd: arrived_pickup).
     * Dùng km hiện tại của xe làm start_km, tạo Started checkpoint, cập nhật trip.
     *
     * @return Collection<int, TripCheckpoint>
     */
    private function autoStartTrip(Trip $trip, array $payload): Collection
    {
        $this->validateNoActiveTrip($trip, CheckpointType::Started);

        $vehicleKm = $trip->vehicle?->current_mileage;
        $occurredAt = $payload['occurred_at'] ?? now();

        if ($vehicleKm === null) {
            throw ValidationException::withMessages([
                'checkpoint_type' => 'Không thể tự động bắt đầu chuyến: xe chưa có km. Vui lòng bắt đầu chuyến thủ công.',
            ]);
        }

        // Lùi 1 giây để started luôn đứng trước checkpoint thực tế trong timeline
        $startOccurredAt = Carbon::parse($occurredAt)->subSecond();

        $startPayload = [
            'checkpoint_type' => CheckpointType::Started->value,
            'occurred_at' => $startOccurredAt,
            'km_reading' => $vehicleKm,
        ];

        $checkpoints = $this->checkpointFactory->create($trip, $startPayload, CheckpointType::Started);

        // Cập nhật vehicle mileage nếu chưa có
        $this->vehicleUpdater->updateFromPayload($trip, $startPayload);

        $this->startedHandler->handle($trip, $startPayload);

        return $checkpoints;
    }
}
