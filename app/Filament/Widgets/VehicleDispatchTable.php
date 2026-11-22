<?php

namespace App\Filament\Widgets;

use App\Enums\TripStatus;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Filament\Resources\Trips\TripResource;
use App\Filament\Traits\HasVehicleDispatchRows;
use App\Models\Order;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\Shift\ShiftScheduleService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\Concerns\HasJsRenderer;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Vite;
use Livewire\Attributes\Reactive;
use Throwable;

/**
 * Bảng "Kiểm soát tình trạng xe" do React vẽ. Page giữ bộ lọc (URL), modal chi tiết và action
 * Filament (xử lý báo sai Km / đảo lái); widget chỉ vẽ bảng và gọi lại các method đó.
 */
class VehicleDispatchTable extends Widget
{
    use HasJsRenderer;
    use HasVehicleDispatchRows;
    use HasWidgetShield;

    /** Lần render đầu chỉ gửi khung để React vẽ skeleton; true thì kèm dữ liệu (React bật sau khi mount). */
    public bool $isReady = false;

    /** @var array<int, string> */
    private const COLUMNS = [
        'Địa điểm', 'HT', 'BSX', 'Phân loại', 'Loại đơn', 'Trạng thái', 'Điểm đi', 'Điểm đến',
        'Xe chờ', 'Khách hàng', 'Vị trí thực tế trên GPS', 'Tốc độ', 'Status', 'Chuyến', 'Đơn chờ', 'KM over',
        'Tên lái xe', 'Trực', 'Ca',
    ];

    /**
     * Cột hiển thị cho sheet Xe thuê ngoài: bỏ các cột GPS (Vị trí thực tế trên GPS, Tốc độ, Status),
     * KM over, Trực, Ca; bổ sung Thời gian dự kiến đóng trước Xe chờ / Khách hàng (14 cột).
     *
     * @var array<int, string>
     */
    private const RENT_COLUMNS = [
        'Địa điểm', 'HT', 'BSX', 'Phân loại', 'Loại đơn', 'Trạng thái', 'Điểm đi', 'Điểm đến',
        'Thời gian dự kiến đóng', 'Xe chờ', 'Khách hàng', 'Chuyến', 'Đơn chờ', 'Tên lái xe',
    ];

    protected ?string $heading = 'Bảng kiểm soát tình trạng xe';

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        return $this->heading;
    }

    #[Reactive]
    public ?string $searchQuery = null;

    #[Reactive]
    public string $sortBy = 'status';

    #[Reactive]
    public string $sortDirection = 'desc';

    #[Reactive]
    public string $activeOwnerType = 'company';

    #[Reactive]
    public string $activeStation = 'all';

    #[Reactive]
    public string $activeMovementStatus = 'all';

    /** Page tăng số này sau mỗi lần dữ liệu đổi (Echo/refresh) để bảng vẽ lại. */
    #[Reactive]
    public int $dataVersion = 0;

    public function getRenderer(): string
    {
        return Vite::asset('resources/js/filament/widgets/vehicle-dispatch-table.tsx');
    }

    /**
     * @return array<string, mixed>
     */
    public function getRendererConfiguration(): array
    {
        $isRent = $this->activeOwnerType === 'rent';

        // Chưa sẵn sàng: gửi khung (tiêu đề, cột, bộ lọc) không kèm xe để React vẽ skeleton.
        $groups = collect($this->isReady ? $this->getGroupedVehicles() : [])
            ->map(fn (array $group): array => [
                'code' => $group['code'],
                'title' => $group['title'],
                'count' => $group['vehicles']->count(),
                'rows' => $group['vehicles']->map(fn (Vehicle $vehicle): array => $this->rowPayload($vehicle))->all(),
            ])
            ->values()
            ->all();

        return [
            'loaded' => $this->isReady,
            'ownerType' => $isRent ? 'rent' : 'company',
            'title' => $isRent ? 'KIỂM SOÁT XE THUÊ NGOÀI 🚛' : 'KIỂM SOÁT TÌNH TRẠNG XE 🚛',
            'columns' => $isRent ? self::RENT_COLUMNS : self::COLUMNS,
            'groups' => $groups,
            'filters' => ['search' => (string) $this->searchQuery],
            'sort' => ['by' => $this->sortBy, 'direction' => $this->sortDirection],
            'refreshSeconds' => VehicleDispatchBoard::REFRESH_SECONDS,
            'shiftDate' => ShiftScheduleService::getShiftDate()->format('d/m/Y'),
            'totalVehicles' => collect($groups)->sum('count'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowPayload(Vehicle $vehicle): array
    {
        $activeTrip = $vehicle->active_trip;
        $orderType = VehicleDispatchBoard::getOrderType($vehicle);
        $xeCho = VehicleDispatchBoard::getXeChoData($vehicle);
        $kmOver = VehicleDispatchBoard::getKmOverData($vehicle);
        $station = VehicleDispatchBoard::getDriverStation($vehicle);

        $statusAction = null;

        if ($activeTrip !== null) {
            if ($activeTrip->isPaused()) {
                $statusAction = ['name' => 'resolve_km_report', 'tooltip' => 'Bấm để xử lý báo sai Km'];
            } elseif ($activeTrip->status === TripStatus::DriverSwap) {
                $statusAction = ['name' => 'reassign_driver', 'tooltip' => 'Bấm để gán lại tài xế (Đảo lái)'];
            } else {
                $statusAction = ['name' => 'edit_trip', 'tooltip' => 'Bấm để sửa chuyến — '.$activeTrip->trip_code];
            }
        }

        return [
            'id' => $vehicle->id,
            'bsx' => VehicleDispatchBoard::renderBsx($vehicle),
            'plateNumber' => (string) $vehicle->plate_number,
            'loadCapacity' => $vehicle->load_capacity ? round((float) $vehicle->load_capacity, 1).'T' : null,
            'ownerCategory' => VehicleDispatchBoard::getOwnerCategory($vehicle),
            'orderType' => $orderType?->getLabel(),
            'orderTypeColor' => $orderType?->getColor(),
            'orderTypePriority' => VehicleDispatchBoard::getOrderTypeSortPriority($vehicle),
            'statusLabel' => VehicleDispatchBoard::getTripStatusLabel($vehicle),
            'statusPriority' => VehicleDispatchBoard::getTripStatusSortPriority($vehicle),
            'statusClass' => VehicleDispatchBoard::getTripStatusBadgeClass($vehicle),
            'statusAction' => $statusAction,
            'pickup' => VehicleDispatchBoard::getPickupPoint($vehicle),
            'pickupTitle' => VehicleDispatchBoard::getPickupPointFullName($vehicle),
            'destination' => VehicleDispatchBoard::getDeliveryPoint($vehicle),
            'destinationTitle' => VehicleDispatchBoard::getDeliveryPointFullName($vehicle),
            'plannedLoadingAt' => VehicleDispatchBoard::getPlannedLoadingAt($vehicle),
            'plannedLoadingAtTitle' => VehicleDispatchBoard::getPlannedLoadingAtTitle($vehicle),
            'plannedLoadingTs' => VehicleDispatchBoard::getPlannedLoadingTimestamp($vehicle),
            'xeCho' => $xeCho['text'],
            'xeChoMinutes' => $xeCho['text'] === '—' ? null : (int) $xeCho['minutes'],
            'xeChoTone' => match (true) {
                $xeCho['isAlert'] => 'danger',
                $xeCho['isWarning'] => 'warning',
                $xeCho['text'] === 'Sẵn sàng' => 'success',
                $xeCho['text'] === '—' => 'muted',
                default => 'normal',
            },
            'xeChoTooltip' => $xeCho['tooltip'],
            'customer' => VehicleDispatchBoard::getCustomerLabel($vehicle),
            'customerTitle' => VehicleDispatchBoard::getCustomerFullName($vehicle),
            'gps' => VehicleDispatchBoard::getGpsLocation($vehicle),
            'speed' => VehicleDispatchBoard::getSpeed($vehicle),
            'speedValue' => $vehicle->gps_speed !== null ? (float) $vehicle->gps_speed : null,
            'speedClass' => VehicleDispatchBoard::getSpeedAlertClass($vehicle),
            'movement' => VehicleDispatchBoard::getMovementStatusText($vehicle),
            'movementClass' => VehicleDispatchBoard::getMovementStatusCellClass($vehicle),
            'movementTooltip' => VehicleDispatchBoard::getMovementStatusTooltip($vehicle),
            'tripCount' => VehicleDispatchBoard::getTodayTripsCount($vehicle),
            'tripId' => $activeTrip?->id,
            'waitingOrders' => VehicleDispatchBoard::getWaitingOrdersCount($vehicle),
            'kmOver' => $kmOver['text'],
            'kmOverValue' => $kmOver['delta'],
            'kmOverClass' => $kmOver['cellClass'],
            'kmOverTooltip' => $kmOver['tooltip'],
            'kmOverFill' => $kmOver['fillPercent'],
            'driverName' => VehicleDispatchBoard::getDriverName($vehicle),
            'station' => $station,
            'shift' => VehicleDispatchBoard::getShiftLabel($vehicle),
            'shiftTrips' => $this->shiftTripsPayload($vehicle),
        ];
    }

    /**
     * Danh sách chuyến trong ca trực để render dạng tree row bên dưới dòng xe.
     *
     * @return array<int, array<string, mixed>>
     */
    private function shiftTripsPayload(Vehicle $vehicle): array
    {
        $trips = $vehicle->relationLoaded('trips')
            ? VehicleDispatchBoard::sortTripsForShift($vehicle->trips)
            : collect();

        $runningStatuses = VehicleDispatchBoard::runningTripStatuses();

        return $trips->map(function (Trip $trip) use ($vehicle, $runningStatuses): array {
            $firstOrder = $trip->orders->first();
            $orderType = $firstOrder?->type;
            $pickup = $trip->startLocation?->code
                ?? $firstOrder?->pickupLocation?->code
                ?? $firstOrder?->pickup_address;

            $deliveryCodes = $trip->endLocation?->code
                ?? $trip->orders
                    ->flatMap(fn (Order $order) => $order->deliveryPoints)
                    ->map(fn ($dp) => $dp->location?->code ?? $dp->address)
                    ->filter()
                    ->unique()
                    ->join(', ');

            $plannedAt = $trip->orders
                ->pluck('planned_loading_at')
                ->filter()
                ->sort()
                ->first();

            $isActive = in_array($trip->status, $runningStatuses, true);
            $isPending = $trip->status === TripStatus::Pending;
            $isCompleted = $trip->status === TripStatus::Completed;

            $timelineUrl = null;
            try {
                $timelineUrl = TripResource::getUrl('timeline', ['record' => $trip]);
            } catch (Throwable) {
            }

            $driverName = $vehicle->isRent() && filled($trip->external_driver_name)
                ? trim((string) $trip->external_driver_name)
                : ($trip->driver?->name ?? '—');

            $orders = $trip->orders->map(function (Order $order): array {
                $status = $order->status;

                return [
                    'id' => $order->id,
                    'orderCode' => (string) $order->order_code,
                    'statusLabel' => $status?->getLabel() ?? (string) $status,
                    'statusColor' => (string) ($status?->getColor() ?? 'gray'),
                    'customerCode' => $order->customer?->code,
                    'pickup' => $order->pickupLocation?->code ?? $order->pickup_address ?? '—',
                    'destination' => $order->deliveryPoints
                        ->map(fn ($dp) => $dp->location?->code ?? $dp->address)
                        ->filter()
                        ->join(', ') ?: '—',
                ];
            })->values()->all();

            return [
                'id' => $trip->id,
                'tripCode' => (string) $trip->trip_code,
                'isEmptyRun' => (bool) $trip->is_empty_run,
                'orderType' => $orderType?->getLabel(),
                'orderTypeColor' => $orderType?->getColor(),
                'isActive' => $isActive,
                'isPending' => $isPending,
                'isCompleted' => $isCompleted,
                'statusLabel' => $trip->getStatusLabel(),
                'statusColor' => (string) ($trip->status?->getColor() ?? ($isCompleted ? 'success' : ($isActive ? 'warning' : ($isPending ? 'info' : 'gray')))),
                'driverName' => $driverName,
                'startedAt' => $trip->started_at ? $trip->started_at->format('H:i d/m') : 'Chưa bắt đầu',
                'startKm' => $trip->start_km ? number_format((float) $trip->start_km, 1) : null,
                'completedAt' => $trip->completed_at
                    ? $trip->completed_at->format('H:i d/m')
                    : ($isActive ? 'Đang chạy' : ($isPending ? 'Chờ chạy' : '—')),
                'endKm' => $trip->end_km ? number_format((float) $trip->end_km, 1) : null,
                'totalKm' => $trip->total_km ? number_format((float) $trip->total_km, 1).' km' : null,
                'totalKmValue' => $trip->total_km ? (float) $trip->total_km : 0.0,
                'pickup' => $pickup ?: null,
                'destination' => $deliveryCodes ?: null,
                'plannedLoadingAt' => $plannedAt ? $plannedAt->format('H:i d/m/Y') : '—',
                'ordersCount' => $trip->orders->count(),
                'orders' => $orders,
                'timelineUrl' => $timelineUrl,
            ];
        })->all();
    }
}
