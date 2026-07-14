<?php

namespace App\Filament\Pages;

use App\Enums\OnDutyLocation;
use App\Filament\Forms\Components\PillFilter;
use App\Filament\Widgets\DriverDutySummaryWidget;
use App\Models\DriverShift;
use App\Models\User;
use App\Models\Vehicle;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use UnitEnum;

class DriverDutyReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Vận hành';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Ca trực lái xe';

    protected static ?string $title = 'Tổng hợp ca trực lái xe';

    protected string $view = 'filament.pages.driver-duty-report';

    protected static ?int $navigationSort = 4;

    #[Url]
    public string $activeDateFilter = 'today';

    #[Url]
    public string $activeStationFilter = 'all';

    public function getHeaderWidgets(): array
    {
        return [
            DriverDutySummaryWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 3;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->buildQuery())
            ->columns([
                TextColumn::make('index')
                    ->label('STT')
                    ->rowIndex()
                    ->alignCenter(),
                TextColumn::make('station_display')
                    ->label('Điểm trực')
                    ->badge()
                    ->alignCenter()
                    ->color(fn ($state): string => match ($state) {
                        'TN (Thái Nguyên)' => 'info',
                        'BN (Bắc Ninh)' => 'warning',
                        'NBA (Nội Bài)' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('name')
                    ->label('Lịch lái xe')
                    ->searchable()
                    ->alignCenter()
                    ->weight('bold'),
                TextColumn::make('plate')
                    ->label('Xe chạy')
                    ->alignCenter()
                    ->formatStateUsing(function ($state, $record) {
                        $plate = $state ?: '—';
                        if (! empty($record->swap_note)) {
                            $plate .= " ({$record->swap_note})";
                        }

                        return $plate;
                    }),
                TextColumn::make('shift_type')
                    ->label('Ca')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => $state?->getLabel() ?? '—'),
                TextColumn::make('trip_count')
                    ->label('Số chuyến')
                    ->alignCenter()
                    ->numeric(),
                TextColumn::make('km_loaded')
                    ->label('Số Km CH')
                    ->alignCenter()
                    ->numeric(1)
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 1) : '—'),
                TextColumn::make('km_empty')
                    ->label('Số Km KH')
                    ->alignCenter()
                    ->numeric(1)
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 1) : '—'),
                TextColumn::make('total_km')
                    ->label('Tổng KM')
                    ->alignCenter()
                    ->numeric(1)
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 1) : '—'),
                TextColumn::make('start_time')
                    ->label('Bắt đầu ca')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => $state ? $state->format('H:i d/m/Y') : '—'),
                TextColumn::make('end_time')
                    ->label('Kết thúc ca')
                    ->alignCenter()
                    ->formatStateUsing(function ($state, $record) {
                        if ($state) {
                            return $state->format('H:i d/m/Y');
                        }

                        return $record->has_active_shift ? 'Đang chạy' : '—';
                    }),
            ])
            ->defaultSort('station')
            ->paginated([50]);
    }

    public function filtersForm(Schema $form): Schema
    {
        return $form
            ->components([
                PillFilter::make('activeDateFilter')
                    ->options([
                        'today' => 'Hôm nay',
                        'week' => 'Tuần này',
                        'month' => 'Tháng này',
                    ])
                    ->activeValue(fn () => $this->activeDateFilter)
                    ->clickAction('filterDate'),

                PillFilter::make('activeStationFilter')
                    ->options(fn (): array => collect(OnDutyLocation::cases())
                        ->mapWithKeys(fn ($station) => [$station->value => $station->getLabel()])
                        ->prepend('Tất cả', 'all')
                        ->toArray()
                    )
                    ->activeValue(fn () => $this->activeStationFilter)
                    ->clickAction('filterStation'),
            ]);
    }

    public function filterDate(string $value): void
    {
        $this->activeDateFilter = $value;

        $this->resetPage();
    }

    public function filterStation(string $value): void
    {
        $this->activeStationFilter = $value;

        $this->resetPage();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function dateRange(): array
    {
        return match ($this->activeDateFilter) {
            'week' => [
                Carbon::now()->startOfWeek()->setHour(8),
                Carbon::now()->endOfWeek()->addDay()->setHour(8),
            ],
            'month' => [
                Carbon::now()->startOfMonth()->setHour(8),
                Carbon::now()->startOfMonth()->addMonth()->setHour(8),
            ],
            default => [
                Carbon::today()->setHour(8),
                Carbon::tomorrow()->setHour(8),
            ],
        };
    }

    protected function buildQuery(): Builder
    {
        [$from, $to] = $this->dateRange();

        // Get all active drivers
        return User::query()
            ->role('driver')
            ->where('is_active', true)
            ->when($this->activeStationFilter !== 'all', fn ($q) => $q->where('station', $this->activeStationFilter))
            ->select('users.*')
            ->selectSub(function ($q) use ($from, $to) {
                $q->selectRaw('COALESCE(s.total_km_loaded, 0)')
                    ->from('driver_shifts', 's')
                    ->whereColumn('s.driver_id', 'users.id')
                    ->where(function ($q2) use ($from, $to) {
                        $q2->whereBetween('s.start_time', [$from, $to])
                            ->orWhereNull('s.end_time');
                    })
                    ->orderByDesc('s.start_time')
                    ->limit(1);
            }, 'km_loaded')
            ->selectSub(function ($q) use ($from, $to) {
                $q->selectRaw('COALESCE(s.total_km_empty, 0)')
                    ->from('driver_shifts', 's')
                    ->whereColumn('s.driver_id', 'users.id')
                    ->where(function ($q2) use ($from, $to) {
                        $q2->whereBetween('s.start_time', [$from, $to])
                            ->orWhereNull('s.end_time');
                    })
                    ->orderByDesc('s.start_time')
                    ->limit(1);
            }, 'km_empty')
            ->selectSub(function ($q) use ($from, $to) {
                $q->selectRaw('COALESCE(s.total_km, 0)')
                    ->from('driver_shifts', 's')
                    ->whereColumn('s.driver_id', 'users.id')
                    ->where(function ($q2) use ($from, $to) {
                        $q2->whereBetween('s.start_time', [$from, $to])
                            ->orWhereNull('s.end_time');
                    })
                    ->orderByDesc('s.start_time')
                    ->limit(1);
            }, 'total_km')
            ->selectSub(function ($q) use ($from, $to) {
                $q->selectRaw('COUNT(t.id)')
                    ->from('trips', 't')
                    ->join('driver_shifts as s2', 's2.id', '=', 't.shift_id')
                    ->whereColumn('s2.driver_id', 'users.id')
                    ->where(function ($q2) use ($from, $to) {
                        $q2->whereBetween('s2.start_time', [$from, $to])
                            ->orWhereNull('s2.end_time');
                    });
            }, 'trip_count')
            ->withCasts([
                'km_loaded' => 'decimal:1',
                'km_empty' => 'decimal:1',
                'total_km' => 'decimal:1',
                'trip_count' => 'integer',
            ]);
    }

    protected function paginateTableQuery(Builder $query): Paginator
    {
        [$from, $to] = $this->dateRange();

        $drivers = $query->get();

        $rows = $drivers->map(function (User $driver) use ($from, $to) {
            $shift = DriverShift::where('driver_id', $driver->id)
                ->where(function ($q) use ($from, $to) {
                    $q->whereBetween('start_time', [$from, $to])
                        ->orWhereNull('end_time');
                })
                ->orderByDesc('start_time')
                ->first();

            // Lấy xe: ưu tiên current_driver_id, fallback qua trip
            $vehicle = Vehicle::where('current_driver_id', $driver->id)->first();
            if ($vehicle === null && $shift) {
                $vehicle = $shift->trips()
                    ->latest('started_at')
                    ->first()?->vehicle;
            }

            $swapTrip = $shift?->trips()
                ->whereNotNull('end_km')
                ->where('status', 'driver_swap')
                ->first();

            $hasSwap = $swapTrip !== null;
            $plate = $vehicle?->plate_number;

            // Lấy station trực tiếp từ model (đã có enum cast)
            $driver->station_display = $driver->station?->getLabel() ?? '—';

            $driver->plate = $plate;
            $driver->swap_note = $hasSwap ? 'đảo lái' : null;
            $driver->shift_type = $shift?->shift_type;
            $driver->has_active_shift = $shift !== null && $shift->end_time === null;
            $driver->trip_count = (int) ($driver->trip_count ?? 0);
            $driver->km_loaded = (float) ($driver->km_loaded ?? 0);
            $driver->km_empty = (float) ($driver->km_empty ?? 0);
            $driver->total_km = (float) ($driver->total_km ?? 0);
            $driver->start_time = $shift?->start_time;
            $driver->end_time = $shift?->end_time;

            return $driver;
        });

        return new LengthAwarePaginator(
            $rows->forPage($this->getTablePage(), $this->getTableRecordsPerPage())->values(),
            $rows->count(),
            $this->getTableRecordsPerPage(),
            $this->getTablePage(),
        );
    }
}
