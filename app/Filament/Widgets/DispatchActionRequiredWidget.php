<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\OvertimeStatus;
use App\Enums\TripKmReportStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\OvertimeRegistrations\OvertimeRegistrationResource;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Order;
use App\Models\OvertimeRegistration;
use App\Models\Trip;
use App\Models\TripKmReport;
use App\Models\Vehicle;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DispatchActionRequiredWidget extends BaseWidget
{
    use HasWidgetShield;

    protected ?string $heading = 'Cảnh báo & Việc cần xử lý Điều phối';

    protected int|string|array $columnSpan = 'full';

    protected int|array|null $columns = [
        'default' => 1,
        'sm' => 2,
        'xl' => 4,
    ];

    protected function getStats(): array
    {
        $draftOrders = Order::where('status', OrderStatus::Draft->value)->count();

        $idleStats = Vehicle::query()
            ->where('is_active', true)
            ->where('type', VehicleOwnerType::Company->value)
            ->whereNotNull('idle_engine_since')
            ->selectRaw(
                'COUNT(*) as idle_any, '
                .'COALESCE(SUM(CASE WHEN idle_engine_since <= ? THEN 1 ELSE 0 END), 0) as idle_over_limit',
                [now()->subMinutes(VehicleDispatchBoard::IDLE_ENGINE_ALERT_MINUTES)]
            )
            ->first();

        $idleOverLimit = (int) ($idleStats->idle_over_limit ?? 0);
        $idleAny = (int) ($idleStats->idle_any ?? 0);

        $driverSwapTrips = Trip::where('status', TripStatus::DriverSwap->value)->count();
        $pendingKmReports = TripKmReport::where('status', TripKmReportStatus::Pending->value)->count();
        $tripAlerts = $driverSwapTrips + $pendingKmReports;

        $pendingOvertime = OvertimeRegistration::where('status', OvertimeStatus::Pending->value)->count();

        $ordersDraftUrl = OrderResource::canViewAny()
            ? OrderResource::getUrl('index', ['activeStatusFilter' => 'draft'])
            : null;
        $dispatchUrl = VehicleDispatchBoard::canAccess() ? VehicleDispatchBoard::getUrl() : null;
        $tripsAlertUrl = TripResource::canViewAny()
            ? TripResource::getUrl('index', ['activeStatusFilter' => $driverSwapTrips > 0 ? 'driver_swap' : ($pendingKmReports > 0 ? 'km_report' : 'all')])
            : null;
        $overtimeUrl = OvertimeRegistrationResource::canViewAny()
            ? OvertimeRegistrationResource::getUrl('index')
            : null;

        return [
            Stat::make('Đơn Nháp chờ gán xe', $draftOrders)
                ->description($draftOrders > 0 ? 'Cần điều phối xe và tài xế' : 'Không có đơn tồn đọng')
                ->descriptionIcon($draftOrders > 0 ? 'heroicon-m-clock' : 'heroicon-m-check-circle')
                ->color($draftOrders > 0 ? 'warning' : 'success')
                ->url($ordersDraftUrl),

            Stat::make('Xe nổ máy tại chỗ > 20\'', $idleOverLimit)
                ->description($idleAny > 0 ? "{$idleAny} xe đang dừng chưa tắt máy" : 'Không có xe dừng nổ máy')
                ->descriptionIcon($idleOverLimit > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($idleOverLimit > 0 ? 'danger' : ($idleAny > 0 ? 'warning' : 'success'))
                ->url($dispatchUrl),

            Stat::make('Đảo lái & Báo sai Km', $tripAlerts)
                ->description("{$driverSwapTrips} đảo lái · {$pendingKmReports} báo sai Km")
                ->descriptionIcon($tripAlerts > 0 ? 'heroicon-m-arrow-path' : 'heroicon-m-check-circle')
                ->color($tripAlerts > 0 ? 'danger' : 'success')
                ->url($tripsAlertUrl),

            Stat::make('Tăng cường chờ duyệt', $pendingOvertime)
                ->description($pendingOvertime > 0 ? 'Đăng ký ca tăng cường mới' : 'Không có yêu cầu chờ duyệt')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($pendingOvertime > 0 ? 'warning' : 'gray')
                ->url($overtimeUrl),
        ];
    }
}
