<?php

namespace App\Services\Trip;

use App\Enums\AssignmentEndReason;
use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Exceptions\InvalidTransitionException;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\TripDriverAssignment;
use App\Models\User;
use App\Services\Notification\DriverNotificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nơi duy nhất thay đổi tài xế của chuyến. Giữ bất biến:
 * mỗi chuyến có tối đa 1 lượt lái đang mở và trips.driver_id = tài xế của lượt đó (null khi chờ lái).
 */
class TripDriverService
{
    public function __construct(
        private readonly TripStateMachine $stateMachine,
        private readonly CheckpointFactory $checkpointFactory,
        private readonly DriverNotificationService $notifications,
    ) {}

    /**
     * Gán tài xế cho chuyến chưa có lượt lái mở (chuyến mới tạo).
     */
    public function openAssignment(Trip $trip, User $driver, ?User $by = null): TripDriverAssignment
    {
        return DB::transaction(function () use ($trip, $driver, $by) {
            $this->lock($trip);
            $this->closeOpenAssignment($trip, AssignmentEndReason::Reassigned);

            return $this->startAssignment($trip, $driver, $by);
        });
    }

    /**
     * Lái xe xin đảo lái: chuyến chuyển sang driver_swap, chờ điều hành gán lái mới.
     *
     * @throws AuthorizationException
     */
    public function requestSwap(Trip $trip, User $driver, AssignmentEndReason $reason, ?string $note = null): Trip
    {
        return DB::transaction(function () use ($trip, $driver, $reason, $note) {
            $this->lock($trip);

            if ((int) $trip->driver_id !== (int) $driver->id) {
                throw new AuthorizationException('Bạn không phải tài xế đang giữ chuyến này.');
            }

            $this->checkpointFactory->create($trip, ['occurred_at' => now()], CheckpointType::DriverSwap);

            $this->stateMachine->requestSwap($trip);
            $this->closeOpenAssignment($trip, $reason, $note);

            $trip->driver_id = null;
            $trip->save();

            return $trip->refresh();
        });
    }

    /**
     * Điều hành gán lái cho chuyến đang chờ lái (driver_swap) hoặc chưa chạy (pending).
     */
    public function assignDriver(Trip $trip, User $driver, ?User $by = null): Trip
    {
        $previousDriver = $this->lastDriver($trip);

        $trip = DB::transaction(function () use ($trip, $driver, $by) {
            $this->lock($trip);

            if (! in_array($trip->status, [TripStatus::DriverSwap, TripStatus::Pending], true)) {
                throw new InvalidTransitionException('Chỉ gán tài xế cho chuyến đang chờ lái hoặc chưa chạy.');
            }

            $this->closeOpenAssignment($trip, AssignmentEndReason::Reassigned);
            $this->startAssignment($trip, $driver, $by);

            return $trip->status === TripStatus::DriverSwap
                ? $this->stateMachine->restoreAfterSwap($trip)
                : $trip->refresh();
        });

        $this->notifyHandover($trip, $driver, $previousDriver);

        return $trip;
    }

    /**
     * Điều hành đổi tài xế trực tiếp khi chuyến đang chạy; trạng thái chuyến giữ nguyên.
     */
    public function replaceDriver(Trip $trip, User $driver, ?User $by = null, ?string $note = null): Trip
    {
        if (in_array($trip->status, [TripStatus::DriverSwap, TripStatus::Pending], true)) {
            return $this->assignDriver($trip, $driver, $by);
        }

        if (in_array($trip->status, [TripStatus::Completed, TripStatus::Cancelled], true)) {
            throw new InvalidTransitionException('Không thể đổi tài xế cho chuyến đã kết thúc.');
        }

        $previousDriver = $this->lastDriver($trip);

        $trip = DB::transaction(function () use ($trip, $driver, $by, $note) {
            $this->lock($trip);

            if (in_array($trip->status, [TripStatus::Completed, TripStatus::Cancelled, TripStatus::DriverSwap, TripStatus::Pending], true)) {
                throw new InvalidTransitionException('Trạng thái chuyến vừa thay đổi, vui lòng tải lại và thử lại.');
            }

            $this->closeOpenAssignment($trip, AssignmentEndReason::Reassigned, $note);
            $this->startAssignment($trip, $driver, $by);

            return $trip->refresh();
        });

        $this->notifyHandover($trip, $driver, $previousDriver);

        return $trip;
    }

    /**
     * Bỏ tài xế khỏi chuyến chưa chạy (điều hành xoá lái khi sửa chuyến).
     */
    public function unassign(Trip $trip): Trip
    {
        return DB::transaction(function () use ($trip) {
            $this->lock($trip);
            $this->closeOpenAssignment($trip, AssignmentEndReason::Reassigned);

            $trip->driver_id = null;
            $trip->save();

            return $trip->refresh();
        });
    }

    /**
     * Gán ca hiện tại cho lượt lái đang mở của tài xế khi họ vào ca sau khi đã được giao chuyến.
     */
    public function attachShift(DriverShift $shift): void
    {
        TripDriverAssignment::query()
            ->where('driver_id', $shift->driver_id)
            ->whereNull('ended_at')
            ->whereNull('shift_id')
            ->update(['shift_id' => $shift->id]);

        Trip::query()
            ->where('driver_id', $shift->driver_id)
            ->whereNull('shift_id')
            ->whereIn('status', TripStatus::busyStatuses())
            ->update(['shift_id' => $shift->id]);
    }

    /**
     * Khoá dòng chuyến trong transaction và nạp lại trạng thái mới nhất, tránh hai thao tác đồng thời cùng mở lượt lái.
     */
    private function lock(Trip $trip): void
    {
        Trip::whereKey($trip->id)->lockForUpdate()->first();
        $trip->refresh();
    }

    private function startAssignment(Trip $trip, User $driver, ?User $by): TripDriverAssignment
    {
        $shiftId = DriverShift::query()
            ->where('driver_id', $driver->id)
            ->whereNull('end_time')
            ->latest('start_time')
            ->value('id');

        $trip->driver_id = $driver->id;
        $trip->shift_id = $shiftId;
        $trip->save();

        return $trip->driverAssignments()->create([
            'driver_id' => $driver->id,
            'shift_id' => $shiftId,
            'started_at' => now(),
            'created_by' => $by?->id,
        ]);
    }

    private function closeOpenAssignment(Trip $trip, AssignmentEndReason $reason, ?string $note = null): void
    {
        $assignment = $trip->openDriverAssignment()->first();

        if ($assignment === null) {
            return;
        }

        $assignment->ended_at = now();
        $assignment->end_reason = $reason;
        $assignment->note = $note ?? $assignment->note;
        $assignment->save();
    }

    private function lastDriver(Trip $trip): ?User
    {
        return $trip->driverAssignments()->with('driver')->get()->last()?->driver;
    }

    private function notifyHandover(Trip $trip, User $newDriver, ?User $previousDriver): void
    {
        try {
            $this->notifications->sendTripDriverSwapped($trip, $newDriver, $previousDriver);

            if ($previousDriver !== null && $previousDriver->id !== $newDriver->id) {
                $this->notifications->sendTripDriverSwapHandover($trip, $previousDriver, $newDriver);
            }
        } catch (Throwable $e) {
            Log::warning('Lỗi gửi push notification khi đổi tài xế: '.$e->getMessage(), ['exception' => $e]);
        }
    }
}
