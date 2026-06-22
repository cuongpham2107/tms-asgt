<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\DriverSwapReason;
use App\Enums\TripStatus;
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
            ->hidden(fn (Order $record): bool => ! $record->trip || ! $record->trip->status->canSwapDriver())
            ->modalHeading('Đảo lái xe')
            ->modalDescription('Chuyển giao chuyến đi cho tài xế mới')
            ->form([
                Select::make('to_driver_id')
                    ->label('Tài xế mới')
                    ->options(fn (Order $record): array => User::where('is_active', true)
                        ->where('id', '!=', $record->trip?->driver_id)
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
                $trip = $record->trip;

                if (! $trip || ! $trip->status->canSwapDriver()) {
                    Notification::make()
                        ->title('Không thể đảo lái')
                        ->body('Chuyến đi không ở trạng thái cho phép đảo lái.')
                        ->warning()
                        ->send();

                    return;
                }

                if (! $trip->driver_id) {
                    Notification::make()
                        ->title('Không thể đảo lái')
                        ->body('Chuyến đi chưa có tài xế được gán.')
                        ->warning()
                        ->send();

                    return;
                }

                $toDriverId = $data['to_driver_id'];

                DriverSwap::create([
                    'trip_id' => $trip->id,
                    'from_driver_id' => $trip->driver_id,
                    'to_driver_id' => $toDriverId,
                    'from_shift_id' => $trip->shift_id,
                    'handover_km' => $data['handover_km'],
                    'reason' => $data['reason'],
                    'note' => $data['note'] ?? null,
                    'created_by' => Auth::id(),
                ]);

                $trip->update([
                    'driver_id' => $toDriverId,
                    'shift_id' => null,
                    'status' => TripStatus::DriverSwap,
                ]);

                Notification::make()
                    ->title('Đảo lái thành công')
                    ->body("Chuyến #{$trip->trip_code} đã được chuyển cho tài xế mới. Km chuyển giao: {$data['handover_km']}")
                    ->success()
                    ->send();
            });
    }
}
