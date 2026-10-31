<?php

namespace App\Filament\Pages;

use App\Enums\CheckpointType;
use App\Enums\MapTile;
use App\Enums\OrderDeliveryPointStatus;
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

    /**
     * Chỉ lần render đầu mới nhúng config bản đồ vào HTML; các lần sau dữ liệu đi qua refreshMap().
     */
    protected bool $isInitialRender = false;

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

    public function handleMapClick(float $latitude, float $longitude): void
    {
        // Safe no-op: clicking map background or dragging should not cancel selection
        // to prevent race conditions with marker clicks and maintain selection while panning.
    }

    public function clearSelectedVehicles(): void
    {
        $this->selectedVehicleIds = [];
        $this->dispatch('vehicleSelectionChanged', selectedIds: []);
        $this->cachedVehicles = null;
        $this->cachedLayerData = null;
        $this->refreshMap();
    }

    public function getSelectedVehiclePlatesString(): string
    {
        if (empty($this->selectedVehicleIds)) {
            return '';
        }

        $vehicles = $this->getRawVehicles()->whereIn('id', $this->selectedVehicleIds);
        if ($vehicles->isEmpty()) {
            return '';
        }

        return $vehicles->pluck('plate_number')->implode(', ');
    }

    #[On('vehicleSelectionChanged')]
    public function updateSelectedVehicles(array $selectedIds = []): void
    {
        $newIds = array_values(array_map('intval', $selectedIds));
        if ($this->selectedVehicleIds === $newIds) {
            return;
        }

        $this->selectedVehicleIds = $newIds;
        $this->cachedVehicles = null;
        $this->cachedLayerData = null;
        $this->refreshMap();
    }

    public function mount(): void
    {
        $this->lastUpdated = now();
        $this->selectedVehicleIds = [];
        $this->isInitialRender = true;
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

    public function isInitialRender(): bool
    {
        return $this->isInitialRender;
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

    /**
     * Không tự fit mỗi lần cập nhật (làm bản đồ nhảy); JS tự fit lúc khởi tạo và khi đổi xe chọn.
     */
    protected function getFitBounds(): bool
    {
        return false;
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
        return MapTile::options();
    }

    /**
     * Màu marker theo trạng thái xe (dùng chung cho chú thích trên view).
     */
    public static function statusColor(?VehicleStatus $status): string
    {
        return match ($status) {
            VehicleStatus::Running => '#f59e0b',
            VehicleStatus::On => '#10b981',
            VehicleStatus::Bdsc => '#ef4444',
            default => '#6b7280',
        };
    }

    /**
     * @return array<string, string> nhãn trạng thái => màu
     */
    public function getStatusLegend(): array
    {
        return collect(VehicleStatus::cases())
            ->mapWithKeys(fn (VehicleStatus $status): array => [$status->getLabel() => self::statusColor($status)])
            ->all();
    }

    public function getVehiclesWithoutGpsCount(): int
    {
        return $this->getFilteredVehicles()
            ->filter(fn (Vehicle $vehicle): bool => $this->vehiclePosition($vehicle) === null)
            ->count();
    }

    /**
     * Chỉ vẽ xe có toạ độ thật; đang chọn xe thì chỉ vẽ các xe đó.
     * Luôn gom cụm với id cố định để JS cập nhật marker theo id thay vì dựng lại.
     *
     * @return MarkerCluster[]
     */
    protected function getMarkers(): array
    {
        $markers = $this->getFilteredVehicles()
            ->when($this->selectedVehicleIds !== [], fn (Collection $vehicles): Collection => $vehicles->whereIn('id', $this->selectedVehicleIds))
            ->map(function (Vehicle $vehicle): ?Marker {
                $position = $this->vehiclePosition($vehicle);

                if ($position === null) {
                    return null;
                }

                return Marker::make($position[0], $position[1])
                    ->id('vehicle-'.$vehicle->id)
                    ->icon(Heroicon::Truck, [30, 42])
                    ->color(self::statusColor($vehicle->status))
                    ->showShadow(false)
                    ->popupContent($this->vehiclePopupHtml($vehicle))
                    ->popupOptions(['maxWidth' => 320]);
            })
            ->filter()
            ->values();

        return [
            MarkerCluster::make($markers->all())
                ->id('vehicles')
                ->maxClusterRadius(45)
                ->spiderfyOnMaxZoom(true)
                ->removeOutsideVisibleBounds(true)
                ->zoomToBoundsOnClick(true),
        ];
    }

    /**
     * Lộ trình của xe đang chọn: đường đã đi (checkpoint theo thời gian + vị trí hiện tại)
     * và đường kế hoạch còn lại (tới các điểm giao chưa giao).
     *
     * @return array<int, Polyline|CircleMarker>
     */
    protected function getShapes(): array
    {
        return $this->getFilteredVehicles()
            ->filter(fn (Vehicle $vehicle): bool => in_array($vehicle->id, $this->selectedVehicleIds, true))
            ->flatMap(fn (Vehicle $vehicle): array => $this->vehicleRouteShapes($vehicle))
            ->all();
    }

    /**
     * @return array<int, Polyline|CircleMarker>
     */
    private function vehicleRouteShapes(Vehicle $vehicle): array
    {
        $trip = $this->activeTrip($vehicle) ?? $vehicle->trips->first();

        if ($trip === null) {
            return [];
        }

        $isActive = in_array($trip->status, TripStatus::activeStatuses(), true);
        $checkpoints = $this->gpsCheckpoints($trip);
        $current = $isActive ? $this->vehicleGps($vehicle) : null;

        // Lộ trình EUP thật của xe trong chuyến; không có thì nối các mốc GPS của tài xế.
        $gpsTrack = $trip->gpsTrack();
        $traveled = count($gpsTrack) >= 2
            ? collect($gpsTrack)
            : $checkpoints->map(fn (array $c): array => [$c['lat'], $c['lng']])->values();
        if ($current !== null && ! $this->samePoint($traveled->last(), $current)) {
            $traveled->push($current);
        }

        $pendingStops = $isActive ? $this->pendingStops($trip, $checkpoints->isEmpty()) : collect();
        $planned = $pendingStops->map(fn (array $s): array => [$s['lat'], $s['lng']]);
        if ($traveled->isNotEmpty()) {
            $planned->prepend($traveled->last());
        }

        $prefix = "trk-{$vehicle->id}-";
        $shapes = [];

        if ($traveled->count() >= 2) {
            $shapes[] = (count($gpsTrack) >= 2
                ? Polyline::make($traveled->all())->id($prefix.'traveled-'.md5(json_encode($traveled->all())))->fill(false)
                : $this->routeLine($prefix.'traveled', $traveled->all()))
                ->color('#2563eb')
                ->weight(5)
                ->opacity(0.9)
                ->tooltipContent(e($vehicle->plate_number).' · Đã đi');
        }

        if ($planned->count() >= 2) {
            $shapes[] = $this->routeLine($prefix.'planned', $planned->values()->all())
                ->color('#e11d48')
                ->weight(4)
                ->opacity(0.85)
                ->dashArray(8, 8)
                ->tooltipContent(e($vehicle->plate_number).' · Kế hoạch còn lại');
        }

        foreach ($checkpoints as $checkpoint) {
            $shapes[] = CircleMarker::make($checkpoint['lat'], $checkpoint['lng'])
                ->id($prefix.'cp-'.$checkpoint['id'])
                ->radius(6)
                ->color('#ffffff')
                ->fillColor($this->checkpointColor($checkpoint['type']))
                ->fillOpacity(1)
                ->weight(2)
                ->tooltipContent(e($checkpoint['label']).' · '.$checkpoint['time']);
        }

        foreach ($pendingStops as $stop) {
            $shapes[] = CircleMarker::make($stop['lat'], $stop['lng'])
                ->id($prefix.'stop-'.$stop['key'])
                ->radius(7)
                ->color('#e11d48')
                ->fillColor('#ffffff')
                ->fillOpacity(1)
                ->weight(3)
                ->tooltipContent(e($stop['label']));
        }

        return $shapes;
    }

    /**
     * Tuyến bám đường qua OSRM; lỗi thì nối thẳng. Id gồm hash toạ độ để tuyến đổi thì JS thay layer mới.
     *
     * @param  array<int, array{0: float, 1: float}>  $points
     */
    private function routeLine(string $idPrefix, array $points): Polyline
    {
        $road = app(OsrmService::class)->getRouteFromPoints($points);

        return Polyline::make(count($road) >= 2 ? $road : $points)
            ->id($idPrefix.'-'.md5(json_encode($points)))
            ->fill(false);
    }

    private function checkpointColor(?CheckpointType $type): string
    {
        return match ($type) {
            CheckpointType::Started => '#16a34a',
            CheckpointType::ArrivedPickup, CheckpointType::LeftPickup => '#d97706',
            CheckpointType::ArrivedDelivery, CheckpointType::Completed => '#7c3aed',
            CheckpointType::End, CheckpointType::Cancelled => '#dc2626',
            default => '#64748b',
        };
    }

    private function activeTrip(Vehicle $vehicle): ?Trip
    {
        return $vehicle->trips->first(fn (Trip $trip): bool => in_array($trip->status, TripStatus::activeStatuses(), true));
    }

    /**
     * Vị trí hiện tại: GPS xe, nếu không có thì checkpoint mới nhất của chuyến. Không bịa toạ độ.
     *
     * @return array{0: float, 1: float}|null
     */
    private function vehiclePosition(Vehicle $vehicle): ?array
    {
        $gps = $this->vehicleGps($vehicle);

        if ($gps !== null) {
            return $gps;
        }

        $trip = $this->activeTrip($vehicle) ?? $vehicle->trips->first();
        $latest = $trip ? $this->gpsCheckpoints($trip)->last() : null;

        return $latest ? [$latest['lat'], $latest['lng']] : null;
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private function vehicleGps(Vehicle $vehicle): ?array
    {
        return $this->validPoint($vehicle->gps_lat, $vehicle->gps_lng);
    }

    /**
     * Checkpoint có GPS hợp lệ, theo thứ tự thời gian (cùng thời điểm thì theo id).
     *
     * @return Collection<int, array{id: int, lat: float, lng: float, type: ?CheckpointType, label: string, time: string}>
     */
    private function gpsCheckpoints(Trip $trip): Collection
    {
        $trip->loadMissing('checkpoints');

        return $trip->checkpoints
            ->sortBy([['occurred_at', 'asc'], ['id', 'asc']])
            ->map(function (TripCheckpoint $checkpoint): ?array {
                $point = $this->validPoint($checkpoint->gps_lat, $checkpoint->gps_lng);

                return $point === null ? null : [
                    'id' => $checkpoint->id,
                    'lat' => $point[0],
                    'lng' => $point[1],
                    'type' => $checkpoint->checkpoint_type,
                    'label' => $checkpoint->checkpoint_type?->getLabel() ?? 'Checkpoint',
                    'time' => $checkpoint->occurred_at?->format('H:i d/m') ?? '',
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Các điểm chưa tới của chuyến: điểm lấy hàng (khi chưa có checkpoint nào) và điểm giao chưa giao.
     *
     * @return Collection<int, array{key: string, lat: float, lng: float, label: string}>
     */
    private function pendingStops(Trip $trip, bool $includePickup): Collection
    {
        $stops = collect();

        foreach ($trip->orders as $order) {
            $pickup = $order->pickupLocation;
            $pickupPoint = $pickup ? $this->validPoint($pickup->lat, $pickup->lng) : null;
            if ($includePickup && $pickupPoint !== null) {
                $stops->put('pickup-'.$pickup->id, [
                    'key' => 'pickup-'.$pickup->id,
                    'lat' => $pickupPoint[0],
                    'lng' => $pickupPoint[1],
                    'label' => 'Lấy hàng: '.($pickup->name ?? $order->pickup_address ?? ''),
                ]);
            }

            foreach ($order->deliveryPoints->sortBy('sequence') as $deliveryPoint) {
                $point = $deliveryPoint->location ? $this->validPoint($deliveryPoint->location->lat, $deliveryPoint->location->lng) : null;
                if ($point === null || $deliveryPoint->status === OrderDeliveryPointStatus::Delivered) {
                    continue;
                }

                $stops->put('dp-'.$deliveryPoint->id, [
                    'key' => 'dp-'.$deliveryPoint->id,
                    'lat' => $point[0],
                    'lng' => $point[1],
                    'label' => 'Điểm giao chưa tới: '.($deliveryPoint->address ?? $deliveryPoint->location->name ?? ''),
                ]);
            }
        }

        return $stops->values();
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private function validPoint(mixed $lat, mixed $lng): ?array
    {
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        if (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0.0 && $lng == 0.0)) {
            return null;
        }

        return [$lat, $lng];
    }

    /**
     * @param  array{0: float, 1: float}|null  $a
     * @param  array{0: float, 1: float}  $b
     */
    private function samePoint(?array $a, array $b): bool
    {
        return $a !== null && abs($a[0] - $b[0]) < 0.0001 && abs($a[1] - $b[1]) < 0.0001;
    }

    private function vehiclePopupHtml(Vehicle $vehicle): string
    {
        $trip = $this->activeTrip($vehicle);
        $driver = $trip?->driver ?? $vehicle->driver;
        $orders = $trip?->orders ?? collect();

        $ordersHtml = $orders->take(2)->map(function (Order $order): string {
            $pickup = $order->pickupLocation?->name ?? $order->pickup_address ?? 'Điểm nhận';
            $delivery = $order->deliveryPoints->sortBy('sequence')->first()?->address ?? 'Điểm giao';

            return sprintf(
                '<div class="trk-order-item"><strong>#%s</strong> · %s<div class="trk-muted trk-ellipsis">%s → %s</div></div>',
                e($order->order_code),
                e($order->customer?->name ?? 'Khách lẻ'),
                e($pickup),
                e($delivery),
            );
        })->implode('');

        $lastUpdate = $vehicle->last_gps_update;
        $isStale = $lastUpdate === null || $lastUpdate->lt(now()->subMinutes(30));
        $meta = array_filter([
            $vehicle->gps_speed !== null && (float) $vehicle->gps_speed > 0 ? round((float) $vehicle->gps_speed).' km/h' : null,
            sprintf(
                '<span class="%s">Cập nhật: %s</span>',
                $isStale ? 'trk-stale' : '',
                $lastUpdate?->format('H:i d/m') ?? 'chưa có GPS',
            ),
        ]);

        return sprintf(
            '<div class="trk-popup">'
            .'<div class="trk-popup-head"><span class="trk-popup-plate">%s</span><span class="trk-popup-badge" style="background:%s;">%s</span></div>'
            .'<div class="trk-row">👤 <strong>%s</strong>%s</div>'
            .'%s%s'
            .'<div class="trk-muted">%s</div>'
            .'</div>',
            e($vehicle->plate_number),
            self::statusColor($vehicle->status),
            e($vehicle->getStatusLabel()),
            e($driver?->name ?? 'Chưa gán lái xe'),
            $driver?->phone ? ' · '.e($driver->phone) : '',
            $trip ? sprintf('<div class="trk-row">🚚 Chuyến <strong>%s</strong> · %d đơn</div>', e($trip->trip_code), $orders->count()) : '<div class="trk-row trk-muted">Không có chuyến đang chạy</div>',
            $ordersHtml,
            implode(' · ', $meta),
        );
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
                    ->with(['driver', 'orders.customer', 'orders.pickupLocation', 'orders.deliveryPoints.location']),
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
