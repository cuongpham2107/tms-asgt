<?php

namespace App\Filament\Resources\Trips\Tables;

use App\Enums\CheckpointType;
use App\Enums\MapTile;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Filament\Actions\ActivityLogTimelineTableAction;
use App\Filament\BaseTable;
use App\Filament\Resources\Trips\Actions\CancelTripAction;
use App\Filament\Resources\Trips\Actions\CopyTrackingLinkAction;
use App\Filament\Resources\Trips\Actions\CopyTransportInfoAction;
use App\Filament\Resources\Trips\Actions\ReassignDriverAction;
use App\Filament\Resources\Trips\Actions\ReassignTransportAction;
use App\Filament\Resources\Trips\Actions\ResolveKmReportAction;
use App\Filament\Resources\Trips\Actions\ViewTripPhotosAction;
use App\Filament\Resources\Trips\Schemas\TripForm;
use App\Filament\Tables\Columns\UniqueMapColumn;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Services\Notification\DriverNotificationService;
use App\Services\OsrmService;
use App\Services\Trip\Handlers\CompletedHandler;
use App\Services\Trip\TripCheckpointOsmValidationService;
use App\Services\TripKmAdjustmentService;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\CircleMarker;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\Polyline;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class TripsTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        $kmSnapshotBeforeEdit = null;

        return parent::applyDefaults($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'vehicle',
                    'driver',
                    'driverSwaps.toDriver',
                    'driverSwaps.fromDriver',
                    'driverSwapCheckpoints' => fn ($q) => $q->where('checkpoint_type', 'driver_swap'),
                    'shift',
                    'startLocation',
                    'endLocation',
                    'orders.customer',
                    'orders.pickupLocation',
                    'orders.deliveryPoints.location',
                    'orders.area',
                    'latestPendingKmReport',
                ])
                ->withCount('photos')
            )
            ->columns([
                TextColumn::make('vehicle.plate_number')
                    ->label('BSX')
                    ->html()
                    ->state(fn (Trip $record): string => self::renderBsx($record)),

                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->color(fn (Trip $record): string => $record->getStatusColor())
                    ->state(fn (Trip $record): string => $record->getStatusLabel()),

                TextColumn::make('pickup_locations')
                    ->label('Điểm đi')
                    ->state(fn (Trip $record): string => self::getPickupLocations($record)),

                TextColumn::make('delivery_destination')
                    ->label('Điểm đến')
                    ->state(fn (Trip $record): string => self::getDeliveryDestination($record))
                    ->wrap(),

                TextColumn::make('order_count')
                    ->label('Số đơn')
                    ->html()
                    ->weight(FontWeight::Bold)
                    ->alignCenter()
                    ->state(function (Trip $record): string {
                        $codes = $record->orders->pluck('order_code')->filter()->values();
                        if ($codes->isEmpty()) {
                            return '—';
                        }

                        $badges = $codes->map(fn ($c) => '<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-bold bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">'.e($c).'</span>')->implode(' ');

                        return '<div class="flex flex-wrap gap-1 justify-center">'.$badges.'</div>';
                    })
                    ->wrap(),

                TextColumn::make('drivers')
                    ->label('Lái xe')
                    ->state(fn (Trip $record): string => self::getDrivers($record))
                    ->searchable(),

                TextColumn::make('driver_swap')
                    ->label('Đảo lái')
                    ->badge()
                    ->color(fn (Trip $record): string => self::hasDriverSwap($record) ? 'warning' : 'gray')
                    ->state(fn (Trip $record): string => self::hasDriverSwap($record) ? 'Có' : '—')
                    ->icon(fn (Trip $record): ?string => self::hasDriverSwap($record) ? 'heroicon-o-arrows-right-left' : null),

                TextColumn::make('km')
                    ->label('KM')
                    ->state(fn (Trip $record): string => self::getKmDisplay($record)),
                TextColumn::make('km_report_status')
                    ->label('Báo sai Km')
                    ->badge()
                    ->color(fn (?Trip $record): ?string => $record?->latestPendingKmReport ? 'warning' : null)
                    ->icon(fn (?Trip $record): ?string => $record?->latestPendingKmReport ? 'heroicon-o-exclamation-triangle' : null)
                    ->placeholder('—')
                    ->state(fn (?Trip $record): ?string => $record?->latestPendingKmReport
                        ? number_format((float) $record->latestPendingKmReport->reported_km, 1, ',', '.').' km'
                        : null
                    )
                    ->action(ResolveKmReportAction::make('resolve_km_report_column'))
                    ->toggleable(),
                TextColumn::make('gps_speed')
                    ->label('Tốc độ')
                    ->state(fn (Trip $record): string => $record->vehicle?->gps_speed !== null
                        ? number_format((float) $record->vehicle->gps_speed, 1).' km/h'
                        : '—'),

                UniqueMapColumn::make('gps_position')
                    ->label('Vị trí GPS')
                    ->height(72)
                    ->width(90)
                    ->zoom(14)
                    // Một lớp bản đồ cho ô nhỏ: nhiều lớp sẽ hiện nút đổi lớp che mất bản đồ
                    ->tileLayersUrl(MapTile::Streets->value)
                    ->static()
                    ->placeholder('—')
                    ->state(fn (Trip $record): ?array => ($record->vehicle?->gps_lat && $record->vehicle?->gps_lng)
                        ? [
                            'lat' => (float) $record->vehicle->gps_lat,
                            'lng' => (float) $record->vehicle->gps_lng,
                        ]
                        : null
                    )
                    ->action(
                        Action::make('select')
                            ->modal()
                            ->modalWidth(Width::SixExtraLarge)
                            ->modalHeading(fn (Trip $record): string => 'Vị trí xe & Lộ trình chuyến — '.($record->vehicle?->plate_number ?? $record->trip_code))
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('Đóng')
                            ->modalContent(fn (Trip $record): HtmlString => new HtmlString(Blade::render(<<<'BLADE'
                                <div class="space-y-4">
                                    {{-- Thẻ thông tin xe, lái xe, GPS và đơn hàng --}}
                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                                        <div class="rounded-xl border border-gray-200 bg-gray-50/80 p-3.5 dark:border-gray-700 dark:bg-gray-800/80">
                                            <div class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Phương tiện & Lái xe</div>
                                            <div class="mt-1.5 flex items-center gap-2">
                                                <span class="text-base font-bold text-amber-700 dark:text-amber-400">
                                                    {{ $trip->vehicle?->plate_number ?? 'Chưa gán xe' }}
                                                </span>
                                                @if ($trip->vehicle?->vehicle_type)
                                                    <span class="text-[11px] font-medium rounded bg-gray-200/80 px-1.5 py-0.5 text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                                        {{ $trip->vehicle->vehicle_type }}
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="text-xs text-gray-600 dark:text-gray-300 mt-1">
                                                Lái xe: <strong class="text-gray-900 dark:text-white">{{ $trip->driver?->name ?? 'Chưa gán' }}</strong>
                                                @if ($trip->driver?->phone)
                                                    <span class="text-gray-500">({{ $trip->driver->phone }})</span>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="rounded-xl border border-gray-200 bg-gray-50/80 p-3.5 dark:border-gray-700 dark:bg-gray-800/80">
                                            <div class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Trạng thái GPS</div>
                                            <div class="mt-1.5 flex items-center gap-2">
                                                @if ($trip->vehicle?->gps_lat && $trip->vehicle?->gps_lng)
                                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                                        <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                                        Có tín hiệu GPS
                                                    </span>
                                                    @if ($trip->vehicle->gps_speed !== null)
                                                        <span class="text-xs font-bold text-blue-700 dark:text-blue-300">
                                                            ⚡ {{ number_format((float) $trip->vehicle->gps_speed, 1) }} km/h
                                                        </span>
                                                    @endif
                                                @else
                                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-200/70 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                                                        Chưa có GPS
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="text-xs text-gray-600 dark:text-gray-300 mt-1 truncate" title="{{ $trip->vehicle?->gps_address ?? 'Vị trí chưa xác định' }}">
                                                📍 {{ $trip->vehicle?->gps_address ?? 'Vị trí chưa xác định' }}
                                            </div>
                                        </div>

                                        <div class="rounded-xl border border-gray-200 bg-gray-50/80 p-3.5 dark:border-gray-700 dark:bg-gray-800/80">
                                            <div class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Cập nhật GPS lúc</div>
                                            <div class="mt-1.5 text-sm font-bold text-gray-900 dark:text-white">
                                                {{ $trip->vehicle?->last_gps_update?->format('H:i:s d/m/Y') ?? '—' }}
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                                @if ($trip->vehicle?->last_gps_update)
                                                    {{ $trip->vehicle->last_gps_update->diffForHumans() }}
                                                @else
                                                    Chưa có dữ liệu thời gian
                                                @endif
                                            </div>
                                        </div>

                                        <div class="rounded-xl border border-gray-200 bg-gray-50/80 p-3.5 dark:border-gray-700 dark:bg-gray-800/80">
                                            <div class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Chuyến & Đơn hàng</div>
                                            <div class="mt-1.5 flex items-center justify-between">
                                                <span class="text-sm font-bold text-primary-600 dark:text-primary-400">{{ $trip->trip_code }}</span>
                                                <span class="text-xs font-medium text-gray-500">{{ $trip->orders->count() }} đơn</span>
                                            </div>
                                            <div class="text-xs text-gray-600 dark:text-gray-300 mt-1 truncate" title="{{ $trip->orders->pluck('order_code')->implode(', ') }}">
                                                {{ $trip->orders->isNotEmpty() ? $trip->orders->pluck('order_code')->implode(', ') : 'Chưa có đơn hàng' }}
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Khung bản đồ kèm bộ kích hoạt resize tự động tránh lỗi màn hình xám trên máy yếu --}}
                                    <div 
                                        x-data="{ 
                                            init() { 
                                                setTimeout(() => window.dispatchEvent(new Event('resize')), 150);
                                                setTimeout(() => window.dispatchEvent(new Event('resize')), 400);
                                                setTimeout(() => window.dispatchEvent(new Event('resize')), 800);
                                            } 
                                        }" 
                                        class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900"
                                        style="min-height: 450px;"
                                    >
                                        <x-filament-leaflet::map :config="$mapConfig" widget />
                                    </div>

                                    {{-- Chú thích các điểm trên bản đồ và lộ trình --}}
                                    <div class="flex flex-col sm:flex-row flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-gray-50/80 px-4 py-3 text-xs dark:border-gray-700 dark:bg-gray-800/80">
                                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                                            <span class="font-bold text-gray-700 dark:text-gray-300">Chú thích:</span>
                                            <span class="inline-flex items-center gap-1.5 font-semibold text-gray-800 dark:text-gray-200">
                                                🚛 Vị trí xe hiện tại
                                            </span>
                                            <span class="inline-flex items-center gap-1.5 text-gray-600 dark:text-gray-300">
                                                <span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span> Điểm xuất phát
                                            </span>
                                            <span class="inline-flex items-center gap-1.5 text-gray-600 dark:text-gray-300">
                                                <span class="h-2.5 w-2.5 rounded-full bg-amber-500"></span> Điểm lấy hàng
                                            </span>
                                            <span class="inline-flex items-center gap-1.5 text-gray-600 dark:text-gray-300">
                                                <span class="h-2.5 w-2.5 rounded-full bg-red-500"></span> Điểm giao hàng
                                            </span>
                                            <span class="inline-flex items-center gap-1.5 text-blue-600 dark:text-blue-400 font-medium">
                                                ━━ Tuyến đường bám đường
                                            </span>
                                            @if ($trip->checkpoints->whereNotNull('gps_lat')->isNotEmpty())
                                                <span class="inline-flex items-center gap-1.5 text-gray-600 dark:text-gray-300">
                                                    <span class="h-2.5 w-2.5 rounded-full bg-sky-400"></span> Mốc đã chạy qua
                                                </span>
                                            @endif
                                        </div>
                                        <div class="text-[11px] text-gray-500 dark:text-gray-400 italic">
                                            * Bản đồ mở tại vị trí xe — lăn chuột để xem chi tiết hoặc rộng hơn
                                        </div>
                                    </div>
                                </div>
                            BLADE, [
                                'trip' => $record,
                                'mapConfig' => self::buildGpsMapConfig($record),
                            ]))),
                    ),

                TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime('H:i d/m/Y'),

                TextColumn::make('shift_info')
                    ->label('Ca')
                    ->badge()
                    ->state(fn (Trip $record): string => self::getShiftLabel($record)),
            ])
            ->groups([
                Group::make('vehicle.plate_number')
                    ->label('Phương tiện'),
            ])
            ->searchable(false)

            // Trang hiện ngay, dữ liệu tải ngay sau (bảng lớn)
            ->deferLoading()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordActions([
                ReassignTransportAction::make(),
                ReassignDriverAction::make(),
                ActionGroup::make([
                    Action::make('view_timeline')
                        ->label('Hành trình')
                        ->icon('heroicon-o-map-pin')
                        ->color('primary')
                        ->modal()
                        ->modalWidth(Width::MaxContent)
                        ->modalHeading(fn (Trip $record): string => 'Hành trình — '.$record->vehicle?->plate_number)
                        ->modalContent(function (Trip $record) {
                            $osmValidation = app(TripCheckpointOsmValidationService::class)->validateTrip($record);

                            return view('filament.resources.trips.components.trip-timeline-popup', [
                                'trip' => $record,
                                'osmValidation' => $osmValidation,
                            ]);
                        })
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Đóng'),

                    ViewTripPhotosAction::make(),

                    CopyTrackingLinkAction::make(),

                    EditAction::make()
                        ->stickyModalFooter()
                        ->modal()
                        ->modalWidth(Width::SevenExtraLarge)
                        ->modalHeading(fn (Trip $record): string => 'Sửa chuyến — '.$record->trip_code)
                        ->mutateRecordDataUsing(function (array $data, Trip $record): array {
                            $record->loadMissing(['startLocation', 'endLocation']);

                            return $data;
                        })
                        ->schema(fn (Schema $schema): Schema => TripForm::configure($schema))
                        ->before(function (Trip $record) use (&$kmSnapshotBeforeEdit): void {
                            $kmSnapshotBeforeEdit = app(TripKmAdjustmentService::class)->kmSnapshot($record);
                        })
                        ->using(function (Model $record, array $data): Model {
                            $record->loadMissing(['vehicle', 'orders']);

                            // Field trạng thái đã bị khoá. Chuyến chỉ kết thúc khi admin đặt completed_at
                            // hoặc mốc cuối cùng là "Kết thúc xe". Mốc "Hoàn thành" là theo từng đơn.
                            $lastCheckpoint = $record->checkpoints()
                                ->reorder()
                                ->orderByDesc('occurred_at')
                                ->orderByDesc('id')
                                ->first();

                            if ($lastCheckpoint?->checkpoint_type === CheckpointType::End) {
                                if (blank($data['completed_at'] ?? null)) {
                                    $data['completed_at'] = $lastCheckpoint->occurred_at;
                                }

                                if ($lastCheckpoint->km_reading !== null) {
                                    $data['end_km'] = $lastCheckpoint->km_reading;
                                }
                            }

                            $isEnding = filled($data['completed_at'] ?? null);

                            if ($isEnding) {
                                $data['status'] = TripStatus::Completed;
                            } elseif (self::applyCompletedCheckpoints($record)) {
                                $data['status'] = TripStatus::Delivered;
                            }

                            $oldDriverId = $record->driver_id;
                            $newDriverId = $data['driver_id'] ?? null;

                            $effectiveDriverId = $newDriverId ?: $record->driver_id;
                            if ($effectiveDriverId && (empty($record->shift_id) || ($newDriverId && (int) $oldDriverId !== (int) $newDriverId))) {
                                $shiftId = DriverShift::where('driver_id', $effectiveDriverId)
                                    ->when($record->vehicle_id, fn ($q) => $q->where('vehicle_id', $record->vehicle_id))
                                    ->whereNull('end_time')
                                    ->latest('start_time')
                                    ->value('id')
                                    ?? DriverShift::where('driver_id', $effectiveDriverId)
                                        ->whereNull('end_time')
                                        ->latest('start_time')
                                        ->value('id')
                                    ?? DriverShift::where('driver_id', $effectiveDriverId)
                                        ->when($record->vehicle_id, fn ($q) => $q->where('vehicle_id', $record->vehicle_id))
                                        ->latest('start_time')
                                        ->value('id')
                                    ?? DriverShift::where('driver_id', $effectiveDriverId)
                                        ->latest('start_time')
                                        ->value('id');

                                if ($shiftId) {
                                    $data['shift_id'] = $shiftId;
                                }
                            }

                            $record->update($data);

                            // Chuyến hoàn thành -> toàn bộ đơn chưa huỷ cũng hoàn thành.
                            if ($isEnding) {
                                $record->orders()
                                    ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Completed->value])
                                    ->get()
                                    ->each(fn ($order) => $order->update(['status' => OrderStatus::Completed]));
                            }

                            if ($newDriverId && (int) $oldDriverId !== (int) $newDriverId) {
                                $oldDriver = $oldDriverId ? User::find($oldDriverId) : null;
                                $newDriver = User::find($newDriverId);

                                if ($newDriver !== null) {
                                    try {
                                        app(DriverNotificationService::class)->sendTripReassigned($record, $newDriver, $oldDriver);
                                    } catch (\Throwable) {
                                    }
                                }

                                if ($oldDriver !== null) {
                                    try {
                                        app(DriverNotificationService::class)->sendTripUnassigned($record, $oldDriver);
                                    } catch (\Throwable) {
                                    }
                                }
                            }

                            return $record;
                        })
                        ->after(function (Trip $record) use (&$kmSnapshotBeforeEdit): void {
                            $service = app(TripKmAdjustmentService::class);

                            if ($kmSnapshotBeforeEdit === $service->kmSnapshot($record)) {
                                return;
                            }

                            $service->recalculateAfterEdit($record);
                            $service->closePendingReportsAfterEdit($record, Auth::id());
                        }),

                    ResolveKmReportAction::make(),

                    CopyTransportInfoAction::make(),
                    CancelTripAction::make(),
                    DeleteAction::make(),
                    ActivityLogTimelineTableAction::make('Activities')
                        ->hidden(fn () => ! auth()->user()->hasRole('super_admin'))
                        ->label('Lịch sử')
                        ->icon('heroicon-m-clock')
                        ->color('info')
                        ->timelineIcons([
                            'created' => 'heroicon-m-check-badge',
                            'updated' => 'heroicon-m-pencil-square',
                            'deleted' => 'heroicon-m-trash',
                        ])
                        ->timelineIconColors([
                            'created' => 'success',
                            'updated' => 'warning',
                            'deleted' => 'danger',
                        ]),
                ])->button()
                    ->color('gray')
                    ->size('xs'),

            ], position: RecordActionsPosition::BeforeColumns);
    }

    /**
     * Áp các mốc "Hoàn thành" do điều hành nhập giống như khi lái xe gửi từ app
     * (điểm giao, đơn, loaded_km, trạng thái xe).
     *
     * @return bool true khi mọi đơn chưa huỷ của chuyến đã hoàn thành
     */
    private static function applyCompletedCheckpoints(Trip $record): bool
    {
        if (in_array($record->status, [TripStatus::Completed, TripStatus::Cancelled, TripStatus::DriverSwap], true)) {
            return false;
        }

        $handler = app(CompletedHandler::class);

        $record->checkpoints()
            ->where('checkpoint_type', CheckpointType::Completed->value)
            ->get()
            ->each(fn (TripCheckpoint $checkpoint) => $handler->handle(
                $record,
                ['occurred_at' => $checkpoint->occurred_at->toDateTimeString()],
                collect([$checkpoint]),
            ));

        $activeOrders = $record->orders()->where('status', '!=', OrderStatus::Cancelled->value);

        return $activeOrders->exists()
            && (clone $activeOrders)->where('status', '!=', OrderStatus::Completed->value)->doesntExist();
    }

    private static function renderBsx(Trip $record): string
    {
        $vehicle = $record->vehicle;

        if ($vehicle === null) {
            return '—';
        }

        $plate = $vehicle->plate_number;
        $tonnage = $vehicle->load_capacity
            ? ' '.number_format((float) $vehicle->load_capacity, 1, ',', '.').'T'
            : '';

        $typeBadges = $record->orders->pluck('type')->filter()->unique()->values();
        $badgeColors = [
            OrderType::Hhhk->value => ['bg' => '#eef2ff', 'text' => '#4f46e5', 'darkBg' => '#312e81', 'darkText' => '#a5b4fc'],
            OrderType::External->value => ['bg' => '#ecfdf5', 'text' => '#059669', 'darkBg' => '#064e3b', 'darkText' => '#6ee7b7'],
        ];
        $badges = $typeBadges->map(function ($type) use ($badgeColors) {
            $c = $badgeColors[$type->value] ?? ['bg' => '#f3f4f6', 'text' => '#6b7280', 'darkBg' => '#374151', 'darkText' => '#d1d5db'];

            return '<span class="fi-badge inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium" style="background-color: '.$c['bg'].'; color: '.$c['text'].';">'.$type->getLabel().'</span>';
        })->implode(' ');

        $html = '<div class="flex flex-col">';
        if ($vehicle->type === VehicleOwnerType::Rent) {
            $html .= '<div class="mt-0.5 flex flex-col gap-0.5 leading-tight">';
            if ($vehicle->plate_number) {
                $html .= '<span class="text-sm font-semibold text-gray-900 dark:text-gray-100">'.e($vehicle->plate_number).' - '.e($vehicle->owner).'</span>';
            }
            if ($vehicle->vehicle_type) {
                $html .= '<span class="text-xs font-medium text-gray-500 dark:text-gray-400">'.e($vehicle->vehicle_type->getLabel()).'</span>';
            }
            $html .= '</div>';
        } else {
            $html .= '<span class="font-semibold text-sm">'.e($plate).e($tonnage).'</span>';
            if ($badges !== '') {
                $html .= '<span class="mt-1">'.$badges.'</span>';
            }
        }

        $html .= '</div>';

        return $html;
    }

    public static function getPickupLocations(Trip $record): string
    {
        $orders = $record->orders->sortBy('planned_loading_at');

        if ($orders->isEmpty()) {
            return $record->startLocation?->code ?? '—';
        }

        $pickups = [];
        foreach ($orders as $order) {
            $code = $order->pickupLocation?->code ?? $order->pickup_address;
            if ($code) {
                $pickups[] = $code;
            }
        }

        $deduped = [];
        foreach ($pickups as $p) {
            if (empty($deduped) || end($deduped) !== $p) {
                $deduped[] = $p;
            }
        }

        if (empty($deduped)) {
            return '—';
        }

        return implode(' → ', $deduped);
    }

    public static function getDeliveryDestination(Trip $record): string
    {
        $orders = $record->orders->sortBy('planned_loading_at');

        if ($orders->isEmpty()) {
            return $record->endLocation?->code ?? '—';
        }

        $destinations = [];
        foreach ($orders as $order) {
            foreach ($order->deliveryPoints->sortBy('sequence') as $dp) {
                $code = $dp->location?->code ?? $dp->address;
                if ($code) {
                    $destinations[] = $code;
                }
            }
        }

        $deduped = [];
        foreach ($destinations as $d) {
            if (empty($deduped) || end($deduped) !== $d) {
                $deduped[] = $d;
            }
        }

        if (empty($deduped)) {
            return $record->endLocation?->code ?? '—';
        }

        return implode(' → ', $deduped);
    }

    private static function getDrivers(Trip $record): string
    {
        $names = [];
        $swaps = $record->driverSwaps->sortBy('created_at');

        if ($swaps->isNotEmpty()) {
            $firstSwap = $swaps->first();
            if ($firstSwap->fromDriver) {
                $names[] = $firstSwap->fromDriver->name;
            }

            foreach ($swaps as $swap) {
                if ($swap->toDriver) {
                    $names[] = $swap->toDriver->name;
                }
            }
        } elseif ($record->driver) {
            $names[] = $record->driver->name;
        }

        return ! empty($names) ? implode(' → ', $names) : '—';
    }

    private static function hasDriverSwap(Trip $record): bool
    {
        if ($record->driverSwaps->isNotEmpty()) {
            return true;
        }

        if ($record->relationLoaded('driverSwapCheckpoints') && $record->driverSwapCheckpoints->isNotEmpty()) {
            return true;
        }

        if ($record->status === TripStatus::DriverSwap) {
            return true;
        }

        return false;
    }

    private static function getKmDisplay(Trip $record): string
    {
        if ($record->end_km !== null && $record->start_km !== null) {
            $totalKm = (float) $record->end_km - (float) $record->start_km;

            return $totalKm > 0 ? number_format($totalKm, 1, ',', '.').' km' : '—';
        }

        if ($record->start_km !== null) {
            $currentKm = $record->vehicle?->current_mileage;
            if ($currentKm !== null) {
                $diff = (float) $currentKm - (float) $record->start_km;

                return $diff > 0 ? number_format($diff, 1, ',', '.').' km' : '—';
            }
        }

        return '—';
    }

    private static function getShiftLabel(Trip $record): string
    {
        return $record->shift?->shift_type?->getLabel() ?? '—';
    }

    private static function buildGpsMapConfig(Trip $record): array
    {
        $record->loadMissing([
            'vehicle',
            'driver',
            'startLocation',
            'endLocation',
            'orders.pickupLocation',
            'orders.deliveryPoints.location',
            'checkpoints',
        ]);

        $vehicle = $record->vehicle;
        $hasVehicleGps = $vehicle && $vehicle->gps_lat !== null && $vehicle->gps_lng !== null;
        $vehicleLat = $hasVehicleGps ? (float) $vehicle->gps_lat : null;
        $vehicleLng = $hasVehicleGps ? (float) $vehicle->gps_lng : null;

        $layers = [];
        $routePoints = [];
        $allCoordinates = [];

        // 1. Điểm xuất phát của chuyến (Kho đi)
        if ($record->startLocation && $record->startLocation->lat !== null && $record->startLocation->lng !== null) {
            $sLat = (float) $record->startLocation->lat;
            $sLng = (float) $record->startLocation->lng;
            $sName = $record->startLocation->name ?? 'Điểm xuất phát';
            $sAddress = $record->startLocation->address ?? $sName;

            $routePoints[] = [$sLat, $sLng];
            $allCoordinates[] = [$sLat, $sLng];

            $layers[] = Marker::make($sLat, $sLng)
                ->id('start-'.$record->getKey())
                ->green()
                ->title('Xuất phát: '.$sName)
                ->popupContent('<div class="p-1 space-y-1"><strong class="text-emerald-600 font-bold">🏁 Điểm xuất phát</strong><div class="text-xs font-semibold text-gray-800">'.$sName.'</div><div class="text-xs text-gray-500">'.$sAddress.'</div></div>')
                ->tooltipContent('Xuất phát: '.$sName)
                ->toArray();
        }

        // 2. Điểm nhận hàng (từ các đơn hàng trong chuyến)
        foreach ($record->orders as $order) {
            if ($order->pickupLocation && $order->pickupLocation->lat !== null && $order->pickupLocation->lng !== null) {
                $pLat = (float) $order->pickupLocation->lat;
                $pLng = (float) $order->pickupLocation->lng;
                $pName = $order->pickupLocation->name ?? $order->pickup_address ?? 'Điểm lấy hàng';
                $pAddress = $order->pickup_address ?? $order->pickupLocation->address ?? $pName;

                $routePoints[] = [$pLat, $pLng];
                $allCoordinates[] = [$pLat, $pLng];

                $layers[] = Marker::make($pLat, $pLng)
                    ->id('pickup-order-'.$order->getKey())
                    ->orange()
                    ->title('Lấy hàng: '.$pName)
                    ->popupContent('<div class="p-1 space-y-1"><strong class="text-amber-600 font-bold">📦 Điểm lấy hàng (#'.$order->order_code.')</strong><div class="text-xs font-semibold text-gray-800">'.$pName.'</div><div class="text-xs text-gray-500">'.$pAddress.'</div></div>')
                    ->tooltipContent('Lấy hàng: '.$pName.' (#'.$order->order_code.')')
                    ->toArray();
            }
        }

        // 3. Điểm giao hàng (theo thứ tự các chặng)
        foreach ($record->orders as $order) {
            $dps = $order->deliveryPoints->sortBy('sequence');
            foreach ($dps as $dp) {
                if ($dp->location && $dp->location->lat !== null && $dp->location->lng !== null) {
                    $dLat = (float) $dp->location->lat;
                    $dLng = (float) $dp->location->lng;
                    $dName = $dp->address ?? $dp->location->name ?? ('Điểm giao '.$dp->sequence);

                    $routePoints[] = [$dLat, $dLng];
                    $allCoordinates[] = [$dLat, $dLng];

                    $layers[] = Marker::make($dLat, $dLng)
                        ->id('delivery-dp-'.$dp->getKey())
                        ->red()
                        ->title('Giao hàng: '.$dName)
                        ->popupContent('<div class="p-1 space-y-1"><strong class="text-red-600 font-bold">🎯 Điểm giao hàng #'.$dp->sequence.' (#'.$order->order_code.')</strong><div class="text-xs font-semibold text-gray-800">'.$dName.'</div><div class="text-xs text-gray-500">Giao cho đơn '.$order->order_code.'</div></div>')
                        ->tooltipContent('Giao #'.$dp->sequence.': '.$dName)
                        ->toArray();
                }
            }
        }

        // 4. Điểm kết thúc của chuyến (Kho về)
        if ($record->endLocation && $record->endLocation->lat !== null && $record->endLocation->lng !== null) {
            $eLat = (float) $record->endLocation->lat;
            $eLng = (float) $record->endLocation->lng;
            $eName = $record->endLocation->name ?? 'Điểm kết thúc';
            $eAddress = $record->endLocation->address ?? $eName;

            $routePoints[] = [$eLat, $eLng];
            $allCoordinates[] = [$eLat, $eLng];

            $layers[] = Marker::make($eLat, $eLng)
                ->id('end-'.$record->getKey())
                ->blue()
                ->title('Kết thúc: '.$eName)
                ->popupContent('<div class="p-1 space-y-1"><strong class="text-blue-600 font-bold">🏁 Điểm kết thúc</strong><div class="text-xs font-semibold text-gray-800">'.$eName.'</div><div class="text-xs text-gray-500">'.$eAddress.'</div></div>')
                ->tooltipContent('Kết thúc: '.$eName)
                ->toArray();
        }

        // 5. Mốc checkpoint thực tế đã ghi nhận trong chuyến
        $realCheckpoints = $record->checkpoints
            ->filter(fn ($c) => $c->gps_lat !== null && $c->gps_lng !== null)
            ->sortBy('occurred_at');

        foreach ($realCheckpoints as $cp) {
            $cpLat = (float) $cp->gps_lat;
            $cpLng = (float) $cp->gps_lng;
            $allCoordinates[] = [$cpLat, $cpLng];

            $layers[] = CircleMarker::make($cpLat, $cpLng)
                ->id('checkpoint-'.$cp->getKey())
                ->radius(6)
                ->color('#0284c7')
                ->fillColor('#38bdf8')
                ->fillOpacity(0.85)
                ->weight(2)
                ->tooltipContent(($cp->checkpoint_type?->getLabel() ?? 'Mốc').' ('.($cp->occurred_at?->format('H:i d/m') ?? '').')')
                ->toArray();
        }

        // 6. Vẽ tuyến đường lộ trình (OSRM bám đường + fallback đường thẳng nét đứt)
        if (count($routePoints) >= 2) {
            // Tuyến đường dự kiến nét đứt
            $layers[] = Polyline::make($routePoints)
                ->id('route-planned-'.$record->getKey())
                ->color('#94a3af')
                ->weight(2)
                ->opacity(0.4)
                ->dashArray(5, 5)
                ->fill(false)
                ->toArray();

            // Tuyến đường bám đường OSRM
            $osrm = app(OsrmService::class);
            $osrmRoute = $osrm->getRouteFromPoints($routePoints);

            if (count($osrmRoute) >= 2) {
                $layers[] = Polyline::make($osrmRoute)
                    ->id('route-osrm-'.$record->getKey())
                    ->color('#2563eb')
                    ->weight(5)
                    ->opacity(0.85)
                    ->fill(false)
                    ->tooltipContent('Tuyến đường bám đường')
                    ->toArray();
            } else {
                $layers[] = Polyline::make($routePoints)
                    ->id('route-direct-'.$record->getKey())
                    ->color('#2563eb')
                    ->weight(4)
                    ->opacity(0.8)
                    ->fill(false)
                    ->toArray();
            }
        }

        // 7. Vị trí thực tế của xe hiện tại (GPS xe)
        if ($hasVehicleGps) {
            $allCoordinates[] = [$vehicleLat, $vehicleLng];

            // Vòng tròn halo xanh tỏa ra định vị xe
            $layers[] = CircleMarker::make($vehicleLat, $vehicleLng)
                ->id('vehicle-pulse-'.$record->getKey())
                ->radius(20)
                ->color('#2563eb')
                ->fillColor('#60a5fa')
                ->fillOpacity(0.35)
                ->weight(2)
                ->tooltipContent('Xe '.$vehicle->plate_number.' (Vị trí hiện tại)')
                ->toArray();

            // Marker xe tải
            $driverName = $record->driver?->name ?? 'Chưa phân lái xe';
            $driverPhone = $record->driver?->phone ? ' ('.$record->driver->phone.')' : '';
            $speedText = $vehicle->gps_speed !== null ? number_format((float) $vehicle->gps_speed, 1).' km/h' : '0 km/h';
            $updateTime = $vehicle->last_gps_update?->format('H:i:s d/m/Y') ?? '—';
            $address = $vehicle->gps_address ?? 'Đang cập nhật địa chỉ';

            $popupHtml = '<div class="p-2 space-y-1 text-xs" style="min-width: 220px;">'
                .'<div class="font-bold text-sm text-blue-700">🚛 '.$vehicle->plate_number.'</div>'
                .'<div><strong>Lái xe:</strong> '.$driverName.$driverPhone.'</div>'
                .'<div><strong>Tốc độ:</strong> <span class="font-semibold text-emerald-600">⚡ '.$speedText.'</span></div>'
                .'<div><strong>Địa chỉ GPS:</strong> '.$address.'</div>'
                .'<div class="text-[11px] text-gray-500"><strong>Cập nhật:</strong> '.$updateTime.'</div>'
                .'</div>';

            $layers[] = Marker::make($vehicleLat, $vehicleLng)
                ->id('gps-vehicle-'.$record->getKey())
                ->icon(asset('images/truck.png'), [40, 40])
                ->title('Xe '.$vehicle->plate_number)
                ->popupContent($popupHtml)
                ->toArray();
        }

        // Tọa độ tâm mặc định: ưu tiên xe -> điểm đầu tiên của lộ trình -> Hà Nội
        $centerLat = $vehicleLat ?? ($routePoints[0][0] ?? 21.0285);
        $centerLng = $vehicleLng ?? ($routePoints[0][1] ?? 105.8542);

        $hasFitBounds = count($allCoordinates) >= 2;

        return [
            'mapId' => 'gps-map-'.$record->getKey(),
            'mapHeight' => 450,
            'defaultCoord' => [$centerLat, $centerLng],
            'autoCenter' => false, // Không lấy vị trí trình duyệt của người điều hành
            // Có GPS xe thì mở đúng chỗ xe đang đứng, ở mức khu vực (~30 km) và để
            // người dùng tự lăn chuột. fitBounds sẽ fit sát các mốc rất gần nhau của
            // một chuyến và khoá luôn zoom (plugin lấy zoomConfig.max làm maxZoom).
            'fitBounds' => $hasFitBounds && ! $hasVehicleGps,
            'defaultZoom' => $hasVehicleGps ? 11 : ($hasFitBounds ? 10 : 13),
            'geoJsonColors' => [],
            'geoJsonData' => [],
            'infoText' => '',
            'tileLayersUrl' => MapTile::config(),
            'layerGroupsData' => [],
            'layersData' => $layers,
            // max cũng là maxZoom của bản đồ: phải để cao thì lăn chuột mới zoom vào được.
            'zoomConfig' => ['max' => 19, 'min' => 3],
            'mapConfig' => [],
            'mapControls' => [],
            'geoSearchConfig' => [],
            'geoJsonUrl' => null,
            'customStyles' => '',
            'customScripts' => '',
        ];
    }
}
