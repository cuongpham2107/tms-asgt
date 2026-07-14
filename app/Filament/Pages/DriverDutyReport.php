<?php

namespace App\Filament\Pages;

use App\Enums\OnDutyLocation;
use App\Filament\Widgets\DriverDutySummaryWidget;
use App\Models\DriverShift;
use App\Models\User;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use UnitEnum;

class DriverDutyReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Báo cáo';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Ca trực lái xe';

    protected static ?string $title = 'Tổng hợp ca trực lái xe';

    protected string $view = 'filament.pages.driver-duty-report';

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
            ->query($this->buildQuery())
            ->columns([
                TextColumn::make('index')
                    ->label('STT')
                    ->rowIndex()
                    ->alignCenter(),
                TextColumn::make('station')
                    ->label('Điểm trực')
                    ->badge()
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => $state ? OnDutyLocation::from($state)->getLabel() : '—'),
                TextColumn::make('name')
                    ->label('Lịch lái xe')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('plate')
                    ->label('Xe chạy')
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
                    ->alignRight()
                    ->numeric(1)
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 1) : '—'),
                TextColumn::make('km_empty')
                    ->label('Số Km KH')
                    ->alignRight()
                    ->numeric(1)
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 1) : '—'),
                TextColumn::make('total_km')
                    ->label('Tổng KM')
                    ->alignRight()
                    ->numeric(1)
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 1) : '—'),
                TextColumn::make('start_time')
                    ->label('Bắt đầu ca')
                    ->dateTime('H:i d/m/Y')
                    ->alignCenter(),
                TextColumn::make('end_time')
                    ->label('Kết thúc ca')
                    ->dateTime('H:i d/m/Y')
                    ->alignCenter()
                    ->placeholder('Đang chạy'),
            ])
            ->defaultSort('station')
            ->paginated([50]);
    }

    protected function buildQuery(): Builder
    {
        $from = Carbon::today()->setHour(8);
        $to = Carbon::tomorrow()->setHour(8);

        // Get all active drivers
        return User::query()
            ->role('driver')
            ->where('is_active', true)
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
        // Get shifts and vehicles per driver, then enrich with plate/shift info
        $from = Carbon::today()->setHour(8);
        $to = Carbon::tomorrow()->setHour(8);

        $drivers = $query->get();

        $rows = $drivers->map(function (User $driver) use ($from, $to) {
            $shift = DriverShift::where('driver_id', $driver->id)
                ->where(function ($q) use ($from, $to) {
                    $q->whereBetween('start_time', [$from, $to])
                        ->orWhereNull('end_time');
                })
                ->orderByDesc('start_time')
                ->first();

            $vehicle = $shift?->trips()
                ->latest('started_at')
                ->first()?->vehicle;

            $swapTrip = $shift?->trips()
                ->whereNotNull('end_km')
                ->where('status', 'driver_swap')
                ->first();

            // Nếu có chuyến đảo lái, xe đã bàn giao
            $hasSwap = $swapTrip !== null;
            $plate = $vehicle?->plate_number;

            $driver->plate = $plate;
            $driver->swap_note = $hasSwap ? 'đảo lái' : null;
            $driver->shift_type = $shift?->shift_type;
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
