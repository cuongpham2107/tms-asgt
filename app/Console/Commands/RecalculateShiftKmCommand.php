<?php

namespace App\Console\Commands;

use App\Models\DriverShift;
use App\Services\ShiftKmCalculatorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculateShiftKmCommand extends Command
{
    protected $signature = 'shifts:recalculate-km
                            {--dry-run : Chỉ in ra chênh lệch, không ghi vào DB}
                            {--open-only : Chỉ tính lại các ca chưa kết thúc}
                            {--shift=* : Chỉ tính lại các ca theo id}';

    protected $description = 'Tính lại Tổng km / Km có hàng / Km rỗng cho các ca (dùng sau khi sửa công thức tính km)';

    public function handle(ShiftKmCalculatorService $calculator): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = DriverShift::query()
            ->whereNotNull('start_km')
            ->when($this->option('open-only'), fn ($builder) => $builder->whereNull('end_time'))
            ->when($this->option('shift'), fn ($builder, array $ids) => $builder->whereIn('id', $ids))
            ->orderBy('id');

        $total = $query->count();

        if ($total === 0) {
            $this->warn('Không có ca nào khớp điều kiện.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Tính lại %d ca%s...', $total, $dryRun ? ' (dry-run, không ghi DB)' : ''));

        $rows = [];
        $changed = 0;

        DB::beginTransaction();

        foreach ($query->cursor() as $shift) {
            $before = [
                (float) $shift->total_km,
                (float) $shift->total_km_loaded,
                (float) $shift->total_km_empty,
            ];

            $calculator->calculate($shift);
            $fresh = $shift->fresh();

            $after = [
                (float) $fresh->total_km,
                (float) $fresh->total_km_loaded,
                (float) $fresh->total_km_empty,
            ];

            if ($before !== $after) {
                $changed++;
                $rows[] = [
                    $shift->id,
                    $shift->driver_id,
                    $shift->end_time ? 'đã kết thúc' : 'đang mở',
                    $shift->start_time?->format('Y-m-d H:i'),
                    $before[0],
                    $after[0],
                    $before[1],
                    $after[1],
                    $before[2],
                    $after[2],
                ];
            }
        }

        $dryRun ? DB::rollBack() : DB::commit();

        if ($rows !== []) {
            $this->table(
                ['Ca', 'Tài xế', 'Trạng thái', 'Vào ca', 'Tổng km (cũ)', 'Tổng km (mới)', 'Có hàng (cũ)', 'Có hàng (mới)', 'Rỗng (cũ)', 'Rỗng (mới)'],
                $rows,
            );
        }

        $this->info(sprintf('Xong: %d/%d ca thay đổi%s.', $changed, $total, $dryRun ? ' (chưa ghi)' : ''));

        return self::SUCCESS;
    }
}
