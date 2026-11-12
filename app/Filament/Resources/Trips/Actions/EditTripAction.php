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
                $trip?->loadMissing(['startLocation', 'endLocation']);

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

                // Field trạng thái đã bị khoá. Chuyến chỉ kết thúc khi admin đặt completed_at
                // hoặc mốc cuối cùng là "Kết thúc xe". Mốc "Hoàn thành" là theo từng đơn.
                $lastCheckpoint = $trip->checkpoints()
                    ->reorder()
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('id')
                    ->first();

                if ($lastCheckpoint?->checkpoint_type === CheckpointType::End) {
                    if (blank($data['completed_at'] ?? null)) {
                        $data['completed_at'] = $lastCheckpoint->occurred_at;
                    }

                    if ($lastCheckpoint->km_reading !== null) {
                        $data['end_km'] = $lastCheckpoint->km_reading;
                    }
                }

                $isEnding = filled($data['completed_at'] ?? null);

                if ($isEnding) {
                    $data['status'] = TripStatus::Completed;
                } elseif (static::applyCompletedCheckpoints($trip)) {
                    $data['status'] = TripStatus::Delivered;
                } elseif ($trip->isPending() && filled($data['started_at'] ?? null)) {
                    $data['status'] = TripStatus::Started;
                }

                $oldDriverId = $trip->driver_id;
                $newDriverId = $data['driver_id'] ?? null;

                $effectiveDriverId = $newDriverId ?: $trip->driver_id;
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

                $latestCheckpoint = $trip->checkpoints()
                    ->reorder()
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('id')
                    ->first();

                if ($latestCheckpoint !== null) {
                    $hasEndCheckpoint = $latestCheckpoint->checkpoint_type === CheckpointType::End;
                    $isEnding = $hasEndCheckpoint || filled($trip->completed_at);

                    if ($isEnding) {
                        if ($trip->status !== TripStatus::Completed) {
                            $trip->update([
                                'status' => TripStatus::Completed,
                                'completed_at' => $trip->completed_at ?? $latestCheckpoint->occurred_at,
                                'end_km' => $trip->end_km ?? $latestCheckpoint->km_reading,
                            ]);
                        }

                        $trip->orders()
                            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Completed->value])
                            ->get()
                            ->each(fn ($order) => $order->update(['status' => OrderStatus::Completed]));
                    } elseif (static::applyCompletedCheckpoints($trip)) {
                        if ($trip->status !== TripStatus::Delivered) {
                            $trip->update(['status' => TripStatus::Delivered]);
                        }
                    } else {
                        $wasPending = ($kmSnapshotBeforeEdit['trip']['status'] ?? null) === TripStatus::Pending->value;
                        $existingLatest = collect($kmSnapshotBeforeEdit['checkpoints'] ?? [])->firstWhere('id', $latestCheckpoint->id);
                        $isNewCheckpoint = $existingLatest === null;
                        $checkpointTypeChanged = $existingLatest !== null && ($existingLatest['checkpoint_type'] ?? null) !== $latestCheckpoint->checkpoint_type?->value;

                        // Chỉ cập nhật trạng thái chuyến nếu có mốc mới, mốc đổi loại, hoặc chuyến trước đó là chờ chạy
                        if (($isNewCheckpoint || $checkpointTypeChanged || $wasPending) && ! in_array($trip->status, [TripStatus::Cancelled, TripStatus::DriverSwap], true)) {
                            $newStatus = $latestCheckpoint->checkpoint_type?->toTripStatus() ?? TripStatus::Started;
                            $updates = [];

                            if ($trip->status !== $newStatus) {
                                $updates['status'] = $newStatus;
                            }

                            if ($trip->started_at === null) {
                                $firstCheckpoint = $trip->checkpoints()->reorder()->orderBy('occurred_at')->orderBy('id')->first();
                                $updates['started_at'] = $firstCheckpoint?->occurred_at ?? now();
                            }

                            if (! empty($updates)) {
                                $trip->update($updates);
                            }

                            if ($wasPending && ! $trip->isPending()) {
                                foreach ($trip->orders()->where('status', OrderStatus::Assigned)->get() as $order) {
                                    $order->update([
                                        'status' => OrderStatus::Sent,
                                        'sent_at' => $trip->started_at ?? now(),
                                    ]);
                                }
                            }
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
