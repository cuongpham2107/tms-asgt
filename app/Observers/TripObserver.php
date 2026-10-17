<?php

namespace App\Observers;

use App\Enums\TripKmReportStatus;
use App\Enums\TripStatus;
use App\Filament\Resources\Trips\TripResource;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripKmReport;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class TripObserver
{
    /**
     * Handle the Trip "created" event.
     */
    public function created(Trip $trip): void
    {
        if ($trip->status === TripStatus::Delivered) {
            $this->notifyUsersTripDelivered($trip);
        }

        if ($trip->status === TripStatus::DriverSwap) {
            $this->notifyUsersDriverSwap($trip);
        }
    }

    /**
     * Handle the Trip "updated" event.
     */
    public function updated(Trip $trip): void
    {
        if ($trip->wasChanged('status')) {
            if ($trip->status === TripStatus::Delivered) {
                $this->notifyUsersTripDelivered($trip);
            }

            if ($trip->status === TripStatus::DriverSwap) {
                $this->notifyUsersDriverSwap($trip);
            }
        }
    }

    /**
     * Handle the Trip "deleting" event.
     * Khi xoá chuyến, xoá luôn tất cả các đơn hàng thuộc chuyến và dữ liệu liên quan.
     */
    public function deleting(Trip $trip): void
    {
        foreach ($trip->orders as $order) {
            OrderDeliveryPoint::query()->where('order_id', $order->id)->delete();
            $order->delete();
        }

        $trip->kmReports()->getQuery()->delete();
        $trip->driverSwaps()->getQuery()->delete();
        $trip->checkpoints()->getQuery()->delete();
    }

    /**
     * 1. Gửi thông báo đến toàn bộ user khi chuyến chuyển sang trạng thái đã giao.
     */
    public function notifyUsersTripDelivered(Trip $trip): void
    {
        $this->sendTripNotification($trip, function (Trip $trip): array {
            $driverName = $trip->driver?->name ?? 'Chưa gán tài xế';
            $plateNumber = $trip->vehicle?->plate_number;

            $title = filled($plateNumber)
                ? "Chuyến xe {$plateNumber} đã giao hàng"
                : 'Chuyến xe đã giao hàng';

            return [
                'title' => $title,
                'body' => "Tài xế {$driverName} đã hoàn thành giao hàng.",
                'icon' => 'heroicon-o-check-circle',
                'status' => 'success',
            ];
        });
    }

    /**
     * 2. Gửi thông báo đến toàn bộ user khi xe báo sai lệch số Km.
     */
    public function notifyUsersTripKmReported(Trip $trip, ?TripKmReport $report = null): void
    {
        $this->sendTripNotification($trip, function (Trip $trip) use ($report): array {
            $report = $report ?? $trip->latestPendingKmReport;
            $report?->loadMissing(['driver', 'vehicle']);

            $driverName = $report?->driver?->name ?? $trip->driver?->name ?? 'Chưa gán tài xế';
            $plateNumber = $report?->vehicle?->plate_number ?? $trip->vehicle?->plate_number;

            $title = filled($plateNumber)
                ? "Chuyến xe {$plateNumber} báo sai Km"
                : 'Chuyến xe báo sai Km';

            $body = $this->kmReportBody($trip, $report);

            $actions = [];
            $isPending = $report ? $report->status === TripKmReportStatus::Pending : ($trip->latestPendingKmReport !== null);

            if ($isPending) {
                try {
                    $resolveUrl = TripResource::getUrl('index', [
                        'tableAction' => 'resolve_km_report',
                        'tableActionRecord' => $trip->getKey(),
                        'tripSearch' => $trip->trip_code,
                        'activeStatusFilter' => 'km_report',
                        'orderType' => 'all',
                        'activePlaceFilter' => 'all',
                    ]);

                    if ($resolveUrl) {
                        $actions[] = Action::make('resolve_km_report')
                            ->label('Xử lý báo sai Km')
                            ->icon('heroicon-o-exclamation-triangle')
                            ->color('warning')
                            ->button()
                            ->url($resolveUrl)
                            ->markAsRead();
                    }

                    $timelineUrl = TripResource::getUrl('timeline', ['record' => $trip]);
                    if ($timelineUrl) {
                        $actions[] = Action::make('view')
                            ->label('Xem chi tiết')
                            ->url($timelineUrl)
                            ->markAsRead();
                    }
                } catch (Throwable) {
                }
            }

            return [
                'title' => $title,
                'body' => $body,
                'icon' => 'heroicon-o-exclamation-triangle',
                'status' => 'warning',
                'actions' => $actions,
                'viewData' => ['km_report_id' => $report?->id, 'trip_id' => $trip->id],
            ];
        });
    }

    /**
     * Tài xế báo lại khi báo cáo còn chờ: cập nhật nội dung thông báo cũ và đánh dấu chưa đọc thay vì gửi thông báo mới.
     *
     * @return int số thông báo đã cập nhật (0 nếu thông báo cũ đã bị xóa)
     */
    public function refreshKmReportNotifications(Trip $trip, TripKmReport $report): int
    {
        $updated = 0;
        $body = $this->kmReportBody($trip, $report).' (báo lại lúc '.now()->format('H:i').')';

        foreach ($this->kmReportNotifications($trip, $report) as $notificationRecord) {
            $data = json_decode($notificationRecord->data, true);

            if (! is_array($data)) {
                continue;
            }

            $data['body'] = $body;

            DB::table('notifications')->where('id', $notificationRecord->id)->update([
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'read_at' => null,
                'updated_at' => now(),
            ]);
            $updated++;
        }

        return $updated;
    }

    private function kmReportBody(Trip $trip, ?TripKmReport $report): string
    {
        $report = $report ?? $trip->latestPendingKmReport;
        $report?->loadMissing(['driver']);

        $driverName = $report?->driver?->name ?? $trip->driver?->name ?? 'Chưa gán tài xế';

        if ($report && $report->reported_km !== null) {
            $reportedKmFormatted = number_format((float) $report->reported_km, 1, ',', '.');

            return "Tài xế {$driverName} báo sai lệch số Km: {$reportedKmFormatted} km.";
        }

        return "Tài xế {$driverName} đã báo sai lệch số Km.";
    }

    /**
     * Dọn dẹp / bỏ các action (Xử lý báo sai Km & Xem chi tiết) trong thông báo khi báo sai Km đã được xử lý.
     */
    public function clearKmReportNotificationActions(Trip $trip, ?TripKmReport $report = null): void
    {
        $notifications = $this->kmReportNotifications($trip, $report);

        if ($notifications->isEmpty()) {
            return;
        }

        $reportStatus = $report?->status ?? $trip->kmReports()->latest('id')->value('status');

        foreach ($notifications as $notificationRecord) {
            $data = json_decode($notificationRecord->data, true);
            if (! is_array($data)) {
                continue;
            }

            // Xóa bỏ toàn bộ action trong notification
            $data['actions'] = [];

            if ($reportStatus === TripKmReportStatus::Resolved) {
                if (! str_contains($data['body'] ?? '', '[Đã')) {
                    $data['body'] = rtrim($data['body'] ?? '').' [Đã duyệt điều chỉnh]';
                }
                $data['status'] = 'success';
                $data['icon'] = 'heroicon-o-check-circle';
                $data['iconColor'] = 'success';
            } elseif ($reportStatus === TripKmReportStatus::Rejected) {
                if (! str_contains($data['body'] ?? '', '[Đã')) {
                    $data['body'] = rtrim($data['body'] ?? '').' [Đã từ chối]';
                }
                $data['status'] = 'danger';
                $data['icon'] = 'heroicon-o-x-circle';
                $data['iconColor'] = 'danger';
            }

            DB::table('notifications')
                ->where('id', $notificationRecord->id)
                ->update([
                    'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'read_at' => $notificationRecord->read_at ?? now(),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Thông báo báo sai km của đúng báo cáo (viewData.km_report_id), hoặc thông báo cũ chưa có viewData của đúng chuyến.
     */
    private function kmReportNotifications(Trip $trip, ?TripKmReport $report): Collection
    {
        $tripId = (int) $trip->getKey();

        return DB::table('notifications')
            ->where('data', 'like', '%resolve_km_report%')
            ->where(function ($query) use ($tripId, $report) {
                if ($report !== null) {
                    $query->orWhere('data->viewData->km_report_id', $report->id);
                }

                $query->orWhere(fn ($legacy) => $legacy
                    ->whereNull('data->viewData->km_report_id')
                    ->where('data', 'like', "%tableActionRecord={$tripId}&%"));
            })
            ->get();
    }

    /**
     * 3. Gửi thông báo đến toàn bộ user khi xe báo đảo lái.
     */
    public function notifyUsersDriverSwap(Trip $trip): void
    {
        $this->sendTripNotification($trip, function (Trip $trip): array {
            $latestSwap = $trip->driverSwaps()->latest('id')->first();
            $fromDriver = $latestSwap?->fromDriver;
            $driverName = $fromDriver?->name ?? $trip->driver?->name ?? 'Chưa gán tài xế';
            $plateNumber = $trip->vehicle?->plate_number;

            $title = filled($plateNumber)
                ? "Chuyến xe {$plateNumber} báo đảo lái"
                : 'Chuyến xe báo đảo lái';

            return [
                'title' => $title,
                'body' => "Tài xế {$driverName} đã báo đảo lái.",
                'icon' => 'heroicon-o-arrows-right-left',
                'status' => 'info',
            ];
        });
    }

    /**
     * Gửi notification chung tới toàn bộ user kèm timeline action.
     *
     * @param  callable(Trip): array{title: string, body: string, icon: string, status: string, actions?: array, viewData?: array}  $contentResolver
     */
    protected function sendTripNotification(Trip $trip, callable $contentResolver): void
    {
        $send = function () use ($trip, $contentResolver): void {
            $trip->loadMissing(['driver', 'vehicle']);

            $users = User::query()
                ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'driver'))
                ->get();

            if ($users->isEmpty()) {
                return;
            }

            $content = $contentResolver($trip);

            $actions = [];

            if (! empty($content['actions'])) {
                $actions = $content['actions'];
            } else {
                try {
                    $timelineUrl = TripResource::getUrl('timeline', ['record' => $trip]);
                    if ($timelineUrl) {
                        $actions[] = Action::make('view')
                            ->label('Xem chi tiết')
                            ->url($timelineUrl)
                            ->markAsRead();
                    }
                } catch (Throwable) {
                    // Route may not be resolvable in non-HTTP/isolated test contexts
                }
            }

            $notification = Notification::make()
                ->title($content['title'])
                ->body($content['body'])
                ->icon($content['icon'])
                ->duration(5000)
                ->status($content['status']);

            if (! empty($actions)) {
                $notification->actions($actions);
            }

            if (! empty($content['viewData'])) {
                $notification->viewData($content['viewData']);
            }

            $notification->sendToDatabase($users, isEventDispatched: true);

            try {
                $notification->broadcast($users);
            } catch (Throwable) {
                // Continue gracefully if broadcasting server is not reachable
            }
        };

        if (app()->runningUnitTests()) {
            $send();
        } else {
            DB::afterCommit($send);
        }
    }
}
