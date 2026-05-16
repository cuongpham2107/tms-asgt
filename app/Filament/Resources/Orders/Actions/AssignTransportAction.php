<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Enums\VehicleStatus;
use App\Filament\Forms\Components\DriverPicker;
use App\Filament\Forms\Components\VehiclePicker;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Order;
use App\Models\Vehicle;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Throwable;

class AssignTransportAction extends CreatesOrderTransportCards
{
    public static function make(): Action
    {
        return Action::make('assign_transport')
            ->label('Gán lái, xe')
            ->icon('heroicon-o-truck')
            ->color('primary')
            ->hidden(fn (Order $record): bool => ! $record->status->canAssign())
            ->modal()
            ->modalHeading('Gán phương tiện và lái xe')
            ->modalDescription('Chọn phương tiện và lái xe cho đơn hàng này.')
            ->modalWidth('2xl')
            ->stickyModalFooter()
            ->schema([
                VehiclePicker::make('vehicle_id')
                    ->label('Phương tiện')
                    ->cards(fn (Get $get): array => self::resolveVehicleCards(
                        self::normalizeDecimal($get('total_weight') ?? 0),
                        null,
                    ))
                    ->searchPlaceholder('Tìm biển số, loại xe...')
                    ->required(),
                DriverPicker::make('driver_id')
                    ->label('Lái xe')
                    ->cards(fn (): array => self::resolveDriverCards())
                    ->searchPlaceholder('Tìm tên, email...')
                    ->required(),
            ])
            ->action(function (Order $record, array $data): void {
                try {
                    $record->update([
                        'vehicle_id' => $data['vehicle_id'] ?? null,
                        'driver_id' => $data['driver_id'] ?? null,
                        'status' => (filled($data['driver_id'] ?? null) || filled($data['vehicle_id'] ?? null))
                            ? OrderStatus::Assigned
                            : $record->status,
                    ]);

                    if (filled($data['vehicle_id'] ?? null)) {
                        $vehicle = Vehicle::query()->find($data['vehicle_id']);

                        if ($vehicle !== null) {
                            $vehicle->status = VehicleStatus::Running;

                            if (filled($data['driver_id'] ?? null)) {
                                $vehicle->current_driver_id = (int) $data['driver_id'];
                            }

                            $vehicle->save();
                        }
                    }

                    Notification::make()
                        ->title('Gán phương tiện thành công')
                        ->body('Đơn hàng đã được gán phương tiện và lái xe.')
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Lỗi')
                        ->body('Không thể gán phương tiện: '.$e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
