<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\DriverShift;
use App\Models\Location;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

class CreateEmptyRunAction
{
    public static function make(): Action
    {
        return Action::make('create_empty_run')
            ->label('Tạo chuyến không hàng')
            ->icon('heroicon-o-truck')
            ->color('success')
            ->modalHeading('Tạo chuyến không hàng')
            ->modalDescription('Tạo chuyến đi rỗng (chuyến không hàng) cho tài xế.')
            ->form([
                Select::make('vehicle_id')
                    ->label('Xe')
                    ->options(fn (): array => Vehicle::query()
                        ->where('is_active', true)
                        ->select('id', 'plate_number', 'status')
                        ->get()
                        ->mapWithKeys(fn (Vehicle $v) => [$v->id => "{$v->plate_number} - {$v->status->getLabel()}"])
                        ->all())
                    ->searchable()
                    ->required(),

                Select::make('driver_id')
                    ->label('Tài xế')
                    ->options(fn (): array => User::query()
                        ->where('is_active', true)
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

                Select::make('start_location_id')
                    ->label('Điểm đi')
                    ->options(fn (): array => Location::query()
                        ->where('is_active', true)
                        ->select('id', 'name', 'address')
                        ->get()
                        ->mapWithKeys(fn ($loc) => [$loc->id => "{$loc->name}".($loc->address ? " ({$loc->address})" : '')])
                        ->all())
                    ->searchable()
                    ->required(),

                Select::make('end_location_id')
                    ->label('Điểm đến')
                    ->options(fn (): array => Location::query()
                        ->where('is_active', true)
                        ->select('id', 'name', 'address')
                        ->get()
                        ->mapWithKeys(fn ($loc) => [$loc->id => "{$loc->name}".($loc->address ? " ({$loc->address})" : '')])
                        ->all())
                    ->searchable()
                    ->required(),

                Textarea::make('note')
                    ->label('Ghi chú')
                    ->rows(3),
            ])
            ->action(function (array $data): void {
                $vehicle = Vehicle::find($data['vehicle_id']);
                $driver = User::find($data['driver_id']);

                $activeShift = DriverShift::query()
                    ->where('driver_id', $data['driver_id'])
                    ->whereNull('end_time')
                    ->first();

                $now = now();

                $trip = Trip::create([
                    'trip_code' => Trip::generateTripCode(),
                    'vehicle_id' => $data['vehicle_id'],
                    'driver_id' => $data['driver_id'],
                    'shift_id' => $activeShift?->id,
                    'status' => TripStatus::ReturnTrip,
                    'is_empty_run' => true,
                    'start_location_id' => $data['start_location_id'],
                    'end_location_id' => $data['end_location_id'],
                    'note' => $data['note'] ?? null,
                    'started_at' => $now,
                    'start_km' => $vehicle?->current_mileage ?? 0,
                ]);

                TripCheckpoint::create([
                    'trip_id' => $trip->id,
                    'checkpoint_type' => CheckpointType::Started->value,
                    'occurred_at' => $now,
                    'km_reading' => $vehicle?->current_mileage,
                    'gps_lat' => $vehicle?->gps_lat,
                    'gps_lng' => $vehicle?->gps_lng,
                    'driver_id' => $data['driver_id'],
                    'shift_id' => $activeShift?->id,
                    'vehicle_id' => $data['vehicle_id'],
                    'order_id' => null,
                ]);

                TripCheckpoint::create([
                    'trip_id' => $trip->id,
                    'checkpoint_type' => CheckpointType::End->value,
                    'occurred_at' => $now->addSecond(),
                    'km_reading' => null,
                    'gps_lat' => $vehicle?->gps_lat,
                    'gps_lng' => $vehicle?->gps_lng,
                    'driver_id' => $data['driver_id'],
                    'shift_id' => $activeShift?->id,
                    'vehicle_id' => $data['vehicle_id'],
                    'order_id' => null,
                ]);

                Notification::make()
                    ->success()
                    ->title('Đã tạo chuyến không hàng')
                    ->body("Đã tạo chuyến không hàng #{$trip->trip_code}")
                    ->send();
            });
    }
}
