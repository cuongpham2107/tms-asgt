<?php

namespace App\Filament\Resources\Trips\Tables;

use App\Filament\BaseTable;
use App\Filament\Tables\Columns\UniqueMapColumn;
use App\Models\Trip;
use EduardoRibeiroDev\FilamentLeaflet\Enums\TileLayer;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
                    'driverSwaps.toDriver',
                    'shift',
                    'orders.customer',
                    'orders.pickupLocation',
                    'orders.deliveryPoints.location',
                    'orders.area',
                ])
            )
            ->columns([
                TextColumn::make('vehicle.plate_number')
                    ->label('BSX')
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
                    ->state(fn (Trip $record): string => self::getDeliveryDestination($record)),

                TextColumn::make('order_count')
                    ->label('Số đơn')
                    ->state(fn (Trip $record): string => (string) $record->orders->count()),

                TextColumn::make('drivers')
                    ->label('Lái xe')
                    ->state(fn (Trip $record): string => self::getDrivers($record))
                    ->searchable(),

                TextColumn::make('km')
                    ->label('KM')
                    ->state(fn (Trip $record): string => self::getKmDisplay($record)),

                UniqueMapColumn::make('gps_position')
                    ->label('Vị trí thực tế trên GPS')
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

                TextColumn::make('created_at')
                    ->label('Thời gian')
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
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordActions([
                Action::make('view_timeline')
                    ->label('Hành trình')
                    ->icon('heroicon-o-map-pin')
                    ->color('primary')
                    ->modal()
                    ->modalWidth('5xl')
                    ->modalHeading(fn (Trip $record): string => 'Hành trình — '.$record->vehicle?->plate_number)
                    ->modalContent(fn (Trip $record) => view('filament.resources.trips.components.trip-timeline-popup', [
                        'trip' => $record,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng'),
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
            ? number_format((float) $vehicle->load_capacity, 1, ',', '.').'T'
            : '';

        return trim("{$plate} {$tonnage}");
    }

    public static function getPickupLocations(Trip $record): string
    {
        $orders = $record->orders->sortBy('planned_loading_at');

        if ($orders->isEmpty()) {
            return '—';
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
            return '—';
        }

        $destinations = [];
        foreach ($orders as $order) {
            foreach ($order->deliveryPoints->sortBy('sequence') as $dp) {
                $destinations[] = $dp->address ?? $dp->location?->name;
            }
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

        if ($record->driver) {
            $names[] = $record->driver->name;
        }

        foreach ($record->driverSwaps->sortBy('created_at') as $swap) {
            if ($swap->toDriver) {
                $names[] = $swap->toDriver->name;
            }
        }

        $names = array_unique(array_filter($names));

        return ! empty($names) ? implode(' → ', $names) : '—';
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
}
