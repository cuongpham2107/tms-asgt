<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Filament\BaseTable;
use App\Filament\Resources\Orders\Actions\AssignTransportAction;
use App\Filament\Resources\Orders\Actions\BulkAssignTransportAction;
use App\Filament\Resources\Orders\Actions\BulkSendOrderAction;
use App\Filament\Resources\Orders\Actions\CancelOrderAction;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Filament\Resources\Orders\Actions\CopyTransportInfoAction;
use App\Filament\Resources\Orders\Actions\CreateReturnTripAction;
use App\Filament\Resources\Orders\Actions\SendOrderAction;
use App\Filament\Resources\Orders\Actions\UnsendOrderAction;
use App\Filament\Tables\Columns\UniqueMapColumn;
use App\Models\Order;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\OsrmService;
use EduardoRibeiroDev\FilamentLeaflet\Enums\TileLayer;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\CircleMarker;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\Polyline;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class OrdersTable extends BaseTable
{
    public static function configure(Table $table, string $type = 'default'): Table
    {
        return parent::applyDefaults($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'deliveryPoints.location',
                'area',
                'pickupLocation',
                'trip.vehicle',
                'trip.driver',
                'tripCheckpoints' => fn ($q) => $q->orderByDesc('occurred_at'),
            ]))
            ->columns([
                TextColumn::make('order_code')
                    ->label('Đơn hàng')
                    ->searchable()
                    ->weight('bold')
                    ->formatStateUsing(function (Order $record): HtmlString {
                        $orderCode = e($record->order_code);
                        $orderTypeLabel = e($record->type?->getLabel() ?? 'Chưa xác định');
                        $priorityColor = $record->priority?->getColor() ?? 'gray';
                        $priorityBadgeClasses = self::getStatusBadgeClasses($priorityColor);
                        $priorityLabel = e($record->priority?->getLabel() ?? 'Chưa xác định');

                        return new HtmlString(<<<HTML
                            <div class="inline-flex flex-col gap-1">
                                <span class="font-bold leading-5 text-[#008fd5] dark:text-blue-100">{$orderCode}</span>
                                <div class="inline-flex items-center gap-2">
                                    <span class="rounded-full border border-primary-100 bg-primary-100 px-1.5 py-0.5 text-xs font-semibold text-primary-800 dark:border-primary-800/50 dark:bg-primary-950/40 dark:text-primary-100">{$orderTypeLabel}</span>
                                </div>
                            </div>
                        HTML);
                    })
                    ->html(),

                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->color(fn ($state): string => (is_object($state) && method_exists($state, 'getColor')) ? ($state->getColor() ?? 'gray') : 'gray')
                    ->icon(fn ($state): ?string => (is_object($state) && method_exists($state, 'getIcon')) ? $state->getIcon() : null)
                    ->sortable(),
                TextColumn::make('transport_info')
                    ->label('Phương tiện / Lái xe')
                    ->state(fn (Order $record): string => $record->trip?->vehicle?->plate_number
                        ?? $record->trip?->driver?->name
                        ?? '')
                    ->formatStateUsing(fn ($state, Order $record): HtmlString => self::renderTransportColumn($record))
                    ->html()
                    ->hidden(fn (): bool => $type === 'plan'),
                TextColumn::make('customer.code')
                    ->label('Khách hàng')
                    ->weight('bold')
                    ->description(fn (Order $record): string => $record->cargo_name ?? '')
                    ->searchable(),
                TextColumn::make('route_timeline')
                    ->state(fn (Order $record): string => $record->order_code)
                    ->formatStateUsing(fn (Order $record): HtmlString => self::renderRouteTimeline($record))
                    ->label('Hành trình')
                    ->html()
                    ->wrap(),

                TextColumn::make('planned_loading_at')
                    ->label('Thời gian đóng hàng')
                    ->dateTime('H:i d/m/Y')
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('total_packages')
                    ->label('Tải trọng/Số kiện')
                    ->formatStateUsing(function (Order $record): HtmlString {
                        $totalPackages = number_format((float) ($record->total_packages ?? 0), 0, ',', '.');
                        $totalWeight = number_format((float) ($record->total_weight ?? 0), 0, ',', '.');

                        return new HtmlString(<<<HTML
                                <div class="flex flex-col gap-1 leading-tight">
                                    <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">Số kiện: {$totalPackages}</div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">Tải trọng: {$totalWeight} tấn</div>
                                </div>
                            HTML);
                    })
                    ->hidden(fn (): bool => $type === 'plan')
                    ->sortable(),

                TextColumn::make('notes')
                    ->label('Ghi chú')
                    ->limit(50),
                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y')
                    ->sortable(),
                UniqueMapColumn::make('map_coords')
                    ->label('Bản đồ')
                    ->height(72)
                    ->zoom(12)
                    ->static()
                    ->action(
                        Action::make('select')
                            ->modal()
                            ->modalWidth('4xl')
                            ->modalHeading(fn (Order $record): string => 'Bản đồ — '.$record->order_code)
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('Đóng')
                            ->modalContent(fn (Order $record): HtmlString => new HtmlString(Blade::render(<<<'BLADE'
                                <div class="space-y-4">
                                    <div class="flex flex-wrap items-center gap-3 rounded-lg bg-gray-50 px-4 py-3 dark:bg-gray-800">
                                        <div>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">Mã đơn</span>
                                            <span class="ml-2 text-sm font-bold text-gray-900 dark:text-white">{{ $order->order_code }}</span>
                                        </div>

                                        <div>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">Khách hàng</span>
                                            <span class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">{{ $order->customer?->name ?? '—' }}</span>
                                        </div>

                                        @if ($order->trip?->vehicle)
                                            <div>
                                                <span class="text-xs text-gray-500 dark:text-gray-400">Xe</span>
                                                <span class="ml-2 text-sm font-bold text-amber-700 dark:text-amber-300">{{ $order->trip->vehicle->plate_number }}</span>
                                            </div>
                                        @endif

                                        @if ($order->trip?->driver)
                                            <div>
                                                <span class="text-xs text-gray-500 dark:text-gray-400">Lái xe</span>
                                                <span class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">{{ $order->trip->driver->name }}</span>
                                            </div>
                                        @endif

                                        <div>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">Điểm lấy</span>
                                            <span class="ml-2 text-sm font-medium text-emerald-700 dark:text-emerald-300">
                                                {{ $order->pickupLocation?->name ?? $order->pickup_address ?? '—' }}
                                            </span>
                                        </div>

                                        @foreach ($order->deliveryPoints->sortBy('sequence') as $dp)
                                            <div>
                                                <span class="text-xs text-gray-500 dark:text-gray-400">Điểm giao {{ $dp->sequence }}</span>
                                                <span class="ml-2 text-sm font-medium text-red-700 dark:text-red-300">
                                                    {{ $dp->address ?? $dp->location?->name ?? '—' }}
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                                        <x-filament-leaflet::map :config="$mapConfig" widget />
                                    </div>

                                    <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-xs text-blue-700 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-300">
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-500"></span> Điểm lấy hàng
                                        </span>
                                        <span class="mx-2">·</span>
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="inline-block h-2.5 w-2.5 rounded-full bg-red-500"></span> Điểm giao hàng
                                        </span>
                                        @if ($order->deliveryPoints->filter(fn ($dp) => $dp->location?->lat !== null)->isNotEmpty())
                                            <span class="mx-2">·</span>
                                            <span class="text-blue-500">━━</span> Tuyến đường
                                        @endif
                                        @if ($order->trip?->vehicle)
                                            <span class="mx-2">·</span>
                                            <span class="inline-flex items-center gap-1.5">🚛 Vị trí xe</span>
                                        @endif
                                    </div>
                                </div>
                            BLADE, [
                                'order' => $record,
                                'mapConfig' => self::buildOrderMapConfig($record),
                            ]))),
                    ),
                TextColumn::make('createdBy.name')
                    ->label('Người tạo')
                    ->searchable(),

                TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime('H:i d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->stackedOnMobile()
            ->searchable(false)
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->slideOver()
                        ->stickyModalFooter()
                        ->modalWidth(Width::ScreenExtraLarge)
                        ->modalDescription(fn (Order $record): string => 'Loại đơn hàng: '.($record->type?->getLabel() ?? 'Chưa xác định'))
                        ->mutateRecordDataUsing(function (array $data, Order $record): array {
                            $data['vehicle_id'] = $record->trip?->vehicle_id;
                            $data['driver_id'] = $record->trip?->driver_id;

                            return $data;
                        })
                        ->using(function (Order $record, array $data): Order {
                            $vehicleId = $data['vehicle_id'] ?? null;
                            $driverId = $data['driver_id'] ?? null;

                            unset($data['vehicle_id'], $data['driver_id']);

                            $record->update($data);

                            if (filled($vehicleId) && filled($driverId)) {
                                if ($record->trip) {
                                    $record->trip->update([
                                        'vehicle_id' => $vehicleId,
                                        'driver_id' => $driverId,
                                    ]);
                                } else {
                                    $trip = Trip::create([
                                        'trip_code' => Trip::generateTripCode(),
                                        'vehicle_id' => $vehicleId,
                                        'driver_id' => $driverId,
                                        'status' => TripStatus::Pending,
                                    ]);

                                    $record->update([
                                        'trip_id' => $trip->id,
                                        'status' => OrderStatus::Assigned,
                                    ]);
                                }

                                $vehicle = Vehicle::query()->find($vehicleId);
                                if ($vehicle !== null) {
                                    $vehicle->status = VehicleStatus::Running;
                                    $vehicle->save();
                                }
                            }

                            return $record;
                        }),
                    AssignTransportAction::make(),
                    SendOrderAction::make(),
                    UnsendOrderAction::make(),
                    CreateReturnTripAction::make(),
                    CancelOrderAction::make(),
                    DeleteAction::make()
                        ->hidden(fn (Order $record): bool => ! $record->status->canDelete())
                        ->requiresConfirmation()
                        ->modalHeading('Xác nhận xóa đơn')
                        ->modalDescription('Bạn chắc chắn muốn xóa đơn hàng này? Chỉ đơn ở trạng thái Nháp hoặc Đã hủy mới có thể xóa.')
                        ->modalSubmitActionLabel('Xóa')
                        ->modalCancelActionLabel('Hủy'),
                    ReplicateAction::make()
                        ->label('Sao chép đơn hàng')
                        ->icon('heroicon-o-document-duplicate')
                        ->color('gray')
                        ->excludeAttributes([
                            'order_code', 'created_by',
                            'sent_at', 'cancelled_at', 'cancel_reason',
                            'parent_order_id',
                        ])
                        ->mutateRecordDataUsing(function (array $data): array {
                            $data['order_code'] = CreatesOrderTransportCards::generateOrderCode();
                            $data['status'] = OrderStatus::Draft;
                            $data['trip_id'] = null;
                            $data['trip_sequence'] = null;
                            $data['created_by'] = auth()->id();

                            return $data;
                        })
                        ->form([
                            DateTimePicker::make('planned_loading_at')
                                ->label('Thời gian đóng hàng')
                                ->required()
                                ->native(false),
                        ])
                        ->after(function ($replica, $record): void {
                            foreach ($record->deliveryPoints as $dp) {
                                $replica->deliveryPoints()->create([
                                    'location_id' => $dp->location_id,
                                    'sequence' => $dp->sequence,
                                    'address' => $dp->address,
                                    'contact_person' => $dp->contact_person,
                                    'contact_phone' => $dp->contact_phone,
                                    'total_packages' => $dp->total_packages,
                                    'total_weight' => $dp->total_weight,
                                    'status' => $dp->status,
                                ]);
                            }
                        })
                        ->successNotificationTitle('Đã sao chép đơn hàng'),
                    CopyTransportInfoAction::make(),
                ]),
            ], position: RecordActionsPosition::BeforeColumns)
            ->groups([
                Group::make('area.code')
                    ->label('Loại khu vực')
                    ->getTitleFromRecordUsing(fn (Order $record): string => $record->area?->code ?? 'Chưa xác định')
                    ->collapsible(),
            ])
            // ->defaultGroup('area.code')
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),

                BulkAssignTransportAction::make(),
                BulkSendOrderAction::make(),
            ]);
    }

    private static function renderRouteTimeline(Order $record): HtmlString
    {
        $pickupLocationName = $record->pickupLocation?->name ?: $record->pickup_address ?: 'Chưa có điểm lấy';
        $deliveryPoints = $record->deliveryPoints->sortBy('sequence');
        $locations = collect([e($pickupLocationName)]);

        foreach ($deliveryPoints as $deliveryPoint) {
            $locations->push(
                e($deliveryPoint->location->name ?: $deliveryPoint->location?->code ?: 'Chưa có điểm đến')
            );
        }

        if ($deliveryPoints->isEmpty()) {
            $locations->push(e('Chưa có điểm đến'));
        }

        $arrowIcon = <<<'SVG'
                        <svg  xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                            fill="currentColor" viewBox="0 0 24 24" >
                            <path d="M6 13h8.09l-3.3 3.29 1.42 1.42 5.7-5.71-5.7-5.71-1.42 1.42 3.3 3.29H6z"></path>
                        </svg>
                    SVG;

        $timeline = $locations
            ->map(function (string $location, int $index) use ($locations, $arrowIcon): string {
                $isLast = $index === $locations->count() - 1;

                return '
                                    <div class="flex items-center gap-1">
                                        <div class="
                                            text-sm
                                            whitespace-nowrap
                                        ">
                                            '.$location.'
                                        </div>
                                        '.(! $isLast ? $arrowIcon : '').'
                                    </div>
                                ';
            })
            ->implode('');

        return new HtmlString(
            '
                            <div class="
                                flex items-center flex-wrap gap-2
                                p-2
                            ">
                                '.$timeline.'
                            </div>
                            '
        );
    }

    private static function renderTransportColumn(Order $record): HtmlString
    {
        $trip = $record->trip;
        $vehiclePlate = e($trip?->vehicle?->plate_number ?? 'Chưa phân phương tiện');
        $driverName = e($trip?->driver?->name ?? 'Chưa phân lái xe');

        return new HtmlString(<<<HTML
            <div class="flex flex-col items-center gap-1.5 leading-tight text-center">
                <div class="inline-flex items-center gap-2 px-1.5 py-0.5 text-xs font-semibold text-gray-700 dark:text-gray-200">
                    <span class="font-bold text-sm text-black">{$vehiclePlate}</span>
                </div>
                <div class="inline-flex items-center gap-2 px-1.5 py-0.5 text-xs font-medium text-gray-500 dark:text-gray-300">
                    <span class="whitespace-nowrap">{$driverName}</span>
                </div>
            </div>
        HTML);
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildOrderMapConfig(Order $record): array
    {
        $pickupLat = (float) ($record->pickupLocation?->lat ?? 10.8231);
        $pickupLng = (float) ($record->pickupLocation?->lng ?? 106.6297);

        $layers = [];
        $routePoints = [[$pickupLat, $pickupLng]];
        $routeLabels = [$record->pickupLocation?->name ?? $record->pickup_address ?? 'Điểm lấy hàng'];

        $pickupMarker = Marker::make($pickupLat, $pickupLng)
            ->id('pickup-'.$record->getKey())
            ->green()
            ->title($routeLabels[0])
            ->popupContent($record->pickup_address ?? '');
        $layers[] = $pickupMarker->toArray();

        $trip = $record->trip;

        if ($trip?->vehicle_id !== null) {
            $truckCoords = $record->map_coords;
            $layers[] = Marker::make((float) $truckCoords['lat'], (float) $truckCoords['lng'])
                ->id('truck-'.$record->getKey())
                ->icon(asset('images/truck.png'), [38, 38])
                ->title($trip->vehicle?->plate_number ?? 'Xe')
                ->popupContent(($trip->vehicle?->plate_number ?? '').' — '.($trip->driver?->name ?? 'Chưa phân lái xe'))
                ->toArray();
        }

        $deliveryPoints = $record->deliveryPoints->sortBy('sequence');

        foreach ($deliveryPoints as $dp) {
            $location = $dp->location;

            if ($location === null || $location->lat === null || $location->lng === null) {
                continue;
            }

            $dpLat = (float) $location->lat;
            $dpLng = (float) $location->lng;
            $routePoints[] = [$dpLat, $dpLng];

            $dpName = $dp->address ?? $location->name ?? 'Điểm giao '.$dp->sequence;
            $routeLabels[] = $dpName;

            $dpMarker = Marker::make($dpLat, $dpLng)
                ->id('delivery-'.$dp->getKey())
                ->red()
                ->title($dpName)
                ->popupContent($dpName);
            $layers[] = $dpMarker->toArray();
        }

        if (count($routePoints) >= 2) {
            $segmentColors = [
                '#22c55e',
                '#3b82f6',
                '#8b5cf6',
                '#f59e0b',
                '#ef4444',
            ];

            $osrm = app(OsrmService::class);

            $layers[] = Polyline::make($routePoints)
                ->id('route-gps-'.$record->getKey())
                ->color('#9ca3af')
                ->weight(2)
                ->opacity(0.35)
                ->dashArray(4, 6)
                ->fill(false)
                ->toArray();

            $layers[] = CircleMarker::make($routePoints[0][0], $routePoints[0][1])
                ->id('start-'.$record->getKey())
                ->radius(8)
                ->color('#16a34a')
                ->fillColor('#22c55e')
                ->fillOpacity(0.8)
                ->weight(3)
                ->tooltipContent('Lấy hàng: '.$routeLabels[0])
                ->toArray();

            $lastIdx = count($routePoints) - 1;
            $layers[] = CircleMarker::make($routePoints[$lastIdx][0], $routePoints[$lastIdx][1])
                ->id('end-'.$record->getKey())
                ->radius(8)
                ->color('#dc2626')
                ->fillColor('#ef4444')
                ->fillOpacity(0.8)
                ->weight(3)
                ->tooltipContent('Giao hàng: '.$routeLabels[$lastIdx])
                ->toArray();

            for ($i = 0; $i < count($routePoints) - 1; $i++) {
                $segment = [$routePoints[$i], $routePoints[$i + 1]];
                $osrmSegment = $osrm->getRouteFromPoints($segment);
                $color = $segmentColors[$i % count($segmentColors)];
                $label = ($routeLabels[$i] ?? '?').' → '.($routeLabels[$i + 1] ?? '?');
                $isLastSegment = $i === (count($routePoints) - 2);

                if (count($osrmSegment) >= 2) {
                    $layers[] = Polyline::make($osrmSegment)
                        ->id('route-seg'.$i.'-'.$record->getKey())
                        ->color($color)
                        ->weight($isLastSegment ? 8 : 6)
                        ->opacity($isLastSegment ? 0.95 : 0.9)
                        ->fill(false)
                        ->tooltipContent($label)
                        ->toArray();

                    if ($isLastSegment) {
                        $layers[] = Polyline::make($osrmSegment)
                            ->id('route-seg'.$i.'-'.$record->getKey().'-highlight')
                            ->color($color)
                            ->weight(12)
                            ->opacity(0.18)
                            ->fill(false)
                            ->toArray();
                    }
                } else {
                    $layers[] = Polyline::make($segment)
                        ->id('route-seg'.$i.'-'.$record->getKey())
                        ->color($color)
                        ->weight($isLastSegment ? 6 : 4)
                        ->opacity(0.75)
                        ->dashArray(8, 4)
                        ->fill(false)
                        ->tooltipContent($label.' (ước lượng)')
                        ->toArray();
                }
            }
        }

        $hasFitBounds = count($routePoints) >= 2;

        return [
            'mapId' => 'order-map-'.$record->getKey(),
            'mapHeight' => 340,
            'defaultCoord' => [$pickupLat, $pickupLng],
            'autoCenter' => false,
            'fitBounds' => $hasFitBounds,
            'defaultZoom' => $hasFitBounds ? 10 : 12,
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
            'zoomConfig' => ['max' => 10, 'min' => 0],
            'mapConfig' => [],
            'mapControls' => [],
            'geoSearchConfig' => [],
            'geoJsonUrl' => null,
            'customStyles' => '',
            'customScripts' => '',
        ];
    }

    private static function getStatusBadgeClasses(string $color): string
    {
        return match ($color) {
            'blue' => 'bg-blue-100 text-blue-800 border border-blue-100 dark:bg-blue-950/40 dark:text-blue-100 dark:border-blue-800/50',
            'cyan' => 'bg-cyan-100 text-cyan-800 border border-cyan-100 dark:bg-cyan-950/40 dark:text-cyan-100 dark:border-cyan-800/50',
            'danger' => 'bg-red-100 text-red-800 border border-red-100 dark:bg-red-950/40 dark:text-red-100 dark:border-red-800/50',
            'warning' => 'bg-amber-100 text-amber-800 border border-amber-100 dark:bg-amber-950/40 dark:text-amber-100 dark:border-amber-800/50',
            'amber' => 'bg-amber-100 text-amber-800 border border-amber-100 dark:bg-amber-950/40 dark:text-amber-100 dark:border-amber-800/50',
            'orange' => 'bg-orange-100 text-orange-800 border border-orange-100 dark:bg-orange-950/40 dark:text-orange-100 dark:border-orange-800/50',
            'lime' => 'bg-lime-100 text-lime-800 border border-lime-100 dark:bg-lime-950/40 dark:text-lime-100 dark:border-lime-800/50',
            'success' => 'bg-emerald-100 text-emerald-800 border border-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-100 dark:border-emerald-800/50',
            'info' => 'bg-sky-100 text-sky-800 border border-sky-100 dark:bg-sky-950/40 dark:text-sky-100 dark:border-sky-800/50',
            'purple' => 'bg-purple-100 text-purple-800 border border-purple-100 dark:bg-purple-950/40 dark:text-purple-100 dark:border-purple-800/50',
            'slate' => 'bg-slate-100 text-slate-800 border border-slate-100 dark:bg-slate-950/40 dark:text-slate-100 dark:border-slate-800/50',
            'primary' => 'bg-primary-100 text-primary-800 border border-primary-100 dark:bg-primary-900/40 dark:text-primary-100 dark:border-primary-800/50',
            default => 'bg-gray-100 text-gray-800 border border-gray-100 dark:bg-gray-900/40 dark:text-gray-100 dark:border-gray-700',
        };
    }
}
