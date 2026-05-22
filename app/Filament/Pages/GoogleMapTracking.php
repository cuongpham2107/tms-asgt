<?php

namespace App\Filament\Pages;

use App\Enums\OrderStatus;
use App\Enums\VehicleStatus;
use App\Models\Order;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use BackedEnum;
use EduardoRibeiroDev\FilamentLeaflet\Concerns\HasMapConfig;
use EduardoRibeiroDev\FilamentLeaflet\Enums\TileLayer;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\CircleMarker;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\Polyline;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use UnitEnum;

/**
 * Bản đồ theo dõi xe theo thời gian thực, dùng Leaflet (OpenStreetMap miễn phí).
 *
 * Sử dụng thư viện eduardoribeirodev/filament-leaflet để tạo marker, polyline, popup.
 */
class GoogleMapTracking extends Page
{
    use HasMapConfig;

    private const HCMC_CENTER = [10.8231, 106.6297];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Google Maps Tracking';

    protected static string|UnitEnum|null $navigationGroup = 'Tổng quan';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Theo dõi qua bản đồ';

    protected string $view = 'filament.pages.google-map-tracking';

    public function mount(): void
    {
        $this->refreshMap();
    }

    protected function getMapCenter(): array
    {
        return self::HCMC_CENTER;
    }

    // ── Map config overrides (HasMapConfig) ────────────────────────────
    // Dùng method thay vì property để tránh conflict với trait

    protected function getDefaultZoom(): int
    {
        return 11;
    }

    protected function getMapHeight(): int
    {
        return 550;
    }

    protected function getFitBounds(): bool
    {
        return true;
    }

    protected function hasFullscreenControl(): bool
    {
        return true;
    }

    protected function hasScaleControl(): bool
    {
        return true;
    }

    protected function hasZoomControl(): bool
    {
        return true;
    }

    protected function getTileLayersUrl(): TileLayer|string|array
    {
        return [
            'Bản đồ đường' => TileLayer::OpenStreetMap,
            'Vệ tinh' => TileLayer::GoogleSatellite,
        ];
    }

    // ── Cache ──────────────────────────────────────────────────────────

    private ?Collection $cachedVehicles = null;

    // ── Public data for the Blade stats bar ────────────────────────────

    /** @return array<string, int> */
    public function getStats(): array
    {
        $vehicles = $this->getRawVehicles();
        $activeStatuses = $this->activeOrderStatuses();

        return [
            'total' => $vehicles->count(),
            'running' => $vehicles->filter(fn (Vehicle $v) => $v->status === VehicleStatus::Running
                || $v->orders->whereIn('status', $activeStatuses)->isNotEmpty()
            )->count(),
            'on' => $vehicles->filter(fn (Vehicle $v) => $v->status === VehicleStatus::On
                && $v->orders->whereIn('status', $activeStatuses)->isEmpty()
            )->count(),
            'bdsc' => $vehicles->filter(fn (Vehicle $v) => $v->status === VehicleStatus::Bdsc)->count(),
            'off' => $vehicles->filter(fn (Vehicle $v) => $v->status === VehicleStatus::Off)->count(),
        ];
    }

    // ── Map layers (HasMapConfig) ──────────────────────────────────────

    /**
     * @return Marker[]
     */
    protected function getMarkers(): array
    {
        $vehicles = $this->getRawVehicles();
        $activeStatuses = $this->activeOrderStatuses();

        return $vehicles->map(function (Vehicle $vehicle) use ($activeStatuses): Marker {
            $allOrders = $vehicle->orders ?? collect();
            $activeOrders = $allOrders->filter(
                fn (Order $o) => in_array($o->status->value, $activeStatuses, true)
            );
            $trackingOrder = $activeOrders->first() ?? $allOrders->first();
            $latestShift = $vehicle->driverShifts->first();

            $routePoints = $this->routePointsForOrder($trackingOrder, $vehicle->id);
            $latestPoint = $routePoints->last();

            $hasShiftGps = $latestShift?->start_gps_lat !== null;
            $lat = $latestPoint['lat']
                ?? $latestShift?->start_gps_lat
                ?? (self::HCMC_CENTER[0] + ($vehicle->id % 7 - 3) * 0.005);
            $lng = $latestPoint['lng']
                ?? $latestShift?->start_gps_lng
                ?? (self::HCMC_CENTER[1] + ($vehicle->id % 7 - 3) * 0.005);

            $trackingDriver = $trackingOrder?->driver?->name
                ?? $latestShift?->driver?->name
                ?? $vehicle->driver?->name
                ?? 'Không lái';

            $statusColor = match ($vehicle->status) {
                VehicleStatus::Running => '#f59e0b',
                VehicleStatus::On => '#10b981',
                VehicleStatus::Bdsc => '#ef4444',
                VehicleStatus::Off => '#6b7280',
                default => '#6b7280',
            };

            // Build orders info for popup
            $ordersHtml = $allOrders->take(3)->map(function (Order $o) {
                $delivery = $o->deliveryPoints?->sortBy('sequence')->first()?->address;

                return sprintf(
                    '<div style="margin-bottom:4px;padding:4px 6px;background:#f9fafb;border-radius:4px;border-left:3px solid #3b82f6">'
                    .'<div style="font-weight:700;font-size:12px">#%s</div>'
                    .'<div style="font-size:11px;color:#6b7280">%s &bull; %s%s</div>'
                    .'<div style="font-size:10px;color:#9ca3af">%s → %s</div>'
                    .'</div>',
                    e($o->order_code),
                    e($o->customer?->name ?? '—'),
                    e($o->status->getLabel()),
                    $o->total_packages ? ' &bull; '.$o->total_packages.' kiện' : '',
                    e($o->pickup_address ?? $o->pickupLocation?->name ?? '—'),
                    e($delivery ?? '—'),
                );
            })->implode('');

            $popupContent = sprintf(
                '<div style="font-family:Inter,system-ui,sans-serif;min-width:260px;max-width:360px">'
                .'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">'
                .'<span style="font-weight:800;font-size:15px">%s</span>'
                .'<span style="background:%s;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px">%s</span>'
                .'</div>'
                .'<div style="font-size:12px;color:#4b5563;margin-bottom:4px">%s %s &bull; %s</div>'
                .'%s'
                .'</div>',
                e($vehicle->plate_number),
                $statusColor,
                e($vehicle->getStatusLabel()),
                '🧑',
                e($trackingDriver),
                e($vehicle->vehicle_type?->getLabel() ?? 'Xe thường'),
                $ordersHtml ?: '<div style="font-size:11px;color:#9ca3af">Không có đơn hàng</div>',
            );

            return Marker::make((float) $lat, (float) $lng)
                ->id('vehicle-'.$vehicle->id)
                ->title($vehicle->plate_number)
                ->icon(asset('images/truck.png'), [38, 38])
                ->color($statusColor)
                ->popupContent($popupContent)
                ->popupOptions(['maxWidth' => 380]);
        })->all();
    }

    /**
     * @return Polyline[]
     */
    protected function getShapes(): array
    {
        $vehicles = $this->getRawVehicles();
        $activeStatuses = $this->activeOrderStatuses();

        // Bảng màu cho từng segment (từ checkpoint đầu → cuối)
        $segmentColors = [
            '#22c55e', // xanh lá — bắt đầu → đến lấy hàng
            '#3b82f6', // xanh dương — rời lấy hàng → đang đi
            '#8b5cf6', // tím — trên đường giao
            '#f59e0b', // cam — gần đến
            '#ef4444', // đỏ — điểm cuối
        ];

        return $vehicles
            ->flatMap(function (Vehicle $vehicle) use ($activeStatuses, $segmentColors): array {
                $allOrders = $vehicle->orders ?? collect();
                $activeOrders = $allOrders->filter(
                    fn (Order $o) => in_array($o->status->value, $activeStatuses, true)
                );
                $trackingOrder = $activeOrders->first() ?? $allOrders->first();

                $routePoints = $this->routePointsForOrder($trackingOrder, $vehicle->id);

                if ($routePoints->count() < 2) {
                    return [];
                }

                $points = $routePoints->map(fn (array $p) => [$p['lat'], $p['lng']])->values()->all();
                $labels = $routePoints->pluck('label')->all();

                $shapes = [];

                // GPS breadcrumbs (đường chim bay nét đứt, tất cả checkpoint)
                $shapes[] = Polyline::make($points)
                    ->id('route-gps-'.$vehicle->id)
                    ->color('#9ca3af')
                    ->weight(2)
                    ->opacity(0.35)
                    ->dashArray(4, 6)
                    ->fill(false);

                // Điểm bắt đầu (xanh lá)
                $firstPoint = $points[0];
                $shapes[] = CircleMarker::make($firstPoint[0], $firstPoint[1])
                    ->id('start-'.$vehicle->id)
                    ->radius(8)
                    ->color('#16a34a')
                    ->fillColor('#22c55e')
                    ->fillOpacity(0.8)
                    ->weight(3)
                    ->tooltipContent('Bắt đầu: '.($labels[0] ?? '?'));

                // Điểm kết thúc (đỏ)
                $lastIdx = count($points) - 1;
                $lastPoint = $points[$lastIdx];
                $shapes[] = CircleMarker::make($lastPoint[0], $lastPoint[1])
                    ->id('end-'.$vehicle->id)
                    ->radius(8)
                    ->color('#dc2626')
                    ->fillColor('#ef4444')
                    ->fillOpacity(0.8)
                    ->weight(3)
                    ->tooltipContent('Kết thúc: '.($labels[$lastIdx] ?? '?'));

                // Vẽ từng segment giữa các checkpoint liên tiếp với OSRM
                for ($i = 0; $i < count($points) - 1; $i++) {
                    $segment = [$points[$i], $points[$i + 1]];
                    $osrmSegment = $this->fetchOsrmRoute($segment);
                    $color = $segmentColors[$i % count($segmentColors)];
                    $label = ($labels[$i] ?? '?').' → '.($labels[$i + 1] ?? '?');

                    if (count($osrmSegment) >= 2) {
                        $shapes[] = Polyline::make($osrmSegment)
                            ->id("route-seg{$i}-{$vehicle->id}")
                            ->color($color)
                            ->weight(5)
                            ->opacity(0.9)
                            ->fill(false)
                            ->tooltipContent($label);
                    } else {
                        // Fallback: đường thẳng giữa 2 checkpoint
                        $shapes[] = Polyline::make($segment)
                            ->id("route-seg{$i}-{$vehicle->id}")
                            ->color($color)
                            ->weight(3)
                            ->opacity(0.7)
                            ->dashArray(8, 4)
                            ->fill(false)
                            ->tooltipContent($label.' (ước lượng)');
                    }
                }

                return $shapes;
            })
            ->all();
    }

    /**
     * Gọi OSRM API để lấy route bám đường thực tế.
     *
     * @param  array<int, array{float, float}>  $points  Mảng các điểm [lat, lng]
     * @return array<int, array{float, float}> Mảng các điểm [lat, lng] chi tiết từ OSRM
     */
    private function fetchOsrmRoute(array $points): array
    {
        if (count($points) < 2) {
            return [];
        }

        // Cache key từ hash của tọa độ (cache 30 phút)
        $cacheKey = 'osrm_route_'.md5(json_encode($points));

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($points): array {
            // OSRM format: lng,lat;lng,lat;...
            $coordStrings = array_map(
                fn (array $p) => $p[1].','.$p[0], // [lat, lng] → "lng,lat"
                $points
            );
            $coords = implode(';', $coordStrings);

            $url = "https://router.project-osrm.org/route/v1/driving/{$coords}";

            try {
                $response = Http::timeout(5)->get($url, [
                    'overview' => 'full',
                    'geometries' => 'geojson',
                    'steps' => 'false',
                ]);

                if (! $response->successful()) {
                    return [];
                }

                $data = $response->json();

                $coordinates = $data['routes'][0]['geometry']['coordinates'] ?? [];

                if (empty($coordinates)) {
                    return [];
                }

                // Chuyển [lng, lat] → [lat, lng]
                return array_map(
                    fn (array $c) => [(float) $c[1], (float) $c[0]],
                    $coordinates
                );
            } catch (\Throwable) {
                return [];
            }
        });
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /** @return array<int, string> */
    private function activeOrderStatuses(): array
    {
        return [
            OrderStatus::Started->value,
            OrderStatus::ArrivedPickup->value,
            OrderStatus::Delivering->value,
            OrderStatus::ArrivedDelivery->value,
            OrderStatus::DriverSwap->value,
        ];
    }

    private function getRawVehicles(): Collection
    {
        if ($this->cachedVehicles !== null) {
            return $this->cachedVehicles;
        }

        $activeStatuses = $this->activeOrderStatuses();

        return $this->cachedVehicles = Vehicle::query()
            ->with([
                'driver',
                'driverShifts' => fn ($q) => $q->whereNull('end_time')->latest('start_time'),
                'orders' => fn ($q) => $q
                    ->with([
                        'customer',
                        'deliveryPoints.location',
                        'driver',
                        'pickupLocation',
                        'tripCheckpoints' => fn ($q) => $q->orderBy('occurred_at'),
                    ])
                    ->where(fn (Builder $q): Builder => $q
                        ->whereIn('status', $activeStatuses)
                        ->orWhereDate('planned_loading_at', today()))
                    ->orderByDesc('planned_loading_at'),
            ])
            ->where('is_active', true)
            ->get();
    }

    /**
     * @return Collection<int, array{lat: float, lng: float, label: string}>
     */
    private function routePointsForOrder(?Order $order, int $vehicleId): Collection
    {
        if ($order === null) {
            return collect();
        }

        return ($order->tripCheckpoints ?? collect())
            ->filter(fn (TripCheckpoint $c) => $c->gps_lat !== null && $c->gps_lng !== null)
            ->sortBy('occurred_at')
            ->values()
            ->map(fn (TripCheckpoint $c) => [
                'lat' => (float) $c->gps_lat,
                'lng' => (float) $c->gps_lng,
                'label' => $c->checkpoint_type?->getLabel() ?? 'Checkpoint',
            ]);
    }
}
