<?php

namespace App\Services\Trip;

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\TripCheckpoint;
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
        private readonly TripStateMachine $stateMachine,
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

        // Chuyến không hàng: started/end đã được tạo sẵn khi điều hành tạo chuyến → trả checkpoint có sẵn
        if ($trip->is_empty_run && $trip->orders()->doesntExist() && in_array($checkpointType, [CheckpointType::Started, CheckpointType::End], true)) {
            $activeCargoTrip = Trip::getActiveCargoTripForDriver($trip->driver_id, $trip->id);
            if ($activeCargoTrip !== null) {
                $plateNumber = $activeCargoTrip->vehicle?->plate_number ?? ('#'.$activeCargoTrip->id);
                throw ValidationException::withMessages([
                    'checkpoint_type' => "Xe {$plateNumber} đang có chuyến hàng thực hiện. Vui lòng hoàn thành hoặc đảo lái trước khi thực hiện chuyến không hàng.",
                ]);
            }

            $existing = TripCheckpoint::where('trip_id', $trip->id)
                ->where('checkpoint_type', $checkpointType->value)
                ->first();

            return collect($existing ? [$existing] : []);
        }

        // Gửi lại "kết thúc" cho chuyến đã hoàn thành → bỏ qua, không báo lỗi.
        if ($checkpointType === CheckpointType::End && $trip->status === TripStatus::Completed) {
            return collect();
        }

        $this->validateOrderBelongsToTrip($trip, $payload, $checkpointType);
        $this->validateNoActiveTrip($trip, $checkpointType);
        $this->validateVehicleNotBusy($trip);

        return DB::transaction(function () use ($trip, $payload, $photos, $checkpointType) {
            $this->shiftResolver->resolveForTrip($trip);

            // Auto-start: tạo checkpoint started trước checkpoint thực tế; trạng thái do state machine xử lý.
            $startedCheckpoints = collect();
            if ($checkpointType !== CheckpointType::Started && $trip->isPending()) {
                $startedCheckpoints = $this->autoStartTrip($trip, $payload);
            }

            $this->deliveryPointResolver->resolve($payload);

            $checkpoints = $this->checkpointFactory->create($trip, $payload, $checkpointType);

            $this->vehicleUpdater->updateFromPayload($trip, $payload);

            if (! empty($photos)) {
                $this->photoAttacher->attach($checkpoints, $photos);
            }

            // Gửi lại checkpoint đã ghi nhận (mạng chập chờn) → không chuyển trạng thái lần nữa.
            $isReplay = $checkpointType !== CheckpointType::End
                && $checkpoints->isEmpty()
                && $startedCheckpoints->isEmpty()
                && $trip->orders()->exists();

            if (! $isReplay) {
                $this->stateMachine->applyCheckpoint(
                    $trip,
                    $checkpointType,
                    $checkpoints,
                    Carbon::parse($payload['occurred_at'] ?? now()),
                );
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

        // Nếu trip này là chuyến không hàng: kiểm tra tài xế có chuyến có hàng nào đang chạy không
        if ($trip->is_empty_run) {
            $activeCargoTrip = Trip::getActiveCargoTripForDriver($trip->driver_id, $trip->id);
            if ($activeCargoTrip !== null) {
                $plateNumber = $activeCargoTrip->vehicle?->plate_number ?? ('#'.$activeCargoTrip->id);
                throw ValidationException::withMessages([
                    'checkpoint_type' => "Xe {$plateNumber} đang có chuyến hàng thực hiện. Vui lòng hoàn thành hoặc đảo lái trước khi bắt đầu chuyến không hàng.",
                ]);
            }
        }

        // Nếu trip này là chuyến có hàng: chỉ kiểm tra các chuyến có hàng khác đang chạy (chuyến không hàng chưa chạy sẽ không chặn)
        $activeTripQuery = Trip::where('driver_id', $trip->driver_id)
            ->where('id', '!=', $trip->id)
            ->whereIn('status', TripStatus::activeStatuses());

        if (! $trip->is_empty_run) {
            $activeTripQuery->where('is_empty_run', false);
        }

        $activeTrip = $activeTripQuery->first();

        if ($activeTrip === null) {
            return;
        }

        throw ValidationException::withMessages([
            'checkpoint_type' => sprintf(
                'Xe %s, mã đơn %s, lái xe %s chưa hoàn thiện chuyến, yêu cầu lái xe hoàn thành trước khi bắt đầu chuyến mới.',
                $activeTrip->vehicle?->plate_number ?? ('#'.$activeTrip->id),
                $activeTrip->orders()->pluck('order_code')->filter()->join(', '),
                $activeTrip->driver?->name ?? 'Không xác định',
            ),
        ]);
    }

    /**
     * Khi bắt đầu chuyến mới, kiểm tra xe không có chuyến nào khác đang chạy.
     * Ngăn trường hợp điều hành gán order vào xe đang chạy chuyến cũ.
     *
     * @throws ValidationException
     */
    private function validateVehicleNotBusy(Trip $trip): void
    {
        if (! $trip->isPending() || $trip->vehicle_id === null) {
            return;
        }

        $activeTripQuery = Trip::where('vehicle_id', $trip->vehicle_id)
            ->where('id', '!=', $trip->id)
            ->whereIn('status', TripStatus::activeStatuses());

        if (! $trip->is_empty_run) {
            $activeTripQuery->where('is_empty_run', false);
        }

        $activeTrip = $activeTripQuery->first();

        if ($activeTrip === null) {
            return;
        }

        throw ValidationException::withMessages([
            'checkpoint_type' => sprintf(
                'Xe biển số %s đang chạy chuyến (trạng thái: %s). Vui lòng đợi chuyến hiện tại hoàn tất.',
                $activeTrip->vehicle?->plate_number ?? ('#'.$activeTrip->id),
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

    /**
     * Tự động bắt đầu chuyến khi tài xế gửi checkpoint đầu tiên (vd: arrived_pickup).
     * Chỉ tạo Started checkpoint; trạng thái do TripStateMachine cập nhật.
     *
     * @return Collection<int, TripCheckpoint>
     */
    private function autoStartTrip(Trip $trip, array $payload): Collection
    {
        $this->validateNoActiveTrip($trip, CheckpointType::Started);
        $this->validateVehicleNotBusy($trip);

        $occurredAt = $payload['occurred_at'] ?? now();

        // Lùi 1 giây để started luôn đứng trước checkpoint thực tế trong timeline
        $startOccurredAt = Carbon::parse($occurredAt)->subSecond();

        $startPayload = [
            'checkpoint_type' => CheckpointType::Started->value,
            'occurred_at' => $startOccurredAt,
        ];

        return $this->checkpointFactory->create($trip, $startPayload, CheckpointType::Started);
    }
}
