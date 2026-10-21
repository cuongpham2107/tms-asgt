<?php

namespace App\Console\Commands;

use App\Services\AutoEndShiftService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shifts:auto-end {--dry-run : Chỉ hiển thị danh sách ca sẽ tự động thoát mà không cập nhật DB}')]
#[Description('Tự động thoát ca cho các tài xế đến mốc giờ quy định (kèm 5 phút ân hạn) không có đơn làm dở')]
class AutoEndDriverShiftsCommand extends Command
{
    public function handle(AutoEndShiftService $service): int
    {
        $this->info('Đang kiểm tra và tự động kết thúc các ca làm việc quá hạn...');

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Chế độ DRY-RUN: không có thay đổi nào được lưu.');
        }

        $endedShifts = $service->autoEndEligibleShifts(dryRun: $dryRun);

        if ($endedShifts->isEmpty()) {
            $this->info('Không có ca làm việc nào cần tự động thoát.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Tài xế', 'Loại ca', 'Giờ vào ca', 'Giờ kết thúc', 'Km kết thúc', 'Tổng Km'],
            $endedShifts->map(fn ($s) => [
                $s->id,
                $s->driver?->name ?? ('#'.$s->driver_id),
                $s->shift_type?->getLabel() ?? $s->shift_type,
                $s->start_time?->format('d/m/Y H:i'),
                $s->end_time?->format('d/m/Y H:i'),
                number_format((float) $s->end_km, 1),
                number_format((float) $s->total_km, 1),
            ])
        );

        $this->info("Đã tự động kết thúc thành công {$endedShifts->count()} ca làm việc.");

        return self::SUCCESS;
    }
}
