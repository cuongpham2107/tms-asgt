<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Enums\TripStatus;
use App\Exceptions\InvalidTransitionException;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Trip;
use App\Models\User;
use App\Services\Trip\TripDriverService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * Điều hành gán tài xế cho chuyến đang chờ lái (Đảo lái) hoặc đổi tài xế khi chuyến đang chạy.
 */
class AssignDriverAction
{
    public static function make(): Action
    {
        return Action::make('assign_driver')
            ->label(fn (Trip $record): string => $record->status === TripStatus::DriverSwap ? 'Gán tài xế' : 'Đổi tài xế')
            ->icon('heroicon-o-arrow-path')
            ->color(fn (Trip $record): string => $record->status === TripStatus::DriverSwap ? 'danger' : 'warning')
            ->visible(fn (Trip $record): bool => in_array($record->status, [
                TripStatus::DriverSwap,
                TripStatus::Started,
                TripStatus::ArrivedPickup,
                TripStatus::Delivering,
                TripStatus::ArrivedDelivery,
            ], true))
            ->modalHeading(fn (Trip $record): string => $record->status === TripStatus::DriverSwap
                ? 'Gán tài xế mới cho chuyến đảo lái'
                : 'Đổi tài xế cho chuyến đang chạy')
            ->modalDescription(fn (Trip $record): string => $record->status === TripStatus::DriverSwap
                ? 'Chuyến sẽ tiếp tục từ bước '.($record->status_before_swap?->getLabel() ?? 'đang dở').' với tài xế mới.'
                : 'Trạng thái chuyến giữ nguyên, tài xế mới làm tiếp ngay.')
            ->schema([
                Select::make('driver_id')
                    ->label('Tài xế')
                    ->options(fn (Trip $record): array => self::driverOptions($record))
                    ->searchable()
                    ->required(),
                Textarea::make('note')
                    ->label('Ghi chú')
                    ->rows(2),
            ])
            ->action(function (Trip $record, array $data): void {
                $driver = User::findOrFail($data['driver_id']);

                try {
                    app(TripDriverService::class)->replaceDriver($record, $driver, auth()->user(), $data['note'] ?? null);
                } catch (InvalidTransitionException $e) {
                    Notification::make()->warning()->title('Không thể gán tài xế')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Đã gán tài xế')
                    ->body("Chuyến #{$record->trip_code} đã giao cho {$driver->name}.")
                    ->send();
            });
    }

    /**
     * Tài xế đang trong ca được đưa lên đầu, kèm xe đang giữ và vị trí gần nhất.
     *
     * @return array<int, string>
     */
    private static function driverOptions(Trip $record): array
    {
        return User::query()
            ->where('is_active', true)
            ->when($record->driver_id, fn ($query) => $query->where('id', '!=', $record->driver_id))
            ->with([
                'vehiclesAsDriver' => fn ($q) => $q->select('id', 'current_driver_id', 'plate_number', 'gps_lat', 'gps_lng'),
                'driverShifts' => fn ($q) => $q->whereNull('end_time'),
            ])
            ->get()
            ->sortByDesc(fn (User $driver): bool => $driver->driverShifts->isNotEmpty())
            ->mapWithKeys(function (User $driver): array {
                $parts = [$driver->name];
                $vehicle = $driver->vehiclesAsDriver->first();

                if ($vehicle) {
                    $parts[] = $vehicle->plate_number;
                }

                if ($driver->phone) {
                    $parts[] = $driver->phone;
                }

                $shift = $driver->driverShifts->first();
                if ($shift?->shift_type) {
                    $parts[] = 'Ca: '.$shift->shift_type->getLabel().' '.$shift->start_time?->format('H:i');
                }

                if ($vehicle?->gps_lat && $vehicle?->gps_lng) {
                    $location = CreatesOrderTransportCards::findNearestLocation((float) $vehicle->gps_lat, (float) $vehicle->gps_lng);
                    if ($location['name']) {
                        $parts[] = $location['name'];
                    }
                }

                return [$driver->id => implode(' · ', $parts)];
            })
            ->all();
    }
}
