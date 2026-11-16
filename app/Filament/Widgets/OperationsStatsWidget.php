<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Filament\Resources\DriverShifts\DriverShiftResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Trips\TripResource;
use App\Models\DriverShift;
use App\Models\Order;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ShiftScheduleService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OperationsStatsWidget extends BaseWidget
{
    use HasWidgetShield;

    protected ?string $heading = 'Chỉ số vận hành tổng quan';

    protected int|string|array $columnSpan = 'full';

    protected int|array|null $columns = [
        'default' => 1,
        'sm' => 2,
        'md' => 3,
        'xl' => 3,
        '2xl' => 6,
    ];

    protected function getStats(): array
    {
        [$shiftStart, $shiftEnd] = ShiftScheduleService::getShiftWindow();

        // 1. Xe công ty sẵn sàng
        $companyStats = Vehicle::where('is_active', true)
            ->where('type', VehicleOwnerType::Company)
            ->selectRaw(
                'COUNT(*) as total_count, '
                .'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as on_count',
                [VehicleStatus::On->value]
            )
            ->first();
        $companyOn = (int) ($companyStats->on_count ?? 0);
        $companyTotal = (int) ($companyStats->total_count ?? 0);
        $availPercent = $companyTotal > 0 ? round(($companyOn / $companyTotal) * 100) : 0;

        // 2. Xe thuê làm việc (trong khung ca 08:00 -> 08:00)
        $rentWorkingToday = Vehicle::where('is_active', true)
            ->where('type', VehicleOwnerType::Rent)
            ->where(function ($query) use ($shiftStart, $shiftEnd) {
                $query->whereHas('trips', function ($q) use ($shiftStart, $shiftEnd) {
                    $q->whereBetween('started_at', [$shiftStart, $shiftEnd])
                        ->orWhereBetween('completed_at', [$shiftStart, $shiftEnd])
                        ->orWhereBetween('created_at', [$shiftStart, $shiftEnd])
                        ->orWhereHas('orders', fn ($oq) => $oq->whereBetween('planned_loading_at', [$shiftStart, $shiftEnd]));
                })
                    ->orWhereHas('driver.driverShifts', fn ($q) => $q->whereNull('end_time')->whereBetween('start_time', [$shiftStart, $shiftEnd]))
                    ->orWhereHas('trips.shift', fn ($q) => $q->whereNull('end_time')->whereBetween('start_time', [$shiftStart, $shiftEnd]));
            })
            ->count();

        // 3. Chuyến xe hôm nay (trong khung ca 08:00 -> 08:00)
        $tripsToday = Trip::where(function ($q) use ($shiftStart, $shiftEnd) {
            $q->whereBetween('started_at', [$shiftStart, $shiftEnd])
                ->orWhereBetween('completed_at', [$shiftStart, $shiftEnd])
                ->orWhere(fn ($sq) => $sq->whereNull('started_at')->whereBetween('created_at', [$shiftStart, $shiftEnd]));
        })->count();

        $tripsRunning = Trip::whereIn('status', [
            TripStatus::Started->value,
            TripStatus::ArrivedPickup->value,
            TripStatus::Delivering->value,
            TripStatus::ArrivedDelivery->value,
            TripStatus::Delivered->value,
            TripStatus::DriverSwap->value,
            TripStatus::ReturnTrip->value,
        ])->count();

        // 4. Đơn hàng đang vận chuyển (dùng đúng OrderStatus)
        $orderStats = Order::query()
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status IN (?, ?, ?, ?) THEN 1 ELSE 0 END), 0) as in_transit_count, '
                .'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as draft_count',
                [
                    OrderStatus::Assigned->value,
                    OrderStatus::Sent->value,
                    OrderStatus::InTransit->value,
                    OrderStatus::DriverSwap->value,
                    OrderStatus::Draft->value,
                ]
            )
            ->first();
        $inTransitOrders = (int) ($orderStats->in_transit_count ?? 0);
        $draftOrders = (int) ($orderStats->draft_count ?? 0);

        // 5. Đơn hàng hoàn thành hôm nay (trong khung ca 08:00 -> 08:00)
        $completedToday = Order::where('status', OrderStatus::Completed->value)
            ->where(function ($q) use ($shiftStart, $shiftEnd) {
                $q->whereHas('trip', fn ($tq) => $tq->whereBetween('completed_at', [$shiftStart, $shiftEnd]))
                    ->orWhereBetween('planned_loading_at', [$shiftStart, $shiftEnd])
                    ->orWhereBetween('updated_at', [$shiftStart, $shiftEnd]);
            })
            ->count();

        // 6. Tài xế trong ca
        $activeDrivers = DriverShift::whereNull('end_time')->count();
        $totalDrivers = User::role('driver')->where('is_active', true)->count();

        [$tripSeries, $completedSeries] = $this->sevenDaySeries($shiftStart);

        $dispatchUrl = VehicleDispatchBoard::canAccess() ? VehicleDispatchBoard::getUrl() : null;
        $ordersUrl = OrderResource::canViewAny() ? OrderResource::getUrl('index') : null;
        $tripsUrl = TripResource::canViewAny() ? TripResource::getUrl('index') : null;
        $shiftsUrl = DriverShiftResource::canViewAny() ? DriverShiftResource::getUrl('index') : null;

        return [
            Stat::make('Xe công ty sẵn sàng', "{$companyOn} / {$companyTotal}")
                ->description("{$availPercent}% đội xe ON sẵn sàng")
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('info')
                ->url($dispatchUrl),

            Stat::make('Xe thuê làm việc', $rentWorkingToday)
                ->description('Xe thuê điều phối hôm nay')
                ->descriptionIcon('heroicon-m-truck')
                ->color('warning')
                ->url($dispatchUrl),

            Stat::make('Chuyến xe hôm nay', $tripsToday)
                ->description("{$tripsRunning} chuyến đang chạy")
                ->descriptionIcon('heroicon-m-map-pin')
                ->color('primary')
                ->chart($tripSeries)
                ->url($tripsUrl),

            Stat::make('Đơn hàng đang xử lý', $inTransitOrders)
                ->description("{$draftOrders} đơn Nháp chờ gán xe")
                ->descriptionIcon('heroicon-m-cube')
                ->color('info')
                ->url($ordersUrl),

            Stat::make('Hoàn thành hôm nay', $completedToday)
                ->description('Đơn giao thành công')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->chart($completedSeries)
                ->url($ordersUrl),

            Stat::make('Tài xế trong ca', "{$activeDrivers} / {$totalDrivers}")
                ->description('Tài xế đang trực ca')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('success')
                ->url($shiftsUrl),
        ];
    }

    /**
     * Tính chuỗi số liệu thật 7 ngày gần nhất theo khung ca 08:00 -> 08:00.
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    private function sevenDaySeries(Carbon $currentShiftStart): array
    {
        $windowStart = $currentShiftStart->copy()->subDays(6);
        $windowEnd = $currentShiftStart->copy()->addDay();

        $trips = Trip::query()
            ->where(function ($q) use ($windowStart, $windowEnd) {
                $q->whereBetween('started_at', [$windowStart, $windowEnd])
                    ->orWhereBetween('completed_at', [$windowStart, $windowEnd])
                    ->orWhere(fn ($sq) => $sq->whereNull('started_at')->whereBetween('created_at', [$windowStart, $windowEnd]));
            })
            ->get(['started_at', 'completed_at', 'created_at']);

        $completedOrders = Order::query()
            ->where('status', OrderStatus::Completed->value)
            ->whereBetween('planned_loading_at', [$windowStart, $windowEnd])
            ->get(['planned_loading_at']);

        $tripSeries = [];
        $completedSeries = [];

        for ($i = 6; $i >= 0; $i--) {
            $dayStart = $currentShiftStart->copy()->subDays($i);
            $dayEnd = $dayStart->copy()->addDay();

            $tripSeries[] = $trips->filter(function (Trip $trip) use ($dayStart, $dayEnd): bool {
                $anchor = $trip->started_at ?? $trip->completed_at ?? $trip->created_at;

                return $anchor !== null && $anchor->gte($dayStart) && $anchor->lt($dayEnd);
            })->count();

            $completedSeries[] = $completedOrders->filter(
                fn (Order $order): bool => $order->planned_loading_at !== null
                    && $order->planned_loading_at->gte($dayStart)
                    && $order->planned_loading_at->lt($dayEnd)
            )->count();
        }

        return [$tripSeries, $completedSeries];
    }
}
