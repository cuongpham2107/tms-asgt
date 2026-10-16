<?php

namespace App\Observers;

use App\Enums\TripKmReportStatus;
use App\Models\TripKmReport;

class TripKmReportObserver
{
    /**
     * Handle the TripKmReport "created" event.
     */
    public function created(TripKmReport $report): void
    {
        $this->notify($report);
    }

    /**
     * Handle the TripKmReport "updated" event.
     */
    public function updated(TripKmReport $report): void
    {
        if ($report->wasChanged('reported_km') && $report->status === TripKmReportStatus::Pending) {
            $this->notify($report);
        }

        if ($report->wasChanged('status') && in_array($report->status, [TripKmReportStatus::Resolved, TripKmReportStatus::Rejected], true)) {
            $this->clearNotificationActions($report);
        }
    }

    /**
     * Gửi thông báo đến toàn bộ user qua TripObserver khi có báo sai Km.
     */
    protected function notify(TripKmReport $report): void
    {
        $report->loadMissing(['trip', 'driver', 'vehicle']);

        if ($report->trip) {
            app(TripObserver::class)->notifyUsersTripKmReported($report->trip, $report);
        }
    }

    /**
     * Xóa các action trong notification khi báo sai Km đã được xử lý.
     */
    protected function clearNotificationActions(TripKmReport $report): void
    {
        $report->loadMissing('trip');

        if ($report->trip) {
            app(TripObserver::class)->clearKmReportNotificationActions($report->trip, $report);
        }
    }
}
