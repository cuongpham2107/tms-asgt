<?php

use App\Models\VehicleGpsLog;
use App\Services\EupGpsService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nơi duy nhất gọi API EUP: lưu vị trí hiện tại + lộ trình. Mọi nơi khác đọc từ DB.
Schedule::call(fn () => app(EupGpsService::class)->sync())
    ->name('sync-eup-gps')
    ->everyThirtySeconds()
    ->withoutOverlapping();

Schedule::command('vehicles:snapshot-monthly-km')
    ->monthlyOn(23, '12:00')
    ->name('snapshot-monthly-vehicle-km');

Schedule::command('shifts:auto-end')
    ->dailyAt('08:05')
    ->name('auto-end-driver-shifts-morning');

Schedule::command('shifts:auto-end')
    ->dailyAt('20:05')
    ->name('auto-end-driver-shifts-evening');

Schedule::command('model:prune', ['--model' => [VehicleGpsLog::class]])
    ->daily()
    ->name('prune-vehicle-gps-logs');
