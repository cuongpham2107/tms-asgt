<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Filament\Resources\Trips\Schemas\TripForm;
use App\Models\DriverShift;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Km\TripKmAdjustmentService;
use App\Services\Notification\DriverNotificationService;
use App\Services\Trip\DeliveryProgressSyncService;
use App\Services\Trip\TripCompletionService;
use App\Services\Trip\TripOrderInlineEditService;
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
        // Chữ ký mốc hành trình trước khi lưu, để biết điều hành có sửa gì ảnh hưởng app tài xế không.
        $checkpointsSignatureBeforeEdit = null;
        // Có đơn hàng nào trong tab "Đơn hàng" bị sửa không.
        $inlineOrdersChanged = false;

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

                if ($trip) {
                    $data['orders_data'] = static::orderEditState($trip);
                }

                return $data;
            })
            ->schema(fn (Schema $schema): Schema => TripForm::configure($schema))
            ->beforeFormValidated(function (?Model $record = null, $livewire = null) use (&$checkpointsSignatureBeforeEdit): void {
                $trip = static::resolveTrip($record, $livewire);

                if ($trip !== null) {
                    $checkpointsSignatureBeforeEdit = $trip->checkpointsSignature();
                }
            })
            ->before(function (?Model $record = null) use (&$kmSnapshotBeforeEdit): void {
                $trip = static::resolveTrip($record);
                if ($trip) {
                    $kmSnapshotBeforeEdit = app(TripKmAdjustmentService::class)->kmSnapshot($trip);
                }
            })
            ->using(function (?Model $record, array $data) use (&$inlineOrdersChanged): Trip {
                $trip = static::resolveTrip($record) ?? $record;
                /** @var Trip $trip */
                $trip->loadMissing(['vehicle', 'orders']);

                $ordersData = $data['orders_data'] ?? [];
                unset($data['orders_data']);

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

                $inlineOrdersChanged = app(TripOrderInlineEditService::class)
                    ->apply($trip, is_array($ordersData) ? $ordersData : []) !== [];

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
            ->after(function (?Model $record = null, $livewire = null) use (&$kmSnapshotBeforeEdit, &$checkpointsSignatureBeforeEdit, &$inlineOrdersChanged): void {
                $trip = static::resolveTrip($record, $livewire) ?? $record;
                /** @var Trip $trip */
                app(DeliveryProgressSyncService::class)->syncAfterTripEdit($trip, $kmSnapshotBeforeEdit);

                $signatureBefore = $checkpointsSignatureBeforeEdit;
                $checkpointsSignatureBeforeEdit = null;
                $ordersChanged = $inlineOrdersChanged;
                $inlineOrdersChanged = false;

                $checkpointsChanged = $signatureBefore !== null
                    && $signatureBefore !== $trip->refresh()->checkpointsSignature();

                if ($checkpointsChanged || $ordersChanged) {
                    try {
                        app(DriverNotificationService::class)->sendTripUpdated($trip);
                    } catch (\Throwable) {
                    }
                }

                if ($livewire instanceof VehicleDispatchBoard) {
                    $livewire->flushCachedTableRecords();
                    $livewire->dataVersion++;
                }
            });
    }

    /**
     * Dữ liệu ban đầu cho tab "Đơn hàng" trong form Sửa chuyến.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function orderEditState(Trip $trip): array
    {
        $trip->loadMissing(['orders.customer', 'orders.area', 'orders.deliveryPoints']);

        return $trip->orders->mapWithKeys(fn (Order $order): array => [
            $order->id => [
                'order_code' => $order->order_code,
                'customer_name' => $order->customer?->name,
                'area_code' => $order->area?->code,
                'cargo_name' => $order->cargo_name,
                'total_packages' => $order->total_packages,
                'total_weight' => $order->total_weight,
                'chargeable_weight' => $order->chargeable_weight,
                'pickup_location_id' => $order->pickup_location_id,
                'planned_loading_at' => $order->planned_loading_at?->format('Y-m-d H:i'),
                'notes' => $order->notes,
                'deliveryPoints' => $order->deliveryPoints
                    ->sortBy(fn (OrderDeliveryPoint $point): array => [(int) $point->sequence, (int) $point->id])
                    ->mapWithKeys(fn (OrderDeliveryPoint $point): array => [
                        $point->id => [
                            'id' => $point->id,
                            'location_id' => $point->location_id,
                            'address' => $point->address,
                            'contact_person' => $point->contact_person,
                            'contact_phone' => $point->contact_phone,
                            'total_packages' => $point->total_packages,
                            'total_weight' => $point->total_weight,
                        ],
                    ])
                    ->all(),
            ],
        ])->all();
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
