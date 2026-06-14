<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\CheckpointType;
use App\Enums\DriverSwapReason;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\DriverShift;
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
            ->visible(fn (Order $record): bool => $record->status === OrderStatus::DriverSwap)
            ->modalHeading('Gán lại tài xế mới')
            ->modalDescription('Đơn hàng đang yêu cầu đảo tài xế. Chọn tài xế mới để tiếp tục xử lý.')
            ->form([
                Select::make('new_driver_id')
                    ->label('Tài xế mới')
                    ->options(fn (): array => User::query()
                        ->where('is_active', true)
                        ->whereHas('driverShifts', fn ($q) => $q->whereNull('end_time'))
                        ->with(['vehiclesAsDriver' => fn ($q) => $q->select('id', 'plate_number', 'gps_lat', 'gps_lng')])
                        ->withCount(['orders as active_orders_count' => fn ($q) => $q->whereIn('status', [
                            OrderStatus::Assigned->value,
                            OrderStatus::Sent->value,
                            OrderStatus::Started->value,
                            OrderStatus::ArrivedPickup->value,
                            OrderStatus::Delivering->value,
                            OrderStatus::ArrivedDelivery->value,
                        ])])
                        ->get()
                        ->mapWithKeys(function (User $driver): array {
                            $parts = [$driver->name];

                            $assignedVehicle = $driver->vehiclesAsDriver->first();
                            if ($assignedVehicle) {
                                $parts[] = $assignedVehicle->plate_number;
                            }

                            $activeOrders = (int) $driver->active_orders_count;
                            if ($activeOrders > 0) {
                                $parts[] = $activeOrders.' đơn';
                            } else {
                                $parts[] = 'Đang rảnh';
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
                $oldDriver = $record->driver;
                $oldShift = DriverShift::query()->where('driver_id', $oldDriver->id)
                    ->latest('start_time')
                    ->first();
                if (! $oldShift) {
                    Notification::make()
                        ->danger()
                        ->title('Không thể gán lại tài xế')
                        ->body('Tài xế cũ chưa có ca trực trên xe này. Vui lòng kiểm tra lại.')
                        ->send();

                    return;
                }

                if (! $oldShift->end_time) {
                    $oldShift->end_time = now();
                    $oldShift->save();
                }

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

                $record->driverSwaps()->create([
                    'from_driver_id' => $record->driver_id,
                    'to_driver_id' => $data['new_driver_id'],
                    'from_shift_id' => $oldShift->id,
                    'to_shift_id' => $newShift->id,
                    'reason' => $data['reason'],
                    'created_by' => auth()->id(),
                ]);

                $lastCheckpoint = TripCheckpoint::query()
                    ->where('order_id', $record->id)
                    ->where('checkpoint_type', '!=', CheckpointType::DriverSwap)
                    ->latest('occurred_at')
                    ->first();

                $status = match ($lastCheckpoint?->checkpoint_type) {
                    CheckpointType::Started => OrderStatus::Started,
                    CheckpointType::ArrivedPickup => OrderStatus::ArrivedPickup,
                    CheckpointType::LeftPickup => OrderStatus::Delivering,
                    CheckpointType::ArrivedDelivery => OrderStatus::ArrivedDelivery,
                    CheckpointType::Completed => OrderStatus::Delivered,
                    default => OrderStatus::Assigned,
                };

                $record->update([
                    'driver_id' => $data['new_driver_id'],
                    'status' => $status->value,
                    'shift_id' => $newShift?->id,
                ]);

                if ($newShift && $record->vehicle_id) {
                    $oldSegment = $oldShift->currentShiftVehicle();
                    if ($oldSegment && ! $oldSegment->end_time) {
                        $oldSegment->update([
                            'end_time' => now(),
                            'end_km' => $lastCheckpoint?->km_reading ?? $record->vehicle?->current_mileage,
                            'end_gps_lat' => $lastCheckpoint?->gps_lat,
                            'end_gps_lng' => $lastCheckpoint?->gps_lng,
                        ]);
                    }

                    $vehicle = $record->vehicle;
                    if ($vehicle && $oldSegment?->end_km) {
                        $vehicle->update(['current_mileage' => $oldSegment->end_km]);
                    }

                    $currentSegment = $newShift->currentShiftVehicle();
                    if (! $currentSegment || (int) $currentSegment->vehicle_id !== (int) $record->vehicle_id) {
                        $newShift->shiftVehicles()->create([
                            'vehicle_id' => $record->vehicle_id,
                            'order_id' => $record->id,
                            'start_time' => now(),
                            'start_km' => $oldSegment?->end_km,
                            'start_gps_lat' => $oldSegment?->end_gps_lat,
                            'start_gps_lng' => $oldSegment?->end_gps_lng,
                        ]);
                    }
                }

                Notification::make()
                    ->success()
                    ->title('Đã gán lại tài xế')
                    ->body(sprintf(
                        'Đơn hàng #%s đã được gán lại từ %s sang %s',
                        $record->order_code,
                        $oldDriver?->name ?? 'N/A',
                        User::find($data['new_driver_id'])?->name ?? 'N/A',
                    ))
                    ->send();
            });
    }
}
