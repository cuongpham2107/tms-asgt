<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Filament\Resources\Trips\Schemas\TripForm;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Km\TripKmAdjustmentService;
use App\Services\Notification\DriverNotificationService;
use App\Services\Trip\DeliveryProgressSyncService;
use App\Services\Trip\TripCompletionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

class EditTripAction
{
    public static function make(?string $name = null): EditAction
    {
        $kmSnapshotBeforeEdit = null;

        return EditAction::make($name ?? 'edit')
            ->stickyModalFooter()
            ->modal()
            ->modalWidth(Width::SevenExtraLarge)
            ->modalHeading(fn (?Model $record = null, $livewire = null): string => 'Sửa chuyến — '.(static::resolveTrip($record, $livewire)?->trip_code ?? ''))
            ->authorize(fn (?Model $record = null, $livewire = null): bool => ($trip = static::resolveTrip($record, $livewire)) !== null
                && (auth()->user()?->can('update', $trip) ?? false))
            ->visible(fn (?Model $record = null, $livewire = null): bool => static::resolveTrip($record, $livewire) !== null)
            ->beforeFormFilled(function (Action $action, ?Model $record = null, $livewire = null): void {
                $trip = static::resolveTrip($record, $livewire);
                if (! $trip) {
                    $action->cancel();

                    return;
                }

                $action->record($trip);
            })
            ->mutateRecordDataUsing(function (array $data, ?Model $record = null): array {
                $trip = static::resolveTrip($record);
                $trip?->loadMissing(['startLocation', 'endLocation', 'vehicle', 'driver']);

                if ($trip && blank($data['external_driver_name'] ?? null) && $trip->vehicle?->isRent()) {
                    $resolvedName = $trip->resolveDriverName();
                    if (filled($resolvedName)) {
                        $data['external_driver_name'] = $resolvedName;
                    }
                }

                return $data;
            })
            ->schema(fn (Schema $schema): Schema => TripForm::configure($schema))
            ->before(function (?Model $record = null) use (&$kmSnapshotBeforeEdit): void {
                $trip = static::resolveTrip($record);
                if ($trip) {
                    $kmSnapshotBeforeEdit = app(TripKmAdjustmentService::class)->kmSnapshot($trip);
                }
            })
            ->using(function (?Model $record, array $data): Trip {
                $trip = static::resolveTrip($record) ?? $record;
                /** @var Trip $trip */
                $trip->loadMissing(['vehicle', 'orders']);

                // Thời gian, km kết thúc và trạng thái do mốc quyết định (Started/End/Completed), không lấy từ form:
                // form tự gán status/end_km khi gõ km mốc nên có thể đóng nhầm chuyến còn điểm chưa giao.
                unset($data['started_at'], $data['completed_at'], $data['status'], $data['end_km']);

                $startedCheckpoint = $trip->checkpoints()
                    ->where('checkpoint_type', CheckpointType::Started->value)
                    ->whereNotNull('occurred_at')
                    ->reorder()
                    ->orderBy('occurred_at')
                    ->orderBy('id')
                    ->first();

                if ($startedCheckpoint !== null && $trip->started_at?->format('Y-m-d H:i') !== $startedCheckpoint->occurred_at?->format('Y-m-d H:i')) {
                    $data['started_at'] = $startedCheckpoint->occurred_at;
                }

                $endCheckpoint = $trip->checkpoints()
                    ->where('checkpoint_type', CheckpointType::End->value)
                    ->reorder()
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('id')
                    ->first();

                if ($endCheckpoint !== null) {
                    if ($endCheckpoint->occurred_at !== null) {
                        $data['completed_at'] = $endCheckpoint->occurred_at;
                    }

                    if ($endCheckpoint->km_reading !== null) {
                        $data['end_km'] = $endCheckpoint->km_reading;
                    }
                }

                $isEnding = $endCheckpoint !== null;

                if ($isEnding) {
                    $data['status'] = TripStatus::Completed;
                } elseif (app(DeliveryProgressSyncService::class)->applyCompletedCheckpoints($trip)) {
                    $data['status'] = TripStatus::Delivered;
                } elseif ($trip->isPending() && filled($data['started_at'] ?? $trip->started_at)) {
                    $data['status'] = TripStatus::Started;
                }

                $isRentVehicle = $trip->vehicle?->isRent() ?? false;
                $oldDriverId = $trip->driver_id;
                $newDriverId = $isRentVehicle ? null : ($data['driver_id'] ?? null);

                // Xe thuê ngoài luôn dùng tên lái xe tự nhập, xoá driver_id cũ (nếu có) để TripObserver không ghi đè external_driver_name.
                if ($isRentVehicle) {
                    $data['driver_id'] = null;
                    $data['shift_id'] = null;
                }

                $effectiveDriverId = $isRentVehicle ? null : ($newDriverId ?: $trip->driver_id);
                if ($effectiveDriverId && (empty($trip->shift_id) || ($newDriverId && (int) $oldDriverId !== (int) $newDriverId))) {
                    $shiftId = DriverShift::where('driver_id', $effectiveDriverId)
                        ->whereNull('end_time')
                        ->latest('start_time')
                        ->value('id')
                        ?? DriverShift::where('driver_id', $effectiveDriverId)
                            ->latest('start_time')
                            ->value('id');

                    if ($shiftId) {
                        $data['shift_id'] = $shiftId;
                    }
                }

                $trip->update($data);

                // Chuyến hoàn thành -> toàn bộ đơn chưa huỷ cũng hoàn thành.
                if ($isEnding) {
                    app(TripCompletionService::class)->markOrdersCompleted($trip);
                }

                if ($newDriverId && (int) $oldDriverId !== (int) $newDriverId) {
                    /** @var User|null $oldDriver */
                    $oldDriver = $oldDriverId ? User::find($oldDriverId) : null;
                    /** @var User|null $newDriver */
                    $newDriver = $newDriverId ? User::find($newDriverId) : null;

                    if ($newDriver instanceof User) {
                        try {
                            app(DriverNotificationService::class)->sendTripReassigned($trip, $newDriver, $oldDriver instanceof User ? $oldDriver : null);
                        } catch (\Throwable) {
                        }
                    }

                    if ($oldDriver instanceof User) {
                        try {
                            app(DriverNotificationService::class)->sendTripUnassigned($trip, $oldDriver);
                        } catch (\Throwable) {
                        }
                    }
                }

                return $trip;
            })
            ->after(function (?Model $record = null, $livewire = null) use (&$kmSnapshotBeforeEdit): void {
                $trip = static::resolveTrip($record, $livewire) ?? $record;
                /** @var Trip $trip */
                app(DeliveryProgressSyncService::class)->syncAfterTripEdit($trip, $kmSnapshotBeforeEdit);

                if ($livewire instanceof VehicleDispatchBoard) {
                    $livewire->flushCachedTableRecords();
                    $livewire->dataVersion++;
                }
            });
    }

    public static function resolveTrip(?Model $record = null, $livewire = null): ?Trip
    {
        if ($record instanceof Trip) {
            return $record;
        }

        if ($record instanceof Vehicle) {
            return $record->active_trip;
        }

        if ($livewire && method_exists($livewire, 'getRecord')) {
            $livewireRecord = $livewire->getRecord();
            if ($livewireRecord instanceof Trip) {
                return $livewireRecord;
            }
            if ($livewireRecord instanceof Vehicle) {
                return $livewireRecord->active_trip;
            }
        }

        return null;
    }
}
