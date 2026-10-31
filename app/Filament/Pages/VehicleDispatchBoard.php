<?php

namespace App\Filament\Pages;

use App\Enums\CheckpointType;
use App\Enums\EupCarStatus;
use App\Enums\GeoCheckStatus;
use App\Enums\OnDutyLocation;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Filament\Resources\Trips\Actions\ReassignDriverAction;
use App\Filament\Resources\Trips\Actions\ResolveKmReportAction;
use App\Filament\Traits\HasVehicleDispatchRows;
use App\Models\DriverShift;
use App\Models\Location;
use App\Models\Order;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\ShiftScheduleService;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use UnitEnum;

class VehicleDispatchBoard extends Page implements HasTable
{
    use HasVehicleDispatchRows;
    use InteractsWithTable;

    /** Nền ô cảnh báo theo mức của getAlertLevel(). */
    public const ALERT_CELL_CLASSES = [
        'light' => 'alert-cell bg-yellow-100 text-yellow-900 font-bold select-none',
        'orange' => 'alert-cell bg-orange-400 text-white font-bold select-none',
        'red' => 'alert-cell bg-rose-500 text-white font-bold select-none',
    ];

    public const IDLE_ENGINE_ALERT_MINUTES = 20;

    /**
     * Chu kỳ tự làm mới bảng (giây). Một bộ đếm Alpine vừa hiển thị đếm ngược vừa gọi làm mới.
     */
    public const REFRESH_SECONDS = 20;

    protected static string|UnitEnum|null $navigationGroup = 'Vận hành';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Kiểm soát xe';

    protected static ?string $title = 'Kiểm soát tình trạng xe';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.vehicle-dispatch-board';

    #[Url]
    public string $activeOwnerType = 'company'; // company only

    #[Url]
    public string $activeStation = 'all'; // all, BN, NBA, TN, OTHER

    #[Url]
    public string $activeMovementStatus = 'all'; // all, running, idling, stopped

    #[Url]
    public ?string $searchQuery = null;

    #[Url]
    public string $sortBy = 'status'; // status, plate_number

    #[Url]
    public string $sortDirection = 'desc'; // asc, desc

    public ?int $selectedVehicleIdForTrip = null;

    public ?int $selectedTripIdForTimeline = null;

    public ?int $selectedVehicleIdForGps = null;

    /** Tăng sau khi dữ liệu đổi (Echo/refresh) để widget React vẽ lại. */
    public int $dataVersion = 0;

    /**
     * Lắng nghe broadcast event realtime qua Laravel Reverb / Echo.
     * Khi chuyến xe thay đổi hoặc vừa đồng bộ GPS xong, tự động làm mới bảng kiểm soát xe.
     * Không có Reverb thì bảng vẫn tự tải lại theo poll().
     *
     * @param  array<string, mixed>|null  $event
     */
    #[On('echo:trips,TripUpdated')]
    #[On('echo:trips,.TripUpdated')]
    #[On('echo:vehicles,.VehiclesSynced')]
    public function onTripUpdated(?array $event = null): void
    {
        if (filled($this->mountedActions ?? [])) {
            return;
        }

        $this->flushCachedTableRecords();
        $this->dataVersion++;
    }

    public function openTripModal(int $vehicleId): void
    {
        $this->selectedVehicleIdForTrip = $vehicleId;
        $this->dispatch('open-modal', id: 'vehicle-trip-modal');
    }

    public function openGpsModal(int $vehicleId): void
    {
        $this->selectedVehicleIdForGps = $vehicleId;
        $this->dispatch('open-modal', id: 'vehicle-gps-modal');
    }

    public function openTimelineModal(int $tripId): void
    {
        $this->selectedTripIdForTimeline = $tripId;
        $this->dispatch('open-modal', id: 'vehicle-timeline-modal');
    }

    public function getSelectedTripForTimeline(): ?Trip
    {
        if (! $this->selectedTripIdForTimeline) {
            return null;
        }

        return Trip::find($this->selectedTripIdForTimeline);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function getShiftWindow(): array
    {
        return ShiftScheduleService::getShiftWindow();
    }

    public static function getShiftDate(): Carbon
    {
        return ShiftScheduleService::getShiftDate();
    }

    /**
     * Danh sách trạng thái chuyến xe đang chạy thực tế (từ lúc bắt đầu đến trước khi hoàn thành).
     * Loại bỏ trạng thái Chờ chạy (Pending) và Đã huỷ (Cancelled).
     *
     * @return TripStatus[]
     */
    public static function runningTripStatuses(): array
    {
        return [
            TripStatus::Started,
            TripStatus::ArrivedPickup,
            TripStatus::Delivering,
            TripStatus::ArrivedDelivery,
            TripStatus::Delivered,
            TripStatus::DriverSwap,
            TripStatus::ReturnTrip,
        ];
    }

    /**
     * Thứ tự ưu tiên trạng thái chuyến xe hiển thị:
     * 1. Đang thực hiện (Started -> ReturnTrip)
     * 2. Chờ chạy (Pending)
     * 3. Hoàn thành (Completed)
     * 4. Khác (Cancelled)
     */
    public static function getTripPriority(Trip|string $status): int
    {
        $statusVal = $status instanceof TripStatus
            ? $status->value
            : ($status instanceof Trip
                ? ($status->status instanceof TripStatus ? $status->status->value : (string) $status->status)
                : (string) $status);

        $runningValues = array_map(fn (TripStatus $s) => $s->value, self::runningTripStatuses());

        if (in_array($statusVal, $runningValues, true)) {
            return 1;
        }

        if ($statusVal === TripStatus::Pending->value) {
            return 2;
        }

        if ($statusVal === TripStatus::Completed->value) {
            return 3;
        }

        return 4;
    }

    public function getSelectedVehicleForTrip(): ?Vehicle
    {
        if (! $this->selectedVehicleIdForTrip) {
            return null;
        }

        [$shiftStart, $shiftEnd] = self::getShiftWindow();
        $runningValues = array_map(fn (TripStatus $s) => $s->value, self::runningTripStatuses());
        $runningSql = "'".implode("','", $runningValues)."'";

        return Vehicle::with([
            'driver',
            'evenDriver',
            'oddDriver',
            'trips' => function ($q) use ($shiftStart, $shiftEnd, $runningValues, $runningSql) {
                $q->where(function (Builder $sub) use ($shiftStart, $shiftEnd, $runningValues) {
                    $sub->whereIn('status', $runningValues)
                        ->orWhere('status', TripStatus::Pending->value)
                        ->orWhere(function (Builder $completedSub) use ($shiftStart, $shiftEnd) {
                            $completedSub->where('status', TripStatus::Completed->value)
                                ->where(function (Builder $dateQ) use ($shiftStart, $shiftEnd) {
                                    $dateQ->whereBetween('completed_at', [$shiftStart, $shiftEnd])
                                        ->orWhereBetween('started_at', [$shiftStart, $shiftEnd]);
                                });
                        });
                })
                    ->with([
                        'driver',
                        'startLocation',
                        'endLocation',
                        'checkpoints',
                        'latestPendingKmReport',
                        'orders.pickupLocation',
                        'orders.deliveryPoints.location',
                        'orders.customer',
                    ])
                    ->orderByRaw("CASE 
                        WHEN status IN ({$runningSql}) THEN 1 
                        WHEN status = '".TripStatus::Pending->value."' THEN 2 
                        WHEN status = '".TripStatus::Completed->value."' THEN 3 
                        ELSE 4 
                    END ASC")
                    ->orderByRaw('COALESCE(started_at, created_at) ASC')
                    ->orderBy('id', 'asc');
            },
        ])->find($this->selectedVehicleIdForTrip);
    }

    public function getTodayTripsForVehicle(Vehicle $vehicle): Collection
    {
        [$shiftStart, $shiftEnd] = self::getShiftWindow();
        $runningValues = array_map(fn (TripStatus $s) => $s->value, self::runningTripStatuses());
        $runningSql = "'".implode("','", $runningValues)."'";

        return Trip::query()
            ->where('vehicle_id', $vehicle->id)
            ->where(function (Builder $q) use ($shiftStart, $shiftEnd, $runningValues) {
                $q->whereIn('status', $runningValues)
                    ->orWhere('status', TripStatus::Pending->value)
                    ->orWhere(function (Builder $sub) use ($shiftStart, $shiftEnd) {
                        $sub->where('status', TripStatus::Completed->value)
                            ->where(function (Builder $dateQ) use ($shiftStart, $shiftEnd) {
                                $dateQ->whereBetween('completed_at', [$shiftStart, $shiftEnd])
                                    ->orWhereBetween('started_at', [$shiftStart, $shiftEnd]);
                            });
                    });
            })
            ->with([
                'driver',
                'startLocation',
                'endLocation',
                'checkpoints',
                'latestPendingKmReport',
                'orders.pickupLocation',
                'orders.deliveryPoints.location',
                'orders.customer',
            ])
            ->orderByRaw("CASE 
                WHEN status IN ({$runningSql}) THEN 1 
                WHEN status = '".TripStatus::Pending->value."' THEN 2 
                WHEN status = '".TripStatus::Completed->value."' THEN 3 
                ELSE 4 
            END ASC")
            ->orderByRaw('COALESCE(started_at, created_at) ASC')
            ->orderBy('id', 'asc')
            ->get();
    }

    public function getTodayTripsForSelectedVehicle(): Collection
    {
        $vehicle = $this->getSelectedVehicleForTrip();
        if (! $vehicle) {
            return collect();
        }

        $trips = $vehicle->trips ?? $this->getTodayTripsForVehicle($vehicle);

        return $trips->sort(function (Trip $a, Trip $b) {
            $pA = self::getTripPriority($a);
            $pB = self::getTripPriority($b);

            if ($pA !== $pB) {
                return $pA <=> $pB;
            }

            $timeA = $a->started_at ?? $a->created_at;
            $timeB = $b->started_at ?? $b->created_at;

            if ($timeA && $timeB && ! $timeA->equalTo($timeB)) {
                return $timeA->lessThan($timeB) ? -1 : 1;
            }

            return $a->id <=> $b->id;
        })->values();
    }

    public function getSelectedVehicleForGps(): ?Vehicle
    {
        if (! $this->selectedVehicleIdForGps) {
            return null;
        }

        return Vehicle::find($this->selectedVehicleIdForGps);
    }

    public function sortTable(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function filterOwnerType(string $value): void
    {
        $this->activeOwnerType = $value;
        $this->resetPage();
    }

    public function filterStation(string $value): void
    {
        $this->activeStation = $value;
        $this->resetPage();
    }

    public function filterMovementStatus(string $value): void
    {
        $this->activeMovementStatus = $value;
        $this->resetPage();
    }

    public function getSummaryStats(): array
    {
        $vehicles = Vehicle::query()
            ->where('is_active', true)
            ->get();

        $total = $vehicles->count();
        $running = $vehicles->filter(fn (Vehicle $v) => (float) $v->gps_speed > 5)->count();
        $idling = $vehicles->filter(function (Vehicle $v) {
            $isRecent = false;
            try {
                $isRecent = $v->last_gps_update instanceof \DateTimeInterface
                    && $v->last_gps_update->diffInMinutes(now()) <= 15;
            } catch (\Throwable) {
                $isRecent = false;
            }

            return ((float) $v->gps_speed <= 5 || $v->gps_speed === null)
                && $isRecent
                && $v->status === VehicleStatus::On;
        })->count();
        $stopped = max(0, $total - $running - $idling);

        $activeTripVehicleIds = Trip::query()
            ->whereIn('status', array_map(fn ($s) => $s->value, TripStatus::activeStatuses()))
            ->whereNotNull('vehicle_id')
            ->distinct()
            ->pluck('vehicle_id')
            ->all();

        $withTrip = count($activeTripVehicleIds);
        $available = max(0, $total - $withTrip);

        return [
            'total' => $total,
            'running' => $running,
            'idling' => $idling,
            'stopped' => $stopped,
            'with_trip' => $withTrip,
            'available' => $available,
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->buildTableQuery())
            ->poll('20s')
            ->defaultSort('plate_number', 'asc')
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->groups([
                Group::make('driver_station')
                    ->label('Địa điểm')
                    ->getTitleFromRecordUsing(fn (Vehicle $record): string => match ($record->driver_station ?? '') {
                        'BN' => 'Bắc Ninh (BN)',
                        'NBA' => 'Nội Bài (NBA)',
                        'TN' => 'Thái Nguyên (TN)',
                        default => 'Chưa phân điểm / Khác',
                    })
                    ->collapsible(),
            ])
            ->defaultGroup('driver_station')
            ->columns([
                // 1. BSX
                TextColumn::make('plate_number')
                    ->label('BSX')
                    ->weight(FontWeight::Bold)
                    ->searchable()
                    ->html()
                    ->state(fn (Vehicle $record): string => self::renderBsx($record))
                    ->action(fn (Vehicle $record, VehicleDispatchBoard $livewire) => $livewire->openTripModal($record->id)),

                // 2. Loại đơn của đơn hiện tại (HHHK / Hàng ngoài)
                TextColumn::make('order_type')
                    ->label('Loại đơn')
                    ->badge()
                    ->alignCenter()
                    ->placeholder('—')
                    ->color(fn (Vehicle $record): ?string => self::getOrderType($record)?->getColor())
                    ->state(fn (Vehicle $record): ?string => self::getOrderType($record)?->getLabel()),

                // 3. Trạng thái chuyến / xe
                TextColumn::make('trip_status')
                    ->label('Trạng thái')
                    ->badge()
                    ->alignCenter()
                    ->color(fn (Vehicle $record): string => self::getStatusBadgeColor($record))
                    ->state(fn (Vehicle $record): string => self::getTripStatusLabel($record))
                    ->icon(fn (Vehicle $record): ?string => match (true) {
                        $record->active_trip?->isPaused() => 'heroicon-m-exclamation-triangle',
                        $record->active_trip?->status === TripStatus::DriverSwap => 'heroicon-m-arrow-path',
                        default => null,
                    })
                    ->tooltip(fn (Vehicle $record): ?string => match (true) {
                        $record->active_trip?->isPaused() => 'Bấm để xử lý báo sai Km',
                        $record->active_trip?->status === TripStatus::DriverSwap => 'Bấm để gán lại tài xế (Đảo lái)',
                        default => null,
                    })
                    ->disabledClick(fn (Vehicle $record): bool => ! self::canClickTripStatus($record))
                    ->action(function (Vehicle $record, HasTable $livewire): void {
                        $trip = $record->active_trip;
                        if (! $trip) {
                            return;
                        }

                        if ($trip->isPaused()) {
                            $livewire->mountTableAction('resolve_km_report', (string) $record->getKey());
                        } elseif ($trip->status === TripStatus::DriverSwap) {
                            $livewire->mountTableAction('reassign_driver', (string) $record->getKey());
                        }
                    }),

                // 4. Điểm đi
                TextColumn::make('pickup_location')
                    ->label('Điểm đi')
                    ->alignCenter()
                    ->state(fn (Vehicle $record): string => self::getPickupPoint($record)),

                // 5. Điểm đến
                TextColumn::make('delivery_destination')
                    ->label('Điểm đến')
                    ->wrap()
                    ->state(fn (Vehicle $record): string => self::getDeliveryPoint($record)),

                // 6. Xe chờ
                TextColumn::make('xe_cho')
                    ->label('Xe chờ')
                    ->alignCenter()
                    ->html()
                    ->tooltip(fn (Vehicle $record): string => self::getXeChoTooltip($record))
                    ->state(fn (Vehicle $record): string => self::getXeCho($record)),

                // 7. Vị trí thực tế trên GPS
                TextColumn::make('gps_address')
                    ->label('Vị trí thực tế trên GPS')
                    ->wrap()
                    ->tooltip(fn (Vehicle $record): ?string => $record->gps_address)
                    ->state(fn (Vehicle $record): string => self::getGpsLocation($record)),

                // 8. Tốc độ
                TextColumn::make('gps_speed')
                    ->label('Tốc độ')
                    ->alignCenter()
                    ->state(fn (Vehicle $record): string => self::getSpeed($record)),

                // 9. Status (Động cơ / GPS)
                TextColumn::make('movement_status')
                    ->label('Status')
                    ->badge()
                    ->alignCenter()
                    ->color(fn (Vehicle $record): string => self::getMovementStatusColor($record))
                    ->state(fn (Vehicle $record): string => self::getMovementStatus($record)),

                // 10. Chuyến
                TextColumn::make('trip_count')
                    ->label('Chuyến')
                    ->alignCenter()
                    ->weight(FontWeight::Bold)
                    ->state(fn (Vehicle $record): int => self::getTodayTripsCount($record))
                    ->action(fn (Vehicle $record, VehicleDispatchBoard $livewire) => $livewire->openTripModal($record->id)),

                // 11. Đơn chờ
                TextColumn::make('waiting_orders_count')
                    ->label('Đơn chờ')
                    ->alignCenter()
                    ->weight(FontWeight::Bold)
                    ->color(fn (Vehicle $record): string => self::getWaitingOrdersCount($record) > 0 ? 'purple' : 'gray')
                    ->state(fn (Vehicle $record): int => self::getWaitingOrdersCount($record))
                    ->action(fn (Vehicle $record, VehicleDispatchBoard $livewire) => $livewire->openTripModal($record->id)),

                // 12. KM over
                TextColumn::make('km_over')
                    ->label('KM over')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (Vehicle $record): string => match (self::getKmOverData($record)['status']) {
                        'danger' => 'danger',
                        'warning', 'notice' => 'warning',
                        default => 'gray',
                    })
                    ->tooltip(fn (Vehicle $record): string => self::getKmOverData($record)['tooltip'])
                    ->state(fn (Vehicle $record): string => self::getKmOver($record)),

                // Kiểm tra vị trí các mốc trong ca
                TextColumn::make('geo_check')
                    ->label('Vị trí mốc')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (Vehicle $record): string => match (true) {
                        self::getGeoCheckData($record)['wrong'] > 0 => 'danger',
                        self::getGeoCheckData($record)['checked'] > 0 => 'success',
                        default => 'gray',
                    })
                    ->tooltip(fn (Vehicle $record): string => self::getGeoCheckData($record)['tooltip'])
                    ->state(fn (Vehicle $record): string => self::getGeoCheckData($record)['label'])
                    ->action(fn (Vehicle $record, VehicleDispatchBoard $livewire) => $livewire->openTripModal($record->id)),

                // 12. Tên lái xe
                TextColumn::make('driver_name')
                    ->label('Tên lái xe')
                    ->searchable(query: function (Builder $query, string $search) {
                        $query->whereHas('driver', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('evenDriver', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('oddDriver', fn ($q) => $q->where('name', 'like', "%{$search}%"));
                    })
                    ->state(fn (Vehicle $record): string => self::getDriverName($record)),

                // 13. Trực
                TextColumn::make('driver_station_badge')
                    ->label('Trực')
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($state): string => match ($state) {
                        'BN' => 'warning',
                        'NBA' => 'success',
                        'TN' => 'info',
                        default => 'gray',
                    })
                    ->state(fn (Vehicle $record): string => self::getDriverStation($record)),

                // 14. Ca
                TextColumn::make('shift_label')
                    ->label('Ca')
                    ->alignCenter()
                    ->badge()
                    ->color('gray')
                    ->state(fn (Vehicle $record): string => self::getShiftLabel($record)),
            ])
            ->recordActions([
                ReassignDriverAction::make('reassign_driver'),
                ResolveKmReportAction::make('resolve_km_report'),

                Action::make('view_trip')
                    ->label('Chuyến xe')
                    ->icon('heroicon-o-truck')
                    ->color('primary')
                    ->visible(fn (Vehicle $record): bool => self::getTodayTripsCount($record) > 0 || $record->active_trip !== null)
                    ->modal()
                    ->modalWidth(Width::FourExtraLarge)
                    ->modalHeading(fn (Vehicle $record): string => 'Danh sách chuyến xe trong ca — '.$record->plate_number.' ('.self::getShiftDate()->format('d/m/Y').')')
                    ->modalContent(fn (Vehicle $record, VehicleDispatchBoard $livewire) => view('filament.pages.partials.vehicle-trip-modal', [
                        'vehicle' => $record,
                        'trips' => $livewire->getTodayTripsForVehicle($record),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng'),

                Action::make('view_gps')
                    ->label('Vị trí GPS')
                    ->icon('heroicon-o-map-pin')
                    ->color('success')
                    ->visible(fn (Vehicle $record): bool => (float) $record->gps_lat !== 0.0 && (float) $record->gps_lng !== 0.0 && $record->gps_lat !== null)
                    ->modal()
                    ->modalHeading(fn (Vehicle $record): string => 'Vị trí xe '.$record->plate_number)
                    ->modalContent(fn (Vehicle $record) => view('filament.pages.partials.vehicle-gps-modal', [
                        'vehicle' => $record,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng'),
            ], position: RecordActionsPosition::AfterColumns);
    }

    // ─── Formatting Helpers ──────────────────────────────────────────

    /**
     * Xe nhà là xe có chủ xe thuộc công ty (ASGT); mọi chủ xe khác là xe thuê ngoài.
     */
    public static function getOwnerCategory(Vehicle $record): string
    {
        return in_array(mb_strtoupper(trim((string) $record->owner)), ['ASGT'], true) ? 'Xe CTY' : 'Xe thuê';
    }

    public static function renderBsx(Vehicle $record): string
    {
        $plate = e($record->plate_number);
        $tonnage = '';

        if ($record->load_capacity) {
            $val = (float) $record->load_capacity;
            if ($val > 0) {
                $ton = match (true) {
                    $val >= 13.0 && $val <= 14.5 => '14',
                    $val >= 6.0 && $val <= 7.5 => '7',
                    $val >= 4.0 && $val <= 5.8 => '5',
                    $val >= 3.0 && $val < 4.0 => '3.5',
                    $val >= 2.0 && $val <= 2.6 => '2.5',
                    $val >= 1.0 && $val <= 1.5 => '1.25',
                    default => (string) round($val),
                };
                $tonnage = "{$ton}T";
            }
        }

        $extra = '';
        if ($record->vehicle_type?->value === 'cold' && ! str_contains($record->notes ?? '', 'cool')) {
            $extra .= ' <span class="text-blue-500 font-normal text-xs">cool</span>';
        }
        if ($record->vehicle_type?->value === 'flatbed' && ! str_contains($record->notes ?? '', 'mâm')) {
            $extra .= ' <span class="text-amber-500 font-normal text-xs">mâm</span>';
        }

        // Phần chỉ chuột vào thì mới show ra: Tải thực, Số khối
        $tooltipParts = [];
        if ($record->load_capacity !== null) {
            $tooltipParts[] = 'Tải thực: '.((float) $record->load_capacity).'T';
        }
        if ($record->cargo_volume !== null) {
            $tooltipParts[] = 'Số khối: '.((float) $record->cargo_volume).' cbm';
        }
        $tooltip = ! empty($tooltipParts) ? implode(' | ', $tooltipParts) : '';

        // Phần show ra luôn: Biển số xe, Tải quy đổi, Số cửa, Ghi chú
        $note = '';
        $noteText = $record->notes ?: ($record->status === VehicleStatus::Off ? $record->off_reason : null);
        if (! empty($noteText)) {
            $note = ' <span class="text-xs text-amber-600 dark:text-amber-400 font-normal italic">('.e(trim($noteText)).')</span>';
        }

        $titleAttr = $tooltip ? ' title="'.e($tooltip).'"' : '';

        return "<div{$titleAttr} class='cursor-default'><span class='font-bold text-primary-600 dark:text-primary-400'>{$plate}</span>".($tonnage ? " <span class='text-xs text-gray-500 dark:text-gray-400 font-medium'>{$tonnage}</span>" : '').$extra.$note.'</div>';
    }

    public static function getTripStatusLabel(Vehicle $record): string
    {
        $activeTrip = $record->active_trip;

        if ($activeTrip === null) {
            if ($record->status === VehicleStatus::Off) {
                return 'Nghỉ';
            }
            if ($record->status === VehicleStatus::Bdsc) {
                return 'Bảo dưỡng';
            }

            return 'Xe chờ';
        }

        if ($activeTrip->isPaused()) {
            return 'Tạm dừng (Sửa Km)';
        }

        if ($activeTrip->status instanceof TripStatus) {
            return $activeTrip->status->getLabel();
        }

        return TripStatus::tryFrom((string) $activeTrip->status)?->getLabel() ?? (string) $activeTrip->status;
    }

    /**
     * Thứ tự ưu tiên sắp xếp theo nhóm trạng thái trên bảng điều phối.
     * Cùng nhóm trạng thái sẽ được gom lại với nhau, giữ nguyên nhóm địa điểm.
     */
    public static function getTripStatusSortPriority(Vehicle $record): int
    {
        $label = self::getTripStatusLabel($record);

        return match ($label) {
            'Tạm dừng (Sửa Km)' => 0,
            'Đảo lái' => 1,
            'Xe chờ' => 2,
            'Chờ chạy' => 3,
            'Đã bắt đầu' => 4,
            'Đến lấy hàng' => 5,
            'Đang giao' => 6,
            'Đến giao hàng' => 7,
            'Đã giao' => 8,
            'Chuyến không hàng' => 9,
            'Hoàn thành' => 10,
            'Bảo dưỡng' => 11,
            'Nghỉ' => 12,
            'Đã huỷ' => 13,
            default => 99,
        };
    }

    public static function getStatusBadgeColor(Vehicle $record): string
    {
        $activeTrip = $record->active_trip;

        if ($activeTrip === null) {
            if ($record->status === VehicleStatus::Off || $record->status === VehicleStatus::Bdsc) {
                return 'danger';
            }

            return 'gray';
        }

        if ($activeTrip->isPaused()) {
            return 'warning';
        }

        return $activeTrip->status?->getColor() ?? 'primary';
    }

    public static function getTripStatusBadgeClass(Vehicle $record): string
    {
        $activeTrip = $record->active_trip;

        if ($activeTrip === null) {
            if ($record->status === VehicleStatus::Off) {
                return 'bg-rose-100 text-rose-800 dark:bg-rose-950/70 dark:text-rose-200 font-bold';
            }
            if ($record->status === VehicleStatus::Bdsc) {
                return 'bg-amber-100 text-amber-800 dark:bg-amber-950/70 dark:text-amber-200 font-bold';
            }

            return 'bg-slate-100 text-slate-500 dark:bg-slate-800/60 dark:text-slate-400 font-normal';
        }

        if ($activeTrip->isPaused()) {
            return 'bg-amber-500 text-white font-bold animate-pulse';
        }

        $status = $activeTrip->status instanceof TripStatus
            ? $activeTrip->status
            : TripStatus::tryFrom((string) $activeTrip->status);

        return match ($status) {
            TripStatus::Pending => 'bg-purple-600 text-white font-bold',
            TripStatus::Started => 'bg-sky-500 text-white font-bold',
            TripStatus::ArrivedPickup => 'bg-amber-500 text-white font-bold',
            TripStatus::Delivering => 'bg-emerald-600 text-white font-bold',
            TripStatus::ArrivedDelivery => 'bg-fuchsia-600 text-white font-bold',
            TripStatus::Delivered => 'bg-teal-600 text-white font-bold',
            TripStatus::Completed => 'bg-green-700 text-white font-bold',
            TripStatus::DriverSwap => 'bg-rose-600 text-white font-bold',
            TripStatus::Cancelled => 'bg-gray-400 text-white font-semibold',
            TripStatus::ReturnTrip => 'bg-indigo-600 text-white font-semibold',
            default => 'bg-blue-600 text-white font-bold',
        };
    }

    public static function canClickTripStatus(Vehicle $record): bool
    {
        $activeTrip = $record->active_trip;

        if ($activeTrip === null) {
            return false;
        }

        return $activeTrip->status === TripStatus::DriverSwap || $activeTrip->isPaused();
    }

    public static function getOrderType(Vehicle $record): ?OrderType
    {
        return $record->active_trip?->orders->first()?->type;
    }

    public static function getPickupPoint(Vehicle $record): string
    {
        $activeTrip = $record->active_trip;

        if ($activeTrip === null) {
            return '—';
        }

        $order = $activeTrip->orders->first();

        return $order?->pickupLocation?->code
            ?? $activeTrip->startLocation?->code
            ?? $order?->pickupLocation?->name
            ?? $activeTrip->startLocation?->name
            ?? $order?->pickup_address
            ?? '—';
    }

    public static function getPickupPointFullName(Vehicle $record): ?string
    {
        $activeTrip = $record->active_trip;

        if ($activeTrip === null) {
            return null;
        }

        $order = $activeTrip->orders->first();

        return $order?->pickupLocation?->name
            ?? $activeTrip->startLocation?->name
            ?? $order?->pickup_address;
    }

    public static function getDeliveryPoint(Vehicle $record): string
    {
        $activeTrip = $record->active_trip;

        if ($activeTrip === null) {
            return '—';
        }

        $destinations = $activeTrip->orders
            ->flatMap(fn (Order $o) => $o->deliveryPoints)
            ->map(fn ($dp) => $dp->location?->code ?? $dp->location?->name ?? $dp->address)
            ->filter()
            ->unique()
            ->values();

        if ($destinations->isNotEmpty()) {
            return $destinations->join(', ');
        }

        return $activeTrip->endLocation?->code
            ?? $activeTrip->endLocation?->name
            ?? '—';
    }

    public static function getDeliveryPointFullName(Vehicle $record): ?string
    {
        $activeTrip = $record->active_trip;

        if ($activeTrip === null) {
            return null;
        }

        $destinations = $activeTrip->orders
            ->flatMap(fn (Order $o) => $o->deliveryPoints)
            ->map(fn ($dp) => $dp->location?->name ?? $dp->address)
            ->filter()
            ->unique()
            ->values();

        if ($destinations->isNotEmpty()) {
            return $destinations->join(' | ');
        }

        return $activeTrip->endLocation?->name;
    }

    public static function formatDurationMinutes(int $minutes): string
    {
        if ($minutes < 1) {
            return '< 1p';
        }

        if ($minutes < 60) {
            return "{$minutes}p";
        }

        $hours = intdiv($minutes, 60);
        $remMinutes = $minutes % 60;

        if ($hours >= 24) {
            $days = intdiv($hours, 24);
            $remHours = $hours % 24;

            return $remHours > 0 ? "{$days}d {$remHours}h" : "{$days}d";
        }

        if ($remMinutes === 0) {
            return "{$hours}h";
        }

        return "{$hours}h {$remMinutes}p";
    }

    /**
     * Dữ liệu chi tiết thời gian xe chờ từ lúc bấm mốc trạng thái gần nhất hoặc lúc hạ xong đến hiện tại.
     *
     * @return array{text: string, tooltip: string, minutes: int, isAlert: bool, isWarning: bool}
     */
    public static function getXeChoData(Vehicle $record): array
    {
        if ($record->status === VehicleStatus::Off) {
            return ['text' => '—', 'tooltip' => 'Xe nghỉ', 'minutes' => 0, 'isAlert' => false, 'isWarning' => false];
        }

        if ($record->status === VehicleStatus::Bdsc) {
            return ['text' => '—', 'tooltip' => 'Xe bảo dưỡng', 'minutes' => 0, 'isAlert' => false, 'isWarning' => false];
        }

        $activeTrip = $record->active_trip;

        // 1. Xe đang có chuyến hoạt động: tính từ lúc bấm mốc trạng thái gần nhất
        if ($activeTrip !== null) {
            $checkpoints = $activeTrip->relationLoaded('checkpoints')
                ? $activeTrip->checkpoints
                : $activeTrip->checkpoints;

            $latestCp = $checkpoints
                ->sortByDesc(fn ($c) => $c->occurred_at ?? $c->created_at)
                ->first();

            if ($latestCp) {
                $time = $latestCp->occurred_at ?? $latestCp->created_at;
                $diff = $time->isFuture() ? 0 : (int) $time->diffInMinutes(now());
                $cpLabel = $latestCp->checkpoint_type instanceof CheckpointType
                    ? $latestCp->checkpoint_type->getLabel()
                    : ($latestCp->checkpoint_type ? CheckpointType::tryFrom($latestCp->checkpoint_type)?->getLabel() ?? $latestCp->checkpoint_type : 'Mốc gần nhất');

                return [
                    'text' => self::formatDurationMinutes($diff),
                    'tooltip' => "Mốc: {$cpLabel} lúc {$time->format('H:i')} (Chuyến {$activeTrip->trip_code}) — Đã chờ {$diff} phút",
                    'minutes' => $diff,
                    'isAlert' => $diff >= 120, // Chờ trên 2 tiếng
                    'isWarning' => $diff >= 60 && $diff < 120, // Chờ 1 - 2 tiếng
                ];
            }

            // Nếu chuyến chưa có checkpoint nào (vừa bắt đầu hoặc đang pending)
            $startTime = $activeTrip->started_at ?? $activeTrip->created_at;
            if ($startTime) {
                $diff = $startTime->isFuture() ? 0 : (int) $startTime->diffInMinutes(now());
                $tripStatusLabel = $activeTrip->status instanceof TripStatus ? $activeTrip->status->getLabel() : (string) $activeTrip->status;

                return [
                    'text' => self::formatDurationMinutes($diff),
                    'tooltip' => "Trạng thái: {$tripStatusLabel} từ {$startTime->format('H:i')} (Chuyến {$activeTrip->trip_code}) — Đã chờ {$diff} phút",
                    'minutes' => $diff,
                    'isAlert' => $diff >= 120,
                    'isWarning' => $diff >= 60 && $diff < 120,
                ];
            }

            return [
                'text' => '—',
                'tooltip' => "Chuyến {$activeTrip->trip_code}",
                'minutes' => 0,
                'isAlert' => false,
                'isWarning' => false,
            ];
        }

        // 2. Xe KHÔNG có chuyến hoạt động: tính từ lúc xe hạ xong chuyến gần nhất
        $completedTrips = $record->trips->where('status', TripStatus::Completed);
        $lastCompletedTrip = $completedTrips->sortByDesc(fn ($t) => $t->completed_at ?? $t->updated_at)->first();

        if ($lastCompletedTrip && ($lastCompletedTrip->completed_at ?? $lastCompletedTrip->updated_at)) {
            $completedTime = $lastCompletedTrip->completed_at ?? $lastCompletedTrip->updated_at;
            $diff = $completedTime->isFuture() ? 0 : (int) $completedTime->diffInMinutes(now());

            // Trong vòng 48h từ lúc hạ xong
            if ($diff <= 2880) {
                return [
                    'text' => self::formatDurationMinutes($diff),
                    'tooltip' => "Hạ xong lúc {$completedTime->format('H:i d/m')} (Chuyến {$lastCompletedTrip->trip_code}) — Chưa gán chuyến mới ({$diff} phút)",
                    'minutes' => $diff,
                    'isAlert' => $diff >= 180, // Hạ xong quá 3 tiếng chưa gán chuyến mới
                    'isWarning' => $diff >= 90 && $diff < 180, // Hạ xong từ 1.5 - 3 tiếng
                ];
            }
        }

        // 3. Xe sẵn sàng (chưa có chuyến mới hoặc rảnh từ lâu)
        return [
            'text' => 'Sẵn sàng',
            'tooltip' => 'Xe sẵn sàng (chưa có chuyến mới)',
            'minutes' => 0,
            'isAlert' => false,
            'isWarning' => false,
        ];
    }

    public static function getXeCho(Vehicle $record): string
    {
        return self::getXeChoData($record)['text'];
    }

    public static function getXeChoTooltip(Vehicle $record): string
    {
        return self::getXeChoData($record)['tooltip'];
    }

    /**
     * Rút gọn địa chỉ GPS thành "xã/phường/quận/huyện, tỉnh/thành phố" (ví dụ: "Phổ Yên, Thái Nguyên").
     */
    public static function formatShortAddress(?string $address): string
    {
        if (empty($address)) {
            return '—';
        }

        $parts = array_map('trim', explode(',', $address));
        $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));

        if (empty($parts)) {
            return '—';
        }

        $last = end($parts);
        if (in_array(mb_strtolower($last, 'UTF-8'), ['việt nam', 'viet nam', 'vietnam'], true)) {
            array_pop($parts);
        }

        if (empty($parts)) {
            return $address;
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        // Lấy 2 phần cuối: xã/huyện/thị xã và tỉnh/thành phố
        $shortParts = array_slice($parts, -2);

        return implode(', ', $shortParts);
    }

    public static function getGpsLocation(Vehicle $record): string
    {
        if (! empty($record->gps_address)) {
            return self::formatShortAddress($record->gps_address);
        }

        if ($record->gps_lat && $record->gps_lng) {
            return number_format((float) $record->gps_lat, 4, ',', '.').', '.number_format((float) $record->gps_lng, 4, ',', '.');
        }

        return '—';
    }

    public static function getSpeed(Vehicle $record): string
    {
        if ($record->gps_speed !== null && (float) $record->gps_speed > 0) {
            return round((float) $record->gps_speed).' km/h';
        }

        return '0';
    }

    public static function getMovementStatus(Vehicle $record): string
    {
        $speed = (float) $record->gps_speed;

        if ($speed > 0) {
            return 'Đang chạy';
        }

        $isGpsLost = false;
        try {
            $isGpsLost = $record->car_status === EupCarStatus::SignalLost->value
                || ($record->last_gps_update instanceof \DateTimeInterface && $record->last_gps_update->diffInHours(now()) >= 24);
        } catch (\Throwable) {
            $isGpsLost = false;
        }

        if ($isGpsLost) {
            return 'Mất GPS';
        }

        return match ($record->car_status) {
            EupCarStatus::Active->value => 'Đang chạy',
            EupCarStatus::IdleEngine->value => 'Ko tắt máy',
            default => 'Xe dừng',
        };
    }

    /**
     * Màu badge Status: Mất GPS đỏ; Ko tắt máy đỏ khi kéo dài quá IDLE_ENGINE_ALERT_MINUTES phút.
     */
    public static function getMovementStatusColor(Vehicle $record): string
    {
        return match (self::getMovementStatus($record)) {
            'Đang chạy' => 'success',
            'Mất GPS' => 'danger',
            'Ko tắt máy' => self::isIdleEngineOverdue($record->idle_engine_since) ? 'danger' : 'warning',
            default => 'gray',
        };
    }

    /**
     * Chữ trong ô Status; trạng thái "Ko tắt máy" kèm thời gian đã dừng không tắt máy.
     */
    public static function getMovementStatusText(Vehicle $record): string
    {
        $status = self::getMovementStatus($record);
        $idleSince = $status === 'Ko tắt máy' ? $record->idle_engine_since : null;

        if ($idleSince === null) {
            return $status;
        }

        $minutes = (int) $idleSince->diffInMinutes(now());

        return $status.' · '.($minutes >= 60 ? intdiv($minutes, 60).'h'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) : $minutes)."'";
    }

    /**
     * Màu nền cả ô Status (cùng kiểu cột Trạng thái): Ko tắt máy cam, quá IDLE_ENGINE_ALERT_MINUTES phút thì đỏ.
     */
    public static function getMovementStatusCellClass(Vehicle $record): string
    {
        return match (self::getMovementStatus($record)) {
            'Đang chạy' => 'bg-emerald-600 text-white font-bold',
            'Mất GPS' => 'bg-rose-600 text-white font-bold',
            'Ko tắt máy' => self::isIdleEngineOverdue($record->idle_engine_since)
                ? 'bg-rose-600 text-white font-bold'
                : 'bg-orange-500 text-white font-bold',
            default => 'bg-slate-100 text-slate-500 dark:bg-slate-800/60 dark:text-slate-400 font-semibold',
        };
    }

    public static function getMovementStatusTooltip(Vehicle $record): ?string
    {
        if (self::getMovementStatus($record) !== 'Ko tắt máy') {
            return null;
        }

        $idleSince = $record->idle_engine_since;

        return $idleSince !== null ? 'Dừng không tắt máy từ '.$idleSince->format('H:i') : null;
    }

    public static function isIdleEngineOverdue(?\DateTimeInterface $idleSince): bool
    {
        return $idleSince !== null && Carbon::instance($idleSince)->lt(now()->subMinutes(self::IDLE_ENGINE_ALERT_MINUTES));
    }

    /**
     * Mức cảnh báo theo 3 ngưỡng tăng dần: vàng nhạt, cam, đỏ.
     *
     * @return 'light'|'orange'|'red'|null
     */
    public static function getAlertLevel(float $value, float $light, float $orange, float $red): ?string
    {
        return match (true) {
            $value >= $red => 'red',
            $value >= $orange => 'orange',
            $value >= $light => 'light',
            default => null,
        };
    }

    /**
     * Làm tròn như getSpeed() để màu khớp số đang hiển thị.
     */
    public static function getSpeedAlertClass(Vehicle $record): string
    {
        $level = self::getAlertLevel(round((float) $record->gps_speed), 55, 60, 65);

        return $level ? self::ALERT_CELL_CLASSES[$level] : '';
    }

    public static function getTodayTripsCount(Vehicle $record): int
    {
        [$shiftStart, $shiftEnd] = self::getShiftWindow();
        $runningStatuses = self::runningTripStatuses();
        $runningValues = array_map(fn (TripStatus $s) => $s->value, $runningStatuses);

        if (! $record->relationLoaded('trips')) {
            return $record->trips()
                ->where(function (Builder $q) use ($shiftStart, $shiftEnd, $runningValues) {
                    $q->whereIn('status', $runningValues)
                        ->orWhere(function (Builder $sub) use ($shiftStart, $shiftEnd) {
                            $sub->where('status', TripStatus::Completed->value)
                                ->where(function (Builder $dateQ) use ($shiftStart, $shiftEnd) {
                                    $dateQ->whereBetween('completed_at', [$shiftStart, $shiftEnd])
                                        ->orWhereBetween('started_at', [$shiftStart, $shiftEnd]);
                                });
                        });
                })
                ->count();
        }

        return $record->trips
            ->filter(fn (Trip $t): bool => self::isTripInCurrentShift($t, $shiftStart, $shiftEnd, $runningStatuses))
            ->count();
    }

    /**
     * Chuyến đang chạy, hoặc đã hoàn thành trong ca hiện tại.
     *
     * @param  array<int, TripStatus>  $runningStatuses
     */
    private static function isTripInCurrentShift(Trip $t, CarbonInterface $shiftStart, CarbonInterface $shiftEnd, array $runningStatuses): bool
    {
        if ($t->status === TripStatus::Pending || $t->status === TripStatus::Cancelled) {
            return false;
        }

        if (in_array($t->status, $runningStatuses, true)) {
            return true;
        }

        if ($t->status === TripStatus::Completed) {
            return ($t->completed_at && $t->completed_at->between($shiftStart, $shiftEnd))
                || ($t->started_at && $t->started_at->between($shiftStart, $shiftEnd));
        }

        return false;
    }

    /**
     * Tổng hợp kiểm tra vị trí các mốc của chuyến trong ca hiện tại.
     *
     * @return array{wrong: int, checked: int, label: string, tooltip: string}
     */
    public static function getGeoCheckData(Vehicle $record): array
    {
        [$shiftStart, $shiftEnd] = self::getShiftWindow();
        $runningStatuses = self::runningTripStatuses();
        $checked = [GeoCheckStatus::Valid, GeoCheckStatus::OutOfRange];

        $checkpoints = $record->trips
            ->filter(fn (Trip $t): bool => self::isTripInCurrentShift($t, $shiftStart, $shiftEnd, $runningStatuses))
            ->flatMap(fn (Trip $t) => $t->checkpoints)
            ->filter(fn ($cp): bool => in_array($cp->vehicle_geo_status, $checked, true) || in_array($cp->location_geo_status, $checked, true))
            ->sortBy('occurred_at');

        $wrong = $checkpoints->filter(fn ($cp): bool => $cp->vehicle_geo_status === GeoCheckStatus::OutOfRange
            || $cp->location_geo_status === GeoCheckStatus::OutOfRange);

        $tooltip = $wrong->map(function ($cp): string {
            $reasons = array_filter([
                $cp->location_geo_status === GeoCheckStatus::OutOfRange ? 'Xe cách điểm '.number_format((int) $cp->location_distance_m, 0, ',', '.').' m' : null,
                $cp->vehicle_geo_status === GeoCheckStatus::OutOfRange ? 'Gửi khi cách xe '.number_format((int) $cp->vehicle_distance_m, 0, ',', '.').' m' : null,
            ]);

            return ($cp->occurred_at?->format('H:i') ?? '—').' '.$cp->checkpoint_type->getLabel().': '.implode(', ', $reasons);
        })->implode("\n");

        return [
            'wrong' => $wrong->count(),
            'checked' => $checkpoints->count(),
            'label' => match (true) {
                $wrong->isNotEmpty() => $wrong->count().' sai',
                $checkpoints->isNotEmpty() => 'Đúng',
                default => '—',
            },
            'tooltip' => $tooltip !== '' ? $tooltip : ($checkpoints->isNotEmpty() ? $checkpoints->count().' mốc đúng vị trí' : 'Chưa có dữ liệu vị trí'),
        ];
    }

    /**
     * Đếm số lượng đơn hàng đang chờ chưa chạy của xe (thuộc các chuyến Chờ chạy - Pending).
     */
    public static function getWaitingOrdersCount(Vehicle $record): int
    {
        if ($record->relationLoaded('trips')) {
            return $record->trips
                ->filter(function (Trip $trip): bool {
                    $status = $trip->status instanceof TripStatus ? $trip->status->value : (string) $trip->status;

                    return $status === TripStatus::Pending->value;
                })
                ->flatMap(function (Trip $trip) {
                    if ($trip->relationLoaded('orders')) {
                        return $trip->orders->filter(function (Order $order): bool {
                            $orderStatus = $order->status instanceof OrderStatus ? $order->status->value : (string) $order->status;

                            return ! in_array($orderStatus, [OrderStatus::Cancelled->value, OrderStatus::Completed->value], true);
                        });
                    }

                    return $trip->orders()
                        ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Completed->value])
                        ->get();
                })
                ->count();
        }

        return Order::query()
            ->whereHas('trip', function (Builder $q) use ($record) {
                $q->where('vehicle_id', $record->id)
                    ->where('status', TripStatus::Pending->value);
            })
            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Completed->value])
            ->count();
    }

    /**
     * @return array{
     *     text: string,
     *     delta: ?float,
     *     status: 'danger'|'warning'|'notice'|'normal'|'none',
     *     cellClass: string,
     *     tooltip: string,
     *     fillPercent: int,
     * }
     */
    public static function getKmOverData(Vehicle $record): array
    {
        // Xe công ty không theo dõi định mức KM over.
        if (self::getOwnerCategory($record) === 'Xe CTY') {
            return [
                'text' => '—',
                'delta' => null,
                'status' => 'none',
                'cellClass' => 'text-gray-400',
                'tooltip' => 'Xe công ty không theo dõi KM over',
                'fillPercent' => 0,
            ];
        }

        $currentKm = $record->current_mileage !== null ? (float) $record->current_mileage : null;
        $startKm = $record->monthly_start_km !== null ? (float) $record->monthly_start_km : null;

        if ($currentKm === null || $startKm === null) {
            return [
                'text' => '—',
                'delta' => null,
                'status' => 'none',
                'cellClass' => 'text-gray-400',
                'tooltip' => 'Chưa có dữ liệu Km bắt đầu chu kỳ ngày 23',
                'fillPercent' => 0,
            ];
        }

        $delta = max(0.0, $currentKm - $startKm);
        // Tỉ lệ đã chạy so với định mức 3.000 km, dùng tô nền ô (1.500 km = nửa ô).
        $fillPercent = (int) round(min($delta / 3000, 1) * 100);
        $formattedDelta = number_format($delta, 0, ',', '.');
        $tooltipDetails = 'Đã chạy: '.$formattedDelta.' km | Đầu chu kỳ (ngày 23): '.number_format($startKm, 0, ',', '.').' km | Hiện tại: '.number_format($currentKm, 0, ',', '.').' km';

        $level = self::getAlertLevel($delta, 2600, 2800, 3000);

        return [
            'text' => $formattedDelta,
            'delta' => $delta,
            'status' => match ($level) {
                'red' => 'danger',
                'orange' => 'warning',
                'light' => 'notice',
                default => 'normal',
            },
            'cellClass' => $level ? 'km-over-cell '.self::ALERT_CELL_CLASSES[$level] : 'text-gray-700 dark:text-gray-300 font-semibold',
            'tooltip' => match ($level) {
                'red' => '⚠️ VƯỢT ĐỊNH MỨC (≥ 3.000 km) — ',
                'orange' => '⚡ SẮP ĐẾN HẠN MỨC (≥ 2.800 km) — ',
                'light' => 'Gần hạn mức (≥ 2.600 km) — ',
                default => '',
            }.$tooltipDetails,
            'fillPercent' => $fillPercent,
        ];
    }

    public static function getKmOver(Vehicle $record): string
    {
        return self::getKmOverData($record)['text'];
    }

    public static function getDriverName(Vehicle $record): string
    {
        $activeTrip = $record->active_trip;

        if ($activeTrip?->driver) {
            return $activeTrip->driver->name;
        }

        $todayDriver = $record->getDriverForToday();

        if ($todayDriver) {
            return $todayDriver->name;
        }

        return $record->driver?->name ?? '—';
    }

    public static function getDriverStation(Vehicle $record): string
    {
        // Lấy Điểm trực (station) từ bảng users của tài xế
        $driver = $record->active_trip?->driver
            ?? $record->getDriverForToday()
            ?? $record->driver
            ?? $record->evenDriver
            ?? $record->oddDriver;

        $station = $driver?->station instanceof OnDutyLocation
            ? $driver->station->value
            : ($driver?->station ? (string) $driver->station : null);

        if (! $station && ! empty($record->driver_station) && $record->driver_station !== 'OTHER') {
            $station = (string) $record->driver_station;
        }

        if ($station) {
            return match (strtoupper($station)) {
                'NBA' => 'NB',
                'BN' => 'BN',
                'TN' => 'TN',
                default => $station,
            };
        }

        return 'OTHER';
    }

    public static function resolveVehicleLocationGroup(Vehicle $vehicle): string
    {
        // Dùng đúng chuyến đang hiển thị trên dòng (active_trip) để nhóm và dòng
        // luôn nhất quán. Chuyến đã kết thúc/đã huỷ thì không dùng điểm đến nữa.
        $activeTrip = $vehicle->active_trip;

        // Ưu tiên 1: điểm đến của các đơn trong chuyến đang chạy.
        if (
            $activeTrip !== null
            && $activeTrip->status !== TripStatus::Cancelled
            && $activeTrip->status !== TripStatus::Completed
            && ! $activeTrip->hasEnded()
        ) {
            $destStation = self::resolveStationFromTripDestination($activeTrip);
            if ($destStation !== null) {
                return $destStation;
            }
        }

        // Ưu tiên 2: chuyến/đơn không có điểm đến -> dùng vị trí GPS hiện tại.
        $gpsStation = self::resolveStationFromGps($vehicle);
        if ($gpsStation !== null) {
            return $gpsStation;
        }

        // Fallback 1: Điểm trực của tài xế (users.station)
        $driverStation = self::getDriverStation($vehicle);
        if ($driverStation !== 'OTHER' && $driverStation !== '—' && ! empty($driverStation)) {
            return $driverStation;
        }

        // Fallback 2: Tiền tố biển số xe (20 -> TN, 99 -> BN, 29/30 -> NB)
        $prefix = substr($vehicle->plate_number ?? '', 0, 2);
        $prefixStation = match ($prefix) {
            '20' => 'TN',
            '99' => 'BN',
            '29', '30' => 'NB',
            default => null,
        };
        if ($prefixStation !== null) {
            return $prefixStation;
        }

        return 'OTHER';
    }

    public static function resolveStationFromTripDestination(Trip $trip): ?string
    {
        // 1. Điểm giao của các đơn, xét theo thứ tự chuyến: đơn cuối cùng trước,
        // trong mỗi đơn lấy điểm giao cuối (sequence lớn nhất).
        $orders = $trip->orders
            ->sortBy(fn (Order $order): int => $order->planned_loading_at?->getTimestamp() ?? PHP_INT_MAX)
            ->values();

        foreach ($orders->reverse() as $order) {
            foreach ($order->deliveryPoints->sortByDesc('sequence') as $dp) {
                $station = self::mapLocationOrAddressToStation($dp->location, $dp->address);
                if ($station !== null) {
                    return $station;
                }
            }
        }

        // 2. Không có điểm giao nào map được -> dùng điểm kết thúc của chuyến.
        if ($trip->endLocation) {
            $station = self::mapLocationOrAddressToStation($trip->endLocation, $trip->endLocation->address);
            if ($station !== null) {
                return $station;
            }
        }

        // 3. Không có điểm đến -> trả null để nơi gọi fallback sang GPS.
        return null;
    }

    public static function mapLocationOrAddressToStation(?Location $location, ?string $address): ?string
    {
        if ($location) {
            $areaCode = $location->area?->code;
            if ($areaCode === 'NBA') {
                return 'NB';
            }
            if ($areaCode === 'BN') {
                return 'BN';
            }
            if (in_array($areaCode, ['TN', 'NBO'], true)) {
                return 'TN';
            }
            if ($areaCode === 'PROVINCE') {
                return 'Province';
            }

            $locCode = strtoupper($location->code ?? '');
            if (in_array($locCode, ['ACSV', 'NCTS', 'ALSC', 'DHL', 'ALSW', 'ASG', 'NBA'], true)) {
                return 'NB';
            }
            if (in_array($locCode, ['SEV', 'ALSB', 'SDV', 'ASGS', 'BN'], true)) {
                return 'BN';
            }
            if (in_array($locCode, ['SEVT', 'ALST', 'TN'], true)) {
                return 'TN';
            }

            $address = $address ?: $location->address;
        }

        if (! empty($address)) {
            if (str_contains($address, 'Thái Nguyên') || str_contains($address, 'Phổ Yên') || str_contains($address, 'Sông Công')) {
                return 'TN';
            }
            if (str_contains($address, 'Bắc Ninh') || str_contains($address, 'Yên Phong') || str_contains($address, 'Quế Võ') || str_contains($address, 'Từ Sơn') || str_contains($address, 'Tiên Du') || str_contains($address, 'Thuận Thành')) {
                return 'BN';
            }
            if (str_contains($address, 'Hà Nội') || str_contains($address, 'Nội Bài') || str_contains($address, 'Sóc Sơn') || str_contains($address, 'Mê Linh') || str_contains($address, 'Đông Anh')) {
                return 'NB';
            }

            return 'Province';
        }

        return null;
    }

    public static function resolveStationFromGps(Vehicle $record): ?string
    {
        $addr = $record->gps_address;
        if (! empty($addr)) {
            if (str_contains($addr, 'Thái Nguyên') || str_contains($addr, 'Phổ Yên') || str_contains($addr, 'Sông Công')) {
                return 'TN';
            }
            if (str_contains($addr, 'Bắc Ninh') || str_contains($addr, 'Yên Phong') || str_contains($addr, 'Quế Võ') || str_contains($addr, 'Từ Sơn') || str_contains($addr, 'Tiên Du') || str_contains($addr, 'Thuận Thành')) {
                return 'BN';
            }
            if (str_contains($addr, 'Hà Nội') || str_contains($addr, 'Nội Bài') || str_contains($addr, 'Sóc Sơn') || str_contains($addr, 'Mê Linh') || str_contains($addr, 'Đông Anh')) {
                return 'NB';
            }
        }

        $lat = (float) $record->gps_lat;
        $lng = (float) $record->gps_lng;
        if ($lat !== 0.0 && $lng !== 0.0) {
            if ($lat > 21.35 && $lat < 21.85 && $lng > 105.5 && $lng < 106.1) {
                return 'TN';
            }
            if ($lat > 21.05 && $lat < 21.35 && $lng > 105.95 && $lng < 106.3) {
                return 'BN';
            }
            if ($lat > 20.95 && $lat < 21.4 && $lng > 105.6 && $lng < 105.95) {
                return 'NB';
            }

            return 'Province';
        }

        if (! empty($addr)) {
            return 'Province';
        }

        return null;
    }

    public static function getShiftLabel(Vehicle $record): string
    {
        $activeTrip = $record->active_trip;
        $driver = $activeTrip?->driver ?? $record->getDriverForToday() ?? $record->driver;

        if ($driver === null) {
            return '—';
        }

        // Chỉ hiển thị ca làm hiện tại khi lái xe đăng nhập trên app (ca đang hoạt động, chưa kết thúc)
        $activeShift = $driver->relationLoaded('driverShifts')
            ? $driver->driverShifts
                ->filter(fn (DriverShift $s) => $s->start_time && $s->start_time->lte(now()) && $s->end_time === null)
                ->sortByDesc('start_time')
                ->first()
            : $driver->driverShifts()
                ->where('start_time', '<=', now())
                ->whereNull('end_time')
                ->latest('start_time')
                ->first();

        if ($activeShift?->shift_type) {
            return match ($activeShift->shift_type) {
                ShiftType::MorningHalf => 'X/2',
                ShiftType::NightHalf => 'Y/2',
                ShiftType::Full => 'X',
                default => 'X',
            };
        }

        return '—';
    }
}
