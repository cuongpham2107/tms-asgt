<?php

namespace App\Filament\Widgets;

use App\Enums\MaintenanceJobStatus;
use App\Enums\VehicleDocumentStatus;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\VehicleDocuments\VehicleDocumentResource;
use App\Filament\Resources\VehicleMaintenanceJobs\VehicleMaintenanceJobResource;
use App\Filament\Resources\VehicleMaintenanceSchedules\VehicleMaintenanceScheduleResource;
use App\Models\User;
use App\Models\VehicleDocument;
use App\Models\VehicleMaintenanceJob;
use App\Models\VehicleMaintenanceSchedule;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FleetComplianceAlertWidget extends BaseWidget
{
    use HasWidgetShield;

    protected ?string $heading = 'Cảnh báo Đội xe, Bảo dưỡng & Hạn giấy tờ';

    protected int|string|array $columnSpan = 'full';

    protected int|array|null $columns = [
        'default' => 1,
        'sm' => 2,
        'xl' => 4,
    ];

    protected function getStats(): array
    {
        $now = now()->startOfDay();
        $soon = $now->copy()->addDays(30);
        $nowDate = $now->toDateString();
        $soonDate = $soon->toDateString();

        $drivers = User::role('driver')
            ->where('is_active', true)
            ->get(['license_expiry_date', 'aviation_security_cert_expiry_date', 'dangerous_goods_cert_expiry_date']);

        $expiredDriverCerts = 0;
        $expiringDriverCerts = 0;
        $driverAlertCount = 0;

        foreach ($drivers as $driver) {
            $dates = array_filter([
                $driver->license_expiry_date,
                $driver->aviation_security_cert_expiry_date,
                $driver->dangerous_goods_cert_expiry_date,
            ]);

            if ($dates === []) {
                continue;
            }

            $hasExpired = false;
            $hasExpiring = false;
            $hasAlert = false;

            foreach ($dates as $expiryDate) {
                $day = $expiryDate->copy()->startOfDay();
                if ($day->lt($now)) {
                    $hasExpired = true;
                }
                if ($day->betweenIncluded($now, $soon)) {
                    $hasExpiring = true;
                }
                if ($day->lte($soon)) {
                    $hasAlert = true;
                }
            }

            if ($hasExpired) {
                $expiredDriverCerts++;
            }
            if ($hasExpiring) {
                $expiringDriverCerts++;
            }
            if ($hasAlert) {
                $driverAlertCount++;
            }
        }

        $docAgg = VehicleDocument::query()
            ->selectRaw(
                'COUNT(*) as total_count, '
                .'COALESCE(SUM(CASE WHEN status = ? OR DATE(expiry_date) < ? THEN 1 ELSE 0 END), 0) as expired_count, '
                .'COALESCE(SUM(CASE WHEN status = ? OR (DATE(expiry_date) >= ? AND DATE(expiry_date) <= ?) THEN 1 ELSE 0 END), 0) as expiring_count, '
                .'COALESCE(SUM(CASE WHEN status IN (?, ?) OR DATE(expiry_date) <= ? THEN 1 ELSE 0 END), 0) as alert_count',
                [
                    VehicleDocumentStatus::Expired->value, $nowDate,
                    VehicleDocumentStatus::ExpiringSoon->value, $nowDate, $soonDate,
                    VehicleDocumentStatus::Expired->value, VehicleDocumentStatus::ExpiringSoon->value, $soonDate,
                ]
            )
            ->first();

        $docTotalCount = (int) ($docAgg->total_count ?? 0);
        $expiredDocs = (int) ($docAgg->expired_count ?? 0);
        $expiringDocs = (int) ($docAgg->expiring_count ?? 0);
        $docAlerts = (int) ($docAgg->alert_count ?? 0);

        $scheduleAgg = VehicleMaintenanceSchedule::query()
            ->selectRaw(
                'COUNT(*) as total_count, '
                .'COALESCE(SUM(CASE WHEN is_active AND alert_status IN (?, ?, ?) THEN 1 ELSE 0 END), 0) as due_count, '
                .'COALESCE(SUM(CASE WHEN is_active AND alert_status = ? THEN 1 ELSE 0 END), 0) as overdue_count',
                ['warning', 'due', 'overdue', 'overdue']
            )
            ->first();

        $scheduleTotalCount = (int) ($scheduleAgg->total_count ?? 0);
        $dueSchedules = (int) ($scheduleAgg->due_count ?? 0);
        $overdueSchedules = (int) ($scheduleAgg->overdue_count ?? 0);

        $jobAgg = VehicleMaintenanceJob::query()
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END), 0) as open_count, '
                .'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as overdue_count',
                [
                    MaintenanceJobStatus::Pending->value,
                    MaintenanceJobStatus::InProgress->value,
                    MaintenanceJobStatus::Overdue->value,
                    MaintenanceJobStatus::Overdue->value,
                ]
            )
            ->first();

        $openJobs = (int) ($jobAgg->open_count ?? 0);
        $overdueJobs = (int) ($jobAgg->overdue_count ?? 0);

        return [
            Stat::make('Bằng lái / Chứng chỉ tài xế', $driverAlertCount)
                ->description("{$expiredDriverCerts} hết hạn · {$expiringDriverCerts} sắp hết hạn ≤ 30 ngày")
                ->descriptionIcon('heroicon-m-identification')
                ->color($expiredDriverCerts > 0 ? 'danger' : ($driverAlertCount > 0 ? 'warning' : 'success'))
                ->url(UserResource::canViewAny() ? UserResource::getUrl('index') : null),

            Stat::make('Giấy tờ xe sắp/hết hạn', $docAlerts)
                ->description($docTotalCount === 0
                    ? 'Chưa có hồ sơ giấy tờ xe'
                    : "{$expiredDocs} hết hạn · {$expiringDocs} sắp hết hạn")
                ->descriptionIcon('heroicon-m-document-text')
                ->color($expiredDocs > 0 ? 'danger' : ($docAlerts > 0 ? 'warning' : 'gray'))
                ->url(VehicleDocumentResource::canViewAny() ? VehicleDocumentResource::getUrl('index') : null),

            Stat::make('Lịch bảo dưỡng đến hạn', $dueSchedules)
                ->description($scheduleTotalCount === 0
                    ? 'Chưa thiết lập lịch bảo dưỡng'
                    : "{$overdueSchedules} lịch quá hạn")
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color($overdueSchedules > 0 ? 'danger' : ($dueSchedules > 0 ? 'warning' : 'gray'))
                ->url(VehicleMaintenanceScheduleResource::canViewAny() ? VehicleMaintenanceScheduleResource::getUrl('index') : null),

            Stat::make('Công việc bảo dưỡng mở', $openJobs)
                ->description($overdueJobs > 0 ? "{$overdueJobs} công việc quá hạn" : 'Đang chờ / đang thực hiện')
                ->descriptionIcon('heroicon-m-cog-6-tooth')
                ->color($overdueJobs > 0 ? 'danger' : ($openJobs > 0 ? 'info' : 'gray'))
                ->url(VehicleMaintenanceJobResource::canViewAny() ? VehicleMaintenanceJobResource::getUrl('index') : null),
        ];
    }
}
