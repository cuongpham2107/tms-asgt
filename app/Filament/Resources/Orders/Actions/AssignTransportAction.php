<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Filament\Forms\Components\DriverPicker;
use App\Filament\Forms\Components\VehiclePicker;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Order;
use App\Models\Trip;
use App\Models\Vehicle;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\DB;
use Throwable;

class AssignTransportAction extends CreatesOrderTransportCards
{
    public static function make(): Action
    {
        return Action::make('assign_transport')
            ->label('Gán xe')
            ->icon('heroicon-o-truck')
            ->color('primary')
            ->hidden(fn (Order $record): bool => ! $record->status->canAssign())
            ->modal()
            ->modalHeading('Gán phương tiện')
            ->modalDescription('Chọn phương tiện cho đơn hàng này. Lái xe sẽ tự động gán theo xe.')
            ->modalWidth(Width::MaxContent)
            ->stickyModalFooter()
            ->schema([
                Grid::make(2)
                    ->schema([
                        VehiclePicker::make('vehicle_id')
                            ->label('Phương tiện')
                            ->live()
                            ->afterStateUpdated(function (Set $set, $state): void {
                                if ($state) {
                                    $vehicle = Vehicle::find($state);
                                    $set('driver_id', $vehicle?->current_driver_id ?? null);
                                } else {
                                    $set('driver_id', null);
                                }
                            })
                            ->cards(fn (Order $record): array => self::resolveVehicleCards(
                                self::normalizeDecimal($record->total_weight ?? 0),
                                null,
                                null,
                            ))
                            ->searchPlaceholder('Tìm biển số, loại xe...')
                            ->required(),

                        DriverPicker::make('driver_id')
                            ->label('Lái xe')
                            ->live()
                            ->cards(fn (): array => self::resolveDriverCards())
                            ->searchPlaceholder('Tìm tên, email...')
                            ->required(),
                    ]),

            ])
            ->action(function (Order $record, array $data): void {
                try {
                    DB::transaction(function () use ($record, $data) {
                        $trip = Trip::create([
                            'trip_code' => Trip::generateTripCode(),
                            'vehicle_id' => $data['vehicle_id'],
                            'driver_id' => $data['driver_id'],
                            'status' => TripStatus::Pending,
                        ]);

                        $record->update([
                            'trip_id' => $trip->id,
                            'status' => OrderStatus::Assigned,
                        ]);

                        if (filled($data['vehicle_id'] ?? null)) {
                            $vehicle = Vehicle::query()->find($data['vehicle_id']);

                            if ($vehicle !== null) {
                                $vehicle->status = VehicleStatus::Running;
                                $vehicle->save();
                            }
                        }
                    });

                    Notification::make()
                        ->title('Gán phương tiện thành công')
                        ->body('Đã tạo chuyến và gán đơn hàng.')
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
