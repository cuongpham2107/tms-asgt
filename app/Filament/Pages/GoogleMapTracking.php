<?php

namespace App\Filament\Pages;

use App\Enums\OrderStatus;
use App\Enums\VehicleStatus;
use App\Models\Order;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Page dùng thư viện cheesegrits/filament-google-maps để theo dõi xe.
 * Dùng thẳng Google Maps JS API (plugin load key) — full Directions + route + custom marker.
 *
 * So sánh với RealTimeTracking (Mapbox tự build).
 */
class GoogleMapTracking extends Page
{
    private const HCMC_CENTER = ['lat' => 10.8231, 'lng' => 106.6297];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Google Maps Tracking';

    protected static string|UnitEnum|null $navigationGroup = 'Tổng quan';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Theo dõi qua Google Maps';

    protected string $view = 'filament.pages.google-map-tracking';

    public function getApiKey(): string
    {
        return config('filament-google-maps.key', '');
    }

    /** @return array<int, array<string, mixed>> */
    public function getVehicles(): array
    {
        $activeStatuses = [
            OrderStatus::Started->value,
            OrderStatus::ArrivedPickup->value,
            OrderStatus::Delivering->value,
            OrderStatus::ArrivedDelivery->value,
            OrderStatus::DriverSwap->value,
        ];

        return Vehicle::query()
            ->with([
                'driver',
                'driverShifts' => fn ($q) => $q->whereNull('end_time')->latest('start_time'),
                'orders' => fn ($q) => $q
                    ->with(['customer', 'deliveryPoints.location', 'driver', 'pickupLocation', 'tripCheckpoints' => fn ($q) => $q->orderBy('occurred_at')])
                    ->where(fn (Builder $q): Builder => $q
                        ->whereIn('status', $activeStatuses)
                        ->orWhereDate('planned_loading_at', today()))
                    ->orderByDesc('planned_loading_at'),
            ])
            ->where('is_active', true)
            ->get()
            ->map(function (Vehicle $vehicle) use ($activeStatuses): array {
                $allOrders = $vehicle->orders ?? collect();
                $activeOrders = $allOrders->filter(fn (Order $o) => in_array($o->status->value, $activeStatuses, true));
                $trackingOrder = $activeOrders->first() ?? $allOrders->first();
                $latestShift = $vehicle->driverShifts->first();

                $routePoints = $this->routePointsForOrder($trackingOrder, $vehicle->id);
                $latestPoint = $routePoints->last();

                $hasShiftGps = $latestShift?->start_gps_lat !== null;
                $lat = $latestPoint['lat'] ?? $latestShift?->start_gps_lat ?? (self::HCMC_CENTER['lat'] + ($vehicle->id % 7 - 3) * 0.005);
                $lng = $latestPoint['lng'] ?? $latestShift?->start_gps_lng ?? (self::HCMC_CENTER['lng'] + ($vehicle->id % 7 - 3) * 0.005);

                return [
                    'id' => $vehicle->id,
                    'plate' => $vehicle->plate_number,
                    'status' => $vehicle->status?->value ?? 'on',
                    'status_label' => $vehicle->getStatusLabel(),
                    'driver' => $trackingOrder?->driver?->name ?? $latestShift?->driver?->name ?? $vehicle->driver?->name ?? 'Không lái',
                    'vehicle_type_label' => $vehicle->vehicle_type?->getLabel() ?? 'Xe thường',
                    'lat' => (float) $lat,
                    'lng' => (float) $lng,
                    'route' => $routePoints->values()->toArray(),
                    'route_order_code' => $trackingOrder?->order_code,
                    'orders' => $allOrders->take(3)->map(fn (Order $o) => [
                        'id' => $o->id,
                        'order_code' => $o->order_code,
                        'status' => $o->status->value,
                        'status_label' => $o->status->getLabel(),
                        'customer' => $o->customer?->name,
                        'pickup' => $o->pickup_address ?? $o->pickupLocation?->name,
                        'delivery' => $o->deliveryPoints?->sortBy('sequence')->first()?->address,
                        'total_packages' => $o->total_packages,
                        'total_weight' => $o->total_weight,
                    ])->values()->toArray(),
                ];
            })
            ->toArray();
    }

    /** @return Collection<int, array{lat: float, lng: float, label: string}> */
    private function routePointsForOrder(?Order $order, int $vehicleId): Collection
    {
        if ($order === null) {
            return collect();
        }

        return ($order->tripCheckpoints ?? collect())
            ->filter(fn (TripCheckpoint $c) => $c->gps_lat !== null && $c->gps_lng !== null)
            ->sortBy('occurred_at')
            ->values()
            ->map(fn (TripCheckpoint $c, int $i) => [
                'lat' => (float) $c->gps_lat,
                'lng' => (float) $c->gps_lng,
                'label' => $c->checkpoint_type?->getLabel() ?? 'Checkpoint',
            ]);
    }

    /** @return array<string, int> */
    public function getStats(): array
    {
        $vehicles = Vehicle::query()->where('is_active', true)->get();
        $activeStatuses = [
            OrderStatus::Started->value,
            OrderStatus::ArrivedPickup->value,
            OrderStatus::Delivering->value,
            OrderStatus::ArrivedDelivery->value,
        ];

        return [
            'total' => $vehicles->count(),
            'running' => $vehicles->filter(fn (Vehicle $v) => $v->status === VehicleStatus::Running ||
                $v->orders()->whereIn('status', $activeStatuses)->exists()
            )->count(),
            'on' => $vehicles->filter(fn (Vehicle $v) => $v->status === VehicleStatus::On &&
                ! $v->orders()->whereIn('status', $activeStatuses)->exists()
            )->count(),
            'bdsc' => $vehicles->filter(fn (Vehicle $v) => $v->status === VehicleStatus::Bdsc)->count(),
            'off' => $vehicles->filter(fn (Vehicle $v) => $v->status === VehicleStatus::Off)->count(),
        ];
    }
}
