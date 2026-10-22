<?php

namespace App\Filament\Pages;

use App\Enums\OnDutyLocation;
use App\Services\DriverMonthlyKmReportService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

class DriverMonthlyKmReport extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Vận hành';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Thống kê tài xế';

    protected static ?string $title = 'Tổng hợp chuyến & km tài xế';

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

    public function mount(): void
    {
        if (! array_key_exists($this->period, self::PERIODS)) {
            $this->period = 'month';
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
     * @return Collection<int, array{trip_id: int, trip_code: string, plate: ?string, started_at: ?Carbon, driver_id: int, is_empty: bool, km_total: float, km_loaded: float, km_empty: float}>
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
     * @return Collection<int, array{trip_id: int, trip_code: string, plate: ?string, started_at: ?Carbon, driver_id: int, is_empty: bool, km_total: float, km_loaded: float, km_empty: float}>
     */
    #[Computed]
    public function selectedTrips(): Collection
    {
        return $this->driverId === null
            ? collect()
            : $this->tripLines()->where('driver_id', $this->driverId)->values();
    }

    public function selectDriver(int $driverId): void
    {
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
        unset($this->tripLines, $this->allDriverRows, $this->driverRows, $this->driverGroups, $this->totals, $this->selectedTrips);
    }
}
