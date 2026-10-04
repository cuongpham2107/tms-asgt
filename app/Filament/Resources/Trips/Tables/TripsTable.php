<?php

namespace App\Filament\Resources\Trips\Tables;

use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Filament\Actions\ActivityLogTimelineTableAction;
use App\Filament\BaseTable;
use App\Filament\Resources\Trips\Actions\AdjustTripKmAction;
use App\Filament\Resources\Trips\Actions\AssignDriverAction;
use App\Filament\Resources\Trips\Actions\CancelTripAction;
use App\Filament\Resources\Trips\Actions\ReassignTransportAction;
use App\Filament\Resources\Trips\Actions\SendTripAction;
use App\Filament\Resources\Trips\Schemas\TripForm;
use App\Filament\Tables\Columns\UniqueMapColumn;
use App\Models\Trip;
use App\Models\User;
use App\Services\Gps\TripTrack;
use App\Services\Trip\TripDriverService;
use App\Services\Trip\TripStateMachine;
use EduardoRibeiroDev\FilamentLeaflet\Enums\TileLayer;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;
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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
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
                    'driverAssignments.driver',
                    'shift',
                    'startLocation',
                    'endLocation',
                    'orders.customer',
                    'orders.pickupLocation',
                    'orders.deliveryPoints.location',
                    'orders.area',
                ])
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
                TextColumn::make('km_review')
                    ->label('Km GPS')
                    ->badge()
                    ->state(fn (Trip $record): string => match (true) {
                        $record->km_calculated_at === null => '—',
                        $record->km_needs_review => 'Cần kiểm tra',
                        $record->km_adjusted !== null => 'Đã điều chỉnh',
                        default => 'OK',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Cần kiểm tra' => 'danger',
                        'Đã điều chỉnh' => 'warning',
                        'OK' => 'success',
                        default => 'gray',
                    })
                    ->tooltip(fn (Trip $record): ?string => $record->km_calculated_at === null ? null : sprintf(
                        'Nguồn: %s · Phủ GPS: %s%%',
                        $record->km_source ?? '—',
                        number_format((float) $record->gps_coverage, 0),
                    )),
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
            ->filters([
                Filter::make('km_needs_review')
                    ->label('Cần kiểm tra km')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('km_needs_review', true)),
            ])

            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->recordActions([
                SendTripAction::make(),
                ReassignTransportAction::make(),
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
                        ->modalWidth(Width::SevenExtraLarge)
                        ->modalHeading(fn (Trip $record): string => 'Sửa chuyến — '.$record->trip_code)
                        ->mutateRecordDataUsing(function (array $data, Trip $record): array {
                            $record->loadMissing(['startLocation', 'endLocation']);

                            return $data;
                        })
                        ->form(fn (Schema $schema): Schema => TripForm::configure($schema))
                        ->using(function (Model $record, array $data): Model {
                            $record->loadMissing(['vehicle', 'orders']);

                            $completesExternalTrip = $record->vehicle?->type === VehicleOwnerType::Rent
                                && filled($data['completed_at'] ?? null)
                                && $record->status !== TripStatus::Completed;

                            $newDriverId = $data['driver_id'] ?? null;
                            $driverChanged = array_key_exists('driver_id', $data) && (int) $record->driver_id !== (int) $newDriverId;
                            unset($data['driver_id']);

                            $record->update($data);

                            if ($driverChanged) {
                                $newDriver = $newDriverId ? User::find($newDriverId) : null;
                                $newDriver !== null
                                    ? app(TripDriverService::class)->replaceDriver($record, $newDriver, auth()->user())
                                    : ($record->status === TripStatus::Pending ? app(TripDriverService::class)->unassign($record) : null);
                            }

                            if ($completesExternalTrip) {
                                app(TripStateMachine::class)->completeExternalTrip($record);
                            }

                            return $record;
                        }),

                    AssignDriverAction::make(),
                    AdjustTripKmAction::make(),
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
        $names = $record->driverAssignments
            ->map(fn ($assignment) => $assignment->driver?->name)
            ->filter()
            ->values();

        if ($names->isEmpty() && $record->driver) {
            $names->push($record->driver->name);
        }

        $label = $names
            ->reject(fn ($name, $index) => $index > 0 && $names[$index - 1] === $name)
            ->implode(' → ');

        if ($record->status === TripStatus::DriverSwap) {
            $label .= ' → (chờ lái)';
        }

        return $label !== '' ? $label : '—';
    }

    private static function hasDriverSwap(Trip $record): bool
    {
        return $record->status === TripStatus::DriverSwap || $record->driverAssignments->count() > 1;
    }

    private static function getKmDisplay(Trip $record): string
    {
        $totalKm = $record->reportedTotalKm();

        return $totalKm !== null && $totalKm > 0 ? number_format($totalKm, 1, ',', '.').' km' : '—';
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

        $track = app(TripTrack::class)->segments($record);
        foreach ($track as $segment) {
            $layers[] = Polyline::make($segment['points'])
                ->color($segment['loaded'] ? '#F97316' : '#3B82F6')
                ->weight(4)
                ->toArray();
        }

        return [
            'mapId' => 'gps-map-'.$record->getKey(),
            'mapHeight' => 340,
            'defaultCoord' => [$lat, $lng],
            'autoCenter' => true,
            'fitBounds' => $track !== [],
            'defaultZoom' => 15,
            'geoJsonColors' => [],
            'geoJsonData' => [],
            'infoText' => $track !== [] ? 'Cam: có hàng · Xanh: không hàng' : '',
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
}
