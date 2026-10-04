<?php

use App\Jobs\SyncEupGpsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Chạy đồng bộ ngay trong scheduler (không cần queue worker).
Schedule::job(new SyncEupGpsJob, connection: 'sync')->everyMinute()->withoutOverlapping();
