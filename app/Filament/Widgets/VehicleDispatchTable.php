<?php

namespace App\Filament\Widgets;

use App\Enums\TripStatus;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Filament\Traits\HasVehicleDispatchRows;
use App\Models\Vehicle;
use Filament\Widgets\Concerns\HasJsRenderer;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Vite;
use Livewire\Attributes\Reactive;

/**
 * Bảng "Kiểm soát tình trạng xe" do React vẽ. Page giữ bộ lọc (URL), modal chi tiết và action
 * Filament (xử lý báo sai Km / đảo lái); widget chỉ vẽ bảng và gọi lại các method đó.
 */
class VehicleDispatchTable extends Widget
{
    use HasJsRenderer;
    use HasVehicleDispatchRows;

    protected int|string|array $columnSpan = 'full';

    #[Reactive]
    public ?string $searchQuery = null;

    #[Reactive]
    public string $sortBy = 'status';

    #[Reactive]
    public string $sortDirection = 'desc';

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
        $groups = collect($this->getGroupedVehicles())
            ->map(fn (array $group): array => [
                'code' => $group['code'],
                'title' => $group['title'],
                'count' => $group['vehicles']->count(),
                'rows' => $group['vehicles']->map(fn (Vehicle $vehicle): array => $this->rowPayload($vehicle))->all(),
            ])
            ->values()
            ->all();

        return [
            'title' => 'KIỂM SOÁT TÌNH TRẠNG XE 🚛',
            'columns' => [
                'Địa điểm', 'HT', 'BSX', 'Phân loại', 'Loại đơn', 'Trạng thái', 'Điểm đi', 'Điểm đến',
                'Xe chờ', 'Vị trí thực tế trên GPS', 'Tốc độ', 'Status', 'Chuyến', 'Đơn chờ', 'KM over',
                'Tên lái xe', 'Trực', 'Ca',
            ],
            'groups' => $groups,
            'filters' => ['search' => (string) $this->searchQuery],
            'sort' => ['by' => $this->sortBy, 'direction' => $this->sortDirection],
            'refreshSeconds' => VehicleDispatchBoard::REFRESH_SECONDS,
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
            }
        }

        return [
            'id' => $vehicle->id,
            'bsx' => VehicleDispatchBoard::renderBsx($vehicle),
            'ownerCategory' => VehicleDispatchBoard::getOwnerCategory($vehicle),
            'orderType' => $orderType?->getLabel(),
            'orderTypeColor' => $orderType?->getColor(),
            'statusLabel' => VehicleDispatchBoard::getTripStatusLabel($vehicle),
            'statusClass' => VehicleDispatchBoard::getTripStatusBadgeClass($vehicle),
            'statusAction' => $statusAction,
            'pickup' => VehicleDispatchBoard::getPickupPoint($vehicle),
            'pickupTitle' => VehicleDispatchBoard::getPickupPointFullName($vehicle),
            'destination' => VehicleDispatchBoard::getDeliveryPoint($vehicle),
            'destinationTitle' => VehicleDispatchBoard::getDeliveryPointFullName($vehicle),
            'xeCho' => $xeCho['text'],
            'xeChoTone' => match (true) {
                $xeCho['isAlert'] => 'danger',
                $xeCho['isWarning'] => 'warning',
                $xeCho['text'] === 'Sẵn sàng' => 'success',
                $xeCho['text'] === '—' => 'muted',
                default => 'normal',
            },
            'xeChoTooltip' => $xeCho['tooltip'],
            'gps' => VehicleDispatchBoard::getGpsLocation($vehicle),
            'speed' => VehicleDispatchBoard::getSpeed($vehicle),
            'speedClass' => VehicleDispatchBoard::getSpeedAlertClass($vehicle),
            'movement' => VehicleDispatchBoard::getMovementStatusText($vehicle),
            'movementClass' => VehicleDispatchBoard::getMovementStatusCellClass($vehicle),
            'movementTooltip' => VehicleDispatchBoard::getMovementStatusTooltip($vehicle),
            'tripCount' => VehicleDispatchBoard::getTodayTripsCount($vehicle),
            'tripId' => $activeTrip?->id,
            'waitingOrders' => VehicleDispatchBoard::getWaitingOrdersCount($vehicle),
            'kmOver' => $kmOver['text'],
            'kmOverClass' => $kmOver['cellClass'],
            'kmOverTooltip' => $kmOver['tooltip'],
            'kmOverFill' => $kmOver['fillPercent'],
            'driverName' => VehicleDispatchBoard::getDriverName($vehicle),
            'station' => $station,
            'shift' => VehicleDispatchBoard::getShiftLabel($vehicle),
        ];
    }
}
