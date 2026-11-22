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
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
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

    private const KM_CYCLE_DAY = 23;

    private const KM_CYCLE_HOUR = 12;

    private const DATE_PRESETS = [
        'today' => 'Hôm nay',
        'yesterday' => 'Hôm qua',
        'last7days' => '7 ngày qua',
        'thisMonth' => 'Tháng này',
        'lastMonth' => 'Tháng trước',
    ];

    /** Đầu khoảng lọc, dạng Y-m-d H:i. */
    #[Url]
    public ?string $from = null;

    /** Cuối khoảng lọc, dạng Y-m-d H:i. */
    #[Url]
    public ?string $to = null;

    #[Url]
    public ?string $datePreset = 'thisMonth';

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
                SelectFilter::make('type')
                    ->label('Quản lý xe')
                    ->options(VehicleOwnerType::class)
                    ->default(VehicleOwnerType::Company->value),
            ])
            ->emptyStateHeading('Không có xe phù hợp bộ lọc');
    }

    public function mount(): void
    {
        if (blank($this->from) || blank($this->to)) {
            $this->datePreset = array_key_exists((string) $this->datePreset, self::DATE_PRESETS) ? $this->datePreset : 'thisMonth';
            [$from, $to] = self::presetRange($this->datePreset);
            $this->from = $from->format('Y-m-d H:i');
            $this->to = $to->format('Y-m-d H:i');
        }
    }

    public function getSubheading(): string
    {
        return $this->periodLabel();
    }

    protected function getHeaderActions(): array
    {
        $presetActions = collect(self::DATE_PRESETS)->map(fn (string $label, string $preset): Action => Action::make('preset_'.$preset)
            ->label($label)
            ->color(fn (): string => $this->datePreset === $preset ? 'primary' : 'gray')
            ->tooltip(in_array($preset, ['thisMonth', 'lastMonth'], true) ? 'Theo chu kỳ chốt km: 12:00 ngày 23 đến 12:00 ngày 23 tháng sau' : null)
            ->action(fn () => $this->applyPreset($preset)));

        return [
            ...$presetActions->values()->all(),
            Action::make('pickRange')
                ->label('Chọn ngày giờ')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color(fn (): string => $this->datePreset === null ? 'primary' : 'gray')
                ->modalWidth(Width::Medium)
                ->modalSubmitActionLabel('Xem')
                ->fillForm(fn (): array => ['from' => $this->from, 'to' => $this->to])
                ->schema([
                    DateTimePicker::make('from')->label('Từ')->native(false)->seconds(false)->displayFormat('H:i d/m/Y')->required(),
                    DateTimePicker::make('to')->label('Đến')->native(false)->seconds(false)->displayFormat('H:i d/m/Y')->afterOrEqual('from')->required(),
                ])
                ->action(fn (array $data) => $this->setPeriod(Carbon::parse($data['from']), Carbon::parse($data['to']), preset: null)),
            Action::make('exportExcel')
                ->label('Xuất Excel')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('success')
                ->action(fn (): StreamedResponse => $this->exportExcel()),
        ];
    }

    public function applyPreset(string $preset): void
    {
        $preset = array_key_exists($preset, self::DATE_PRESETS) ? $preset : 'thisMonth';
        [$from, $to] = self::presetRange($preset);

        $this->setPeriod($from, $to, $preset);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function presetRange(string $preset): array
    {
        $now = now();

        return match ($preset) {
            'today' => [$now->copy()->startOfDay(), $now],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'last7days' => [$now->copy()->subDays(6)->startOfDay(), $now],
            'lastMonth' => [self::kmCycleStart($now)->subMonthNoOverflow(), self::kmCycleStart($now)->subMinute()],
            default => [self::kmCycleStart($now), $now],
        };
    }

    /**
     * Đầu chu kỳ km đang chạy: 12:00 ngày 23, trùng giờ job vehicles:snapshot-monthly-km chốt Km đầu kỳ.
     */
    private static function kmCycleStart(Carbon $at): Carbon
    {
        $start = $at->copy()->setDay(self::KM_CYCLE_DAY)->setTime(self::KM_CYCLE_HOUR, 0);

        return $at->lt($start) ? $start->subMonthNoOverflow() : $start;
    }

    private function setPeriod(Carbon $from, Carbon $to, ?string $preset): void
    {
        $this->from = $from->format('Y-m-d H:i');
        $this->to = $to->format('Y-m-d H:i');
        $this->datePreset = $preset;
        $this->kmSummary = null;
        $this->resetPage();
    }

    /**
     * Khoảng thời gian đang lọc (tính cả phút cuối); đảo lại nếu đầu lớn hơn cuối.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function period(): array
    {
        $from = filled($this->from) ? Carbon::parse($this->from)->startOfMinute() : now()->startOfMonth();
        $to = filled($this->to) ? Carbon::parse($this->to)->endOfMinute() : now();

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
