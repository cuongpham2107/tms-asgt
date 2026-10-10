<?php

namespace App\Observers;

use App\Enums\TripStatus;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Trip;
use App\Models\TripKmReport;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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

            $body = "Tài xế {$driverName} đã báo sai lệch số Km.";
            if ($report && $report->reported_km !== null) {
                $reportedKmFormatted = number_format((float) $report->reported_km, 1, ',', '.');
                $body = "Tài xế {$driverName} báo sai lệch số Km: {$reportedKmFormatted} km.";
            }

            return [
                'title' => $title,
                'body' => $body,
                'icon' => 'heroicon-o-exclamation-triangle',
                'status' => 'warning',
            ];
        });
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
     * @param  callable(Trip): array{title: string, body: string, icon: string, status: string}  $contentResolver
     */
    protected function sendTripNotification(Trip $trip, callable $contentResolver): void
    {
        DB::afterCommit(function () use ($trip, $contentResolver): void {
            $trip->loadMissing(['driver', 'vehicle']);

            $users = User::all();

            if ($users->isEmpty()) {
                return;
            }

            $content = $contentResolver($trip);

            $actions = [];

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

            $notification = Notification::make()
                ->title($content['title'])
                ->body($content['body'])
                ->icon($content['icon'])
                ->duration(5000)
                ->status($content['status']);

            if (! empty($actions)) {
                $notification->actions($actions);
            }

            $notification->sendToDatabase($users, isEventDispatched: true);

            try {
                $notification->broadcast($users);
            } catch (Throwable) {
                // Continue gracefully if broadcasting server is not reachable
            }
        });
    }
}
