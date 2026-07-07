<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Enums\DriverSwapReason;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\DriverSwap;
use App\Models\Trip;
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
            ->hidden(fn (Trip $record): bool => ! $record->status->canSwapDriver())
            ->modalHeading('Đảo lái xe')
            ->modalDescription('Chuyển giao chuyến đi cho tài xế mới')
            ->form([
                Select::make('to_driver_id')
                    ->label('Tài xế mới')
                    ->options(fn (Trip $record): array => User::query()
                        ->where('is_active', true)
                        ->where('id', '!=', $record->driver_id)
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

                            return [$driver->id => implode(' · ', $parts)];
                        })
                        ->all())
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
            ->action(function (Trip $record, array $data): void {
                if (! $record->status->canSwapDriver()) {
                    Notification::make()
                        ->title('Không thể đảo lái')
                        ->body('Chuyến đi không ở trạng thái cho phép đảo lái.')
                        ->warning()
                        ->send();

                    return;
                }

                if (! $record->driver_id) {
                    Notification::make()
                        ->title('Không thể đảo lái')
                        ->body('Chuyến đi chưa có tài xế được gán.')
                        ->warning()
                        ->send();

                    return;
                }

                $toDriverId = $data['to_driver_id'];

                if (! $record->shift_id) {
                    Notification::make()
                        ->title('Không thể đảo lái')
                        ->body('Tài xế hiện tại không có ca trực.')
                        ->warning()
                        ->send();

                    return;
                }

                DriverSwap::create([
                    'trip_id' => $record->id,
                    'from_driver_id' => $record->driver_id,
                    'to_driver_id' => $toDriverId,
                    'from_shift_id' => $record->shift_id,
                    'handover_km' => $data['handover_km'],
                    'reason' => $data['reason'],
                    'note' => $data['note'] ?? null,
                    'created_by' => Auth::id(),
                ]);

                $record->update([
                    'driver_id' => $toDriverId,
                    'shift_id' => null,
                    'status' => TripStatus::DriverSwap,
                ]);

                $record->orders()
                    ->whereIn('status', [OrderStatus::Sent->value, OrderStatus::InTransit->value])
                    ->update(['status' => OrderStatus::DriverSwap->value]);

                Notification::make()
                    ->title('Đảo lái thành công')
                    ->body("Chuyến #{$record->trip_code} đã được chuyển cho tài xế mới. Km chuyển giao: {$data['handover_km']}")
                    ->success()
                    ->send();
            });
    }
}
