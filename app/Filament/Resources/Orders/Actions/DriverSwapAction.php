<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\DriverSwapReason;
use App\Enums\OrderStatus;
use App\Models\DriverSwap;
use App\Models\Order;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class DriverSwapAction
{
    public static function make(): Action
    {
        return Action::make('driver_swap')
            ->label('Đảo lái')
            ->icon('heroicon-o-arrow-path')
            ->color('primary')
            ->hidden(fn (Order $record): bool => ! $record->status->canSwapDriver())
            ->modalHeading('Đảo lái xe')
            ->modalDescription('Chuyển giao đơn hàng cho tài xế mới')
            ->form([
                Select::make('to_driver_id')
                    ->label('Tài xế mới')
                    ->options(fn (Order $record): array => User::where('is_active', true)
                        ->where('id', '!=', $record->driver_id)
                        ->pluck('name', 'id')
                        ->toArray())
                    ->required()
                    ->searchable()
                    ->preload(),
                TextInput::make('handover_km')
                    ->label('Km chuyển giao')
                    ->numeric()
                    ->required()
                    ->helperText('Nhập số km hiện tại của xe tại thời điểm chuyển giao'),
                Select::make('reason')
                    ->label('Lý do đảo lái')
                    ->options(DriverSwapReason::class)
                    ->required(),
                Textarea::make('note')
                    ->label('Ghi chú')
                    ->rows(2),
            ])
            ->action(function (Order $record, array $data): void {
                if (! $record->status->canSwapDriver()) {
                    Notification::make()
                        ->title('Không thể đảo lái')
                        ->body('Đơn hàng không ở trạng thái cho phép đảo lái.')
                        ->warning()
                        ->send();

                    return;
                }

                if (! $record->driver_id) {
                    Notification::make()
                        ->title('Không thể đảo lái')
                        ->body('Đơn hàng chưa có tài xế được gán.')
                        ->warning()
                        ->send();

                    return;
                }

                $fromDriverId = $record->driver_id;
                $toDriverId = $data['to_driver_id'];

                $driverSwap = DriverSwap::create([
                    'order_id' => $record->id,
                    'from_driver_id' => $fromDriverId,
                    'to_driver_id' => $toDriverId,
                    'from_shift_id' => $record->shift_id,
                    'handover_km' => $data['handover_km'],
                    'reason' => $data['reason'],
                    'note' => $data['note'] ?? null,
                    'created_by' => Auth::id(),
                ]);

                Order::query()->whereKey($record->id)->update([
                    'driver_id' => $toDriverId,
                    'status' => OrderStatus::DriverSwap->value,
                ]);

                Notification::make()
                    ->title('Đảo lái thành công')
                    ->body("Đơn hàng đã được chuyển cho tài xế mới. Km chuyển giao: {$data['handover_km']}")
                    ->success()
                    ->send();
            });
    }
}
