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
use App\Services\Notification\DriverNotificationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateEmptyRunAction
{
    public static function make(): Action
    {
        return Action::make('create_empty_run')
            ->label('Tạo chuyến không hàng')
            ->icon('heroicon-o-truck')
            ->color('primary')
            ->authorize(fn (): bool => auth()->user()?->can('create', Trip::class) ?? false)
            ->modalHeading('Tạo chuyến không hàng')
            ->modalDescription('Tạo chuyến đi rỗng (chuyến không hàng) cho tài xế.')
            ->schema([
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
                        ->role('driver')
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
                        ->orderBy('code')
                        ->pluck('code', 'id')
                        ->all())
                    ->placeholder('Để trống nếu đi đổ dầu, bảo dưỡng, việc theo yêu cầu...')
                    ->searchable()
                    ->nullable()
                    ->live(),

                Select::make('end_location_id')
                    ->label('Điểm đến')
                    ->options(fn (): array => Location::query()
                        ->where('is_active', true)
                        ->orderBy('code')
                        ->pluck('code', 'id')
                        ->all())
                    ->placeholder('Để trống nếu không có điểm cố định...')
                    ->searchable()
                    ->nullable()
                    ->live(),

                Textarea::make('note')
                    ->label('Ghi chú / Mục đích chuyến')
                    ->placeholder('VD: Đi đổ dầu, bảo dưỡng xe, đăng kiểm, chạy theo yêu cầu...')
                    ->helperText('Bắt buộc nhập khi không chọn đủ Điểm đi và Điểm đến.')
                    ->required(fn (Get $get): bool => blank($get('start_location_id')) || blank($get('end_location_id')))
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
                    'start_location_id' => $data['start_location_id'] ?? null,
                    'end_location_id' => $data['end_location_id'] ?? null,
                    'note' => filled($data['note'] ?? null) ? trim((string) $data['note']) : null,
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

                // Gửi push notification qua Firebase cho lái xe
                try {
                    app(DriverNotificationService::class)->sendEmptyRunDispatched($trip);
                } catch (Throwable $e) {
                    Log::warning('Lỗi gửi push notification khi tạo chuyến không hàng: '.$e->getMessage(), ['exception' => $e]);
                }

                Notification::make()
                    ->success()
                    ->title('Đã tạo chuyến không hàng')
                    ->body("Đã tạo chuyến không hàng #{$trip->trip_code}")
                    ->send();
            });
    }
}
