<?php

namespace App\Filament\Resources\Trips\Tables;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Filament\BaseTable;
use App\Filament\Resources\Trips\Actions\CancelTripAction;
use App\Filament\Resources\Trips\Actions\DriverSwapAction;
use App\Filament\Resources\Trips\Actions\ReassignDriverAction;
use App\Filament\Resources\Trips\Schemas\TripForm;
use App\Filament\Tables\Columns\UniqueMapColumn;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Services\ShiftKmCalculatorService;
use App\Services\TripKmAdjustmentService;
use App\Services\TripKmCalculatorService;
use EduardoRibeiroDev\FilamentLeaflet\Enums\TileLayer;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class TripsTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'vehicle',
                    'driver',
                    'driverSwaps.toDriver',
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
                ->where(fn (Builder $q) => $q
                    ->whereDoesntHave('orders')
                    ->orWhereHas('orders', fn (Builder $sub) => $sub->where('status', '!=', OrderStatus::Assigned->value))
                )
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
                    ->alignCenter()
                    ->state(function (Trip $record): string {
                        $codes = $record->orders->pluck('order_code')->filter()->values();
                        if ($codes->isEmpty()) {
                            return '—';
                        }

                        $badges = $codes->map(fn ($c) => '<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">'.e($c).'</span>')->implode(' ');

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
                    ->toggleable(),
                TextColumn::make('gps_speed')
                    ->label('Tốc độ')
                    ->state(fn (Trip $record): string => $record->vehicle?->gps_speed !== null
                        ? number_format((float) $record->vehicle->gps_speed, 1).' km/h'
                        : '—'),

                UniqueMapColumn::make('gps_position')
                    ->label('Vị trí GPS')
                    ->height(72)
                    ->zoom(14)
                    ->static()
                    ->state(fn (Trip $record): array => [
                        'lat' => (float) ($record->vehicle?->gps_lat ?? 10.8231),
                        'lng' => (float) ($record->vehicle?->gps_lng ?? 106.6297),
                    ])
                    ->action(
                        Action::make('select')
                            ->modal()
                            ->modalWidth('4xl')
                            ->modalHeading(fn (Trip $record): string => 'Vị trí xe — '.$record->vehicle?->plate_number)
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('Đóng')
                            ->modalContent(fn (Trip $record): HtmlString => new HtmlString(Blade::render(<<<'BLADE'
                                <div class="space-y-4">
                                    <div class="flex flex-wrap items-center gap-3 rounded-lg bg-gray-50 px-4 py-3 dark:bg-gray-800">
                                        @if ($trip->vehicle)
                                            <div>
                                                <span class="text-xs text-gray-500 dark:text-gray-400">Xe</span>
                                                <span class="ml-2 text-sm font-bold text-amber-700 dark:text-amber-300">{{ $trip->vehicle->plate_number }}</span>
                                            </div>
                                        @endif

                                        @if ($trip->orders->isNotEmpty())
                                            <div>
                                                <span class="text-xs text-gray-500 dark:text-gray-400">Đơn</span>
                                                <span class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">{{ $trip->orders->pluck('order_code')->implode(', ') }}</span>
                                            </div>
                                        @endif

                                        <div>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">Cập nhật</span>
                                            <span class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                                {{ $trip->vehicle?->last_gps_update?->format('H:i d/m/Y') ?? '—' }}
                                            </span>
                                        </div>
                                    </div>

                                    <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                                        <x-filament-leaflet::map :config="$mapConfig" widget />
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

            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordActions([
                ActionGroup::make([
                    Action::make('view_timeline')
                        ->label('Hành trình')
                        ->icon('heroicon-o-map-pin')
                        ->color('primary')
                        ->modal()
                        ->modalWidth(Width::MaxContent)
                        ->modalHeading(fn (Trip $record): string => 'Hành trình — '.$record->vehicle?->plate_number)
                        ->modalContent(fn (Trip $record) => view('filament.resources.trips.components.trip-timeline-popup', [
                            'trip' => $record,
                        ]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Đóng'),

                    EditAction::make()
                        ->stickyModalFooter()
                        ->modal()
                        ->modalWidth(Width::MaxContent)
                        ->modalHeading(fn (Trip $record): string => 'Sửa chuyến — '.$record->trip_code)
                        ->mutateRecordDataUsing(function (array $data, Trip $record): array {
                            $record->loadMissing(['startLocation', 'endLocation']);

                            return $data;
                        })
                        ->form(fn (Schema $schema): Schema => TripForm::configure($schema))
                        ->using(function (Model $record, array $data): Model {
                            $newStatus = $data['status'] ?? $record->status;

                            if ($newStatus === TripStatus::Completed && $record->vehicle?->type === VehicleOwnerType::Rent) {
                                $record->orders->each(function ($order) {
                                    $order->update(['status' => OrderStatus::Completed]);
                                });
                            }
                            $record->update($data);

                            self::recalculateKm($record);

                            return $record;
                        }),

                    Action::make('recalculate_km')
                        ->label('Tính lại Km')
                        ->icon('heroicon-o-calculator')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Tính lại số km?')
                        ->modalDescription('Sẽ tính lại total_km, loaded, empty cho trip, cập nhật km xe, và tính lại km cho tất cả ca liên quan.')
                        ->action(function (Trip $record) {
                            self::recalculateKm($record);
                            Notification::make()->success()->title('Đã tính lại km')->send();
                        }),

                    Action::make('resolve_km_report')
                        ->label('Xử lý báo sai Km')
                        ->icon('heroicon-o-exclamation-triangle')
                        ->color('warning')
                        ->visible(fn (?Trip $record): bool => $record?->latestPendingKmReport !== null)
                        ->modal()
                        ->modalWidth(Width::ExtraLarge)
                        ->modalHeading(fn (?Trip $record): string => 'Xử lý báo sai Km — '.($record?->trip_code ?? ''))
                        ->modalContent(function (Trip $record): HtmlString {
                            $report = $record->latestPendingKmReport;
                            if ($report === null) {
                                return new HtmlString('<p>Không có báo cáo nào.</p>');
                            }

                            $photoHtml = '';
                            if ($report->photo_path) {
                                $photoUrl = Storage::disk('public')->url($report->photo_path);
                                $photoHtml = '<div class="mt-3"><img src="'.e($photoUrl).'" class="max-w-full max-h-80 rounded-lg border" alt="Ảnh taplo"></div>';
                            }

                            $delta = (float) $report->reported_km - (float) ($report->system_km ?? 0);
                            $deltaFormatted = ($delta >= 0 ? '+' : '').number_format($delta, 1, ',', '.');
                            $deltaColor = $delta < 0 ? 'text-red-600' : 'text-green-600';
                            $driverName = e($report->driver?->name ?? '—');
                            $createdAt = $report->created_at ? $report->created_at->format('H:i d/m/Y') : '—';
                            $reportedKmStr = number_format((float) $report->reported_km, 1, ',', '.');
                            $systemKmStr = $report->system_km !== null ? number_format((float) $report->system_km, 1, ',', '.') : '—';
                            $noteStr = e($report->note ?? '—');

                            return new HtmlString(<<<HTML
                                <div class="space-y-3">
                                    <div class="grid grid-cols-2 gap-4 rounded-lg bg-gray-50 p-4 dark:bg-gray-800">
                                        <div>
                                            <span class="text-xs text-gray-500">Tài xế báo cáo</span>
                                            <p class="font-semibold">{$driverName}</p>
                                        </div>
                                        <div>
                                            <span class="text-xs text-gray-500">Thời gian</span>
                                            <p class="font-semibold">{$createdAt}</p>
                                        </div>
                                        <div>
                                            <span class="text-xs text-gray-500">Km lái xe báo (taplo)</span>
                                            <p class="text-lg font-bold text-amber-600">{$reportedKmStr} km</p>
                                        </div>
                                        <div>
                                            <span class="text-xs text-gray-500">Km hệ thống</span>
                                            <p class="text-lg font-bold">{$systemKmStr} km</p>
                                        </div>
                                        <div>
                                            <span class="text-xs text-gray-500">Độ lệch</span>
                                            <p class="text-lg font-bold {$deltaColor}">{$deltaFormatted} km</p>
                                        </div>
                                    </div>
                                    {$photoHtml}
                                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                        <span class="text-xs text-gray-500">Ghi chú lái xe</span>
                                        <p class="mt-1 text-sm">{$noteStr}</p>
                                    </div>
                                </div>
                            HTML);
                        })
                        ->form([
                            TextInput::make('corrected_km')
                                ->label('Số km điều chỉnh')
                                ->numeric()
                                ->required()
                                ->default(fn (?Trip $record) => $record?->latestPendingKmReport?->reported_km)
                                ->helperText('Nhập số km thực tế (mặc định lấy số lái xe báo).'),
                            Textarea::make('admin_note')
                                ->label('Ghi chú xử lý')
                                ->rows(2),
                        ])
                        ->action(function (Trip $record, array $data) {
                            $report = $record->latestPendingKmReport;
                            if ($report === null) {
                                return;
                            }

                            app(TripKmAdjustmentService::class)->resolveReport(
                                $report,
                                (float) $data['corrected_km'],
                                $data['admin_note'] ?? null,
                                auth()->id(),
                            );

                            Notification::make()
                                ->success()
                                ->title('Đã điều chỉnh km và tính lại toàn bộ chuỗi')
                                ->send();
                        })
                        ->modalSubmitActionLabel('Chấp nhận & Điều chỉnh Km'),

                    Action::make('reject_km_report')
                        ->label('Từ chối báo sai Km')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (?Trip $record): bool => $record?->latestPendingKmReport !== null)
                        ->requiresConfirmation()
                        ->modalHeading('Từ chối báo sai Km?')
                        ->form([
                            Textarea::make('admin_note')
                                ->label('Lý do từ chối')
                                ->required()
                                ->rows(2),
                        ])
                        ->action(function (Trip $record, array $data) {
                            $report = $record->latestPendingKmReport;
                            if ($report === null) {
                                return;
                            }

                            app(TripKmAdjustmentService::class)->rejectReport(
                                $report,
                                $data['admin_note'],
                                auth()->id(),
                            );

                            Notification::make()
                                ->success()
                                ->title('Đã từ chối báo cáo sai km')
                                ->send();
                        }),

                    // DriverSwapAction::make(),
                    ReassignDriverAction::make(),
                    CancelTripAction::make(),
                    DeleteAction::make(),
                ]),
            ], position: RecordActionsPosition::BeforeColumns);
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
            if ($vehicle->owner) {
                $html .= '<span class="text-sm font-semibold text-gray-900 dark:text-gray-100">'.e($vehicle->owner).'</span>';
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
            return $record->startLocation?->name ?? '—';
        }

        $pickups = [];
        foreach ($orders as $order) {
            $pickups[] = $order->pickupLocation?->name ?? $order->pickup_address;
        }

        $pickups = array_filter(array_unique($pickups));

        if (empty($pickups)) {
            return '—';
        }

        return implode(' → ', $pickups);
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
                $destinations[] = $dp->location?->code ?? $dp->address;
            }
        }

        if ($record->endLocation) {
            $destinations[] = $record->endLocation->code;
        }

        $destinations = array_filter(array_unique($destinations));

        if (empty($destinations)) {
            return '—';
        }

        return implode(' → ', $destinations);
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
        $vehicle = $record->vehicle;
        $lat = (float) ($vehicle?->gps_lat ?? 10.8231);
        $lng = (float) ($vehicle?->gps_lng ?? 106.6297);

        $layers = [];

        $layers[] = Marker::make($lat, $lng)
            ->id('gps-vehicle-'.$record->getKey())
            ->icon(asset('images/truck.png'), [38, 38])
            ->title($vehicle?->plate_number ?? 'Xe')
            ->popupContent(($vehicle?->plate_number ?? '').' — '.($record->driver?->name ?? 'Chưa phân lái xe'))
            ->toArray();

        return [
            'mapId' => 'gps-map-'.$record->getKey(),
            'mapHeight' => 340,
            'defaultCoord' => [$lat, $lng],
            'autoCenter' => true,
            'fitBounds' => false,
            'defaultZoom' => 15,
            'geoJsonColors' => [],
            'geoJsonData' => [],
            'infoText' => '',
            'tileLayersUrl' => [[
                TileLayer::OpenStreetMap->getLabel(),
                TileLayer::OpenStreetMap->getUrl(),
                TileLayer::OpenStreetMap->getAttribution(),
            ]],
            'layerGroupsData' => [],
            'layersData' => $layers,
            'zoomConfig' => ['max' => 18, 'min' => 0],
            'mapConfig' => [],
            'mapControls' => [],
            'geoSearchConfig' => [],
            'geoJsonUrl' => null,
            'customStyles' => '',
            'customScripts' => '',
        ];
    }

    private static function recalculateKm(Trip $record): void
    {
        app(TripKmCalculatorService::class)->calculate($record);
        $record->refresh();

        if ($record->end_km > 0 && $record->vehicle) {
            $record->vehicle->update(['current_mileage' => $record->end_km]);
        }

        if ($record->shift_id) {
            app(ShiftKmCalculatorService::class)->calculate($record->shift);
        }

        $swapShiftIds = $record->driverSwaps()
            ->pluck('from_shift_id')
            ->merge($record->driverSwaps()->pluck('to_shift_id'))
            ->filter()
            ->unique();

        foreach ($swapShiftIds as $shiftId) {
            $shift = DriverShift::find($shiftId);
            if ($shift) {
                app(ShiftKmCalculatorService::class)->calculate($shift);
            }
        }
    }
}
