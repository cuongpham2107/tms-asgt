<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\CheckpointType;
use App\Enums\DriverSwapReason;
use App\Enums\TripStatus;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\DriverShift;
use App\Models\DriverSwap;
use App\Models\Order;
use App\Models\TripCheckpoint;
use App\Models\User;
use Filament\Actions\Action;
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
            ->visible(fn (Order $record): bool => $record->trip && $record->trip->status === TripStatus::DriverSwap)
            ->modalHeading('Gán lại tài xế mới')
            ->modalDescription('Chuyến đi đang yêu cầu đảo tài xế. Chọn tài xế mới để tiếp tục xử lý.')
            ->form([
                Select::make('new_driver_id')
                    ->label('Tài xế mới')
                    ->options(fn (Order $record): array => User::query()
                        ->where('is_active', true)
                        ->whereHas('driverShifts', fn ($q) => $q->whereNull('end_time'))
                        ->with(['vehiclesAsDriver' => fn ($q) => $q->select('id', 'plate_number', 'gps_lat', 'gps_lng')])
                        ->get()
                        ->mapWithKeys(function (User $driver): array {
                            $parts = [$driver->name];

                            $assignedVehicle = $driver->vehiclesAsDriver->first();
                            if ($assignedVehicle) {
                                $parts[] = $assignedVehicle->plate_number;
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
            ])
            ->action(function (Order $record, array $data): void {
                $trip = $record->trip;
                if (! $trip) {
                    Notification::make()
                        ->danger()
                        ->title('Không tìm thấy chuyến đi')
                        ->body('Đơn hàng này chưa được gán chuyến đi.')
                        ->send();

                    return;
                }

                $oldDriver = $trip->driver;
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

                if (! $newShift) {
                    Notification::make()
                        ->danger()
                        ->title('Tài xế mới chưa có ca trực')
                        ->body('Tài xế này hiện không có ca đang hoạt động. Vui lòng chọn tài xế khác.')
                        ->send();

                    return;
                }

                DriverSwap::create([
                    'trip_id' => $trip->id,
                    'from_driver_id' => $trip->driver_id,
                    'to_driver_id' => $data['new_driver_id'],
                    'from_shift_id' => $oldShift?->id,
                    'to_shift_id' => $newShift->id,
                    'reason' => $data['reason'],
                    'created_by' => auth()->id(),
                ]);

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
                ]);

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
            });
    }
}
