<?php

namespace App\Filament\Pages;

use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Toàn bộ thân trang (chọn kỳ, tìm, tab, bảng) do widget React DriverMonthlyKmTable vẽ;
 * page chỉ đọc kỳ ban đầu từ URL và giữ cách tính khoảng ngày / nhãn của kỳ.
 */
class DriverMonthlyKmReport extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Vận hành';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Báo cáo tài xế';

    protected static ?string $title = 'Báo cáo chuyến, km & bảng kê tài xế';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.driver-monthly-km-report';

    /** @var array<string, string> */
    public const PERIODS = [
        'today' => 'Hôm nay',
        'week' => 'Tuần này',
        'month' => 'Tháng này',
    ];

    #[Url]
    public string $period = 'month';

    #[Url]
    public string $date = '';

    public function mount(): void
    {
        $this->period = self::normalizePeriod($this->period);
        $this->date = self::normalizeDate($this->date);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periodRange(): array
    {
        return self::rangeFor($this->period, $this->date);
    }

    public function periodLabel(): string
    {
        return self::labelFor($this->period, $this->date);
    }

    public static function normalizePeriod(string $period): string
    {
        return array_key_exists($period, self::PERIODS) ? $period : 'month';
    }

    public static function normalizeDate(string $date): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && Carbon::canBeCreatedFromFormat($date, 'Y-m-d')
            ? $date
            : now()->toDateString();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function rangeFor(string $period, string $date): array
    {
        $anchor = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();

        return match ($period) {
            'today' => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay()],
            'week' => [$anchor->copy()->startOfWeek(), $anchor->copy()->endOfWeek()],
            default => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()],
        };
    }

    public static function labelFor(string $period, string $date): string
    {
        [$from, $to] = self::rangeFor($period, $date);

        return match ($period) {
            'today' => 'Ngày '.$from->format('d/m/Y'),
            'week' => 'Tuần '.$from->format('d/m').' – '.$to->format('d/m/Y'),
            default => 'Tháng '.$from->format('m/Y'),
        };
    }
}
