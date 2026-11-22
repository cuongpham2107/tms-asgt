<?php

namespace App\Filament\Pages;

use App\Enums\VehicleOwnerType;
use App\Models\Vehicle;
use App\Services\Export\SimpleTableExcelExporter;
use App\Services\Km\VehicleKmReportService;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Km đồng hồ của từng xe trong một khoảng ngày giờ (đầu kỳ, cuối kỳ, đã chạy) để kỹ thuật lên kế hoạch bảo dưỡng.
 */
class VehicleKmReport extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Bảo dưỡng';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Báo cáo km xe';

    protected static ?string $title = 'Báo cáo km xe theo thời gian';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.vehicle-km-report';

    /** @var array<int, array{start_km: ?float, start_at: ?Carbon, start_in_range: bool, end_km: ?float, end_at: ?Carbon, km_run: ?float, trip_count: int}>|null */
    protected ?array $kmSummary = null;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Vehicle::query())
            ->defaultSort('plate_number')
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('index')
                    ->label('STT')
                    ->rowIndex()
                    ->alignCenter(),
                TextColumn::make('plate_number')
                    ->label('Biển số')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('type')
                    ->label('Quản lý xe')
                    ->badge(),
                TextColumn::make('vehicle_type')
                    ->label('Kiểu xe')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('start_km')
                    ->label('Km đầu kỳ')
                    ->alignEnd()
                    ->state(fn (Vehicle $record): ?float => $this->summaryFor($record)['start_km'])
                    ->formatStateUsing(fn (?float $state): string => self::formatKm($state))
                    ->description(fn (Vehicle $record): ?string => $this->startDescription($record))
                    ->placeholder('—'),
                TextColumn::make('end_km')
                    ->label('Km cuối kỳ')
                    ->alignEnd()
                    ->state(fn (Vehicle $record): ?float => $this->summaryFor($record)['end_km'])
                    ->formatStateUsing(fn (?float $state): string => self::formatKm($state))
                    ->description(fn (Vehicle $record): ?string => $this->summaryFor($record)['end_at']?->format('H:i d/m/Y'))
                    ->placeholder('—'),
                TextColumn::make('km_run')
                    ->label('Km đã chạy')
                    ->alignEnd()
                    ->weight('bold')
                    ->color(fn (?float $state): string => ($state ?? 0) < 0 ? 'danger' : 'primary')
                    ->state(fn (Vehicle $record): ?float => $this->summaryFor($record)['km_run'])
                    ->formatStateUsing(fn (?float $state): string => self::formatKm($state))
                    ->placeholder('—'),
                TextColumn::make('trip_count')
                    ->label('Số chuyến')
                    ->alignCenter()
                    ->state(fn (Vehicle $record): int => $this->summaryFor($record)['trip_count']),
                TextColumn::make('current_mileage')
                    ->label('Số km hiện tại')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => self::formatKm($state !== null ? (float) $state : null))
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('period')
                    ->schema([
                        DateTimePicker::make('from')
                            ->label('Từ')
                            ->seconds(false)
                            ->default(now()->startOfMonth()),
                        DateTimePicker::make('to')
                            ->label('Đến')
                            ->seconds(false)
                            ->default(now()->startOfMinute()),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query): Builder => $query)
                    ->indicateUsing(fn (): string => $this->periodLabel()),
                SelectFilter::make('type')
                    ->label('Quản lý xe')
                    ->options(VehicleOwnerType::class)
                    ->default(VehicleOwnerType::Company->value),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(3)
            ->deferFilters(false)
            ->headerActions([
                Action::make('exportExcel')
                    ->label('Xuất Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->action(fn (): StreamedResponse => $this->exportExcel()),
            ])
            ->emptyStateHeading('Không có xe phù hợp bộ lọc');
    }

    /**
     * Khoảng thời gian đang lọc; đảo lại nếu người dùng chọn ngược.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function period(): array
    {
        $state = $this->tableFilters['period'] ?? [];
        $from = filled($state['from'] ?? null) ? Carbon::parse($state['from'])->startOfMinute() : now()->startOfMonth();
        $to = filled($state['to'] ?? null) ? Carbon::parse($state['to'])->endOfMinute() : now();

        return $from->lte($to) ? [$from, $to] : [$to->copy()->startOfMinute(), $from->copy()->endOfMinute()];
    }

    public function periodLabel(): string
    {
        [$from, $to] = $this->period();

        return 'Từ '.$from->format('H:i d/m/Y').' đến '.$to->format('H:i d/m/Y');
    }

    public function exportExcel(): StreamedResponse
    {
        [$from, $to] = $this->period();
        $vehicles = $this->getFilteredSortedTableQuery()->get();

        $rows = $vehicles->values()->map(function (Vehicle $vehicle, int $index): array {
            $summary = $this->summaryFor($vehicle);

            return [
                $index + 1,
                $vehicle->plate_number,
                $vehicle->type?->getLabel(),
                $vehicle->vehicle_type?->getLabel(),
                $summary['start_km'],
                $summary['start_at']?->format('d/m/Y H:i'),
                $summary['end_km'],
                $summary['end_at']?->format('d/m/Y H:i'),
                $summary['km_run'],
                $summary['trip_count'],
                $vehicle->current_mileage !== null ? (float) $vehicle->current_mileage : null,
                $summary['start_in_range'] ? 'Chưa có số km trước kỳ, đầu kỳ lấy số đầu tiên trong kỳ' : null,
            ];
        });

        return app(SimpleTableExcelExporter::class)->download(
            title: 'Báo cáo km xe '.mb_strtolower($this->periodLabel()),
            headers: [
                'STT', 'Biển số', 'Quản lý xe', 'Kiểu xe', 'Km đầu kỳ', 'Thời điểm km đầu kỳ', 'Km cuối kỳ',
                'Thời điểm km cuối kỳ', 'Km đã chạy', 'Số chuyến', 'Số km hiện tại', 'Ghi chú',
            ],
            rows: $rows,
            filename: 'Bao-cao-km-xe-'.$from->format('Ymd-Hi').'_'.$to->format('Ymd-Hi').'.xlsx',
        );
    }

    /**
     * @return array{start_km: ?float, start_at: ?Carbon, start_in_range: bool, end_km: ?float, end_at: ?Carbon, km_run: ?float, trip_count: int}
     */
    private function summaryFor(Vehicle $vehicle): array
    {
        if ($this->kmSummary === null) {
            [$from, $to] = $this->period();
            $vehicleIds = $this->getFilteredTableQuery()->pluck('vehicles.id')->map(fn ($id): int => (int) $id)->all();
            $this->kmSummary = app(VehicleKmReportService::class)->summarize($vehicleIds, $from, $to);
        }

        return $this->kmSummary[$vehicle->id] ?? [
            'start_km' => null,
            'start_at' => null,
            'start_in_range' => false,
            'end_km' => null,
            'end_at' => null,
            'km_run' => null,
            'trip_count' => 0,
        ];
    }

    private function startDescription(Vehicle $vehicle): ?string
    {
        $summary = $this->summaryFor($vehicle);

        if ($summary['start_at'] === null) {
            return null;
        }

        return $summary['start_at']->format('H:i d/m/Y').($summary['start_in_range'] ? ' (số đầu tiên trong kỳ)' : '');
    }

    private static function formatKm(?float $km): string
    {
        return $km === null ? '—' : number_format($km, 1, ',', '.');
    }
}
