<?php

namespace App\Filament\Pages;

use App\Enums\CheckpointType;
use App\Enums\DriverWorkShift;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Filament\Resources\Trips\Actions\ReassignDriverAction;
use App\Filament\Resources\Trips\Actions\ResolveKmReportAction;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use App\Services\EupGpsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

class VehicleDispatchBoard extends Page implements HasTable
{
    use InteractsWithTable;

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

    public ?int $selectedVehicleIdForTrip = null;

    public ?int $selectedVehicleIdForGps = null;

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

    public function getSelectedVehicleForTrip(): ?Vehicle
    {
        if (! $this->selectedVehicleIdForTrip) {
            return null;
        }

        return Vehicle::with(['trips.orders.deliveryPoints.location', 'trips.orders.pickupLocation', 'trips.driver'])->find($this->selectedVehicleIdForTrip);
    }

    public function getSelectedVehicleForGps(): ?Vehicle
    {
        if (! $this->selectedVehicleIdForGps) {
            return null;
        }

        return Vehicle::find($this->selectedVehicleIdForGps);
    }

    public function syncEupGps(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        try {
            $cacheKey = 'last_eup_gps_sync_at';
            $lastSync = cache()->get($cacheKey);

            if (! is_numeric($lastSync) || (time() - (int) $lastSync) >= 15) {
                cache()->put($cacheKey, time(), 60);

                app(EupGpsService::class)->sync(5);
            }
        } catch (\Throwable) {
            // Ensure page render is never blocked by external API, database or cache failure
        }
    }

    public function getGroupedVehicles(): array
    {
        $this->syncEupGps();

        $vehicles = Vehicle::query()
            ->select('vehicles.*')
            ->selectSub(function ($q) {
                $q->selectRaw("COALESCE(u1.station, u2.station, u3.station, 'OTHER')")
                    ->from('vehicles as v_sub')
                    ->leftJoin('users as u1', 'u1.id', '=', 'v_sub.current_driver_id')
                    ->leftJoin('users as u2', 'u2.id', '=', 'v_sub.even_driver_id')
                    ->leftJoin('users as u3', 'u3.id', '=', 'v_sub.odd_driver_id')
                    ->whereColumn('v_sub.id', 'vehicles.id')
                    ->limit(1);
            }, 'driver_station')
            ->where('vehicles.is_active', true)
            ->whereIn('vehicles.type', [VehicleOwnerType::Company->value, 'company'])
            ->with([
                'driver.driverShifts',
                'evenDriver.driverShifts',
                'oddDriver.driverShifts',
                'trips' => function ($q) {
                    $q->where(function ($sub) {
                        $sub->whereDate('started_at', today())
                            ->orWhereIn('status', array_map(fn ($s) => $s->value, TripStatus::activeStatuses()))
                            ->orWhere('completed_at', '>=', now()->subHours(48));
                    })->with([
                        'checkpoints',
                        'driver',
                        'startLocation',
                        'endLocation',
                        'orders.pickupLocation',
                        'orders.deliveryPoints.location',
                    ]);
                },
            ])
            ->get();

        $stationOrder = [
            'BN' => 'Bắc ninh',
            'NBA' => 'Nội bài',
            'TN' => 'Thái Nguyên',
            'OTHER' => 'Khác',
        ];

        $grouped = [];
        foreach ($stationOrder as $code => $title) {
            $grouped[$code] = [
                'code' => $code,
                'title' => $title,
                'vehicles' => collect(),
            ];
        }

        foreach ($vehicles as $vehicle) {
            $driver = $vehicle->getDriverForToday() ?? $vehicle->driver ?? $vehicle->evenDriver ?? $vehicle->oddDriver;
            $code = $driver?->station?->value;

            if (! $code) {
                $prefix = substr($vehicle->plate_number, 0, 2);
                $code = match ($prefix) {
                    '20' => 'TN',
                    '99' => 'BN',
                    '29', '30' => 'NBA',
                    default => 'OTHER',
                };
            }

            if (! isset($grouped[$code])) {
                $grouped[$code] = [
                    'code' => $code,
                    'title' => $code,
                    'vehicles' => collect(),
                ];
            }

            $grouped[$code]['vehicles']->push($vehicle);
        }

        foreach ($grouped as $code => &$group) {
            $group['vehicles'] = $group['vehicles']->sortBy('plate_number')->values();
        }

        return array_filter($grouped, fn ($g) => $g['vehicles']->isNotEmpty());
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
                    ->state(fn (Vehicle $record): string => self::renderBsx($record)),

                // 2. STT (CH / KH)
                TextColumn::make('type')
                    ->label('STT')
                    ->badge()
                    ->alignCenter()
                    ->color(fn (Vehicle $record): string => $record->isCompany() ? 'info' : 'warning')
                    ->state(fn (Vehicle $record): string => $record->isCompany() ? 'CH' : 'KH'),

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
                    ->color(fn (Vehicle $record): string => match (self::getMovementStatus($record)) {
                        'Đang chạy' => 'success',
                        'Ko tắt máy' => 'warning',
                        default => 'gray',
                    })
                    ->state(fn (Vehicle $record): string => self::getMovementStatus($record)),

                // 10. Chuyến
                TextColumn::make('trip_count')
                    ->label('Chuyến')
                    ->alignCenter()
                    ->weight(FontWeight::Bold)
                    ->state(fn (Vehicle $record): int => self::getTodayTripsCount($record)),

                // 11. KM over
                TextColumn::make('km_over')
                    ->label('KM over')
                    ->alignCenter()
                    ->state(fn (Vehicle $record): string => self::getKmOver($record)),

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
                    ->visible(fn (Vehicle $record): bool => $record->active_trip !== null)
                    ->modal()
                    ->modalHeading(fn (Vehicle $record): string => 'Chuyến xe — '.($record->active_trip?->trip_code ?? ''))
                    ->modalContent(fn (Vehicle $record) => view('filament.pages.partials.vehicle-trip-modal', [
                        'vehicle' => $record,
                        'trip' => $record->active_trip,
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

    protected function buildTableQuery(): Builder
    {
        return Vehicle::query()
            ->select('vehicles.*')
            ->selectSub(function ($q) {
                $q->selectRaw("COALESCE(u1.station, u2.station, u3.station, 'OTHER')")
                    ->from('vehicles as v_sub')
                    ->leftJoin('users as u1', 'u1.id', '=', 'v_sub.current_driver_id')
                    ->leftJoin('users as u2', 'u2.id', '=', 'v_sub.even_driver_id')
                    ->leftJoin('users as u3', 'u3.id', '=', 'v_sub.odd_driver_id')
                    ->whereColumn('v_sub.id', 'vehicles.id')
                    ->limit(1);
            }, 'driver_station')
            ->with([
                'driver.driverShifts',
                'evenDriver.driverShifts',
                'oddDriver.driverShifts',
                'trips' => function ($q) {
                    $q->where(function ($sub) {
                        $sub->whereDate('started_at', today())
                            ->orWhereIn('status', array_map(fn ($s) => $s->value, TripStatus::activeStatuses()))
                            ->orWhere('completed_at', '>=', now()->subHours(48));
                    })->with([
                        'checkpoints',
                        'driver',
                        'startLocation',
                        'endLocation',
                        'orders.pickupLocation',
                        'orders.deliveryPoints.location',
                        'latestPendingKmReport',
                    ]);
                },
            ])
            ->where('vehicles.is_active', true)
            ->when($this->activeOwnerType !== 'all', function (Builder $q) {
                $q->where('vehicles.type', $this->activeOwnerType);
            })
            ->when($this->activeStation !== 'all', function (Builder $q) {
                if ($this->activeStation === 'OTHER') {
                    $q->havingRaw("driver_station = 'OTHER'");
                } else {
                    $q->havingRaw('driver_station = ?', [$this->activeStation]);
                }
            })
            ->when($this->activeMovementStatus !== 'all', function (Builder $q) {
                if ($this->activeMovementStatus === 'running') {
                    $q->where('vehicles.gps_speed', '>', 5);
                } elseif ($this->activeMovementStatus === 'idling') {
                    $q->where(function ($sub) {
                        $sub->whereNull('vehicles.gps_speed')->orWhere('vehicles.gps_speed', '<=', 5);
                    })->where('vehicles.last_gps_update', '>=', now()->subMinutes(15));
                } elseif ($this->activeMovementStatus === 'stopped') {
                    $q->where(function ($sub) {
                        $sub->where('vehicles.gps_speed', '<=', 0)
                            ->orWhereNull('vehicles.gps_speed')
                            ->orWhere('vehicles.last_gps_update', '<', now()->subMinutes(15))
                            ->orWhereNull('vehicles.last_gps_update');
                    });
                }
            });
    }

    // ─── Formatting Helpers ──────────────────────────────────────────

    public static function renderBsx(Vehicle $record): string
    {
        $plate = e($record->plate_number);
        $tonnage = '';

        if ($record->load_capacity) {
            $val = (float) $record->load_capacity;
            if ($val > 0) {
                $ton = match (true) {
                    $val >= 13.0 && $val <= 14.5 => '14',
                    $val >= 4.5 && $val <= 5.8 => '5',
                    $val >= 6.0 && $val <= 7.5 => '7',
                    $val >= 2.0 && $val <= 2.6 => '2.5',
                    $val >= 1.0 && $val <= 1.5 => '1.25',
                    default => (string) round($val),
                };
                $tonnage = "{$ton}T";
            }
        }

        $extra = '';
        if ($record->vehicle_type?->value === 'cold') {
            $extra .= ' <span class="text-blue-500 font-normal text-xs">cool</span>';
        }
        if ($record->vehicle_type?->value === 'flatbed') {
            $extra .= ' <span class="text-amber-500 font-normal text-xs">mâm</span>';
        }

        return "<div><span class='font-bold'>{$plate}</span>".($tonnage ? " <span class='text-xs text-gray-500 font-medium'>{$tonnage}</span>" : '').$extra.'</div>';
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
                return 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300 border border-rose-300';
            }
            if ($record->status === VehicleStatus::Bdsc) {
                return 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-300';
            }

            return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 border border-slate-300 dark:border-slate-700';
        }

        if ($activeTrip->isPaused()) {
            return 'bg-amber-500 text-white font-bold animate-pulse shadow-sm';
        }

        $status = $activeTrip->status instanceof TripStatus
            ? $activeTrip->status
            : TripStatus::tryFrom((string) $activeTrip->status);

        return match ($status) {
            TripStatus::Pending => 'bg-slate-200 text-slate-800 dark:bg-slate-700 dark:text-slate-200 font-semibold',
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
            $isGpsLost = $record->car_status === 5
                || ($record->last_gps_update instanceof \DateTimeInterface && $record->last_gps_update->diffInHours(now()) >= 24);
        } catch (\Throwable) {
            $isGpsLost = false;
        }

        if ($isGpsLost) {
            return 'Mất GPS';
        }

        if ($record->car_status === 0) {
            return 'Ko tắt máy';
        }

        return 'Xe dừng';
    }

    public static function getTodayTripsCount(Vehicle $record): int
    {
        return $record->trips
            ->filter(fn (Trip $t) => $t->started_at && $t->started_at->isToday())
            ->count();
    }

    public static function getKmOver(Vehicle $record): string
    {
        if (! $record->isRent()) {
            return '—';
        }

        $monthlyKm = TripCheckpoint::query()
            ->where('vehicle_id', $record->id)
            ->whereMonth('occurred_at', now()->month)
            ->whereYear('occurred_at', now()->year)
            ->max('km_reading');

        if ($monthlyKm === null) {
            return '—';
        }

        $limit = 3000;
        $over = (float) $monthlyKm - $limit;

        if ($over <= 0) {
            return '—';
        }

        return '+'.number_format($over, 0, ',', '.');
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
        $activeTrip = $record->active_trip;
        $driver = $activeTrip?->driver ?? $record->getDriverForToday() ?? $record->driver ?? $record->evenDriver ?? $record->oddDriver;
        $val = $driver?->station?->value ?? (! empty($record->driver_station) && $record->driver_station !== 'OTHER' ? (string) $record->driver_station : null);

        if (! $val) {
            $prefix = substr($record->plate_number, 0, 2);
            $val = match ($prefix) {
                '20' => 'TN',
                '99' => 'BN',
                '29', '30' => 'NBA',
                default => '—',
            };
        }

        return $val;
    }

    public static function getShiftLabel(Vehicle $record): string
    {
        $activeTrip = $record->active_trip;
        $driver = $activeTrip?->driver ?? $record->getDriverForToday() ?? $record->driver;

        if ($driver === null) {
            return '—';
        }

        $latestShift = $driver->driverShifts
            ->sortByDesc('start_time')
            ->first();

        if ($latestShift?->shift_type) {
            return match ($latestShift->shift_type) {
                ShiftType::MorningHalf => 'X/2',
                ShiftType::NightHalf => 'Y/2',
                ShiftType::Full => 'X',
            };
        }

        if ($driver->work_shift) {
            return $driver->work_shift === DriverWorkShift::Even ? 'Ca chẵn' : 'Ca lẻ';
        }

        return '—';
    }
}
