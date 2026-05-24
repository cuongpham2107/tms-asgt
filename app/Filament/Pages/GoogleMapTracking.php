<?php

namespace App\Filament\Pages;

use App\Enums\OrderStatus;
use App\Enums\VehicleStatus;
use App\Models\Order;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use App\Services\OsrmService;
use BackedEnum;
use Carbon\Carbon;
use EduardoRibeiroDev\FilamentLeaflet\Concerns\HasMapConfig;
use EduardoRibeiroDev\FilamentLeaflet\Enums\TileLayer;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\CircleMarker;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\Polyline;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Bản đồ theo dõi xe theo thời gian thực, dùng Leaflet (OpenStreetMap miễn phí).
 *
 * Sử dụng thư viện eduardoribeirodev/filament-leaflet để tạo marker, polyline, popup.
 */
class GoogleMapTracking extends Page
{
    use HasMapConfig;

    private const MAP_CENTER = [21.0285, 105.8542];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Google Maps Tracking';

    protected static string|UnitEnum|null $navigationGroup = 'Tổng quan';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Theo dõi qua bản đồ';

    protected string $view = 'filament.pages.google-map-tracking';

    protected function getMapCenter(): array
    {
        return self::MAP_CENTER;
    }

    // ── Map config overrides (HasMapConfig) ────────────────────────────
    // Dùng method thay vì property để tránh conflict với trait

    protected function getDefaultZoom(): int
    {
        return 13;
    }

    protected function getMapHeight(): int
    {
        return 620;
    }

    public ?Carbon $lastUpdated = null;

    public array $selectedVehicleIds = [];

    public string $vehicleSearch = '';

    // Playback / replay controls (D)
    public ?int $playbackTimestamp = null; // unix timestamp (seconds)
    public bool $playbackPlaying = false;
    public int $playbackSpeed = 1000; // ms between steps when autoplay (frontend uses this)

    // Filters (D3)
    public string $filterStatus = 'all'; // 'all' or VehicleStatus enum value
    public string $filterVehicleType = 'all';
    public ?string $filterDateFrom = null; // ISO string from datetime-local
    public ?string $filterDateTo = null;

    public function getLastUpdated(): ?Carbon
    {
        return $this->lastUpdated;
    }

    public function refreshData(): void
    {
        $this->cachedVehicles = null;
        $this->lastUpdated = now();
        $this->refreshMap();
    }

    public function mount(): void
    {
        $this->lastUpdated = now();
        $this->selectedVehicleIds = [];
        // Initialize playback range to latest available by default
        [$min, $max] = $this->getPlaybackBounds();
        $this->playbackTimestamp = $max ?? now()->timestamp;
        $this->refreshMap();
    }

    public function togglePlayback(): void
    {
        $this->playbackPlaying = ! $this->playbackPlaying;
    }

    public function setPlaybackTimestamp(int $ts): void
    {
        $this->playbackTimestamp = $ts;
        $this->cachedVehicles = null;
        $this->refreshMap();
    }

    public function updatedPlaybackTimestamp(): void
    {
        $this->cachedVehicles = null;
        $this->refreshMap();
    }

    // When filters change -> refresh map
    public function updatedFilterStatus(): void
    {
        $this->cachedVehicles = null;
        $this->refreshMap();
    }

    public function updatedFilterVehicleType(): void
    {
        $this->cachedVehicles = null;
        $this->refreshMap();
    }

    public function updatedFilterDateFrom(): void
    {
        $this->cachedVehicles = null;
        $this->refreshMap();
    }

    public function updatedFilterDateTo(): void
    {
        $this->cachedVehicles = null;
        $this->refreshMap();
    }

    public function toggleVehicle(int $id): void
    {
        $this->selectedVehicleIds = in_array($id, $this->selectedVehicleIds, true)
            ? array_values(array_filter($this->selectedVehicleIds, fn (int $v) => $v !== $id))
            : [...$this->selectedVehicleIds, $id];

        $this->cachedVehicles = null;
        $this->refreshMap();
    }

    public function selectAllVehicles(): void
    {
        $this->selectedVehicleIds = $this->getRawVehicles()->pluck('id')->values()->all();
        $this->cachedVehicles = null;
        $this->refreshMap();
    }

    public function deselectAllVehicles(): void
    {
        $this->selectedVehicleIds = [];
        $this->cachedVehicles = null;
        $this->refreshMap();
    }

    /** @return array<int, array{id:int,plate:string,driver:string,status_label:string,status_color:string,selected:bool}> */
    public function getSidebarVehicles(): array
    {
        return $this->getFilteredVehicles()->map(function (Vehicle $vehicle): array {
            $color = match ($vehicle->status) {
                VehicleStatus::Running => 'amber',
                VehicleStatus::On => 'emerald',
                VehicleStatus::Bdsc => 'red',
                VehicleStatus::Off => 'gray',
                default => 'gray',
            };

            return [
                'id' => $vehicle->id,
                'plate' => $vehicle->plate_number,
                'driver' => $vehicle->driver?->name ?? '—',
                'status_label' => $vehicle->getStatusLabel(),
                'status_color' => $color,
                'selected' => in_array($vehicle->id, $this->selectedVehicleIds, true),
            ];
        })->all();
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
        $vehicles = $this->getFilteredVehicles();
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
        $vehicles = $this->getFilteredVehicles();
        $activeStatuses = $this->activeOrderStatuses();

        // Build markers for all vehicles, but keep selected vehicles separate so they are not clustered.
        $allMarkers = $vehicles->map(function (Vehicle $vehicle) use ($activeStatuses): Marker {
                $allOrders = $vehicle->orders ?? collect();
                $activeOrders = $allOrders->filter(
                    fn (Order $o) => in_array($o->status->value, $activeStatuses, true)
                );
                $trackingOrder = $activeOrders->first();
                $latestShift = $vehicle->driverShifts->first();
                $hasActiveTrip = $trackingOrder !== null && $vehicle->status === VehicleStatus::Running;

                $routePoints = $hasActiveTrip
                    ? $this->routePointsForOrder($trackingOrder, $vehicle->id, $this->playbackTimestamp)
                    : collect();
                $latestPoint = $routePoints->last();

                // Compute ETA/duration/distance using OSRM for the full route (one call)
                $etaText = null;
                if ($hasActiveTrip && $routePoints->count() >= 2) {
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
                        $duration = $osrmInfo['data']['duration'] ?? null; // seconds
                        $distance = $osrmInfo['data']['distance'] ?? null; // meters
                        if ($duration !== null) {
                            $eta = now()->addSeconds($duration);
                            $etaText = $eta->format('H:i');
                        }

                        if ($distance !== null) {
                            $distanceKm = round($distance / 1000, 1);
                        }
                    }
                }

                $lat = $latestPoint['lat']
                    ?? $latestShift?->start_gps_lat
                    ?? (self::MAP_CENTER[0] + ($vehicle->id % 7 - 3) * 0.005);
                $lng = $latestPoint['lng']
                    ?? $latestShift?->start_gps_lng
                    ?? (self::MAP_CENTER[1] + ($vehicle->id % 7 - 3) * 0.005);

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

                $ordersHtml = $hasActiveTrip ? $activeOrders->take(3)->map(function (Order $o) {
                    $delivery = $o->deliveryPoints?->sortBy('sequence')->first()?->address;

                    return sprintf(
                        '<div style="margin-bottom:5px;padding:6px 8px;background:#f8fafc;border-radius:6px;border-left:3px solid #3b82f6;box-shadow:0 1px 2px rgba(0,0,0,0.04)">'
                        .'<div style="font-weight:700;font-size:12px;color:#1e293b">#%s</div>'
                        .'<div style="font-size:11px;color:#64748b">%s &bull; %s%s</div>'
                        .'<div style="font-size:10px;color:#94a3b8;margin-top:1px">%s → %s</div>'
                        .'</div>',
                        e($o->order_code),
                        e($o->customer?->name ?? '—'),
                        e($o->status->getLabel()),
                        $o->total_packages ? ' &bull; '.$o->total_packages.' kiện' : '',
                        e($o->pickup_address ?? $o->pickupLocation?->name ?? '—'),
                        e($delivery ?? '—'),
                    );
                })->implode('') : '';

                $popupContent = sprintf(
                    '<div style="font-family:Inter,system-ui,sans-serif;min-width:270px;max-width:360px;line-height:1.5">'
                    .'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid #e2e8f0">'
                    .'<div>'
                        .'<div style="font-weight:800;font-size:16px;color:#0f172a">%s</div>'
                        .'<div style="font-size:12px;color:#64748b;margin-top:3px">%s%s</div>'
                    .'</div>'
                    .'<span style="background:%s;color:#fff;font-size:10px;font-weight:700;padding:3px 10px;border-radius:99px;letter-spacing:0.02em">%s</span>'
                    .'</div>'
                    .'<div style="font-size:12px;color:#475569;margin-bottom:8px">'
                    .'<span style="display:inline-flex;align-items:center;gap:3px">🚛 %s</span>'
                    .'<span style="margin:0 6px;color:#cbd5e1">|</span>'
                    .'<span>%s</span>'
                    .'</div>'
                    .'<div style="font-size:11px;font-weight:600;color:#64748b;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.03em">Đơn hàng</div>'
                    .'%s'
                    .'</div>',
                    e($vehicle->plate_number),
                    ($etaText ? ('ETA: '.e($etaText).' • ') : ''),
                    (! empty($distanceKm) ? e($distanceKm).' km' : ''),
                    $statusColor,
                    e($vehicle->getStatusLabel()),
                    e($trackingDriver),
                    e($vehicle->vehicle_type?->getLabel() ?? 'Xe thường'),
                    $ordersHtml ?: '<div style="font-size:11px;color:#94a3b8;text-align:center;padding:8px 0">Không có đơn hàng</div>',
                );

                return Marker::make((float) $lat, (float) $lng)
                    ->id('vehicle-'.$vehicle->id)
                    ->title($vehicle->plate_number)
                    ->icon(asset('images/truck.png'), [38, 38])
                    ->color($statusColor)
                    ->popupContent($popupContent)
                    ->popupOptions(['maxWidth' => 380]);
            });

            $selectedMarkers = $allMarkers->filter(fn (Marker $m) => in_array((int) str_replace('vehicle-', '', $m->getId()), $this->selectedVehicleIds, true))->values()->all();
            $otherMarkers = $allMarkers->filter(fn (Marker $m) => ! in_array((int) str_replace('vehicle-', '', $m->getId()), $this->selectedVehicleIds, true))->values()->all();

            $totalVehicles = $allMarkers->count();

            // If many vehicles, return a MarkerCluster for non-selected ones, keeping selected markers separate.
            if ($totalVehicles > 50) {
                $cluster = \EduardoRibeiroDev\FilamentLeaflet\LayerGroups\MarkerCluster::make($otherMarkers)
                    ->maxClusterRadius(80)
                    ->spiderfyOnMaxZoom(true)
                    ->removeOutsideVisibleBounds(true)
                    ->zoomToBoundsOnClick(true);

                return array_merge([$cluster], $selectedMarkers);
            }

            // Fallback: return all markers (no clustering)
            return $allMarkers->all();
        }
    }

    /**
     * @return Polyline[]
     */
    protected function getShapes(): array
    {
        $vehicles = $this->getFilteredVehicles();
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
            ->filter(fn (Vehicle $v) => in_array($v->id, $this->selectedVehicleIds, true))
            ->flatMap(function (Vehicle $vehicle) use ($activeStatuses, $segmentColors): array {
                $allOrders = $vehicle->orders ?? collect();
                $activeOrders = $allOrders->filter(
                    fn (Order $o) => in_array($o->status->value, $activeStatuses, true)
                );
                $trackingOrder = $activeOrders->first();

                // Chỉ vẽ route khi xe đang chạy và có đơn hàng active
                if ($trackingOrder === null || $vehicle->status !== VehicleStatus::Running) {
                    return [];
                }

                $routePoints = $this->routePointsForOrder($trackingOrder, $vehicle->id, $this->playbackTimestamp);

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

                    $isLastSegment = $i === (count($points) - 2);

                    if (count($osrmSegment) >= 2) {
                        // Main route line (thicker, high opacity)
                        $shapes[] = Polyline::make($osrmSegment)
                            ->id("route-seg{$i}-{$vehicle->id}")
                            ->color($color)
                            ->weight($isLastSegment ? 8 : 6)
                            ->opacity($isLastSegment ? 0.95 : 0.9)
                            ->fill(false)
                            ->tooltipContent($label);

                        // If this is the last segment (near destination), add a subtle highlight layer
                        if ($isLastSegment) {
                            $shapes[] = Polyline::make($osrmSegment)
                                ->id("route-seg{$i}-{$vehicle->id}-highlight")
                                ->color($color)
                                ->weight(12)
                                ->opacity(0.18)
                                ->fill(false);
                        }
                    } else {
                        // Fallback: đường thẳng giữa 2 checkpoint (dashed)
                        $shapes[] = Polyline::make($segment)
                            ->id("route-seg{$i}-{$vehicle->id}")
                            ->color($color)
                            ->weight($isLastSegment ? 6 : 4)
                            ->opacity(0.75)
                            ->dashArray(8, 4)
                            ->fill(false)
                            ->tooltipContent($label.' (ước lượng)');
                    }

                    // Add a small, non-intrusive midpoint label for the segment
                    $midIdx = (int) floor(count($segment) / 2);
                    [$aLat, $aLng] = $segment[0];
                    [$bLat, $bLng] = $segment[1];
                    $midLat = ($aLat + $bLat) / 2;
                    $midLng = ($aLng + $bLng) / 2;

                    $shapes[] = CircleMarker::make($midLat, $midLng)
                        ->id("route-seg-label-{$i}-{$vehicle->id}")
                        ->radius(0.5)
                        ->color($color)
                        ->fillColor($color)
                        ->fillOpacity(0)
                        ->tooltipContent($label)
                        ->tooltipOptions(['permanent' => true, 'direction' => 'center']);
                }

                return $shapes;
            })
            ->all();
    }

    /**
     * Gọi OSRM service để lấy route bám đường thực tế.
     *
     * @param  array<int, array{float, float}>  $points  Mảng các điểm [lat, lng]
     * @return array<int, array{float, float}> Mảng các điểm [lat, lng] chi tiết từ OSRM
     */
    private function fetchOsrmRoute(array $points): array
    {
        return app(OsrmService::class)->getRouteFromPoints($points);
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
     * Apply UI filters to raw vehicles collection.
     */
    private function getFilteredVehicles(): Collection
    {
        $vehicles = $this->getRawVehicles();

        if ($this->filterStatus !== 'all') {
            // allow either enum name or value
            $vehicles = $vehicles->filter(fn (Vehicle $v) => ($v->status->value ?? null) === $this->filterStatus || ($v->status->name ?? null) === $this->filterStatus)->values();
        }

        if ($this->filterVehicleType !== 'all') {
            $vehicles = $vehicles->filter(fn (Vehicle $v) => (string) ($v->vehicle_type?->value ?? $v->vehicle_type?->name ?? '') === $this->filterVehicleType)->values();
        }

        if ($this->filterDateFrom || $this->filterDateTo) {
            $from = $this->filterDateFrom ? Carbon::parse($this->filterDateFrom) : null;
            $to = $this->filterDateTo ? Carbon::parse($this->filterDateTo) : null;

            $vehicles = $vehicles->filter(function (Vehicle $v) use ($from, $to) {
                // look at planned_loading_at on orders or trip checkpoint occurred_at
                foreach ($v->orders as $o) {
                    if ($from && $to) {
                        $pl = $o->planned_loading_at ? Carbon::parse($o->planned_loading_at) : null;
                        if ($pl && $pl->between($from, $to)) {
                            return true;
                        }
                        // checkpoints
                        foreach ($o->tripCheckpoints ?? [] as $c) {
                            $occ = $c->occurred_at ? Carbon::parse($c->occurred_at) : null;
                            if ($occ && $occ->between($from, $to)) {
                                return true;
                            }
                        }
                    } else {
                        $pl = $o->planned_loading_at ? Carbon::parse($o->planned_loading_at) : null;
                        if ($from && $pl && $pl >= $from) return true;
                        if ($to && $pl && $pl <= $to) return true;
                        foreach ($o->tripCheckpoints ?? [] as $c) {
                            $occ = $c->occurred_at ? Carbon::parse($c->occurred_at) : null;
                            if ($from && $occ && $occ >= $from) return true;
                            if ($to && $occ && $occ <= $to) return true;
                        }
                    }
                }

                return false;
            })->values();
        }

        return $vehicles;
    }

    /**
     * @return Collection<int, array{lat: float, lng: float, label: string}>
     */
    /**
     * @param Order|null $order
     * @param int $vehicleId
     * @param int|null $asOfTimestamp Return only points with occurred_at <= this timestamp (unix seconds)
     */
    private function routePointsForOrder(?Order $order, int $vehicleId, ?int $asOfTimestamp = null): Collection
    {
        if ($order === null) {
            return collect();
        }
        $points = ($order->tripCheckpoints ?? collect())
            ->filter(fn (TripCheckpoint $c) => $c->gps_lat !== null && $c->gps_lng !== null)
            ->sortBy('occurred_at')
            ->values();

        if ($asOfTimestamp !== null) {
            $asOf = Carbon::createFromTimestamp($asOfTimestamp);
            $points = $points->filter(fn (TripCheckpoint $c) => $c->occurred_at <= $asOf)->values();
        }

        return $points->map(fn (TripCheckpoint $c) => [
            'lat' => (float) $c->gps_lat,
            'lng' => (float) $c->gps_lng,
            'label' => $c->checkpoint_type?->getLabel() ?? 'Checkpoint',
        ]);
    }

    /**
     * Get playback bounds (min,max) as unix timestamps based on available trip checkpoints.
     * @return array{0:int|null,1:int|null}
     */
    public function getPlaybackBounds(): array
    {
        $min = null;
        $max = null;

        $vehicles = $this->getFilteredVehicles();
        $vehicles->each(function (Vehicle $v) use (&$min, &$max) {
            $v->orders->each(function (Order $o) use (&$min, &$max) {
                ($o->tripCheckpoints ?? collect())->each(function (TripCheckpoint $c) use (&$min, &$max) {
                    if ($c->occurred_at) {
                        $ts = Carbon::parse($c->occurred_at)->timestamp;
                        $min = $min === null ? $ts : min($min, $ts);
                        $max = $max === null ? $ts : max($max, $ts);
                    }
                });
            });
        });

        return [$min, $max];
    }
}
