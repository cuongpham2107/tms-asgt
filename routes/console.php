<?php

use App\Http\Middleware\LogDriverApiErrors;
use App\Models\DatabaseNotification;
use App\Models\VehicleGpsLog;
use App\Services\Gps\EupGpsService;
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
    ->withoutOverlapping(1);

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

Schedule::command('model:prune', ['--model' => [DatabaseNotification::class]])
    ->hourly()
    ->name('prune-database-notifications');

Schedule::command('db:backup --keep=3')
    ->dailyAt('01:00')
    ->name('daily-database-backup')
    ->withoutOverlapping();

// Dọn Nhật ký hoạt động hàng tháng cho nhẹ database: giữ ACTIVITYLOG_KEEP_DAYS ngày (mặc định 90),
// riêng log lỗi API của App tài xế chỉ giữ 30 ngày.
Schedule::command('activitylog:clean', ['--force' => true])
    ->monthlyOn(1, '02:00')
    ->name('clean-activity-log')
    ->withoutOverlapping();

Schedule::command('activitylog:clean', [LogDriverApiErrors::LOG_NAME, '--days' => 30, '--force' => true])
    ->monthlyOn(1, '02:10')
    ->name('clean-driver-api-error-log')
    ->withoutOverlapping();
