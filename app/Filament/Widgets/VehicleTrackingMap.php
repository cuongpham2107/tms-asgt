<?php

namespace App\Filament\Widgets;

use App\Enums\CheckpointType;
use App\Enums\MapTile;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use App\Services\OsrmService;
use Filament\Widgets\Concerns\HasJsRenderer;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * Toàn bộ trang "Theo dõi thực tế" do React vẽ: danh sách xe (lọc, chọn), bản đồ Leaflet
 * (gom cụm marker, lộ trình xe đang chọn) và chú thích. Page chỉ nhúng widget này.
 */
class VehicleTrackingMap extends Widget
{
    use HasJsRenderer;

    private const DEFAULT_CENTER = [21.0285, 105.8542]; // Hà Nội Center

    /** Lộ trình đã đi chỉ vẽ trong N giờ gần nhất: có chuyến bị treo "đang chạy" nhiều ngày. */
    private const TRACK_WINDOW_HOURS = 8;

    protected int|string|array $columnSpan = 'full';

    /** @var array<int, int> */
    public array $selectedVehicleIds = [];

    public string $vehicleSearch = '';

    public string $filterStatus = 'all';

    public int $perPage = 30;

    public ?string $lastUpdated = null;

    private ?Collection $cachedVehicles = null;

    public function getRenderer(): string
    {
        return Vite::asset('resources/js/filament/widgets/vehicle-tracking-map.tsx');
    }

    public function mount(): void
    {
        $this->lastUpdated = now()->format('H:i:s');
    }

    public function updatedVehicleSearch(): void
    {
        $this->perPage = 30;
    }

    public function updatedFilterStatus(): void
    {
        $this->perPage = 30;
    }

    /**
     * Bấm marker hoặc thẻ xe: chọn đúng xe đó (bấm lại khi đang là xe duy nhất thì bỏ chọn).
     */
    public function selectVehicle(int $id): void
    {
        $current = array_map('intval', $this->selectedVehicleIds);
        $this->selectedVehicleIds = ($current === [$id]) ? [] : [$id];
    }

    public function toggleCheckbox(int $id): void
    {
        $current = array_map('intval', $this->selectedVehicleIds);
        $this->selectedVehicleIds = in_array($id, $current, true)
            ? array_values(array_filter($current, fn (int $value): bool => $value !== $id))
            : [...$current, $id];
    }

    public function selectAll(): void
    {
        $this->selectedVehicleIds = $this->filteredVehicles()->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    public function deselectAll(): void
    {
        $this->selectedVehicleIds = [];
    }

    public function selectRunningOnly(): void
    {
        $this->selectedVehicleIds = $this->rawVehicles()
            ->where('status', VehicleStatus::Running)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public function loadMore(): void
    {
        $this->perPage += 30;
    }

    public function refreshData(): void
    {
        $this->cachedVehicles = null;
        $this->lastUpdated = now()->format('H:i:s');
    }

    /**
     * Reverb chưa chạy thì React tự làm mới mỗi 30 giây; có event thì cập nhật ngay.
     */
    #[On('echo:vehicles,.VehiclesSynced')]
    public function onVehiclesSynced(): void
    {
        $this->refreshData();
    }

    /**
     * @return array<string, mixed>
     */
    public function getRendererConfiguration(): array
    {
        $vehicles = $this->filteredVehicles();
        $selectedIds = array_map('intval', $this->selectedVehicleIds);

        return [
            'vehicles' => $vehicles->map(fn (Vehicle $vehicle): array => $this->vehiclePayload($vehicle, $selectedIds))->values()->all(),
            'visibleCount' => min($this->perPage, $vehicles->count()),
            'totalCount' => $vehicles->count(),
            'hasMore' => $vehicles->count() > $this->perPage,
            'selectedIds' => $selectedIds,
            'selectedPlates' => $vehicles->whereIn('id', $selectedIds)->pluck('plate_number')->implode(', '),
            'routes' => $this->routesPayload($vehicles, $selectedIds),
            'filters' => [
                'search' => $this->vehicleSearch,
                'status' => $this->filterStatus,
            ],
            'statusOptions' => [
                ['key' => 'all', 'label' => 'Tất cả', 'color' => null],
                ['key' => 'running', 'label' => 'Đang chạy', 'color' => self::statusColor(VehicleStatus::Running)],
                ['key' => 'on', 'label' => 'Sẵn sàng', 'color' => self::statusColor(VehicleStatus::On)],
                ['key' => 'bdsc', 'label' => 'Bảo dưỡng', 'color' => self::statusColor(VehicleStatus::Bdsc)],
                ['key' => 'off', 'label' => 'Tắt máy', 'color' => self::statusColor(VehicleStatus::Off)],
            ],
            'legend' => $this->getStatusLegend(),
            'withoutGpsCount' => $this->getVehiclesWithoutGpsCount(),
            'tiles' => MapTile::config(),
            'center' => self::DEFAULT_CENTER,
            'zoom' => 12,
            'lastUpdated' => $this->lastUpdated,
        ];
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

    public static function statusColor(?VehicleStatus $status): string
    {
        return match ($status) {
            VehicleStatus::Running => '#f59e0b',
            VehicleStatus::On => '#10b981',
            VehicleStatus::Bdsc => '#ef4444',
            default => '#6b7280',
        };
    }

    public function getVehiclesWithoutGpsCount(): int
    {
        return $this->filteredVehicles()
            ->filter(fn (Vehicle $vehicle): bool => $this->vehiclePosition($vehicle) === null)
            ->count();
    }

    /**
     * @param  array<int, int>  $selectedIds
     * @return array<string, mixed>
     */
    private function vehiclePayload(Vehicle $vehicle, array $selectedIds): array
    {
        $status = $vehicle->status instanceof VehicleStatus ? $vehicle->status : VehicleStatus::tryFrom((string) $vehicle->status);
        $position = $this->vehiclePosition($vehicle);
        $trip = $this->activeTrip($vehicle);
        $driver = $trip?->driver ?? $vehicle->driver;
        $orders = $trip?->orders ?? collect();
        $lastUpdate = $vehicle->last_gps_update;

        return [
            'id' => $vehicle->id,
            'plate' => $vehicle->plate_number,
            'lat' => $position[0] ?? null,
            'lng' => $position[1] ?? null,
            'hasGps' => $position !== null,
            'status' => $status?->value ?? 'off',
            'statusLabel' => $vehicle->getStatusLabel(),
            'color' => self::statusColor($status),
            'selected' => in_array((int) $vehicle->id, $selectedIds, true),
            'driver' => $driver?->name ?? 'Chưa gán lái',
            'phone' => $driver?->phone,
            'vehicleType' => $vehicle->getVehicleTypeLabel(),
            'speed' => $vehicle->gps_speed ? (float) $vehicle->gps_speed : null,
            'updated' => $lastUpdate?->format('H:i d/m'),
            'stale' => $lastUpdate === null || $lastUpdate->lt(now()->subMinutes(30)),
            'tripCode' => $trip?->trip_code,
            'ordersCount' => $orders->count(),
            'orders' => $orders->take(2)->map(function (Order $order): array {
                return [
                    'code' => $order->order_code,
                    'customer' => $order->customer?->name ?? 'Khách lẻ',
                    'from' => $order->pickupLocation?->name ?? $order->pickup_address ?? 'Điểm nhận',
                    'to' => $order->deliveryPoints->sortBy('sequence')->first()?->address ?? 'Điểm giao',
                ];
            })->values()->all(),
        ];
    }

    /**
     * Lộ trình của các xe đang chọn: đường đã đi (GPS/chấm công, bám OSRM) và kế hoạch còn lại.
     *
     * @param  Collection<int, Vehicle>  $vehicles
     * @param  array<int, int>  $selectedIds
     * @return array<int, array<string, mixed>>
     */
    private function routesPayload(Collection $vehicles, array $selectedIds): array
    {
        if ($selectedIds === []) {
            return [];
        }

        return $vehicles
            ->filter(fn (Vehicle $vehicle): bool => in_array((int) $vehicle->id, $selectedIds, true))
            ->map(fn (Vehicle $vehicle): array => $this->vehicleRoute($vehicle))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function vehicleRoute(Vehicle $vehicle): array
    {
        $trip = $this->activeTrip($vehicle) ?? $vehicle->trips->first();

        if ($trip === null) {
            return ['vehicleId' => $vehicle->id, 'traveled' => [], 'planned' => [], 'checkpoints' => [], 'stops' => []];
        }

        $isActive = in_array($trip->status, TripStatus::activeStatuses(), true);
        $checkpoints = $this->gpsCheckpoints($trip);
        $current = $isActive ? $this->vehicleGps($vehicle) : null;

        // Lộ trình EUP thật của xe; không có thì nối các mốc GPS của tài xế.
        $gpsTrack = $trip->gpsTrack(since: now()->subHours(self::TRACK_WINDOW_HOURS));
        $traveled = count($gpsTrack) >= 2
            ? collect($gpsTrack)
            : $checkpoints->map(fn (array $checkpoint): array => [$checkpoint['lat'], $checkpoint['lng']])->values();

        if ($current !== null && ! $this->samePoint($traveled->last(), $current)) {
            $traveled->push($current);
        }

        $pendingStops = $isActive ? $this->pendingStops($trip, $checkpoints->isEmpty()) : collect();
        $planned = $pendingStops->map(fn (array $stop): array => [$stop['lat'], $stop['lng']]);

        if ($traveled->isNotEmpty()) {
            $planned->prepend($traveled->last());
        }

        $osrm = app(OsrmService::class);

        // Bám đường qua OSRM; lỗi/không có tuyến thì nối thẳng các điểm.
        $traveledPoints = $traveled->values()->all();
        if (count($gpsTrack) < 2 && count($traveledPoints) >= 2) {
            $road = $osrm->getRouteFromPoints($traveledPoints);
            if (count($road) >= 2) {
                $traveledPoints = $road;
            }
        }

        $plannedPoints = $planned->values()->all();
        if (count($plannedPoints) >= 2) {
            $road = $osrm->getRouteFromPoints($plannedPoints);
            if (count($road) >= 2) {
                $plannedPoints = $road;
            }
        }

        return [
            'vehicleId' => $vehicle->id,
            'traveled' => $traveledPoints,
            'planned' => count($plannedPoints) >= 2 ? $plannedPoints : [],
            'checkpoints' => $checkpoints->map(fn (array $checkpoint): array => [
                'lat' => $checkpoint['lat'],
                'lng' => $checkpoint['lng'],
                'color' => $this->checkpointColor($checkpoint['type']),
                'label' => $checkpoint['label'].' · '.$checkpoint['time'],
            ])->values()->all(),
            'stops' => $pendingStops->map(fn (array $stop): array => [
                'lat' => $stop['lat'],
                'lng' => $stop['lng'],
                'label' => $stop['label'],
            ])->values()->all(),
        ];
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

    /**
     * @return Collection<int, Vehicle>
     */
    private function rawVehicles(): Collection
    {
        if ($this->cachedVehicles !== null) {
            return $this->cachedVehicles;
        }

        return $this->cachedVehicles = Vehicle::query()
            ->with([
                'driver',
                'trips' => fn ($query) => $query
                    ->latest('id')
                    ->take(5)
                    ->with([
                        'driver',
                        'checkpoints',
                        'orders.customer',
                        'orders.pickupLocation',
                        'orders.deliveryPoints.location',
                    ]),
            ])
            ->where('is_active', true)
            ->where('type', VehicleOwnerType::Company)
            ->orderByRaw("CASE status WHEN 'running' THEN 1 WHEN 'on' THEN 2 WHEN 'bdsc' THEN 3 ELSE 4 END")
            ->orderBy('plate_number')
            ->get();
    }

    /**
     * @return Collection<int, Vehicle>
     */
    private function filteredVehicles(): Collection
    {
        $vehicles = $this->rawVehicles();

        if ($this->filterStatus !== 'all') {
            $vehicles = $vehicles->filter(function (Vehicle $vehicle): bool {
                $status = $vehicle->status instanceof VehicleStatus ? $vehicle->status->value : (string) $vehicle->status;

                return $status === $this->filterStatus;
            })->values();
        }

        if (filled($this->vehicleSearch)) {
            $search = Str::lower(trim($this->vehicleSearch));

            $vehicles = $vehicles->filter(function (Vehicle $vehicle) use ($search): bool {
                return str_contains(Str::lower($vehicle->plate_number ?? ''), $search)
                    || str_contains(Str::lower($vehicle->driver?->name ?? ''), $search)
                    || str_contains(Str::lower($vehicle->driver?->phone ?? ''), $search)
                    || str_contains(Str::lower($vehicle->trips->first()?->trip_code ?? ''), $search);
            })->values();
        }

        return $vehicles;
    }
}
