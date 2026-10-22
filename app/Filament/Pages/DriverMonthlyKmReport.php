<?php

namespace App\Filament\Pages;

use App\Services\DriverMonthlyKmReportService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

class DriverMonthlyKmReport extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Vận hành';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Km tài xế theo tháng';

    protected static ?string $title = 'Tổng hợp chuyến & km tài xế theo tháng';

    protected string $view = 'filament.pages.driver-monthly-km-report';

    protected static ?int $navigationSort = 5;

    #[Url]
    public string $month = '';

    #[Url]
    public ?int $driverId = null;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now()->format('Y-m');
        }
    }

    public function updatedMonth(): void
    {
        $this->mount();
        $this->driverId = null;
    }

    public function shiftMonth(int $offset): void
    {
        $this->month = $this->monthDate()->addMonthsNoOverflow($offset)->format('Y-m');
        $this->driverId = null;
    }

    public function selectDriver(int $driverId): void
    {
        $this->driverId = $this->driverId === $driverId ? null : $driverId;
    }

    public function monthDate(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay();
    }

    /**
     * @return Collection<int, array{trip_id: int, trip_code: string, plate: ?string, started_at: ?Carbon, driver_id: int, km_total: float, km_loaded: float, km_empty: float}>
     */
    #[Computed]
    public function tripLines(): Collection
    {
        return app(DriverMonthlyKmReportService::class)->tripLines($this->monthDate());
    }

    /**
     * @return Collection<int, array{driver_id: int, driver_name: string, employee_code: ?string, station: ?string, trip_count: int, km_total: float, km_loaded: float, km_empty: float}>
     */
    #[Computed]
    public function driverRows(): Collection
    {
        $rows = app(DriverMonthlyKmReportService::class)->summarize($this->tripLines);
        $search = mb_strtolower(trim($this->search));

        if ($search === '') {
            return $rows;
        }

        return $rows->filter(fn (array $row): bool => str_contains(mb_strtolower($row['driver_name'].' '.$row['employee_code']), $search))->values();
    }

    /**
     * @return array{drivers: int, active_drivers: int, trips: int, km_total: float, km_loaded: float, km_empty: float}
     */
    #[Computed]
    public function totals(): array
    {
        $lines = $this->tripLines;

        return [
            'drivers' => $this->driverRows->count(),
            'active_drivers' => $this->driverRows->where('trip_count', '>', 0)->count(),
            'trips' => $lines->pluck('trip_id')->unique()->count(),
            'km_total' => round($lines->sum('km_total'), 1),
            'km_loaded' => round($lines->sum('km_loaded'), 1),
            'km_empty' => round($lines->sum('km_empty'), 1),
        ];
    }

    /**
     * @return Collection<int, array{trip_id: int, trip_code: string, plate: ?string, started_at: ?Carbon, driver_id: int, km_total: float, km_loaded: float, km_empty: float}>
     */
    #[Computed]
    public function selectedTrips(): Collection
    {
        return $this->driverId === null
            ? collect()
            : $this->tripLines->where('driver_id', $this->driverId)->values();
    }

    public static function loadedPercent(float $loaded, float $total): int
    {
        return $total > 0 ? (int) round($loaded / $total * 100) : 0;
    }
}
