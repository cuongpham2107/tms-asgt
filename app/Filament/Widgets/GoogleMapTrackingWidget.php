<?php

namespace App\Filament\Widgets;

use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use Cheesegrits\FilamentGoogleMaps\Widgets\MapWidget;

/**
 * Google Maps widget hiển thị vị trí xe realtime — dùng cheesegrits/filament-google-maps.
 *
 * So sánh với RealTimeTracking page đang dùng Mapbox tự build.
 */
class GoogleMapTrackingWidget extends MapWidget
{
    protected static ?string $heading = 'Google Maps — Theo dõi xe';

    protected static ?string $minHeight = '60vh';

    protected static ?int $zoom = 11;

    protected static ?bool $clustering = false;

    protected static ?bool $fitToBounds = true;

    protected static ?string $icon = 'heroicon-o-map';

    protected array $mapConfig = [
        'draggable' => true,
        'center' => [
            'lat' => 10.8231,
            'lng' => 106.6297,
        ],
        'zoom' => 11,
    ];

    /**
     * @return array<int, array{location: array{lat: float, lng: float}, label: string, id: int}>
     */
    protected function getData(): array
    {
        return Vehicle::query()
            ->with(['driver', 'driverShifts' => fn ($q) => $q->whereNull('end_time')->latest('start_time')])
            ->where('is_active', true)
            ->get()
            ->map(function (Vehicle $vehicle): array {
                $latestShift = $vehicle->driverShifts->first();

                // Ưu tiên GPS từ shift đang chạy → fallback về vị trí mặc định
                $lat = $latestShift?->start_gps_lat ?? 10.8231;
                $lng = $latestShift?->start_gps_lng ?? 106.6297;

                // Làm nhiễu nhẹ để các marker không chồng lên nhau
                $lat += ($vehicle->id % 7 - 3) * 0.002;
                $lng += ($vehicle->id % 7 - 3) * 0.002;

                $statusIcon = match ($vehicle->status) {
                    VehicleStatus::Running => '🟢',
                    VehicleStatus::On => '🟡',
                    VehicleStatus::Off => '⚫',
                    VehicleStatus::Bdsc => '🔴',
                    default => '⚪',
                };

                return [
                    'location' => [
                        'lat' => (float) $lat,
                        'lng' => (float) $lng,
                    ],
                    'label' => implode(' | ', array_filter([
                        $statusIcon.' '.($vehicle->plate_number ?? '—'),
                        $vehicle->driver?->name,
                        $vehicle->vehicle_type?->getLabel(),
                        $vehicle->getStatusLabel(),
                    ])),
                    'id' => $vehicle->id,
                ];
            })
            ->toArray();
    }
}
