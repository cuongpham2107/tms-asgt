<?php

namespace App\Filament\Widgets;

use App\Models\Vehicle;
use Filament\Widgets\Widget;

class VehicleMapWidget extends Widget
{
    private const DEFAULT_MAP_CENTER = [
        'lat' => 21.1250,
        'lng' => 105.9500,
    ];

    protected string $view = 'filament.widgets.vehicle-map';

    protected int|string|array $columnSpan = 'full';

    /** @return array<int, array{plate: string, status: string, driver: string|null, lat: float, lng: float, type: string}> */
    public function getVehicles(): array
    {
        return Vehicle::query()
            ->with('driver')
            ->where('is_active', true)
            ->get()
            ->map(function (Vehicle $v) {
                $latestShift = $v->driver?->driverShifts()
                    ->whereNull('end_time')
                    ->latest('start_time')
                    ->first();

                $lat = $latestShift?->start_gps_lat ?? self::DEFAULT_MAP_CENTER['lat'];
                $lng = $latestShift?->start_gps_lng ?? self::DEFAULT_MAP_CENTER['lng'];
                $coordinate = $this->normalizeDemoCoordinate((float) $lat, (float) $lng, $v->id);

                return [
                    'plate' => $v->plate_number,
                    'status' => $v->status?->value ?? $v->status,
                    'driver' => $v->driver?->name ?? 'Không lái',
                    'lat' => $coordinate['lat'],
                    'lng' => $coordinate['lng'],
                    'type' => $v->vehicle_type?->getLabel() ?? 'Xe thường',
                ];
            })
            ->toArray();
    }

    /** @return array{lat: float, lng: float} */
    private function normalizeDemoCoordinate(float $lat, float $lng, int $seed): array
    {
        if (! ($lat >= 10.0 && $lat <= 11.5 && $lng >= 106.0 && $lng <= 107.5)) {
            return ['lat' => $lat, 'lng' => $lng];
        }

        $offset = (($seed % 11) - 5) / 1000;

        return [
            'lat' => self::DEFAULT_MAP_CENTER['lat'] + $offset,
            'lng' => self::DEFAULT_MAP_CENTER['lng'] - $offset,
        ];
    }
}
