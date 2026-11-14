<?php

namespace App\Filament\Pages;

use App\Enums\OnDutyLocation;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Filament\Forms\Components\PillFilter;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use BackedEnum;
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

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Ca trực lái xe';

    protected static ?string $title = 'Tổng hợp ca trực lái xe';

    protected string $view = 'filament.pages.driver-duty-report';

    protected static ?int $navigationSort = 4;

    #[Url]
    public string $activeStationFilter = 'all';

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
                    ->formatStateUsing(fn ($state) => match ($state) {
                        ShiftType::Full => 'X',
                        ShiftType::MorningHalf => 'X/2',
                        ShiftType::NightHalf => 'Y/2',
                        default => '—',
                    }),
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
                    ->badge(fn ($record): bool => ! $record->end_time && $record->has_active_shift)
                    ->color(fn ($record): string => $record->is_stale_shift ? 'warning' : 'success')
                    ->formatStateUsing(function ($state, $record) {
                        if ($state) {
                            return $state->format('H:i d/m/Y');
                        }

                        if ($record->is_stale_shift) {
                            return 'Chưa thoát ca cũ';
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

    public function filterStation(string $value): void
    {
        $this->activeStationFilter = $value;

        $this->resetPage();
    }

    protected function getSummaryData(): array
    {
        $drivers = User::role('driver')->where('is_active', true)->get();
        $openShifts = DriverShift::query()
            ->whereNull('end_time')
            ->whereIn('driver_id', $drivers->modelKeys())
            ->get()
            ->unique('driver_id')
            ->keyBy('driver_id');

        $stations = [];
        $grandTotal = 0;
        $grandWorkingTtl = 0;
        $grandFull = 0;
        $grandMorningHalf = 0;
        $grandNightHalf = 0;
        $grandOff = 0;

        foreach (OnDutyLocation::cases() as $station) {
            $stationDrivers = $drivers->filter(fn ($d) => $d->station === $station);
            $total = $stationDrivers->count();
            if ($total === 0) {
                continue;
            }

            $full = 0;
            $morningHalf = 0;
            $nightHalf = 0;
            $workingDriverIds = [];

            foreach ($stationDrivers as $driver) {
                $shift = $openShifts->get($driver->id);

                if ($shift) {
                    $workingDriverIds[] = $driver->id;

                    match ($shift->shift_type) {
                        ShiftType::Full => $full++,
                        ShiftType::MorningHalf => $morningHalf++,
                        ShiftType::NightHalf => $nightHalf++,
                    };
                }
            }

            $workingTtl = $full + $morningHalf + $nightHalf;
            $workingDrivers = count(array_unique($workingDriverIds));
            $off = $total - $workingDrivers;

            $grandTotal += $total;
            $grandWorkingTtl += $workingTtl;
            $grandFull += $full;
            $grandMorningHalf += $morningHalf;
            $grandNightHalf += $nightHalf;
            $grandOff += $off;

            $stations[] = [
                'label' => $station->getLabel(),
                'color' => $station->getColor(),
                'total' => $total,
                'working_ttl' => $workingTtl,
                'full' => $full,
                'morning_half' => $morningHalf,
                'night_half' => $nightHalf,
                'off' => $off,
            ];
        }

        return [
            'stations' => $stations,
            'grand' => [
                'total' => $grandTotal,
                'working_ttl' => $grandWorkingTtl,
                'full' => $grandFull,
                'morning_half' => $grandMorningHalf,
                'night_half' => $grandNightHalf,
                'off' => $grandOff,
            ],
        ];
    }

    protected function buildQuery(): Builder
    {
        return User::query()
            ->role('driver')
            ->whereHas('driverShifts', fn ($q) => $q->whereNull('end_time'))
            ->where('is_active', true)
            ->when($this->activeStationFilter !== 'all', fn ($q) => $q->where('station', $this->activeStationFilter))
            ->select('users.*');
    }

    protected function paginateTableQuery(Builder $query): Paginator
    {
        [$shiftStart, $shiftEnd] = VehicleDispatchBoard::getShiftWindow();
        $runningValues = array_map(fn ($s) => $s->value, VehicleDispatchBoard::runningTripStatuses());

        $drivers = $query->get();

        $rows = $drivers->map(function (User $driver) use ($shiftStart, $shiftEnd, $runningValues) {
            $shift = DriverShift::where('driver_id', $driver->id)
                ->whereNull('end_time')
                ->orderByDesc('start_time')
                ->first();

            $windowTrips = Trip::query()
                ->where('driver_id', $driver->id)
                ->whereNotIn('status', ['pending', 'cancelled'])
                ->where(function (Builder $q) use ($shiftStart, $shiftEnd, $runningValues) {
                    $q->whereIn('status', $runningValues)
                        ->orWhereBetween('started_at', [$shiftStart, $shiftEnd])
                        ->orWhereBetween('completed_at', [$shiftStart, $shiftEnd]);
                })
                ->with('vehicle')
                ->orderByDesc('started_at')
                ->get();

            $isShiftInCurrentWindow = $shift?->start_time !== null && $shift->start_time->between($shiftStart, $shiftEnd);

            $vehicle = Vehicle::where('current_driver_id', $driver->id)->first()
                ?? $windowTrips->first()?->vehicle;

            $hasSwap = $windowTrips->contains(fn ($t): bool => $t->end_km !== null
                && ($t->status === TripStatus::DriverSwap || $t->status === 'driver_swap'));
            $plate = $vehicle?->plate_number;

            $driver->station_display = $driver->station?->getLabel() ?? '—';
            $driver->plate = $plate;
            $driver->swap_note = $hasSwap ? 'đảo lái' : null;
            $driver->shift_type = $shift?->shift_type;
            $driver->has_active_shift = $shift !== null;
            $driver->is_stale_shift = $shift !== null && ! $isShiftInCurrentWindow;
            $driver->trip_count = $windowTrips->count();
            $driver->km_loaded = $windowTrips->isNotEmpty()
                ? (float) $windowTrips->sum('total_km_loaded')
                : ($isShiftInCurrentWindow ? (float) ($shift?->total_km_loaded ?? 0) : 0.0);
            $driver->km_empty = $windowTrips->isNotEmpty()
                ? (float) $windowTrips->sum('total_km_empty')
                : ($isShiftInCurrentWindow ? (float) ($shift?->total_km_empty ?? 0) : 0.0);
            $driver->total_km = $windowTrips->isNotEmpty()
                ? (float) $windowTrips->sum('total_km')
                : ($isShiftInCurrentWindow ? (float) ($shift?->total_km ?? 0) : 0.0);
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
