<?php

namespace App\Filament\Pages;

use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Filament\Widgets\GoogleMapStatsOverview;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use App\Services\OsrmService;
use BackedEnum;
use Carbon\Carbon;
use EduardoRibeiroDev\FilamentLeaflet\Concerns\HasMapConfig;
use EduardoRibeiroDev\FilamentLeaflet\Enums\TileLayer;
use EduardoRibeiroDev\FilamentLeaflet\LayerGroups\MarkerCluster;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\CircleMarker;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\Polyline;
use Filament\Pages\Page;
use Filament\Support\Concerns\EvaluatesClosures;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use UnitEnum;

/**
 * Bản đồ theo dõi xe theo thời gian thực (TMS ASGT).
 *
 * Sử dụng thư viện eduardoribeirodev/filament-leaflet và OSRM để vẽ lộ trình bám đường.
 */
class GoogleMapTracking extends Page
{
    use EvaluatesClosures, HasMapConfig;

    private const DEFAULT_HUB_CENTER = [21.0285, 105.8542]; // Hà Nội Center

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Theo dõi thực tế';

    protected static string|UnitEnum|null $navigationGroup = 'Tổng quan';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Bản đồ giám sát phương tiện';

    protected string $view = 'filament.pages.google-map-tracking';

    public ?Carbon $lastUpdated = null;

    public array $selectedVehicleIds = [];

    // Date range filters for historical tracking
    public ?string $filterDateFrom = null;

    public ?string $filterDateTo = null;

    private ?Collection $cachedVehicles = null;

    private bool $filterDateLightUpdate = false;

    protected function getHeaderWidgets(): array
    {
        return [
            GoogleMapStatsOverview::class,
        ];
    }

    public function handleLayerClick(string $layerId): void
    {
        if (str_starts_with($layerId, 'vehicle-')) {
            $vehicleId = (int) str_replace('vehicle-', '', $layerId);
            $this->selectedVehicleIds = [$vehicleId];
            $this->dispatch('vehicleSelectionChanged', selectedIds: $this->selectedVehicleIds);
            $this->cachedVehicles = null;
            $this->cachedLayerData = null;
            $this->refreshMap();
        }
    }

    #[On('vehicleSelectionChanged')]
    public function updateSelectedVehicles(array $selectedIds = []): void
    {
        $this->selectedVehicleIds = $selectedIds;
        $this->cachedVehicles = null;
        $this->cachedLayerData = null;
        $this->refreshMap();
    }

    public function mount(): void
    {
        $this->lastUpdated = now();
        $this->selectedVehicleIds = [];
        $this->cachedLayerData = null;
        $this->refreshMap();
    }

    #[On('refreshMapData')]
    public function refreshData(): void
    {
        $this->cachedVehicles = null;
        $this->cachedLayerData = null;
        $this->lastUpdated = now();
        $this->refreshMap();
    }

    public function updatedFilterDateFrom(): void
    {
        if ($this->filterDateLightUpdate) {
            $this->filterDateLightUpdate = false;

            return;
        }

        $this->cachedVehicles = null;
        $this->cachedLayerData = null;
        $this->refreshMap();
    }

    public function updatedFilterDateTo(): void
    {
        if ($this->filterDateLightUpdate) {
            $this->filterDateLightUpdate = false;

            return;
        }

        $this->cachedVehicles = null;
        $this->cachedLayerData = null;
        $this->refreshMap();
    }

    public function getLastUpdated(): ?Carbon
    {
        return $this->lastUpdated;
    }

    protected function getMapCenter(): array
    {
        return self::DEFAULT_HUB_CENTER;
    }

    protected function getDefaultZoom(): int
    {
        return 12;
    }

    protected function getMapHeight(): int
    {
        return 740;
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

    /**
     * @return Marker[]
     */
    protected function getMarkers(): array
    {
        $vehicles = $this->getFilteredVehicles();

        $allMarkers = $vehicles->map(function (Vehicle $vehicle): Marker {
            $activeTrip = $vehicle->trips
                ->first(fn (Trip $t) => in_array($t->status, TripStatus::activeStatuses(), true))
                ?? $vehicle->trips->first();

            $activeOrders = $activeTrip?->orders ?? $vehicle->trips->flatMap->orders;

            $hasActiveTrip = $activeTrip !== null || $activeOrders->isNotEmpty();

            // Checkpoints with valid GPS
            $realCheckpoints = ($activeTrip?->checkpoints ?? collect())
                ->filter(fn (TripCheckpoint $c) => $c->gps_lat !== null && $c->gps_lng !== null)
                ->sortBy('occurred_at');

            $latestCheckpoint = $realCheckpoints->last();

            // Latitude & Longitude Resolution
            $lat = $vehicle->gps_lat ?? $latestCheckpoint?->gps_lat;
            $lng = $vehicle->gps_lng ?? $latestCheckpoint?->gps_lng;

            // Fallback to order pickup location or hub
            if ($lat === null || $lng === null) {
                $firstOrder = $activeOrders->first();
                if ($firstOrder?->pickupLocation && $firstOrder->pickupLocation->lat !== null) {
                    $lat = (float) $firstOrder->pickupLocation->lat;
                    $lng = (float) $firstOrder->pickupLocation->lng;
                } else {
                    $offsetLat = (($vehicle->id % 9) - 4) * 0.008;
                    $offsetLng = ((($vehicle->id * 3) % 9) - 4) * 0.008;
                    $lat = self::DEFAULT_HUB_CENTER[0] + $offsetLat;
                    $lng = self::DEFAULT_HUB_CENTER[1] + $offsetLng;
                }
            }

            $statusColor = match ($vehicle->status) {
                VehicleStatus::Running => '#f59e0b',
                VehicleStatus::On => '#10b981',
                VehicleStatus::Bdsc => '#ef4444',
                VehicleStatus::Off => '#6b7280',
                default => '#6b7280',
            };

            $driverName = $activeTrip?->driver?->name ?? $vehicle->driver?->name ?? 'Chưa gán lái xe';
            $driverPhone = $activeTrip?->driver?->phone ?? $vehicle->driver?->phone ?? null;

            // Route ETA & Distance calculation if running
            $etaText = null;
            $distanceText = null;
            if ($vehicle->status === VehicleStatus::Running && $hasActiveTrip) {
                $routePoints = $this->routePointsForTrip($activeTrip, $activeOrders, $vehicle);
                if ($routePoints->count() >= 2) {
                    $origin = $routePoints->first();
                    $destination = $routePoints->last();
                    $waypoints = $routePoints->slice(1, $routePoints->count() - 2)->map(fn ($p) => ['lat' => $p['lat'], 'lng' => $p['lng']])->values()->all();

                    $osrmInfo = app(OsrmService::class)->getRoute(
                        $origin['lat'],
                        $origin['lng'],
                        $destination['lat'],
                        $destination['lng'],
                        $waypoints,
                    );

                    if (! empty($osrmInfo['success']) && ! empty($osrmInfo['data'])) {
                        $duration = $osrmInfo['data']['duration'] ?? null;
                        $distance = $osrmInfo['data']['distance'] ?? null;

                        if ($duration !== null) {
                            $eta = now()->addSeconds($duration);
                            $etaText = $eta->format('H:i');
                        }

                        if ($distance !== null) {
                            $distanceText = round($distance / 1000, 1).' km';
                        }
                    }
                }
            }

            $ordersHtml = '';
            if ($activeOrders->isNotEmpty()) {
                $ordersHtml = $activeOrders->take(3)->map(function (Order $o) {
                    $pickup = $o->pickup_address ?? $o->pickupLocation?->name ?? 'Điểm nhận';
                    $delivery = $o->deliveryPoints?->sortBy('sequence')->first()?->address ?? 'Điểm giao';
                    $weight = $o->chargeable_weight ? ($o->chargeable_weight.'T') : ($o->total_weight ? ($o->total_weight.'T') : null);

                    return sprintf(
                        '<div style="margin-bottom:6px;padding:7px 10px;background:#f8fafc;border-radius:8px;border-left:3px solid #3b82f6;box-shadow:0 1px 2px rgba(0,0,0,0.03);">'
                        .'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2px;">'
                            .'<span style="font-weight:700;font-size:12px;color:#1e293b;">#%s</span>'
                            .'<span style="font-size:10px;font-weight:600;padding:1px 6px;border-radius:4px;background:#e0f2fe;color:#0369a1;">%s</span>'
                        .'</div>'
                        .'<div style="font-size:11px;color:#475569;margin-bottom:2px;">%s %s</div>'
                        .'<div style="font-size:10px;color:#64748b;display:flex;align-items:center;gap:4px;">📍 %s → %s</div>'
                        .'</div>',
                        e($o->order_code),
                        e($o->status->getLabel()),
                        e($o->customer?->name ?? 'Khách lẻ'),
                        $weight ? ' &bull; <strong>'.$weight.'</strong>' : '',
                        e($pickup),
                        e($delivery),
                    );
                })->implode('');
            }

            $speedHtml = '';
            if ($vehicle->gps_speed !== null && (float) $vehicle->gps_speed > 0) {
                $speedHtml = sprintf(
                    '<span style="display:inline-flex;align-items:center;gap:3px;color:#d97706;font-weight:700;font-size:11px;">⚡ %s km/h</span>',
                    round((float) $vehicle->gps_speed, 1)
                );
            }

            $tripBadge = $activeTrip
                ? sprintf('<div style="font-size:11px;color:#2563eb;font-weight:600;margin-top:2px;">Chuyến: %s (%s đơn)</div>', e($activeTrip->trip_code), $activeOrders->count())
                : '';

            $popupContent = sprintf(
                '<div style="font-family:Inter,-apple-system,BlinkMacSystemFont,sans-serif;min-width:280px;max-width:360px;line-height:1.4;color:#0f172a;">'
                .'<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid #f1f5f9;">'
                    .'<div>'
                        .'<div style="font-weight:800;font-size:16px;color:#0f172a;letter-spacing:-0.02em;">%s</div>'
                        .'%s'
                        .'<div style="font-size:11px;color:#64748b;margin-top:2px;">%s%s</div>'
                    .'</div>'
                    .'<span style="background:%s;color:#ffffff;font-size:10px;font-weight:700;padding:3px 9px;border-radius:99px;white-space:nowrap;">%s</span>'
                .'</div>'

                .'<div style="display:flex;align-items:center;justify-content:space-between;background:#f8fafc;padding:6px 10px;border-radius:6px;margin-bottom:8px;font-size:11px;color:#334155;">'
                    .'<div>👤 <strong>%s</strong>%s</div>'
                    .'%s'
                .'</div>'

                .'<div style="font-size:11px;font-weight:700;color:#64748b;margin-bottom:5px;text-transform:uppercase;letter-spacing:0.04em;">Đơn hàng vận chuyển</div>'
                .'%s'
                .'</div>',
                e($vehicle->plate_number),
                $tripBadge,
                $etaText ? ('ETA: <strong>'.e($etaText).'</strong> • ') : '',
                $distanceText ? ('Khoảng cách: <strong>'.e($distanceText).'</strong>') : ($vehicle->getVehicleTypeLabel()),
                $statusColor,
                e($vehicle->getStatusLabel()),
                e($driverName),
                $driverPhone ? (' ('.e($driverPhone).')') : '',
                $speedHtml,
                $ordersHtml ?: '<div style="font-size:11px;color:#94a3b8;text-align:center;padding:8px 0;">Không có đơn hàng nào đang chạy</div>',
            );

            return Marker::make((float) $lat, (float) $lng)
                ->id('vehicle-'.$vehicle->id)
                ->title($vehicle->plate_number)
                ->icon(asset('images/truck.png'), [36, 36])
                ->color($statusColor)
                ->popupContent($popupContent)
                ->popupOptions(['maxWidth' => 380]);
        });

        // If user selected specific vehicles, only show those
        if (! empty($this->selectedVehicleIds)) {
            return $allMarkers->filter(fn (Marker $m) => in_array(
                (int) str_replace('vehicle-', '', $m->getId()),
                $this->selectedVehicleIds,
                true,
            ))->values()->all();
        }

        // Clustering for large fleet
        if ($allMarkers->count() > 50) {
            return [
                MarkerCluster::make($allMarkers->all())
                    ->maxClusterRadius(70)
                    ->spiderfyOnMaxZoom(true)
                    ->removeOutsideVisibleBounds(true)
                    ->zoomToBoundsOnClick(true),
            ];
        }

        return $allMarkers->all();
    }

    /**
     * @return Polyline[]|CircleMarker[]
     */
    protected function getShapes(): array
    {
        $vehicles = $this->getFilteredVehicles();

        $segmentColors = [
            '#16a34a', // xanh lá: Xuất phát
            '#2563eb', // xanh dương: Lấy hàng / Trên đường
            '#7c3aed', // tím: Điểm giao hàng
            '#ea580c', // cam: Đang tiếp cận
            '#dc2626', // đỏ: Điểm cuối
        ];

        return $vehicles
            ->filter(fn (Vehicle $v) => in_array($v->id, $this->selectedVehicleIds, true))
            ->flatMap(function (Vehicle $vehicle) use ($segmentColors): array {
                // 1. Tìm chuyến đi active hoặc chuyến gần nhất có checkpoints
                $trackingTrip = $vehicle->trips
                    ->first(fn (Trip $t) => in_array($t->status, TripStatus::activeStatuses(), true))
                    ?? $vehicle->trips->first(fn (Trip $t) => ($t->checkpoints ?? collect())->whereNotNull('gps_lat')->count() >= 2)
                    ?? $vehicle->trips->first();

                $activeOrders = $trackingTrip?->orders ?? $vehicle->trips->flatMap->orders;

                $routePoints = $this->routePointsForTrip($trackingTrip, $activeOrders, $vehicle);

                if ($routePoints->count() < 2) {
                    // Nếu chỉ có 1 điểm (vị trí hiện tại của xe), vẽ vòng tròn định vị nổi bật
                    if ($routePoints->count() === 1) {
                        $p = $routePoints->first();

                        return [
                            CircleMarker::make($p['lat'], $p['lng'])
                                ->id('pulse-vehicle-'.$vehicle->id)
                                ->radius(18)
                                ->color('#3b82f6')
                                ->fillColor('#60a5fa')
                                ->fillOpacity(0.35)
                                ->weight(2)
                                ->tooltipContent($vehicle->plate_number.' (Vị trí hiện tại)'),
                        ];
                    }

                    return [];
                }

                $points = $routePoints->map(fn (array $p) => [$p['lat'], $p['lng']])->values()->all();
                $labels = $routePoints->pluck('label')->all();

                $shapes = [];

                // GPS Breadcrumbs (đường nét đứt nối các checkpoint thực tế)
                $shapes[] = Polyline::make($points)
                    ->id('route-gps-'.$vehicle->id)
                    ->color('#64748b')
                    ->weight(2)
                    ->opacity(0.5)
                    ->dashArray(5, 5)
                    ->fill(false);

                // Điểm bắt đầu (Marker tròn xanh lá)
                $firstPoint = $points[0];
                $shapes[] = CircleMarker::make($firstPoint[0], $firstPoint[1])
                    ->id('start-'.$vehicle->id)
                    ->radius(8)
                    ->color('#15803d')
                    ->fillColor('#22c55e')
                    ->fillOpacity(0.95)
                    ->weight(2)
                    ->tooltipContent('Xuất phát: '.($labels[0] ?? 'Điểm bắt đầu'));

                // Điểm kết thúc (Marker tròn đỏ)
                $lastIdx = count($points) - 1;
                $lastPoint = $points[$lastIdx];
                $shapes[] = CircleMarker::make($lastPoint[0], $lastPoint[1])
                    ->id('end-'.$vehicle->id)
                    ->radius(8)
                    ->color('#b91c1c')
                    ->fillColor('#ef4444')
                    ->fillOpacity(0.95)
                    ->weight(2)
                    ->tooltipContent('Đích đến: '.($labels[$lastIdx] ?? 'Điểm kết thúc'));

                // Các điểm dừng trung gian (Marker tròn tím)
                for ($k = 1; $k < $lastIdx; $k++) {
                    $pt = $points[$k];
                    $shapes[] = CircleMarker::make($pt[0], $pt[1])
                        ->id("waypoint-{$k}-{$vehicle->id}")
                        ->radius(6)
                        ->color('#6d28d9')
                        ->fillColor('#8b5cf6')
                        ->fillOpacity(0.9)
                        ->weight(2)
                        ->tooltipContent(($k).'. '.($labels[$k] ?? 'Điểm dừng'));
                }

                // Vẽ các chặng đường OSRM bám đường thực tế
                for ($i = 0; $i < count($points) - 1; $i++) {
                    $segment = [$points[$i], $points[$i + 1]];
                    $osrmSegment = app(OsrmService::class)->getRouteFromPoints($segment);
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
                        $shapes[] = Polyline::make($segment)
                            ->id("route-seg{$i}-{$vehicle->id}")
                            ->color($color)
                            ->weight(4)
                            ->opacity(0.85)
                            ->dashArray(6, 4)
                            ->fill(false)
                            ->tooltipContent($label);
                    }
                }

                return $shapes;
            })
            ->all();
    }

    /**
     * @return Collection<int, array{lat: float, lng: float, label: string}>
     */
    private function routePointsForTrip(?Trip $trip, Collection $orders, ?Vehicle $vehicle = null): Collection
    {
        $points = collect();

        // 1. Tọa độ từ các Checkpoint thực tế của chuyến đi
        if ($trip !== null) {
            $tripCheckpoints = ($trip->checkpoints ?? collect())
                ->filter(fn (TripCheckpoint $c) => $c->gps_lat !== null && $c->gps_lng !== null)
                ->sortBy('occurred_at')
                ->values();

            foreach ($tripCheckpoints as $cp) {
                $points->push([
                    'lat' => (float) $cp->gps_lat,
                    'lng' => (float) $cp->gps_lng,
                    'label' => $cp->checkpoint_type?->getLabel() ?? 'Checkpoint',
                ]);
            }
        }

        // 2. Nếu thiếu điểm, bổ sung từ điểm lấy hàng và các điểm giao của đơn hàng
        if ($points->count() < 2 && $orders->isNotEmpty()) {
            $fallbackPoints = collect();

            foreach ($orders as $order) {
                if ($order->pickupLocation && $order->pickupLocation->lat !== null && $order->pickupLocation->lng !== null) {
                    $fallbackPoints->push([
                        'lat' => (float) $order->pickupLocation->lat,
                        'lng' => (float) $order->pickupLocation->lng,
                        'label' => $order->pickupLocation->name ?? 'Điểm nhận hàng',
                    ]);
                }

                $deliveryPoints = $order->deliveryPoints ?? collect();
                foreach ($deliveryPoints->sortBy('sequence') as $dp) {
                    if ($dp->location && $dp->location->lat !== null && $dp->location->lng !== null) {
                        $fallbackPoints->push([
                            'lat' => (float) $dp->location->lat,
                            'lng' => (float) $dp->location->lng,
                            'label' => $dp->address ?? ($dp->location->name ?? 'Điểm trả hàng'),
                        ]);
                    }
                }
            }

            if ($fallbackPoints->count() >= 2) {
                return $fallbackPoints;
            }

            if ($fallbackPoints->isNotEmpty() && $points->isEmpty()) {
                $points = $fallbackPoints;
            }
        }

        // 3. Nếu vẫn chỉ có 0 hoặc 1 điểm, kiểm tra GPS hiện tại của phương tiện
        if ($points->count() < 2 && $vehicle !== null && $vehicle->gps_lat !== null && $vehicle->gps_lng !== null) {
            $currentGps = [
                'lat' => (float) $vehicle->gps_lat,
                'lng' => (float) $vehicle->gps_lng,
                'label' => 'Vị trí hiện tại',
            ];

            if ($points->isEmpty()) {
                $points->push($currentGps);
            } elseif (
                abs($points->first()['lat'] - $currentGps['lat']) > 0.0001 ||
                abs($points->first()['lng'] - $currentGps['lng']) > 0.0001
            ) {
                $points->push($currentGps);
            }
        }

        return $points;
    }

    private function getRawVehicles(): Collection
    {
        if ($this->cachedVehicles !== null) {
            return $this->cachedVehicles;
        }

        return $this->cachedVehicles = Vehicle::query()
            ->with([
                'driver',
                'trips' => fn ($q) => $q
                    ->latest('id')
                    ->take(5)
                    ->with(['orders.customer', 'orders.pickupLocation', 'orders.deliveryPoints.location', 'checkpoints']),
            ])
            ->where('is_active', true)
            ->where('type', VehicleOwnerType::Company)
            ->get();
    }

    private function getFilteredVehicles(): Collection
    {
        $vehicles = $this->getRawVehicles();

        if ($this->filterDateFrom || $this->filterDateTo) {
            $from = $this->filterDateFrom ? Carbon::parse($this->filterDateFrom) : null;
            $to = $this->filterDateTo ? Carbon::parse($this->filterDateTo) : null;

            $vehicles = $vehicles->filter(function (Vehicle $v) use ($from, $to) {
                $orders = $v->trips->flatMap->orders;
                foreach ($orders as $o) {
                    $pl = $o->planned_loading_at ? Carbon::parse($o->planned_loading_at) : null;
                    if ($from && $to && $pl && $pl->between($from, $to)) {
                        return true;
                    }
                    if ($from && ! $to && $pl && $pl >= $from) {
                        return true;
                    }
                    if ($to && ! $from && $pl && $pl <= $to) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        return $vehicles;
    }
}
