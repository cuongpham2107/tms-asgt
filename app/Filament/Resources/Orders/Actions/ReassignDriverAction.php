<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\DriverSwapReason;
use App\Enums\OrderStatus;
use App\Models\DriverShift;
use App\Models\Order;
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
                    ->options(fn (): array => User::query()->where('is_active', true)
                        ->pluck('name', 'id')
                        ->toArray())
                    ->searchable()
                    ->required(),
                Select::make('reason')
                    ->label('Lý do')
                    ->options(DriverSwapReason::class)
                    ->required(),
            ])
            ->action(function (Order $record, array $data): void {
                $oldDriver = $record->driver;

                $oldShift = DriverShift::query()->where('driver_id', $record->driver_id)
                    ->whereNull('end_time')
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

                $record->driverSwaps()->create([
                    'from_driver_id' => $record->driver_id,
                    'to_driver_id' => $data['new_driver_id'],
                    'from_shift_id' => $oldShift->id,
                    'to_shift_id' => $newShift?->id,
                    'reason' => $data['reason'],
                    'created_by' => auth()->id(),
                ]);

                $record->update([
                    'driver_id' => $data['new_driver_id'],
                    'status' => OrderStatus::Assigned->value,
                    'shift_id' => $newShift?->id,
                ]);

                if ($newShift && $record->vehicle_id) {
                    $currentSegment = $newShift->currentShiftVehicle();
                    if (! $currentSegment || (int) $currentSegment->vehicle_id !== (int) $record->vehicle_id) {
                        $newShift->shiftVehicles()->create([
                            'vehicle_id' => $record->vehicle_id,
                            'order_id' => $record->id,
                            'start_time' => now(),
                            'start_km' => $currentSegment?->end_km,
                            'start_gps_lat' => $currentSegment?->end_gps_lat,
                            'start_gps_lng' => $currentSegment?->end_gps_lng,
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
