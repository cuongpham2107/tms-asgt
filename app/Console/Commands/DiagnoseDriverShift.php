<?php

namespace App\Console\Commands;

use App\Enums\CheckpointType;
use App\Http\Middleware\LogDriverApiErrors;
use App\Models\DriverShift;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Models\Activity;

/**
 * Chẩn đoán vì sao tài xế không kết thúc được ca: trạng thái ca, các điều kiện chặn hiện tại
 * và dòng thời gian (ai sửa chuyến / đơn / mốc, App gọi API ca lúc nào, server trả gì).
 */
class DiagnoseDriverShift extends Command
{
    protected $signature = 'drivers:diagnose-shift
                            {driver : ID, mã nhân viên, số điện thoại hoặc tên tài xế}
                            {--hours=24 : Xem dòng thời gian trong bao nhiêu giờ gần nhất}';

    protected $description = 'Chẩn đoán vì sao tài xế không kết thúc được ca (ca, điều kiện chặn, nhật ký sửa và lần gọi API)';

    private const TIMEZONE = 'Asia/Ho_Chi_Minh';

    public function handle(): int
    {
        $driver = $this->findDriver((string) $this->argument('driver'));

        if ($driver === null) {
            $this->error('Không tìm thấy tài xế.');

            return self::FAILURE;
        }

        $since = now()->subHours(max(1, (int) $this->option('hours')));
        $shift = DriverShift::query()->where('driver_id', $driver->id)->latest('start_time')->first();

        $this->info("Tài xế #{$driver->id} {$driver->name}");
        $this->line('Token App dùng lần cuối: '.$this->time(PersonalAccessToken::query()
            ->where('tokenable_type', User::class)->where('tokenable_id', $driver->id)->max('last_used_at')));

        $this->reportShift($shift, $driver);
        $this->reportTimeline($driver, $shift, $since);

        return self::SUCCESS;
    }

    private function findDriver(string $needle): ?User
    {
        return User::query()
            ->where(fn ($query) => $query->where('id', ctype_digit($needle) ? (int) $needle : 0)
                ->orWhere('employee_code', $needle)
                ->orWhere('phone', $needle)
                ->orWhere('name', 'like', "%{$needle}%"))
            ->orderByRaw('id = ? desc', [ctype_digit($needle) ? (int) $needle : 0])
            ->first();
    }

    private function reportShift(?DriverShift $shift, User $driver): void
    {
        $this->newLine();

        if ($shift === null) {
            $this->warn('Tài xế chưa có ca nào.');

            return;
        }

        $this->line(sprintf('Ca #%d: bắt đầu %s, kết thúc %s', $shift->id, $this->time($shift->start_time), $shift->end_time ? $this->time($shift->end_time) : 'ĐANG MỞ'));

        if ($shift->end_time !== null) {
            return;
        }

        $hasTrips = $shift->trips()->whereNotIn('status', ['cancelled', 'pending'])->exists();
        $endCheckpoint = TripCheckpoint::query()->where('shift_id', $shift->id)
            ->whereIn('checkpoint_type', [CheckpointType::End->value, CheckpointType::DriverSwap->value])
            ->whereNotNull('km_reading')->latest('id')->first();
        $unfinished = Trip::query()->unfinishedForDriver($driver->id)->with('orders')->get();

        $this->line('Điều kiện Kết thúc ca hiện tại:');
        $this->line('  - Mốc kết thúc gắn ca: '.(! $hasTrips ? 'không cần (ca chưa có chuyến)' : ($endCheckpoint ? "có (mốc #{$endCheckpoint->id})" : 'THIẾU → bị chặn "Cần nhập km kết thúc"')));
        $this->line('  - Chuyến chưa xong: '.($unfinished->isEmpty()
            ? 'không có → server cho kết thúc ca'
            : $unfinished->map(fn (Trip $trip): string => $trip->trip_code.' ('.$trip->status->value.', đơn '.$trip->orders->pluck('order_code')->join(',').')')->join('; ').' → server chặn'));

        foreach ($shift->trips()->whereNotIn('status', ['cancelled', 'pending'])->get() as $trip) {
            $this->line("  - Chuyến trong ca: {$trip->trip_code} {$trip->status->value}");
        }
    }

    private function reportTimeline(User $driver, ?DriverShift $shift, CarbonInterface $since): void
    {
        $tripIds = Trip::query()->where('driver_id', $driver->id)->where('updated_at', '>=', $since)->pluck('id');
        $orderIds = Order::query()->whereIn('trip_id', $tripIds)->pluck('id');
        $checkpointIds = TripCheckpoint::query()->whereIn('trip_id', $tripIds)->pluck('id');

        $activities = Activity::query()
            ->where('created_at', '>=', $since)
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('subject_type', Trip::class)->whereIn('subject_id', $tripIds))
                ->orWhere(fn ($q) => $q->where('subject_type', Order::class)->whereIn('subject_id', $orderIds))
                ->orWhere(fn ($q) => $q->where('subject_type', TripCheckpoint::class)->whereIn('subject_id', $checkpointIds))
                ->orWhere(fn ($q) => $q->where('subject_type', DriverShift::class)->where('subject_id', $shift?->id))
                ->orWhere(fn ($q) => $q->where('log_name', LogDriverApiErrors::LOG_NAME)->where('causer_type', User::class)->where('causer_id', $driver->id)))
            ->with('causer')
            ->orderBy('id')
            ->get();

        $this->newLine();
        $this->line("Dòng thời gian từ {$this->time($since)} ({$activities->count()} dòng):");

        if ($activities->isEmpty()) {
            $this->line('  (trống — App không gọi API ca và không ai sửa chuyến/đơn trong khoảng này)');

            return;
        }

        $activities->each(fn (Activity $activity) => $this->line('  '.$this->describe($activity)));
    }

    private function describe(Activity $activity): string
    {
        $who = $activity->causer?->name ?? 'hệ thống';
        $time = $this->time($activity->created_at, 'H:i:s d/m');

        if ($activity->log_name === LogDriverApiErrors::LOG_NAME) {
            $props = $activity->properties;
            $trips = collect($props['shift_trips'] ?? [])->join(', ');

            return "{$time} [App] {$activity->description}"
                .($props['message'] ?? null ? ' — '.$props['message'] : '')
                .($trips !== '' ? " | chuyến trả về: {$trips}" : '');
        }

        $subject = class_basename((string) $activity->subject_type).' #'.$activity->subject_id;
        $changes = collect($activity->attribute_changes['attributes'] ?? [])
            ->map(fn ($new, $field): string => $field.'='.$this->short($new))
            ->take(6)
            ->join(', ');

        return "{$time} [{$who}] {$activity->event} {$subject}".($changes !== '' ? ": {$changes}" : '');
    }

    private function short(mixed $value): string
    {
        $text = is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value, JSON_UNESCAPED_UNICODE);

        return mb_strimwidth(trim((string) $text, "'"), 0, 40, '…');
    }

    private function time(mixed $value, string $format = 'H:i d/m/Y'): string
    {
        if ($value === null) {
            return '—';
        }

        return ($value instanceof CarbonInterface ? $value : now()->parse($value))->timezone(self::TIMEZONE)->format($format);
    }
}
