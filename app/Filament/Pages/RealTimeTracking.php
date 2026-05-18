<?php

namespace App\Filament\Pages;

use App\Enums\OrderStatus;
use App\Enums\VehicleStatus;
use App\Models\Order;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

class RealTimeTracking extends Page
{
    private const DEFAULT_MAP_CENTER = [
        'lat' => 21.1250,
        'lng' => 105.9500,
    ];

    private const NORTHERN_DEMO_POINTS = [
        ['lat' => 21.0285, 'lng' => 105.8542],
        ['lat' => 21.2142, 'lng' => 105.8027],
        ['lat' => 21.1861, 'lng' => 106.0763],
        ['lat' => 21.5942, 'lng' => 105.8482],
        ['lat' => 21.1167, 'lng' => 105.9583],
        ['lat' => 21.3019, 'lng' => 105.8995],
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $navigationLabel = 'Theo dõi thực tế';

    protected static string|UnitEnum|null $navigationGroup = 'Tổng quan';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Theo dõi thực tế';

    protected string $view = 'filament.pages.real-time-tracking';

    private ?Collection $cachedVehicles = null;

    private ?CarbonInterface $cachedTrackingDate = null;

    public function getMapboxToken(): string
    {
        return config('services.mapbox.token', '');
    }

    private function getRawVehicles(): Collection
    {
        if ($this->cachedVehicles !== null) {
            return $this->cachedVehicles;
        }

        $activeStatuses = $this->activeOrderStatuses();
        $trackingDate = $this->trackingDate();

        $this->cachedVehicles = Vehicle::query()
            ->with([
                'driver',
                'driverShifts' => fn ($q) => $q
                    ->with('driver')
                    ->whereNull('end_time')
                    ->latest('start_time'),
                'documents' => fn ($q) => $q
                    ->whereIn('status', ['expiring_soon', 'expired'])
                    ->orderBy('expiry_date'),
                'maintenanceJobs' => fn ($q) => $q
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->where(fn ($q) => $q
                        ->where('status', 'overdue')
                        ->orWhereDate('planned_date', '<=', today()->addDays(3)))
                    ->orderBy('planned_date'),
                'maintenanceSchedules' => fn ($q) => $q
                    ->where('is_active', true)
                    ->whereIn('alert_status', ['warning', 'due', 'overdue']),
                'orders' => fn ($q) => $q
                    ->with([
                        'customer',
                        'deliveryPoints.location',
                        'driver',
                        'orderCategory',
                        'pickupLocation',
                        // withCount('photos') nếu TripCheckpoint có quan hệ photos
                        'tripCheckpoints' => fn ($q) => $q->orderBy('occurred_at'),
                    ])
                    ->where(fn (Builder $q): Builder => $q
                        ->whereIn('status', $activeStatuses)
                        ->orWhereDate('planned_loading_at', $trackingDate))
                    ->orderByDesc('planned_loading_at'),
            ])
            ->where('is_active', true)
            ->get();

        return $this->cachedVehicles;
    }

    /** @return array<int, array<string, mixed>> */
    public function getVehicles(): array
    {
        $activeStatuses = $this->activeOrderStatuses();
        $trackingDate = $this->trackingDate();
        $vehicles = $this->getRawVehicles();

        return $vehicles->map(function (Vehicle $vehicle) use ($activeStatuses, $trackingDate): array {
            $latestShift = $vehicle->driverShifts->first();

            /** @var Collection<int, Order> $allOrders */
            $allOrders = $vehicle->orders ?? collect();
            $activeOrders = $allOrders
                ->filter(fn (Order $o): bool => in_array($this->orderStatusValue($o), $activeStatuses, true))
                ->sortByDesc('planned_loading_at');
            $todayOrders = $allOrders
                ->filter(fn (Order $o): bool => $o->planned_loading_at?->isSameDay($trackingDate) ?? false)
                ->sortByDesc('planned_loading_at');

            $selectedOrders = $activeOrders->isNotEmpty()
                ? $activeOrders->take(3)
                : $todayOrders->take(3);

            $trackingOrder = $activeOrders->first() ?? $todayOrders->first();
            $routePoints = $this->routePointsForOrder($trackingOrder, $vehicle->id);
            $latestPoint = $routePoints->last();

            $hasShiftGps = $latestShift?->start_gps_lat !== null && $latestShift?->start_gps_lng !== null;
            $shiftPosition = $hasShiftGps
                ? $this->normalizeDemoCoordinate(
                    (float) $latestShift->start_gps_lat,
                    (float) $latestShift->start_gps_lng,
                    $vehicle->id
                )
                : null;

            $fallbackPosition = $this->fallbackPositionForVehicle($vehicle);
            $lat = $latestPoint['lat'] ?? ($shiftPosition['lat'] ?? $fallbackPosition['lat']);
            $lng = $latestPoint['lng'] ?? ($shiftPosition['lng'] ?? $fallbackPosition['lng']);

            $trackingStatus = $this->trackingStatusForVehicle($vehicle, $activeOrders);
            $trackingDriver = $trackingOrder?->driver?->name
                ?? $latestShift?->driver?->name
                ?? $vehicle->driver?->name
                ?? 'Không lái';

            // ── Orders (up to 3) ──────────────────────────────────────────────
            $orders = $selectedOrders->map(
                function (Order $order) use ($activeStatuses): array {
                    /** @var Collection<int, TripCheckpoint> $checkpoints */
                    $checkpoints = $order->tripCheckpoints ?? collect();
                    $latestCheckpoint = $checkpoints->sortByDesc('occurred_at')->first();

                    // Tất cả điểm giao hàng theo thứ tự
                    $deliveryPoints = $order->deliveryPoints
                        ?->sortBy('sequence')
                        ->map(fn ($dp) => [
                            'sequence' => $dp->sequence,
                            'name' => $dp->address ?? $dp->location?->name ?? '—',
                            'contact' => $dp->contact_person,
                            'phone' => $dp->contact_phone,
                            'status' => $dp->status instanceof BackedEnum
                                ? $dp->status->value
                                : (string) ($dp->status ?? 'pending'),
                            'arrived_at' => $this->formatDateTime($dp->arrived_at),
                            'delivered_at' => $this->formatDateTime($dp->delivered_at),
                        ])
                        ->values()
                        ->toArray() ?? [];

                    $firstDelivery = $order->deliveryPoints?->sortBy('sequence')->first();

                    // Đơn hàng quá giờ: đã qua planned_loading_at nhưng chưa hoàn thành
                    $isOverdue = $order->planned_loading_at?->isPast()
                        && ! in_array($this->orderStatusValue($order), [
                            OrderStatus::Delivered->value,
                            OrderStatus::Completed->value,
                            OrderStatus::Cancelled->value,
                        ], true);

                    return [
                        'id' => $order->id,
                        'order_code' => $order->order_code,
                        'status' => $this->orderStatusValue($order),
                        'status_label' => $order->status?->getLabel() ?? $this->orderStatusValue($order),
                        'route_color' => $this->routeColorForOrder($order, $activeStatuses),
                        'is_overdue' => $isOverdue,
                        'pickup' => $order->pickup_address ?? $order->pickupLocation?->name ?? null,
                        'delivery' => $firstDelivery?->address ?? $firstDelivery?->location?->name ?? null,
                        'customer' => $order->customer?->name ?? null,
                        'planned_loading_at' => $this->formatDateTime($order->planned_loading_at),
                        'latest_checkpoint' => $latestCheckpoint?->checkpoint_type?->getLabel(),
                        'latest_checkpoint_at' => $this->formatDateTime($latestCheckpoint?->occurred_at),
                        'total_packages' => $order->total_packages,
                        'total_weight' => $order->total_weight,
                        'delivery_points' => $deliveryPoints,
                    ];
                }
            )->values()->toArray();

            $alerts = $this->alertsForVehicle(
                vehicle: $vehicle,
                hasGps: $latestPoint !== null || $hasShiftGps,
                hasRoute: $routePoints->count() >= 2,
                activeOrders: $activeOrders,
            );

            return [
                'id' => $vehicle->id,
                'plate' => $vehicle->plate_number,
                'status' => $trackingStatus['value'],
                'status_label' => $trackingStatus['label'],
                'vehicle_status' => $vehicle->status?->value ?? $vehicle->status,
                'vehicle_status_label' => $vehicle->getStatusLabel(),
                'driver' => $trackingDriver,
                'vehicle_type' => $vehicle->vehicle_type?->value,
                'vehicle_type_label' => $vehicle->vehicle_type?->getLabel() ?? 'Xe thường',
                'owner_type' => $vehicle->type?->value,
                'owner_type_label' => $vehicle->type?->getLabel() ?? null,
                'lat' => $lat,
                'lng' => $lng,
                'heading' => 0,
                'position_source' => $latestPoint !== null
                    ? 'GPS checkpoint'
                    : ($hasShiftGps ? 'GPS vào ca' : 'Vị trí mô phỏng'),
                'today_category' => $this->todayCategoryForOrders($todayOrders, $activeStatuses),
                'today_order_count' => $todayOrders->count(),
                // Route cho bản đồ: chỉ điểm đầu + cuối để Directions API vẽ đường
                'route' => $routePoints->values()->toArray(),
                // Tất cả checkpoints để vẽ marker annotation
                'checkpoints' => $routePoints->values()->toArray(),
                'route_color' => $this->routeColorForOrder($trackingOrder, $activeStatuses),
                'route_order_code' => $trackingOrder?->order_code,
                'route_start' => $routePoints->first()['label'] ?? null,
                'route_end' => $routePoints->last()['label'] ?? null,
                'has_alerts' => count($alerts) > 0,
                'alerts' => $alerts,
                'orders' => $orders,
            ];
        })->toArray();
    }

    /** @return array<string, int> */
    public function getStats(): array
    {
        $activeStatuses = $this->activeOrderStatuses();
        $trackingDate = $this->trackingDate();
        $vehicles = $this->getRawVehicles();

        return [
            'total' => $vehicles->count(),
            'running' => $vehicles->filter(fn (Vehicle $v) => $v->status === VehicleStatus::Running ||
                $v->orders->whereIn('status', $activeStatuses)->isNotEmpty()
            )->count(),
            'on' => $vehicles->filter(fn (Vehicle $v) => $v->status === VehicleStatus::On &&
                $v->orders->whereIn('status', $activeStatuses)->isEmpty()
            )->count(),
            'bdsc' => $vehicles->filter(fn (Vehicle $v) => $v->status === VehicleStatus::Bdsc)->count(),
            'off' => $vehicles->filter(fn (Vehicle $v) => $v->status === VehicleStatus::Off)->count(),
            'alerts' => $vehicles->filter(fn (Vehicle $v) => in_array($v->status?->value ?? $v->status, [VehicleStatus::Off->value, VehicleStatus::Bdsc->value]) ||
                $v->documents->isNotEmpty() ||
                $v->maintenanceJobs->isNotEmpty() ||
                $v->maintenanceSchedules->isNotEmpty()
            )->count(),
            'today_total' => $vehicles->filter(fn (Vehicle $v) => $v->orders->filter(fn (Order $o) => $o->planned_loading_at?->isSameDay($trackingDate) ?? false)->isNotEmpty()
            )->count(),
            'today_running' => $vehicles->filter(fn (Vehicle $v) => $v->orders->filter(fn (Order $o) => ($o->planned_loading_at?->isSameDay($trackingDate) ?? false) &&
                    in_array($this->orderStatusValue($o), $activeStatuses, true)
            )->isNotEmpty()
            )->count(),
            'today_planned' => $vehicles->filter(function (Vehicle $v) use ($trackingDate) {
                $s = [OrderStatus::Draft->value, OrderStatus::Assigned->value, OrderStatus::Sent->value];

                return $v->orders->filter(fn (Order $o) => ($o->planned_loading_at?->isSameDay($trackingDate) ?? false) &&
                    in_array($this->orderStatusValue($o), $s, true)
                )->isNotEmpty();
            })->count(),
            'today_completed' => $vehicles->filter(function (Vehicle $v) use ($trackingDate) {
                $s = [OrderStatus::Delivered->value, OrderStatus::Completed->value];

                return $v->orders->filter(fn (Order $o) => ($o->planned_loading_at?->isSameDay($trackingDate) ?? false) &&
                    in_array($this->orderStatusValue($o), $s, true)
                )->isNotEmpty();
            })->count(),
            'today_idle' => $vehicles->filter(fn (Vehicle $v) => $v->orders->filter(fn (Order $o) => $o->planned_loading_at?->isSameDay($trackingDate) ?? false)->isEmpty()
            )->count(),
        ];
    }

    public function getTrackingDateLabel(): string
    {
        return $this->trackingDate()->format('d/m/Y');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function trackingDate(): CarbonInterface
    {
        if ($this->cachedTrackingDate !== null) {
            return $this->cachedTrackingDate;
        }

        if (Order::whereDate('planned_loading_at', '=', today(), 'and')
            ->whereNotNull('vehicle_id', 'and')->exists()) {
            return $this->cachedTrackingDate = today();
        }

        $latest = Order::query()
            ->whereNotNull('planned_loading_at', 'and')
            ->whereNotNull('vehicle_id', 'and')
            ->max('planned_loading_at');

        return $this->cachedTrackingDate = $latest !== null
            ? Carbon::parse($latest)->startOfDay()
            : today();
    }

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

    private function orderStatusValue(Order $order): string
    {
        return $order->status?->value ?? (string) $order->status;
    }

    /**
     * Màu tuyến đường theo trạng thái đơn:
     *   xanh lá  = hoàn thành
     *   xanh dương = đang chạy (đúng giờ)
     *   đỏ       = đang chạy nhưng quá giờ
     *   xám      = kế hoạch chưa xuất phát
     *
     * @param  array<int, string>  $activeStatuses
     */
    private function routeColorForOrder(?Order $order, array $activeStatuses): string
    {
        if ($order === null) {
            return '#9ca3af';
        }

        $status = $this->orderStatusValue($order);

        if (in_array($status, [OrderStatus::Delivered->value, OrderStatus::Completed->value], true)) {
            return '#22c55e'; // xanh lá — hoàn thành
        }

        if (in_array($status, $activeStatuses, true)) {
            return $order->planned_loading_at?->isPast()
                ? '#ef4444'  // đỏ — quá giờ
                : '#3b82f6'; // xanh dương — đang chạy
        }

        return '#9ca3af'; // xám — kế hoạch
    }

    /**
     * Các GPS checkpoint của đơn hàng — dùng để vẽ route line + annotation markers.
     *
     * @return Collection<int, array{
     *   lat: float, lng: float, label: string,
     *   occurred_at: ?string, checkpoint_type: ?string,
     *   km_reading: ?float, voice_note: ?string, photo_count: int, sequence: int
     * }>
     */
    private function routePointsForOrder(?Order $order, int $vehicleId): Collection
    {
        if ($order === null) {
            return collect();
        }

        /** @var Collection<int, TripCheckpoint> $checkpoints */
        $checkpoints = $order->tripCheckpoints ?? collect();

        return $checkpoints
            ->filter(fn (TripCheckpoint $c): bool => $c->gps_lat !== null && $c->gps_lng !== null)
            ->sortBy('occurred_at')
            ->values()
            ->map(function (TripCheckpoint $checkpoint, int $index) use ($vehicleId): array {
                $coord = $this->normalizeDemoCoordinate(
                    (float) $checkpoint->gps_lat,
                    (float) $checkpoint->gps_lng,
                    $vehicleId + $index
                );

                return [
                    'lat' => $coord['lat'],
                    'lng' => $coord['lng'],
                    'label' => $checkpoint->checkpoint_type?->getLabel() ?? 'Checkpoint',
                    'occurred_at' => $this->formatDateTime($checkpoint->occurred_at),
                    'checkpoint_type' => $checkpoint->checkpoint_type?->value,
                    'km_reading' => $checkpoint->km_reading !== null
                        ? (float) $checkpoint->km_reading
                        : null,
                    'voice_note' => $checkpoint->voice_note,
                    // Thêm withCount('photos') vào eager load nếu có quan hệ photos
                    'photo_count' => $checkpoint->photos_count ?? 0,
                    'sequence' => $index + 1,
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, Order>  $todayOrders
     * @param  array<int, string>  $activeStatuses
     */
    private function todayCategoryForOrders(Collection $todayOrders, array $activeStatuses): string
    {
        if ($todayOrders->isEmpty()) {
            return 'idle_today';
        }

        if ($todayOrders->contains(fn (Order $o): bool => in_array($this->orderStatusValue($o), $activeStatuses, true))) {
            return 'running_today';
        }

        if ($todayOrders->contains(fn (Order $o): bool => in_array($this->orderStatusValue($o), [OrderStatus::Delivered->value, OrderStatus::Completed->value], true))) {
            return 'completed_today';
        }

        return 'planned_today';
    }

    /**
     * @param  Collection<int, Order>  $activeOrders
     * @return array<int, array{level: string, label: string}>
     */
    private function alertsForVehicle(
        Vehicle $vehicle,
        bool $hasGps,
        bool $hasRoute,
        Collection $activeOrders
    ): array {
        $alerts = [];

        if ($vehicle->status === VehicleStatus::Off) {
            $alerts[] = ['level' => 'danger',
                'label' => 'Xe đang tắt'.($vehicle->off_reason ? ': '.$vehicle->off_reason : '')];
        }

        if ($vehicle->status === VehicleStatus::Bdsc) {
            $alerts[] = ['level' => 'warning', 'label' => 'Xe đang bảo dưỡng sửa chữa'];
        }

        foreach ($vehicle->documents as $document) {
            $alerts[] = [
                'level' => $document->status?->value === 'expired' ? 'danger' : 'warning',
                'label' => ($document->doc_type?->getLabel() ?? 'Giấy tờ')
                    .' '.$document->status?->getLabel()
                    .' '.$this->formatDate($document->expiry_date),
            ];
        }

        foreach ($vehicle->maintenanceJobs as $job) {
            $isOverdue = $job->status?->value === 'overdue' || ($job->planned_date?->isPast() ?? false);
            $alerts[] = [
                'level' => $isOverdue ? 'danger' : 'warning',
                'label' => $job->title.' - '.$job->status?->getLabel().' '.$this->formatDate($job->planned_date),
            ];
        }

        foreach ($vehicle->maintenanceSchedules as $schedule) {
            $alerts[] = [
                'level' => $schedule->alert_status === 'overdue' ? 'danger' : 'warning',
                'label' => $schedule->name.' - '.$this->maintenanceAlertLabel($schedule->alert_status),
            ];
        }

        if (! $hasGps) {
            $alerts[] = ['level' => 'warning', 'label' => 'Chưa có dữ liệu GPS thực tế'];
        }

        if ($activeOrders->isNotEmpty() && ! $hasRoute) {
            $alerts[] = ['level' => 'warning', 'label' => 'Chưa đủ GPS checkpoint để vẽ đường đi'];
        }

        return $alerts;
    }

    /**
     * @param  Collection<int, Order>  $activeOrders
     * @return array{value: string, label: string}
     */
    private function trackingStatusForVehicle(Vehicle $vehicle, Collection $activeOrders): array
    {
        if ($vehicle->status === VehicleStatus::Off) {
            return ['value' => VehicleStatus::Off->value, 'label' => VehicleStatus::Off->getLabel()];
        }

        if ($vehicle->status === VehicleStatus::Bdsc) {
            return ['value' => VehicleStatus::Bdsc->value, 'label' => VehicleStatus::Bdsc->getLabel()];
        }

        if ($activeOrders->isNotEmpty()) {
            return ['value' => VehicleStatus::Running->value, 'label' => VehicleStatus::Running->getLabel()];
        }

        return [
            'value' => $vehicle->status?->value ?? VehicleStatus::On->value,
            'label' => $vehicle->getStatusLabel(),
        ];
    }

    /** @return array{lat: float, lng: float} */
    private function fallbackPositionForVehicle(Vehicle $vehicle): array
    {
        $point = self::NORTHERN_DEMO_POINTS[$vehicle->id % count(self::NORTHERN_DEMO_POINTS)];
        $offset = (($vehicle->id % 7) - 3) / 10000;

        return ['lat' => $point['lat'] + $offset, 'lng' => $point['lng'] - $offset];
    }

    /** @return array{lat: float, lng: float} */
    private function normalizeDemoCoordinate(float $lat, float $lng, int $seed): array
    {
        if (! $this->isHoChiMinhDemoCoordinate($lat, $lng)) {
            return ['lat' => $lat, 'lng' => $lng];
        }

        $anchor = self::NORTHERN_DEMO_POINTS[$seed % count(self::NORTHERN_DEMO_POINTS)];
        $latDelta = ($lat - 10.8231) * 0.22;
        $lngDelta = ($lng - 106.6297) * 0.22;

        return ['lat' => $anchor['lat'] + $latDelta, 'lng' => $anchor['lng'] + $lngDelta];
    }

    private function isHoChiMinhDemoCoordinate(float $lat, float $lng): bool
    {
        return $lat >= 10.0 && $lat <= 11.5 && $lng >= 106.0 && $lng <= 107.5;
    }

    private function formatDateTime(?CarbonInterface $dateTime): ?string
    {
        return $dateTime?->format('H:i d/m/Y');
    }

    private function formatDate(?CarbonInterface $date): string
    {
        return $date?->format('d/m/Y') ?? '';
    }

    private function maintenanceAlertLabel(?string $alertStatus): string
    {
        return match ($alertStatus) {
            'warning' => 'Sắp đến hạn',
            'due' => 'Đến hạn',
            'overdue' => 'Quá hạn',
            default => 'Cần kiểm tra',
        };
    }
}
