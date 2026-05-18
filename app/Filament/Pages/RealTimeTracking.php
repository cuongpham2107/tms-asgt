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
        ['lat' => 21.0285, 'lng' => 105.8542], // Hà Nội
        ['lat' => 21.2142, 'lng' => 105.8027], // Nội Bài
        ['lat' => 21.1861, 'lng' => 106.0763], // Bắc Ninh
        ['lat' => 21.5942, 'lng' => 105.8482], // Thái Nguyên
        ['lat' => 21.1167, 'lng' => 105.9583], // Đông Anh / Sóc Sơn
        ['lat' => 21.3019, 'lng' => 105.8995], // Phổ Yên
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
                'driverShifts' => fn ($query) => $query
                    ->with('driver')
                    ->whereNull('end_time')
                    ->latest('start_time'),
                'documents' => fn ($query) => $query
                    ->whereIn('status', ['expiring_soon', 'expired'])
                    ->orderBy('expiry_date'),
                'maintenanceJobs' => fn ($query) => $query
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->where(fn ($query) => $query
                        ->where('status', 'overdue')
                        ->orWhereDate('planned_date', '<=', today()->addDays(3)))
                    ->orderBy('planned_date'),
                'maintenanceSchedules' => fn ($query) => $query
                    ->where('is_active', true)
                    ->whereIn('alert_status', ['warning', 'due', 'overdue']),
                'orders' => fn ($query) => $query
                    ->with([
                        'customer',
                        'deliveryPoints.location',
                        'driver',
                        'orderCategory',
                        'pickupLocation',
                        'tripCheckpoints' => fn ($query) => $query->orderBy('occurred_at'),
                    ])
                    ->where(fn (Builder $query): Builder => $query
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
            $activeOrders = $allOrders->filter(fn (Order $order): bool => in_array($this->orderStatusValue($order), $activeStatuses, true))
                ->sortByDesc('planned_loading_at');
            $todayOrders = $allOrders->filter(fn (Order $order): bool => $order->planned_loading_at?->isSameDay($trackingDate) ?? false)
                ->sortByDesc('planned_loading_at');

            $selectedOrders = $activeOrders->isNotEmpty()
                ? $activeOrders->take(3)
                : $todayOrders->take(3);

            $trackingOrder = $activeOrders->first() ?? $todayOrders->first();
            $routePoints = $this->routePointsForOrder($trackingOrder, $vehicle->id);
            $latestPoint = $routePoints->last();
            $hasShiftGps = $latestShift?->start_gps_lat !== null && $latestShift?->start_gps_lng !== null;
            $shiftPosition = $hasShiftGps
                ? $this->normalizeDemoCoordinate((float) $latestShift->start_gps_lat, (float) $latestShift->start_gps_lng, $vehicle->id)
                : null;
            $fallbackPosition = $this->fallbackPositionForVehicle($vehicle);
            $lat = $latestPoint['lat'] ?? ($shiftPosition['lat'] ?? $fallbackPosition['lat']);
            $lng = $latestPoint['lng'] ?? ($shiftPosition['lng'] ?? $fallbackPosition['lng']);
            $trackingStatus = $this->trackingStatusForVehicle($vehicle, $activeOrders);
            $trackingDriver = $trackingOrder?->driver?->name
                ?? $latestShift?->driver?->name
                ?? $vehicle->driver?->name
                ?? 'Không lái';

            $orders = $selectedOrders->map(function (Order $order): array {
                $firstDelivery = $order->deliveryPoints?->sortBy('sequence')->first();

                /** @var Collection<int, TripCheckpoint> $checkpoints */
                $checkpoints = $order->tripCheckpoints ?? collect();
                $latestCheckpoint = $checkpoints->sortByDesc('occurred_at')->first();

                return [
                    'id' => $order->id,
                    'order_code' => $order->order_code,
                    'status' => $this->orderStatusValue($order),
                    'status_label' => $order->status?->getLabel() ?? $this->orderStatusValue($order),
                    'pickup' => $order->pickup_address ?? $order->pickupLocation?->name ?? null,
                    'delivery' => $firstDelivery?->address ?? $firstDelivery?->location?->name ?? null,
                    'customer' => $order->customer?->name ?? null,
                    'planned_loading_at' => $this->formatDateTime($order->planned_loading_at),
                    'latest_checkpoint' => $latestCheckpoint?->checkpoint_type?->getLabel(),
                    'latest_checkpoint_at' => $this->formatDateTime($latestCheckpoint?->occurred_at),
                    'total_packages' => $order->total_packages,
                    'total_weight' => $order->total_weight,
                ];
            })->values()->toArray();

            $alerts = $this->alertsForVehicle(
                vehicle: $vehicle,
                hasGps: $latestPoint !== null || $hasShiftGps,
                hasRoute: $routePoints->count() >= 2,
                activeOrders: $activeOrders,
            );
            dd([
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
                'position_source' => $latestPoint !== null ? 'GPS checkpoint' : ($hasShiftGps ? 'GPS vào ca' : 'Vị trí mô phỏng miền Bắc'),
                'today_category' => $this->todayCategoryForOrders($todayOrders, $activeStatuses),
                'today_order_count' => $todayOrders->count(),
                'route' => $routePoints->values()->toArray(),
                'route_order_code' => $trackingOrder?->order_code,
                'route_start' => $routePoints->first()['label'] ?? null,
                'route_end' => $routePoints->last()['label'] ?? null,
                'has_alerts' => count($alerts) > 0,
                'alerts' => $alerts,
                'orders' => $orders,
            ]);

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
                'position_source' => $latestPoint !== null ? 'GPS checkpoint' : ($hasShiftGps ? 'GPS vào ca' : 'Vị trí mô phỏng miền Bắc'),
                'today_category' => $this->todayCategoryForOrders($todayOrders, $activeStatuses),
                'today_order_count' => $todayOrders->count(),
                'route' => $routePoints->values()->toArray(),
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
            'running' => $vehicles->filter(function (Vehicle $v) use ($activeStatuses) {
                return $v->status === VehicleStatus::Running || $v->orders->whereIn('status', $activeStatuses)->isNotEmpty();
            })->count(),
            'on' => $vehicles->filter(function (Vehicle $v) use ($activeStatuses) {
                return $v->status === VehicleStatus::On && $v->orders->whereIn('status', $activeStatuses)->isEmpty();
            })->count(),
            'bdsc' => $vehicles->filter(fn (Vehicle $v) => $v->status === VehicleStatus::Bdsc)->count(),
            'off' => $vehicles->filter(fn (Vehicle $v) => $v->status === VehicleStatus::Off)->count(),
            'alerts' => $vehicles->filter(function (Vehicle $v) {
                return in_array($v->status?->value ?? $v->status, [VehicleStatus::Off->value, VehicleStatus::Bdsc->value])
                    || $v->documents->isNotEmpty()
                    || $v->maintenanceJobs->isNotEmpty()
                    || $v->maintenanceSchedules->isNotEmpty();
            })->count(),
            'today_total' => $vehicles->filter(function (Vehicle $v) use ($trackingDate) {
                return $v->orders->filter(fn (Order $o) => $o->planned_loading_at?->isSameDay($trackingDate) ?? false)->isNotEmpty();
            })->count(),
            'today_running' => $vehicles->filter(function (Vehicle $v) use ($trackingDate, $activeStatuses) {
                return $v->orders->filter(fn (Order $o) => ($o->planned_loading_at?->isSameDay($trackingDate) ?? false) && in_array($this->orderStatusValue($o), $activeStatuses, true))->isNotEmpty();
            })->count(),
            'today_planned' => $vehicles->filter(function (Vehicle $v) use ($trackingDate) {
                $plannedStatuses = [OrderStatus::Draft->value, OrderStatus::Assigned->value, OrderStatus::Sent->value];

                return $v->orders->filter(fn (Order $o) => ($o->planned_loading_at?->isSameDay($trackingDate) ?? false) && in_array($this->orderStatusValue($o), $plannedStatuses, true))->isNotEmpty();
            })->count(),
            'today_completed' => $vehicles->filter(function (Vehicle $v) use ($trackingDate) {
                $completedStatuses = [OrderStatus::Delivered->value, OrderStatus::Completed->value];

                return $v->orders->filter(fn (Order $o) => ($o->planned_loading_at?->isSameDay($trackingDate) ?? false) && in_array($this->orderStatusValue($o), $completedStatuses, true))->isNotEmpty();
            })->count(),
            'today_idle' => $vehicles->filter(function (Vehicle $v) use ($trackingDate) {
                return $v->orders->filter(fn (Order $o) => $o->planned_loading_at?->isSameDay($trackingDate) ?? false)->isEmpty();
            })->count(),
        ];
    }

    public function getTrackingDateLabel(): string
    {
        return $this->trackingDate()->format('d/m/Y');
    }

    private function trackingDate(): CarbonInterface
    {
        if ($this->cachedTrackingDate !== null) {
            return $this->cachedTrackingDate;
        }

        if (Order::whereDate('planned_loading_at', '=', today(), 'and')->whereNotNull('vehicle_id', 'and')->exists()) {
            return $this->cachedTrackingDate = today();
        }

        $latestPlannedAt = Order::query()
            ->whereNotNull('planned_loading_at', 'and')
            ->whereNotNull('vehicle_id', 'and')
            ->max('planned_loading_at');

        return $this->cachedTrackingDate = $latestPlannedAt !== null
            ? Carbon::parse($latestPlannedAt)->startOfDay()
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
     * @return Collection<int, array{lat: float, lng: float, label: string, occurred_at: ?string, checkpoint_type: ?string}>
     */
    private function routePointsForOrder(?Order $order, int $vehicleId): Collection
    {
        if ($order === null) {
            return collect();
        }

        /** @var Collection<int, TripCheckpoint> $checkpoints */
        $checkpoints = $order->tripCheckpoints ?? collect();

        return $checkpoints
            ->filter(fn (TripCheckpoint $checkpoint): bool => $checkpoint->gps_lat !== null && $checkpoint->gps_lng !== null)
            ->sortBy('occurred_at')
            ->values()
            ->map(function (TripCheckpoint $checkpoint, int $index) use ($vehicleId): array {
                $coordinate = $this->normalizeDemoCoordinate((float) $checkpoint->gps_lat, (float) $checkpoint->gps_lng, $vehicleId + $index);

                return [
                    'lat' => $coordinate['lat'],
                    'lng' => $coordinate['lng'],
                    'label' => $checkpoint->checkpoint_type?->getLabel() ?? 'Checkpoint',
                    'occurred_at' => $this->formatDateTime($checkpoint->occurred_at),
                    'checkpoint_type' => $checkpoint->checkpoint_type?->value,
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

        if ($todayOrders->contains(fn (Order $order): bool => in_array($this->orderStatusValue($order), $activeStatuses, true))) {
            return 'running_today';
        }

        if ($todayOrders->contains(fn (Order $order): bool => in_array($this->orderStatusValue($order), [OrderStatus::Delivered->value, OrderStatus::Completed->value], true))) {
            return 'completed_today';
        }

        return 'planned_today';
    }

    /**
     * @param  Collection<int, Order>  $activeOrders
     * @return array<int, array{level: string, label: string}>
     */
    private function alertsForVehicle(Vehicle $vehicle, bool $hasGps, bool $hasRoute, Collection $activeOrders): array
    {
        $alerts = [];

        if ($vehicle->status === VehicleStatus::Off) {
            $alerts[] = ['level' => 'danger', 'label' => 'Xe đang tắt'.($vehicle->off_reason ? ': '.$vehicle->off_reason : '')];
        }

        if ($vehicle->status === VehicleStatus::Bdsc) {
            $alerts[] = ['level' => 'warning', 'label' => 'Xe đang bảo dưỡng sửa chữa'];
        }

        foreach ($vehicle->documents as $document) {
            $alerts[] = [
                'level' => $document->status?->value === 'expired' ? 'danger' : 'warning',
                'label' => ($document->doc_type?->getLabel() ?? 'Giấy tờ').' '.$document->status?->getLabel().' '.$this->formatDate($document->expiry_date),
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

        if ($activeOrders->isNotEmpty() && (! $hasRoute)) {
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

        return [
            'lat' => $point['lat'] + $offset,
            'lng' => $point['lng'] - $offset,
        ];
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

        return [
            'lat' => $anchor['lat'] + $latDelta,
            'lng' => $anchor['lng'] + $lngDelta,
        ];
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
