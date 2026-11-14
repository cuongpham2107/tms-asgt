<?php

namespace App\Filament\Pages;

use App\Models\Trip;
use App\Services\ShiftScheduleService;
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

    /** Chuyến đang mở popup hành trình (bấm dòng chuyến trong bảng React). */
    public ?int $timelineTripId = null;

    public function mount(): void
    {
        $this->period = self::normalizePeriod($this->period);
        $this->date = self::normalizeDate($this->date);
    }

    public function openTripTimeline(int $tripId): void
    {
        $this->timelineTripId = $tripId;
        $this->dispatch('open-modal', id: 'driver-trip-timeline');
    }

    public function timelineTrip(): ?Trip
    {
        return $this->timelineTripId === null ? null : Trip::with(['vehicle', 'driver'])->find($this->timelineTripId);
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
            : ShiftScheduleService::getShiftWindow()[0]->toDateString();
    }

    /**
     * Khoảng thời gian kỳ báo cáo tính theo mốc giao ca 08:00 sáng:
     * - today: 08:00 ngày $date -> 08:00 ngày hôm sau
     * - week:  08:00 thứ Hai đầu tuần -> 08:00 thứ Hai tuần kế tiếp
     * - month: 08:00 ngày 01 tháng này -> 08:00 ngày 01 tháng kế tiếp
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function rangeFor(string $period, string $date): array
    {
        $anchor = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();

        return match ($period) {
            'today' => [
                $anchor->copy()->setTime(8, 0, 0),
                $anchor->copy()->addDay()->setTime(8, 0, 0),
            ],
            'week' => [
                $anchor->copy()->startOfWeek()->setTime(8, 0, 0),
                $anchor->copy()->endOfWeek()->addDay()->setTime(8, 0, 0),
            ],
            default => [
                $anchor->copy()->startOfMonth()->setTime(8, 0, 0),
                $anchor->copy()->startOfMonth()->addMonthNoOverflow()->setTime(8, 0, 0),
            ],
        };
    }

    public static function labelFor(string $period, string $date): string
    {
        [$from, $to] = self::rangeFor($period, $date);

        return match ($period) {
            'today' => 'Ngày '.$from->format('d/m/Y'),
            'week' => 'Tuần '.$from->format('d/m').' – '.$to->copy()->subDay()->format('d/m/Y'),
            default => 'Tháng '.$from->format('m/Y'),
        };
    }
}
