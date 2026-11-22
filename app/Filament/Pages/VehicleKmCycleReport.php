<?php

namespace App\Filament\Pages;

use App\Models\VehicleKmCycle;
use App\Services\Export\SimpleTableExcelExporter;
use App\Services\Km\VehicleKmReportService;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Km đã chốt của từng xe theo kỳ KM over (12:00 ngày 23), so với km tính từ mốc chuyến để đối chiếu.
 */
class VehicleKmCycleReport extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Vận hành';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'Chốt KM over';

    protected static ?string $title = 'Chốt KM over theo kỳ';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.vehicle-km-cycle-report';

    /** @var array<string, array{start: string, short: string, label: string}>|null */
    protected ?array $cycleOptionsCache = null;

    /** Tháng KM over đang xem (Y-m, tháng của mốc chốt cuối kỳ); trống thì lấy kỳ mới nhất. */
    #[Url]
    public ?string $month = null;

    /** @var array<int, array{km_run: ?float}>|null km tính từ mốc chuyến trong kỳ đang chọn, theo vehicle_id */
    protected ?array $checkpointKm = null;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => VehicleKmCycle::query()
                ->with('vehicle')
                ->where('cycle_start', $this->selectedCycleStart() ?? '1970-01-01 00:00:00'))
            ->defaultSort('km_run', 'desc')
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('index')->label('STT')->rowIndex()->alignCenter(),
                TextColumn::make('vehicle.plate_number')->label('Biển số')->searchable()->weight('bold'),
                TextColumn::make('vehicle_owner')->label('Chủ xe')->searchable(),
                TextColumn::make('start_km')->label('Km đầu kỳ')->alignEnd()->formatStateUsing(fn ($state): string => self::km($state)),
                TextColumn::make('end_km')->label('Km cuối kỳ')->alignEnd()->formatStateUsing(fn ($state): string => self::km($state)),
                TextColumn::make('km_run')->label('Km đã chạy')->alignEnd()->sortable()->weight('bold')
                    ->formatStateUsing(fn ($state): string => self::km($state)),
                TextColumn::make('over_km')->label('Km vượt')->alignEnd()->sortable()
                    ->color(fn ($state): string => (float) $state > 0 ? 'danger' : 'gray')
                    ->formatStateUsing(fn ($state, VehicleKmCycle $record): string => $record->tracks_km_over ? self::km($state) : '—'),
                TextColumn::make('over_fee')->label('Tiền vượt (đ)')->alignEnd()->sortable()
                    ->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'gray')
                    ->formatStateUsing(fn ($state, VehicleKmCycle $record): string => $record->tracks_km_over ? number_format((int) $state, 0, ',', '.') : '—'),
                TextColumn::make('checkpoint_km')->label('Km theo mốc chuyến')->alignEnd()
                    ->state(fn (VehicleKmCycle $record): ?float => $this->checkpointKmFor($record))
                    ->formatStateUsing(fn ($state): string => self::km($state))
                    ->placeholder('—'),
                TextColumn::make('difference')->label('Chênh lệch')->alignEnd()
                    ->tooltip('Km đã chạy (chốt) trừ km tính từ mốc chuyến trong kỳ')
                    ->state(fn (VehicleKmCycle $record): ?float => $this->differenceFor($record))
                    ->color(fn (?float $state): string => abs($state ?? 0) > 100 ? 'warning' : 'gray')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : ($state > 0 ? '+' : '').self::km($state))
                    ->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('tracks_km_over')
                    ->label('Xe tính KM over')
                    ->trueLabel('Chỉ xe tính KM over')
                    ->falseLabel('Chỉ xe không tính')
                    ->default(true),
            ])
            ->headerActions([
                Action::make('exportExcel')
                    ->label('Xuất Excel')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('success')
                    ->action(fn (): StreamedResponse => $this->exportExcel()),
            ])
            ->emptyStateHeading('Chưa có kỳ nào được chốt')
            ->emptyStateDescription('Km của mỗi kỳ được lưu lúc job chốt km chạy (12:00 ngày 23 hằng tháng).');
    }

    protected function getHeaderActions(): array
    {
        $options = $this->cycleOptions();
        $selected = $this->selectedMonth();

        return [
            ActionGroup::make(collect($options)->map(fn (array $option, string $month): Action => Action::make('month_'.str_replace('-', '_', $month))
                ->label($option['label'])
                ->color($month === $selected ? 'primary' : 'gray')
                ->icon($month === $selected ? 'heroicon-m-check' : null)
                ->action(function () use ($month): void {
                    $this->month = $month;
                    $this->checkpointKm = null;
                    $this->resetPage();
                }))->values()->all())
                ->label($selected !== null ? $options[$selected]['short'] : 'Chưa có kỳ chốt')
                ->icon('heroicon-o-calendar-days')
                ->color('primary')
                ->button(),
        ];
    }

    public function getSubheading(): ?string
    {
        $cycle = $this->selectedCycle();

        if ($cycle === null) {
            return null;
        }

        $totals = VehicleKmCycle::query()
            ->where('cycle_start', $cycle->cycle_start)
            ->where('tracks_km_over', true)
            ->selectRaw('count(*) as vehicles, sum(case when over_km > 0 then 1 else 0 end) as over_vehicles, coalesce(sum(over_km), 0) as over_km, coalesce(sum(over_fee), 0) as over_fee')
            ->first();

        return $this->cycleOptions()[$this->selectedMonth()]['short'].' · kỳ '.$this->periodText($cycle)
            .' · '.(int) $totals->vehicles.' xe tính KM over, '.(int) $totals->over_vehicles.' xe vượt '
            .self::km((float) $totals->over_km).' km · tiền vượt '.number_format((int) $totals->over_fee, 0, ',', '.').' đ';
    }

    public function exportExcel(): StreamedResponse
    {
        $cycle = $this->selectedCycle();
        $records = $this->getFilteredSortedTableQuery()->get();

        $rows = $records->values()->map(fn (VehicleKmCycle $record, int $index): array => [
            $index + 1,
            $record->vehicle?->plate_number,
            $record->vehicle_owner,
            (float) $record->start_km,
            (float) $record->end_km,
            (float) $record->km_run,
            $record->tracks_km_over ? (float) $record->over_km : null,
            $record->tracks_km_over ? (int) $record->over_fee : null,
            $this->checkpointKmFor($record),
            $this->differenceFor($record),
        ]);

        $period = $cycle ? $this->periodText($cycle) : '';

        return app(SimpleTableExcelExporter::class)->download(
            title: 'Chốt KM over '.mb_strtolower((string) ($this->selectedMonth() ? $this->cycleOptions()[$this->selectedMonth()]['short'] : '')).' (kỳ '.$period.')',
            headers: ['STT', 'Biển số', 'Chủ xe', 'Km đầu kỳ', 'Km cuối kỳ', 'Km đã chạy', 'Km vượt', 'Tiền vượt (đ)', 'Km theo mốc chuyến', 'Chênh lệch'],
            rows: $rows,
            filename: 'Chot-KM-over-thang-'.($cycle?->cycle_start->copy()->addMonthNoOverflow()->format('m-Y') ?? now()->format('m-Y')).'.xlsx',
        );
    }

    /**
     * Các tháng đã có kỳ chốt, mới nhất trước. Tháng của kỳ là tháng của mốc 12:00 ngày 23 kết thúc kỳ
     * (kỳ 23/10 → 23/11 là tháng 11).
     *
     * @return array<string, array{start: string, short: string, label: string}>
     */
    private function cycleOptions(): array
    {
        return $this->cycleOptionsCache ??= VehicleKmCycle::query()
            ->select('cycle_start')
            ->distinct()
            ->orderByDesc('cycle_start')
            ->pluck('cycle_start')
            ->map(fn ($start): Carbon => Carbon::parse($start))
            ->mapWithKeys(function (Carbon $start): array {
                $end = $start->copy()->addMonthNoOverflow();

                return [$end->format('Y-m') => [
                    'start' => $start->toDateTimeString(),
                    'short' => 'Tháng '.$end->format('m/Y'),
                    'label' => 'Tháng '.$end->format('m/Y').' ('.$start->format('d/m').' → '.$end->format('d/m').')',
                ]];
            })
            ->all();
    }

    private function selectedMonth(): ?string
    {
        $options = $this->cycleOptions();

        return array_key_exists((string) $this->month, $options) ? $this->month : array_key_first($options);
    }

    private function selectedCycleStart(): ?string
    {
        $month = $this->selectedMonth();

        return $month === null ? null : $this->cycleOptions()[$month]['start'];
    }

    /**
     * Một dòng đại diện của kỳ đang xem; cycle_end lấy mốc chốt muộn nhất trong kỳ.
     */
    private function selectedCycle(): ?VehicleKmCycle
    {
        $start = $this->selectedCycleStart();

        return $start === null ? null : VehicleKmCycle::query()->where('cycle_start', $start)->orderByDesc('cycle_end')->first();
    }

    /**
     * Kỳ đầy đủ 12:00 ngày 23 → 12:00 ngày 23 tháng sau; nếu chưa tới mốc chốt (bản lưu tạm) thì ghi số tạm tính đến lúc nào.
     */
    private function periodText(VehicleKmCycle $cycle): string
    {
        $nominalEnd = $cycle->cycle_start->copy()->addMonthNoOverflow();
        $text = $cycle->cycle_start->format('H:i d/m/Y').' → '.$nominalEnd->format('H:i d/m/Y');

        return $cycle->cycle_end->lt($nominalEnd)
            ? $text.' (số tạm tính đến '.$cycle->cycle_end->format('H:i d/m/Y').', chốt chính thức lúc '.$nominalEnd->format('H:i d/m/Y').')'
            : $text;
    }

    private function checkpointKmFor(VehicleKmCycle $record): ?float
    {
        if ($this->checkpointKm === null) {
            $cycle = $this->selectedCycle();
            $this->checkpointKm = $cycle === null ? [] : app(VehicleKmReportService::class)->summarize(
                VehicleKmCycle::query()->where('cycle_start', $cycle->cycle_start)->pluck('vehicle_id')->map(fn ($id): int => (int) $id)->all(),
                Carbon::parse($cycle->cycle_start),
                Carbon::parse($cycle->cycle_end)->subSecond(),
            );
        }

        return $this->checkpointKm[$record->vehicle_id]['km_run'] ?? null;
    }

    private function differenceFor(VehicleKmCycle $record): ?float
    {
        $checkpointKm = $this->checkpointKmFor($record);

        return $checkpointKm === null ? null : round((float) $record->km_run - $checkpointKm, 1);
    }

    private static function km(mixed $km): string
    {
        return $km === null ? '—' : number_format((float) $km, 1, ',', '.');
    }
}
