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
}
