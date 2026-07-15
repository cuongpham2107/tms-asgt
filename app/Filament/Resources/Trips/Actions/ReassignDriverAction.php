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
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

class ReassignDriverAction
{
    public static function make(): Action
    {
        return Action::make('reassign_driver')
            ->label('Gán lại tài xế')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (Trip $record): bool => $record->status === TripStatus::DriverSwap)
            ->modalHeading('Gán lại tài xế mới')
            ->modalDescription('Chuyến đi đang yêu cầu đảo tài xế. Chọn tài xế mới để tiếp tục xử lý.')
            ->form([
                Select::make('new_driver_id')
                    ->label('Tài xế mới')
                    ->options(fn (): array => User::query()
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
                        ->all())
                    ->searchable()
                    ->required(),
                Select::make('reason')
                    ->label('Lý do')
                    ->options(DriverSwapReason::class)
                    ->required(),
                Checkbox::make('create_return_trip')
                    ->label('Tạo chuyến không hàng (quay đầu)')
                    ->helperText('Tạo chuyến đi rỗng cho tài xế mới để nhập km')
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
            ->action(function (Trip $record, array $data): void {
                $oldDriver = $record->driver;
                if (! $oldDriver) {
                    Notification::make()
                        ->danger()
                        ->title('Không thể gán lại tài xế')
                        ->body('Chuyến đi chưa có tài xế cũ.')
                        ->send();

                    return;
                }

                $newDriver = User::find($data['new_driver_id']);
                if (! $newDriver) {
                    Notification::make()
                        ->danger()
                        ->title('Không tìm thấy tài xế')
                        ->body('Tài xế mới không tồn tại.')
                        ->send();

                    return;
                }

                $oldShift = DriverShift::query()->where('driver_id', $oldDriver->id)
                    ->latest('start_time')
                    ->first();

                $newShift = DriverShift::query()->where('driver_id', $data['new_driver_id'])
                    ->whereNull('end_time')
                    ->first();

                DriverSwap::create([
                    'trip_id' => $record->id,
                    'from_driver_id' => $record->driver_id,
                    'to_driver_id' => $data['new_driver_id'],
                    'from_shift_id' => $oldShift?->id,
                    'to_shift_id' => $newShift?->id,
                    'reason' => $data['reason'],
                    'created_by' => auth()->id(),
                ]);

                $lastCheckpoint = TripCheckpoint::query()
                    ->where('trip_id', $record->id)
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

                $record->update([
                    'driver_id' => $data['new_driver_id'],
                    'shift_id' => $newShift?->id,
                    'status' => $status,
                    'start_km' => $record->start_km ?? $record->vehicle?->current_mileage,
                    'started_at' => $record->started_at ?? now(),
                ]);

                $restoredStatus = in_array($status, [
                    TripStatus::Delivering,
                    TripStatus::ArrivedDelivery,
                    TripStatus::Delivered,
                ]) ? OrderStatus::InTransit : OrderStatus::Sent;

                $record->orders()
                    ->where('status', OrderStatus::DriverSwap->value)
                    ->update(['status' => $restoredStatus->value]);

                Notification::make()
                    ->success()
                    ->title('Đã gán lại tài xế')
                    ->body(sprintf(
                        'Chuyến #%s đã được gán lại từ %s sang %s',
                        $record->trip_code,
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

                    Notification::make()
                        ->success()
                        ->title('Đã tạo chuyến quay đầu')
                        ->body("Chuyến không hàng #{$returnTrip->trip_code} đã được tạo cho tài xế {$oldDriver->name}")
                        ->send();
                }
            });
    }
}
