<?php

namespace App\Filament\Widgets;

use App\Models\Vehicle;
use Filament\Widgets\Widget;

class VehicleMapWidget extends Widget
{
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

                $lat = $latestShift?->start_gps_lat ?? 10.8231;
                $lng = $latestShift?->start_gps_lng ?? 106.6297;

                return [
                    'plate' => $v->plate_number,
                    'status' => $v->status,
                    'driver' => $v->driver?->name ?? 'Không lái',
                    'lat' => (float) $lat,
                    'lng' => (float) $lng,
                    'type' => $v->vehicle_type?->getLabel() ?? 'Xe thường',
                ];
            })
            ->toArray();
    }
}
