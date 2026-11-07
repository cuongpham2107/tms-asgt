<?php

namespace App\Filament\Pages;

use App\Enums\OnDutyLocation;
use App\Enums\TripStatus;
use App\Services\DriverMonthlyKmReportService;
use App\Services\Export\TripExcelExportService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use UnitEnum;

class DriverMonthlyKmReport extends Page
{
    use WithPagination;

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

    #[Url]
    public ?int $driverId = null;

    #[Url]
    public string $search = '';

    /** Tab đang xem: 'summary' (tổng hợp km) hoặc 'statement' (bảng kê chỉ xem, không có tiền). */
    #[Url]
    public string $tab = 'summary';

    #[Url]
    public int $statementPerPage = 25;

    /** Bộ lọc tab bảng kê: loại hình ('HHHK' | 'Hàng ngoài' | 'Xe không hàng'), mã khách, mã kho. */
    #[Url]
    public string $serviceType = '';

    #[Url]
    public string $customer = '';

    #[Url]
    public string $warehouse = '';

    /** Cột xếp hạng lái xe và chiều sắp xếp. */
    #[Url]
    public string $rankSort = 'trip_count';

    #[Url]
    public bool $rankDesc = true;

    /** @var list<string> */
    public const RANK_COLUMNS = ['trip_count', 'km_total', 'avg_travel', 'avg_wait', 'wait_percent', 'avg_speed', 'long_wait_count'];

    public function mount(): void
    {
        if (! array_key_exists($this->period, self::PERIODS)) {
            $this->period = 'month';
        }

        if (! in_array($this->tab, ['summary', 'statement'], true)) {
            $this->tab = 'summary';
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)) {
            $this->date = now()->toDateString();
        }
    }

    public function getSubheading(): string
    {
        return $this->periodLabel().' · chỉ tính chuyến đã hoàn thành thuộc ca đã kết thúc.';
    }

    protected function getHeaderActions(): array
    {
        $periodActions = collect(self::PERIODS)->map(fn (string $label, string $period): Action => Action::make('period_'.$period)
            ->label($label)
            ->color(fn (): string => $this->period === $period && $this->isCurrentPeriod() ? 'primary' : 'gray')
            ->action(fn () => $this->setPeriod($period, now())));

        return [
            ...$periodActions->values()->all(),
            Action::make('previousPeriod')
                ->label('Kỳ trước')
                ->icon(Heroicon::ChevronLeft)
                ->iconButton()
                ->color('gray')
                ->action(fn () => $this->shiftPeriod(-1)),
            Action::make('pickMonth')
                ->label('Chọn tháng')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->modalWidth('sm')
                ->modalSubmitActionLabel('Xem')
                ->fillForm(fn (): array => ['month' => $this->anchorDate()->format('Y-m')])
                ->schema([
                    Select::make('month')
                        ->label('Tháng')
                        ->options(fn (): array => collect(range(0, 23))
                            ->mapWithKeys(fn (int $back): array => [
                                now()->startOfMonth()->subMonthsNoOverflow($back)->format('Y-m') => 'Tháng '.now()->startOfMonth()->subMonthsNoOverflow($back)->format('m/Y'),
                            ])
                            ->all())
                        ->required()
                        ->native(false),
                ])
                ->action(fn (array $data) => $this->setPeriod('month', Carbon::createFromFormat('Y-m-d', $data['month'].'-01'))),
            Action::make('nextPeriod')
                ->label('Kỳ sau')
                ->icon(Heroicon::ChevronRight)
                ->iconButton()
                ->color('gray')
                ->disabled(fn (): bool => $this->periodRange()[1]->isFuture())
                ->action(fn () => $this->shiftPeriod(1)),
        ];
    }

    public function anchorDate(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->date)->startOfDay();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periodRange(): array
    {
        $anchor = $this->anchorDate();

        return match ($this->period) {
            'today' => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay()],
            'week' => [$anchor->copy()->startOfWeek(), $anchor->copy()->endOfWeek()],
            default => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()],
        };
    }

    public function periodLabel(): string
    {
        [$from, $to] = $this->periodRange();

        return match ($this->period) {
            'today' => 'Ngày '.$from->format('d/m/Y'),
            'week' => 'Tuần '.$from->format('d/m').' – '.$to->format('d/m/Y'),
            default => 'Tháng '.$from->format('m/Y'),
        };
    }

    /**
     * @return Collection<int, array{trip_id: int, trip_code: string, plate: ?string, started_at: ?Carbon, driver_id: int, status: TripStatus, order_codes: list<string>, is_empty: bool, km_total: float, km_loaded: float, km_empty: float}>
     */
    #[Computed]
    public function tripLines(): Collection
    {
        return app(DriverMonthlyKmReportService::class)->tripLines(...$this->periodRange());
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function allDriverRows(): Collection
    {
        return app(DriverMonthlyKmReportService::class)->summarize($this->tripLines());
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function driverRows(): Collection
    {
        $needle = mb_strtolower(trim($this->search));

        if ($needle === '') {
            return $this->allDriverRows();
        }

        return $this->allDriverRows()
            ->filter(fn (array $row): bool => str_contains(mb_strtolower($row['driver_name'].' '.$row['employee_code']), $needle))
            ->values();
    }

    /**
     * Tài xế nhóm theo điểm trực (thứ tự như OnDutyLocation), tài xế chưa gán điểm trực xếp cuối.
     *
     * @return Collection<int, array{code: string, title: string, rows: Collection<int, array<string, mixed>>}>
     */
    #[Computed]
    public function driverGroups(): Collection
    {
        $order = array_flip(array_map(fn (OnDutyLocation $station): string => $station->value, OnDutyLocation::cases()));

        return $this->driverRows()
            ->groupBy(fn (array $row): string => $row['station']?->value ?? 'none')
            ->sortBy(fn (Collection $rows, string $code): int => $order[$code] ?? PHP_INT_MAX)
            ->map(fn (Collection $rows, string $code): array => [
                'code' => $code,
                'title' => $rows->first()['station']?->getLabel() ?? 'Chưa gán',
                'rows' => $rows->values(),
            ])
            ->values();
    }

    /**
     * @return array{drivers: int, active_drivers: int, trips: int, empty_trips: int, km_total: float, km_loaded: float, km_empty: float}
     */
    #[Computed]
    public function totals(): array
    {
        $lines = $this->tripLines();

        return [
            'drivers' => $this->allDriverRows()->count(),
            'active_drivers' => $this->allDriverRows()->where('trip_count', '>', 0)->count(),
            'trips' => $lines->pluck('trip_id')->unique()->count(),
            'empty_trips' => $lines->where('is_empty', true)->pluck('trip_id')->unique()->count(),
            'km_total' => round($lines->sum('km_total'), 1),
            'km_loaded' => round($lines->sum('km_loaded'), 1),
            'km_empty' => round($lines->sum('km_empty'), 1),
        ];
    }

    /**
     * @return Collection<int, array{trip_id: int, trip_code: string, plate: ?string, started_at: ?Carbon, driver_id: int, status: TripStatus, order_codes: list<string>, is_empty: bool, km_total: float, km_loaded: float, km_empty: float}>
     */
    #[Computed]
    public function selectedTrips(): Collection
    {
        return $this->driverId === null
            ? collect()
            : $this->tripLines()->where('driver_id', $this->driverId)->values();
    }

    /**
     * Dòng bảng kê trong kỳ đã qua bộ lọc loại hình / khách / kho / ô tìm lái xe (chưa lọc theo lái xe đang chọn).
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function filteredStatementRows(): Collection
    {
        $needle = mb_strtolower(trim($this->search));

        return $this->periodStatementRows()
            ->when($this->serviceType !== '', fn (Collection $rows): Collection => $rows->where('service_type', $this->serviceType))
            ->when($this->customer !== '', fn (Collection $rows): Collection => $rows->where('customer', $this->customer))
            ->when($this->warehouse !== '', fn (Collection $rows): Collection => $rows->where('warehouse', $this->warehouse))
            ->when($needle !== '', fn (Collection $rows): Collection => $rows->filter(fn (array $row): bool => str_contains(mb_strtolower($row['driver_name']), $needle)))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function periodStatementRows(): Collection
    {
        [$from, $to] = $this->periodRange();

        return app(DriverMonthlyKmReportService::class)->statementRows($from, $to);
    }

    /**
     * Dòng bảng kê hiển thị ở bảng chuyến: thêm lọc theo lái xe đang chọn.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function statementRows(): Collection
    {
        return $this->driverId === null
            ? $this->filteredStatementRows()
            : $this->filteredStatementRows()->where('driver_id', $this->driverId)->values();
    }

    /**
     * @return array{serviceTypes: list<string>, customers: list<string>, warehouses: list<string>, drivers: array<int, string>}
     */
    #[Computed]
    public function statementFilterOptions(): array
    {
        $rows = $this->periodStatementRows();
        $distinct = fn (string $key): array => $rows->pluck($key)->filter()->unique()->sort()->values()->all();

        return [
            'serviceTypes' => ['HHHK', 'Hàng ngoài', 'Xe không hàng'],
            'customers' => $distinct('customer'),
            'warehouses' => $distinct('warehouse'),
            'drivers' => $rows->pluck('driver_name', 'driver_id')->filter()->sort()->all(),
        ];
    }

    /**
     * Xếp hạng lái xe theo cột đang chọn; lái xe thiếu số liệu cột đó xếp cuối.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function driverRanking(): Collection
    {
        $column = in_array($this->rankSort, self::RANK_COLUMNS, true) ? $this->rankSort : 'trip_count';
        $ranking = app(DriverMonthlyKmReportService::class)->driverPerformance($this->filteredStatementRows());

        [$withValue, $withoutValue] = $ranking->partition(fn (array $row): bool => $row[$column] !== null);

        return $withValue
            ->sortBy($column, SORT_REGULAR, $this->rankDesc)
            ->concat($withoutValue)
            ->values();
    }

    /**
     * Số liệu chung của các dòng đang lọc, làm mốc so sánh cho từng lái xe.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function fleetPerformance(): array
    {
        return app(DriverMonthlyKmReportService::class)->performance($this->filteredStatementRows());
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    #[Computed]
    public function statementPage(): LengthAwarePaginator
    {
        $rows = $this->statementRows();
        $perPage = in_array($this->statementPerPage, [25, 50, 100], true) ? $this->statementPerPage : 25;

        return new LengthAwarePaginator($rows->forPage($this->getPage(), $perPage)->values(), $rows->count(), $perPage, $this->getPage());
    }

    public function sortRanking(string $column): void
    {
        if (! in_array($column, self::RANK_COLUMNS, true)) {
            return;
        }

        $this->rankDesc = $this->rankSort === $column ? ! $this->rankDesc : true;
        $this->rankSort = $column;
    }

    public function resetStatementFilters(): void
    {
        $this->reset('serviceType', 'customer', 'warehouse', 'driverId', 'search');
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'search', 'statementPerPage', 'serviceType', 'customer', 'warehouse', 'driverId'], true)) {
            $this->resetPage();
        }
    }

    public static function formatMinutes(?int $minutes): string
    {
        return $minutes === null ? '—' : app(TripExcelExportService::class)->formatDuration($minutes);
    }

    public function selectDriver(int $driverId): void
    {
        $this->resetPage();
        $this->driverId = $this->driverId === $driverId ? null : $driverId;
    }

    public static function loadedPercent(float $loaded, float $total): int
    {
        return $total > 0 ? (int) round($loaded / $total * 100) : 0;
    }

    private function isCurrentPeriod(): bool
    {
        [$from, $to] = $this->periodRange();

        return now()->between($from, $to);
    }

    private function shiftPeriod(int $step): void
    {
        $anchor = $this->anchorDate();

        $this->setPeriod($this->period, match ($this->period) {
            'today' => $anchor->addDays($step),
            'week' => $anchor->addWeeks($step),
            default => $anchor->startOfMonth()->addMonthsNoOverflow($step),
        });
    }

    private function setPeriod(string $period, Carbon $anchor): void
    {
        $this->period = $period;
        $this->date = $anchor->toDateString();
        $this->resetPage();
        unset($this->tripLines, $this->allDriverRows, $this->driverRows, $this->driverGroups, $this->totals, $this->selectedTrips, $this->statementRows, $this->statementPage, $this->periodStatementRows, $this->filteredStatementRows, $this->statementFilterOptions, $this->driverRanking, $this->fleetPerformance);
    }
}
