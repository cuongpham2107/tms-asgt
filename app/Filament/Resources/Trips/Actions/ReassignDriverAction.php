<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Enums\CheckpointType;
use App\Enums\DriverSwapReason;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\DriverShift;
use App\Models\DriverSwap;
use App\Models\Location;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Notification\DriverNotificationService;
use App\Services\TripKmSplitService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

class ReassignDriverAction
{
    public static function make(?string $name = null): Action
    {
        return Action::make($name ?? 'reassign_driver')
            ->label('Gán lại tài xế')
            ->icon('heroicon-o-arrow-path')
            ->button()
            ->size('xs')
            ->color('warning')
            ->visible(fn (?Model $record): bool => static::resolveTrip($record)?->status === TripStatus::DriverSwap)
            ->modalHeading(fn (?Model $record): string => 'Gán lại tài xế mới'.(static::resolveTrip($record)?->trip_code ? ' — '.static::resolveTrip($record)->trip_code : ''))
            ->modalDescription('Chuyến đi đang yêu cầu đảo tài xế. Chọn tài xế mới để tiếp tục xử lý.')
            ->form([
                Select::make('new_driver_id')
                    ->label('Tài xế mới')
                    ->options(fn (?Model $record): array => static::getDriverOptions(static::resolveTrip($record)))
                    ->searchable()
                    ->required(),
                Placeholder::make('handover_info')
                    ->label('Km bàn giao')
                    ->content(fn (?Model $record): string => static::getHandoverInfo(static::resolveTrip($record))),
                Select::make('reason')
                    ->label('Lý do')
                    ->options(DriverSwapReason::class)
                    ->default(DriverSwapReason::CargoNotUnloaded)
                    ->required(),
                Checkbox::make('create_return_trip')
                    ->label('Tạo chuyến không hàng (quay đầu)')
                    ->helperText('Tạo chuyến đi rỗng cho tài xế mới để nhập km')
                    ->hidden()
                    ->live(),
                Select::make('return_vehicle_id')
                    ->label('Xe cho chuyến quay đầu')
                    ->options(function (): array {
                        return Vehicle::query()
                            ->where('is_active', true)
                            ->select('id', 'plate_number', 'status')
                            ->get()
                            ->mapWithKeys(fn ($v) => [$v->id => "{$v->plate_number} - {$v->status->getLabel()}"])
                            ->all();
                    })
                    ->visible(fn (callable $get): bool => (bool) $get('create_return_trip'))
                    ->live()
                    ->searchable()
                    ->required(),
                Placeholder::make('vehicle_gps_info')
                    ->label('Vị trí xe hiện tại')
                    ->content(function (callable $get): string {
                        $vehicleId = $get('return_vehicle_id');
                        if (! $vehicleId) {
                            return '—';
                        }

                        $vehicle = Vehicle::find($vehicleId);

                        return $vehicle?->gps_address
                            ?? ($vehicle ? sprintf('Lat: %s, Lng: %s', $vehicle->gps_lat ?? '—', $vehicle->gps_lng ?? '—') : '—');
                    })
                    ->visible(fn (callable $get): bool => (bool) $get('create_return_trip') && (bool) $get('return_vehicle_id')),
                Select::make('start_location_id')
                    ->label('Điểm bắt đầu')
                    ->options(fn (): array => Location::query()
                        ->where('is_active', true)
                        ->select('id', 'name', 'address')
                        ->get()
                        ->mapWithKeys(fn ($loc) => [$loc->id => "{$loc->name}".($loc->address ? " ({$loc->address})" : '')])
                        ->all())
                    ->visible(fn (callable $get): bool => (bool) $get('create_return_trip'))
                    ->searchable(),
                Select::make('end_location_id')
                    ->label('Điểm kết thúc')
                    ->options(fn (): array => Location::query()
                        ->where('is_active', true)
                        ->select('id', 'name', 'address')
                        ->get()
                        ->mapWithKeys(fn ($loc) => [$loc->id => "{$loc->name}".($loc->address ? " ({$loc->address})" : '')])
                        ->all())
                    ->visible(fn (callable $get): bool => (bool) $get('create_return_trip'))
                    ->searchable(),
            ])
            ->action(function (?Model $record, array $data, Action $action): void {
                $trip = static::resolveTrip($record);
                if (! $trip) {
                    $action->cancel();

                    return;
                }

                $success = static::executeSwap($trip, $data);
                if (! $success) {
                    $action->cancel();
                }
            });
    }

    /**
     * Danh sách tài xế khả dụng để chọn đảo lái.
     *
     * @return array<int, string>
     */
    public static function getDriverOptions(?Trip $trip = null): array
    {
        return User::query()
            ->role('driver')
            ->where('is_active', true)
            ->with([
                'vehiclesAsDriver' => fn ($q) => $q->select('id', 'plate_number', 'gps_lat', 'gps_lng'),
                'driverShifts' => fn ($q) => $q->whereNull('end_time'),
            ])
            ->get()
            ->sortByDesc(fn (User $driver): bool => $driver->driverShifts->isNotEmpty())
            ->mapWithKeys(function (User $driver): array {
                $parts = [$driver->name];

                $assignedVehicle = $driver->vehiclesAsDriver->first();
                if ($assignedVehicle) {
                    $parts[] = $assignedVehicle->plate_number;
                }

                if ($driver->phone) {
                    $parts[] = $driver->phone;
                }

                $activeShift = $driver->driverShifts->first();
                if ($activeShift && $activeShift->shift_type) {
                    $shiftLabel = $activeShift->shift_type->getLabel();
                    $startTime = $activeShift->start_time?->format('H:i');
                    $parts[] = "Ca: {$shiftLabel}";
                    if ($startTime) {
                        $parts[] = $startTime;
                    }
                }

                if ($assignedVehicle?->gps_lat && $assignedVehicle?->gps_lng) {
                    $location = CreatesOrderTransportCards::findNearestLocation(
                        (float) $assignedVehicle->gps_lat,
                        (float) $assignedVehicle->gps_lng,
                    );
                    if ($location['name']) {
                        $parts[] = $location['name'];
                    }
                }

                return [$driver->id => implode(' · ', $parts)];
            })
            ->all();
    }

    /**
     * Danh sách tài xế chi tiết cho bộ chọn searchable picker.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getDriverListForPicker(?Trip $trip = null): array
    {
        return User::query()
            ->role('driver')
            ->where('is_active', true)
            ->with([
                'vehiclesAsDriver' => fn ($q) => $q->select('id', 'plate_number', 'gps_lat', 'gps_lng'),
                'driverShifts' => fn ($q) => $q->whereNull('end_time'),
            ])
            ->get()
            ->sortByDesc(fn (User $driver): bool => $driver->driverShifts->isNotEmpty())
            ->map(function (User $driver): array {
                $assignedVehicle = $driver->vehiclesAsDriver->first();
                $activeShift = $driver->driverShifts->first();
                $shiftLabel = null;
                if ($activeShift && $activeShift->shift_type) {
                    $shiftLabel = 'Ca '.$activeShift->shift_type->getLabel().($activeShift->start_time ? ' ('.$activeShift->start_time->format('H:i').')' : '');
                }

                $locationName = null;
                if ($assignedVehicle?->gps_lat && $assignedVehicle?->gps_lng) {
                    $location = CreatesOrderTransportCards::findNearestLocation(
                        (float) $assignedVehicle->gps_lat,
                        (float) $assignedVehicle->gps_lng,
                    );
                    $locationName = $location['name'] ?? null;
                }

                $parts = array_filter([$driver->name, $assignedVehicle?->plate_number, $driver->phone, $shiftLabel, $locationName]);

                return [
                    'id' => $driver->id,
                    'name' => $driver->name,
                    'phone' => $driver->phone ?? '',
                    'plate' => $assignedVehicle?->plate_number ?? '',
                    'shift' => $shiftLabel ?? '',
                    'location' => $locationName ?? '',
                    'label' => implode(' · ', $parts),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Chuỗi thông tin Km bàn giao mới nhất của chuyến.
     */
    public static function getHandoverInfo(?Trip $trip = null): string
    {
        if (! $trip) {
            return 'Chưa có thông tin chuyến';
        }

        $handover = static::latestHandoverCheckpoint($trip);

        return $handover
            ? number_format((float) $handover->km_reading, 1, ',', '.').' km lúc '.($handover->occurred_at?->format('H:i d/m') ?? '')
            : 'Chưa có mốc bàn giao';
    }

    /**
     * Thực hiện logic đổi lái cho chuyến xe.
     *
     * @param  array<string, mixed>  $data
     */
    public static function executeSwap(Trip $trip, array $data): bool
    {
        $oldDriver = $trip->driver;
        if (! $oldDriver) {
            Notification::make()
                ->danger()
                ->title('Không thể gán lại tài xế')
                ->body('Chuyến đi chưa có tài xế cũ.')
                ->send();

            return false;
        }

        $newDriver = User::find($data['new_driver_id'] ?? null);
        if (! $newDriver) {
            Notification::make()
                ->danger()
                ->title('Không tìm thấy tài xế')
                ->body('Tài xế mới không tồn tại.')
                ->send();

            return false;
        }

        $handover = static::latestHandoverCheckpoint($trip);

        if ($handover === null) {
            Notification::make()
                ->danger()
                ->title('Chuyến chưa có mốc bàn giao')
                ->body('Tài xế cần bấm "Bàn giao chuyến chưa xong" trên app để ghi km bàn giao trước.')
                ->send();

            return false;
        }

        $handoverKm = (float) $handover->km_reading;
        $companionIds = TripCheckpoint::where('trip_id', $trip->id)
            ->where('checkpoint_type', CheckpointType::DriverSwap)
            ->where('occurred_at', $handover->occurred_at)
            ->pluck('id');

        $existingSwap = DriverSwap::where('trip_id', $trip->id)->whereIn('checkpoint_id', $companionIds)->first();

        if ($existingSwap === null) {
            $unlinkedId = array_search(true, array_map(
                fn (int $checkpointId): bool => $companionIds->contains($checkpointId),
                TripKmSplitService::matchUnlinkedSwaps($trip),
            ), true);
            $existingSwap = $unlinkedId === false ? null : DriverSwap::find($unlinkedId);
        }

        $receiverStarted = $existingSwap !== null && TripCheckpoint::where('trip_id', $trip->id)
            ->where('driver_id', $existingSwap->to_driver_id)
            ->where('occurred_at', '>', $handover->occurred_at)
            ->exists();

        if ($receiverStarted) {
            Notification::make()
                ->danger()
                ->title('Chuyến chưa có mốc bàn giao mới')
                ->body('Tài xế cần bấm "Bàn giao chuyến chưa xong" trên app để ghi km bàn giao trước.')
                ->send();

            return false;
        }

        $oldShift = DriverShift::query()->where('driver_id', $oldDriver->id)
            ->latest('start_time')
            ->first();

        $newShift = DriverShift::query()->where('driver_id', $data['new_driver_id'])
            ->whereNull('end_time')
            ->first();

        $swapData = [
            'to_driver_id' => $data['new_driver_id'],
            'to_shift_id' => $newShift?->id,
            'reason' => $data['reason'] ?? DriverSwapReason::CargoNotUnloaded->value,
            'created_by' => auth()->id(),
        ];

        if ($existingSwap !== null) {
            $existingSwap->update([...$swapData, 'checkpoint_id' => $handover->id]);
        } else {
            DriverSwap::create([
                ...$swapData,
                'trip_id' => $trip->id,
                'checkpoint_id' => $handover->id,
                'from_driver_id' => $trip->driver_id,
                'from_shift_id' => $oldShift?->id,
                'handover_km' => $handoverKm,
            ]);
        }

        $lastCheckpoint = TripCheckpoint::query()
            ->where('trip_id', $trip->id)
            ->where('checkpoint_type', '!=', CheckpointType::DriverSwap)
            ->latest('occurred_at')
            ->first();

        $status = match ($lastCheckpoint?->checkpoint_type) {
            CheckpointType::Started => TripStatus::Started,
            CheckpointType::ArrivedPickup => TripStatus::ArrivedPickup,
            CheckpointType::LeftPickup => TripStatus::Delivering,
            CheckpointType::ArrivedDelivery => TripStatus::ArrivedDelivery,
            CheckpointType::Completed => TripStatus::Delivered,
            default => TripStatus::Pending,
        };

        $trip->update([
            'driver_id' => $data['new_driver_id'],
            'shift_id' => $newShift?->id,
            'status' => $status,
            'start_km' => $trip->start_km ?? $trip->vehicle?->current_mileage,
            'started_at' => $trip->started_at ?? now(),
        ]);

        $restoredStatus = in_array($status, [
            TripStatus::Delivering,
            TripStatus::ArrivedDelivery,
            TripStatus::Delivered,
        ]) ? OrderStatus::InTransit : OrderStatus::Sent;

        $trip->orders()
            ->where('status', OrderStatus::DriverSwap->value)
            ->update(['status' => $restoredStatus->value]);

        if ($newDriver !== null) {
            try {
                app(DriverNotificationService::class)->sendTripDriverSwapped($trip, $newDriver, $oldDriver, $handoverKm);
            } catch (\Throwable) {
            }
        }

        Notification::make()
            ->success()
            ->title('Đã gán lại tài xế')
            ->body(sprintf(
                'Chuyến #%s đã được gán lại từ %s sang %s',
                $trip->trip_code,
                $oldDriver?->name ?? 'N/A',
                $newDriver->name ?? 'N/A',
            ))
            ->send();

        if (! empty($data['create_return_trip']) && ! empty($data['return_vehicle_id'])) {
            $vehicle = Vehicle::find($data['return_vehicle_id']);

            $now = now();

            $returnTrip = Trip::create([
                'trip_code' => Trip::generateTripCode(),
                'vehicle_id' => $data['return_vehicle_id'],
                'driver_id' => $oldDriver->id,
                'shift_id' => $oldShift?->id,
                'status' => TripStatus::ReturnTrip,
                'start_location_id' => $data['start_location_id'] ?? null,
                'end_location_id' => $data['end_location_id'] ?? null,
                'started_at' => $now,
                'start_km' => $vehicle?->current_mileage,
                'is_empty_run' => true,
            ]);

            TripCheckpoint::create([
                'trip_id' => $returnTrip->id,
                'checkpoint_type' => CheckpointType::Started->value,
                'occurred_at' => $now,
                'km_reading' => $vehicle?->current_mileage,
                'gps_lat' => $vehicle?->gps_lat,
                'gps_lng' => $vehicle?->gps_lng,
                'driver_id' => $oldDriver->id,
                'shift_id' => $oldShift?->id,
                'vehicle_id' => $data['return_vehicle_id'],
                'order_id' => null,
            ]);

            TripCheckpoint::create([
                'trip_id' => $returnTrip->id,
                'checkpoint_type' => CheckpointType::End->value,
                'occurred_at' => $now->addSecond(),
                'km_reading' => null,
                'gps_lat' => $vehicle?->gps_lat,
                'gps_lng' => $vehicle?->gps_lng,
                'driver_id' => $oldDriver->id,
                'shift_id' => $oldShift?->id,
                'vehicle_id' => $data['return_vehicle_id'],
                'order_id' => null,
            ]);

            try {
                app(DriverNotificationService::class)->sendEmptyRunDispatched($returnTrip);
            } catch (\Throwable) {
            }

            Notification::make()
                ->success()
                ->title('Đã tạo chuyến quay đầu')
                ->body("Chuyến không hàng #{$returnTrip->trip_code} đã được tạo cho tài xế {$oldDriver->name}")
                ->send();
        }

        return true;
    }

    /**
     * Mốc driver_swap mới nhất (mốc đầu của nhóm đồng hành) của chuyến.
     */
    public static function latestHandoverCheckpoint(Trip $trip): ?TripCheckpoint
    {
        $latest = TripCheckpoint::where('trip_id', $trip->id)
            ->where('checkpoint_type', CheckpointType::DriverSwap)
            ->whereNotNull('km_reading')
            ->latest('occurred_at')
            ->first();

        if ($latest === null) {
            return null;
        }

        return TripCheckpoint::where('trip_id', $trip->id)
            ->where('checkpoint_type', CheckpointType::DriverSwap)
            ->where('occurred_at', $latest->occurred_at)
            ->orderBy('id')
            ->first();
    }

    public static function resolveTrip(?Model $record): ?Trip
    {
        if ($record instanceof Trip) {
            return $record;
        }

        if ($record instanceof Vehicle) {
            if ($record->active_trip?->status === TripStatus::DriverSwap) {
                return $record->active_trip;
            }

            $swapTrip = $record->trips()->where('status', TripStatus::DriverSwap->value)->latest('id')->first();
            if ($swapTrip !== null) {
                return $swapTrip;
            }

            return $record->active_trip;
        }

        return null;
    }
}
