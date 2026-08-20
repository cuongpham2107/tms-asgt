<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Filament\Forms\Components\DriverPicker;
use App\Filament\Forms\Components\VehiclePicker;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\Vehicle;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReassignTransportAction extends CreatesOrderTransportCards
{
    public static function make(): Action
    {
        return Action::make('reassign_transport')
            ->label('Gán lại')
            ->button()
            ->size('xs')
            ->icon('heroicon-o-truck')
            // ->color('primary')
            ->visible(fn (Trip $record): bool => $record->status === TripStatus::Pending)
            ->modal()
            ->modalHeading('Gán lại phương tiện và lái xe')
            ->modalDescription('Chọn phương tiện và lái xe mới cho chuyến đi đang chờ chạy.')
            ->modalWidth(Width::MaxContent)
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Cập nhật')
            ->schema([
                Grid::make(2)
                    ->schema([
                        VehiclePicker::make('vehicle_id')
                            ->label('Phương tiện')
                            ->live()
                            ->default(fn (Trip $record): ?int => $record->vehicle_id)
                            ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                                if ($state) {
                                    $vehicle = Vehicle::query()->find($state);
                                    if (blank($get('driver_id'))) {
                                        $set('driver_id', $vehicle?->current_driver_id ?? null);
                                    }
                                } else {
                                    $set('driver_id', null);
                                }
                            })
                            ->cards(fn (Trip $record): array => self::resolveVehicleCards(
                                self::normalizeDecimal($record->orders->sum('total_weight')),
                                self::normalizeInteger($record->start_location_id ?? $record->orders->first()?->pickup_location_id),
                                $record->vehicle_id,
                            ))
                            ->searchPlaceholder('Tìm biển số, loại xe...')
                            ->required(),

                        DriverPicker::make('driver_id')
                            ->label('Lái xe')
                            ->live()
                            ->default(fn (Trip $record): ?int => $record->driver_id)
                            ->cards(fn (): array => self::resolveDriverCards())
                            ->searchPlaceholder('Tìm tên, email...'),
                    ]),
            ])
            ->action(function (Trip $record, array $data): void {
                if ($record->status !== TripStatus::Pending) {
                    Notification::make()
                        ->warning()
                        ->title('Không thể gán lại')
                        ->body('Chỉ có thể gán lại xe và tài xế khi chuyến đi ở trạng thái Chờ chạy.')
                        ->send();

                    return;
                }

                try {
                    DB::transaction(function () use ($record, $data) {
                        $oldVehicleId = $record->vehicle_id;
                        $newVehicleId = $data['vehicle_id'];
                        $newDriverId = $data['driver_id'] ?? null;

                        $newShift = null;
                        if ($newDriverId) {
                            $newShift = DriverShift::query()
                                ->where('driver_id', $newDriverId)
                                ->whereNull('end_time')
                                ->latest('start_time')
                                ->first();
                        }

                        $record->update([
                            'vehicle_id' => $newVehicleId,
                            'driver_id' => $newDriverId,
                            'shift_id' => $newShift?->id,
                        ]);

                        $record->checkpoints()->update([
                            'vehicle_id' => $newVehicleId,
                            'driver_id' => $newDriverId,
                            'shift_id' => $newShift?->id,
                        ]);

                        if ($oldVehicleId && (int) $oldVehicleId !== (int) $newVehicleId) {
                            $hasOtherActiveTrips = Trip::query()
                                ->where('vehicle_id', $oldVehicleId)
                                ->where('id', '!=', $record->id)
                                ->whereIn('status', TripStatus::activeStatuses())
                                ->exists();

                            if (! $hasOtherActiveTrips) {
                                $oldVehicle = Vehicle::find($oldVehicleId);
                                if ($oldVehicle && $oldVehicle->status === VehicleStatus::Running) {
                                    $oldVehicle->update(['status' => VehicleStatus::On]);
                                }
                            }
                        }

                        if ($newVehicleId) {
                            $newVehicle = Vehicle::find($newVehicleId);
                            if ($newVehicle && $newVehicle->status === VehicleStatus::On) {
                                $newVehicle->update(['status' => VehicleStatus::Running]);
                            }
                        }
                    });

                    Notification::make()
                        ->title('Gán lại xe và tài xế thành công')
                        ->body("Chuyến đi #{$record->trip_code} đã được cập nhật phương tiện và lái xe.")
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Lỗi')
                        ->body('Không thể gán lại phương tiện và tài xế: '.$e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
