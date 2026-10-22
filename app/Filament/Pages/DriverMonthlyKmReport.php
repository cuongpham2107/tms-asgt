<?php

namespace App\Filament\Pages;

use App\Services\DriverMonthlyKmReportService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

class DriverMonthlyKmReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Vận hành';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Thống kê tài xế';

    protected static ?string $title = 'Tổng hợp chuyến & km tài xế';

    protected static ?int $navigationSort = 5;

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

    public function content(Schema $schema): Schema
    {
        $totals = $this->totals();

        return $schema->components([
            Section::make()
                ->schema([
                    Stat::make('Tài xế có chạy', $totals['active_drivers'])
                        ->description('trên '.$totals['drivers'].' tài xế')
                        ->descriptionIcon(Heroicon::OutlinedUsers)
                        ->color('primary'),
                    Stat::make('Tổng chuyến', number_format($totals['trips']))
                        ->description('chuyến đã chốt trong kỳ')
                        ->descriptionIcon(Heroicon::OutlinedTruck)
                        ->color('gray'),
                    Stat::make('Km có hàng', number_format($totals['km_loaded'], 1))
                        ->description(self::loadedPercent($totals['km_loaded'], $totals['km_total']).'% tổng km')
                        ->descriptionIcon(Heroicon::OutlinedCube)
                        ->color('success'),
                    Stat::make('Km không hàng', number_format($totals['km_empty'], 1))
                        ->description('tổng '.number_format($totals['km_total'], 1).' km')
                        ->descriptionIcon(Heroicon::OutlinedArrowUturnLeft)
                        ->color('warning'),
                ])
                ->columns(['md' => 2, 'xl' => 4])
                ->contained(false)
                ->gridContainer(),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage): LengthAwarePaginator => $this->paginateRows($search, $sortColumn, $sortDirection, $page, $recordsPerPage))
            ->heading('Theo tài xế')
            ->description('Bấm vào một tài xế để xem danh sách chuyến.')
            ->columns([
                TextColumn::make('index')
                    ->label('STT')
                    ->rowIndex(),
                TextColumn::make('driver_name')
                    ->label('Tài xế')
                    ->description(fn (array $record): ?string => $record['employee_code'])
                    ->weight(FontWeight::SemiBold)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('station')
                    ->label('Điểm trực')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('trip_count')
                    ->label('Số chuyến')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('km_loaded')
                    ->label('Km có hàng')
                    ->numeric(1)
                    ->color('success')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('km_empty')
                    ->label('Km không hàng')
                    ->numeric(1)
                    ->color('warning')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('km_total')
                    ->label('Tổng km')
                    ->numeric(1)
                    ->weight(FontWeight::Bold)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('loaded_percent')
                    ->label('% có hàng')
                    ->suffix('%')
                    ->badge()
                    ->color('gray')
                    ->alignEnd()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('trips')
                    ->label('Chuyến')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->color('gray')
                    ->slideOver()
                    ->modalHeading(fn (array $record): string => $record['driver_name'])
                    ->modalDescription(fn (array $record): string => $record['trip_count'].' chuyến · '.number_format($record['km_total'], 1).' km · '.$this->periodLabel())
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
                    ->schema(fn (array $record): array => [
                        RepeatableEntry::make('trips')
                            ->hiddenLabel()
                            ->state($this->tripLines()->where('driver_id', $record['driver_id'])->values()->all())
                            ->placeholder('Chưa có chuyến nào được chốt trong kỳ.')
                            ->table([
                                TableColumn::make('Ngày'),
                                TableColumn::make('Mã chuyến'),
                                TableColumn::make('Xe'),
                                TableColumn::make('Có hàng'),
                                TableColumn::make('Không hàng'),
                                TableColumn::make('Tổng'),
                            ])
                            ->schema([
                                TextEntry::make('started_at')->dateTime('d/m H:i'),
                                TextEntry::make('trip_code')->weight(FontWeight::Medium),
                                TextEntry::make('plate')->placeholder('—'),
                                TextEntry::make('km_loaded')->numeric(1)->color('success'),
                                TextEntry::make('km_empty')->numeric(1)->color('warning'),
                                TextEntry::make('km_total')->numeric(1)->weight(FontWeight::Bold),
                            ]),
                    ]),
            ])
            ->recordAction('trips')
            ->defaultSort('km_total', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50);
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
     * @return Collection<int, array{trip_id: int, trip_code: string, plate: ?string, started_at: ?Carbon, driver_id: int, km_total: float, km_loaded: float, km_empty: float}>
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
    public function driverRows(): Collection
    {
        return app(DriverMonthlyKmReportService::class)
            ->summarize($this->tripLines())
            ->map(fn (array $row): array => [...$row, 'loaded_percent' => self::loadedPercent($row['km_loaded'], $row['km_total'])])
            ->keyBy('driver_id');
    }

    /**
     * @return array{drivers: int, active_drivers: int, trips: int, km_total: float, km_loaded: float, km_empty: float}
     */
    public function totals(): array
    {
        $lines = $this->tripLines();

        return [
            'drivers' => $this->driverRows()->count(),
            'active_drivers' => $this->driverRows()->where('trip_count', '>', 0)->count(),
            'trips' => $lines->pluck('trip_id')->unique()->count(),
            'km_total' => round($lines->sum('km_total'), 1),
            'km_loaded' => round($lines->sum('km_loaded'), 1),
            'km_empty' => round($lines->sum('km_empty'), 1),
        ];
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
        unset($this->tripLines, $this->driverRows);
        $this->resetTable();
    }

    private function paginateRows(?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage): LengthAwarePaginator
    {
        $needle = mb_strtolower(trim((string) $search));

        $rows = $this->driverRows()
            ->when($needle !== '', fn (Collection $rows): Collection => $rows->filter(
                fn (array $row): bool => str_contains(mb_strtolower($row['driver_name'].' '.$row['employee_code']), $needle),
            ))
            ->when(filled($sortColumn), fn (Collection $rows): Collection => $rows->sortBy(
                [[$sortColumn, $sortDirection === 'asc' ? 'asc' : 'desc'], ['driver_name', 'asc']],
            ));

        return new LengthAwarePaginator(
            $rows->forPage($page, $recordsPerPage),
            total: $rows->count(),
            perPage: $recordsPerPage,
            currentPage: $page,
        );
    }
}
