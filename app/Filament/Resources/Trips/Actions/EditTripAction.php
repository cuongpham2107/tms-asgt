<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Filament\Resources\Trips\Schemas\TripForm;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Notification\DriverNotificationService;
use App\Services\Trip\DeliveryProgressSyncService;
use App\Services\Trip\Handlers\CompletedHandler;
use App\Services\TripKmAdjustmentService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

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

                // Thời gian bắt đầu/kết thúc tự đồng bộ từ mốc Started/End; không cho form sửa trực tiếp hoặc tự đóng chuyến qua completed_at.
                unset($data['started_at'], $data['completed_at']);

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
                } elseif (static::applyCompletedCheckpoints($trip)) {
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
                        ->when($trip->vehicle_id, fn ($q) => $q->where('vehicle_id', $trip->vehicle_id))
                        ->whereNull('end_time')
                        ->latest('start_time')
                        ->value('id')
                        ?? DriverShift::where('driver_id', $effectiveDriverId)
                            ->whereNull('end_time')
                            ->latest('start_time')
                            ->value('id')
                        ?? DriverShift::where('driver_id', $effectiveDriverId)
                            ->when($trip->vehicle_id, fn ($q) => $q->where('vehicle_id', $trip->vehicle_id))
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
                    $trip->orders()
                        ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Completed->value])
                        ->get()
                        ->each(fn ($order) => $order->update(['status' => OrderStatus::Completed]));
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

                $syncService = app(DeliveryProgressSyncService::class);

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

                    $trip->orders()
                        ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Completed->value])
                        ->get()
                        ->each(fn ($order) => $order->update(['status' => OrderStatus::Completed]));
                } elseif ($hadEndCheckpointBefore && ! in_array($trip->status, [TripStatus::Cancelled, TripStatus::DriverSwap], true)) {
                    $trip->update([
                        'completed_at' => null,
                        'end_km' => null,
                    ]);

                    $syncService->syncTripCheckpointsState($trip->refresh(), reconcileCheckpoints: true);
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
                        if (static::applyCompletedCheckpoints($trip)) {
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

    public static function applyCompletedCheckpoints(Trip $record): bool
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
}
