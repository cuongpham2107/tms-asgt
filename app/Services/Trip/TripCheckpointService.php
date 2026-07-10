<?php

namespace App\Services\Trip;

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Services\Trip\Handlers\ArrivedDeliveryHandler;
use App\Services\Trip\Handlers\ArrivedPickupHandler;
use App\Services\Trip\Handlers\CompletedHandler;
use App\Services\Trip\Handlers\LeftPickupHandler;
use App\Services\Trip\Handlers\StartedHandler;
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
            $this->shiftResolver->resolveForTrip($trip);

            $this->deliveryPointResolver->resolve($payload);

            $checkpoints = $this->checkpointFactory->create($trip, $payload, $checkpointType);

            $this->vehicleUpdater->updateFromPayload($trip, $payload);

            if (! empty($photos)) {
                $this->photoAttacher->attach($checkpoints, $photos);
            }

            $this->dispatchHandler($checkpointType, $trip, $payload, $checkpoints);

            $checkpoints->each->load('photos');

            return $checkpoints;
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
        match ($type) {
            CheckpointType::Started => $this->startedHandler->handle($trip, $payload),
            CheckpointType::ArrivedPickup => $this->arrivedPickupHandler->handle($trip),
            CheckpointType::LeftPickup => $this->leftPickupHandler->handle($trip),
            CheckpointType::ArrivedDelivery => $this->arrivedDeliveryHandler->handle($trip, $payload, $checkpoints),
            CheckpointType::Completed => $this->completedHandler->handle($trip, $payload, $checkpoints),
            CheckpointType::DriverSwap => null,
            CheckpointType::End => null,
        };
    }
}
