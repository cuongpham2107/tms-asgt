<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Enums\VehicleStatus;
use App\Models\DriverShift;
use App\Models\Order;
use App\Models\User;
use App\Models\Vehicle;
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
                    ->options(fn (): array => User::where('is_active', true)
                        ->pluck('name', 'id')
                        ->toArray())
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set): void {
                        $vehicle = Vehicle::where('current_driver_id', $state)->first();
                        if ($vehicle) {
                            $set('new_vehicle_id', $vehicle->id);
                        }
                    }),

                Select::make('new_vehicle_id')
                    ->label('Xe mới')
                    ->options(fn (): array => Vehicle::where('status', VehicleStatus::On)
                        ->pluck('plate_number', 'id')
                        ->toArray())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (Order $record, array $data): void {
                $oldDriver = $record->driver;

                $oldShift = DriverShift::where('driver_id', $record->driver_id)
                    ->where('vehicle_id', $record->vehicle_id)
                    ->whereNull('end_time')
                    ->first();

                if ($oldShift) {
                    $oldShift->end_time = now();
                    $oldShift->save();
                }

                $newShift = DriverShift::where('driver_id', $data['new_driver_id'])
                    ->where('vehicle_id', $data['new_vehicle_id'])
                    ->whereNull('end_time')
                    ->first();

                $record->driverSwaps()->create([
                    'from_driver_id' => $record->driver_id,
                    'to_driver_id' => $data['new_driver_id'],
                    'from_shift_id' => $oldShift?->id,
                    'to_shift_id' => $newShift?->id,
                    'created_by' => auth()->id(),
                ]);

                $record->update([
                    'driver_id' => $data['new_driver_id'],
                    'vehicle_id' => $data['new_vehicle_id'],
                    'status' => OrderStatus::Assigned->value,
                    'shift_id' => $newShift?->id,
                ]);

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
