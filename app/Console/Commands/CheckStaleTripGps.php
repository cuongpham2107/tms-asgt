<?php

namespace App\Console\Commands;

use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\User;
use App\Models\VehicleGpsPoint;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CheckStaleTripGps extends Command
{
    private const STALE_MINUTES = 3;

    private const REPEAT_MINUTES = 15;

    protected $signature = 'gps:check-stale';

    protected $description = 'Cảnh báo điều hành khi chuyến đang chạy quá 3 phút không nhận được GPS từ điện thoại tài xế';

    public function handle(): int
    {
        $staleSince = now()->subMinutes(self::STALE_MINUTES);

        $trips = Trip::query()
            ->with(['vehicle', 'driver'])
            ->whereNotNull('driver_id')
            ->whereIn('status', [TripStatus::Started, TripStatus::ArrivedPickup, TripStatus::Delivering, TripStatus::ArrivedDelivery])
            ->where('started_at', '<=', $staleSince)
            ->get()
            ->reject(fn (Trip $trip) => VehicleGpsPoint::query()
                ->where('source', VehicleGpsPoint::SOURCE_PHONE)
                ->where('driver_id', $trip->driver_id)
                ->where('recorded_at', '>=', $staleSince)
                ->exists());

        $recipients = User::query()->whereDoesntHave('roles', fn ($q) => $q->where('name', 'driver'))->get();

        foreach ($trips as $trip) {
            if (! Cache::add("gps-stale-alert:{$trip->id}", true, now()->addMinutes(self::REPEAT_MINUTES))) {
                continue;
            }

            Notification::make()
                ->title('Mất GPS: '.($trip->vehicle?->plate_number ?? $trip->trip_code))
                ->body(sprintf('Chuyến %s – tài xế %s: quá %d phút không nhận được vị trí từ điện thoại.', $trip->trip_code, $trip->driver?->name ?? '—', self::STALE_MINUTES))
                ->icon('heroicon-o-signal-slash')
                ->status('warning')
                ->sendToDatabase($recipients);
        }

        $this->info("Đã cảnh báo {$trips->count()} chuyến mất GPS.");

        return self::SUCCESS;
    }
}
