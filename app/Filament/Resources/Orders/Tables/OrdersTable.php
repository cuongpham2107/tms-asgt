<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Filament\BaseTable;
use App\Filament\Resources\Orders\Actions\AssignTransportAction;
use App\Filament\Resources\Orders\Actions\CancelOrderAction;
use App\Filament\Resources\Orders\Actions\CopyTransportInfoAction;
use App\Filament\Resources\Orders\Actions\CreateReturnTripAction;
use App\Filament\Resources\Orders\Actions\DriverSwapAction;
use App\Filament\Resources\Orders\Actions\ReassignDriverAction;
use App\Filament\Resources\Orders\Actions\SendOrderAction;
use App\Filament\Resources\Orders\Actions\UnsendOrderAction;
use App\Models\Order;
use EduardoRibeiroDev\FilamentLeaflet\Enums\TileLayer;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;
use EduardoRibeiroDev\FilamentLeaflet\Tables\MapColumn;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class OrdersTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'deliveryPoints.location',
                'orderCategory',
                'pickupLocation',
                'tripCheckpoints' => fn ($q) => $q->orderByDesc('occurred_at'),
            ])->orderByRaw("CASE
                WHEN status = 'draft' THEN 1
                WHEN status = 'assigned' THEN 2
                WHEN status = 'sent' THEN 3
                WHEN status = 'started' THEN 4
                WHEN status = 'arrived_pickup' THEN 5
                WHEN status = 'delivering' THEN 6
                WHEN status = 'arrived_delivery' THEN 7
                WHEN status = 'delivered' THEN 8
                WHEN status = 'completed' THEN 9
                WHEN status = 'driver_swap' THEN 10
                WHEN status = 'cancelled' THEN 11
                WHEN status = 'trashed' THEN 12
            END")->orderBy('planned_loading_at'))
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
                                    <span class="rounded-full {$priorityBadgeClasses} px-1.5 py-0.5 text-xs font-semibold">{$priorityLabel}</span>
                                </div>
                            </div>
                        HTML);
                    })
                    ->html(),

                MapColumn::make('map_coords')
                    ->label('Bản đồ')
                    ->height(72)
                    ->zoom(12)
                    ->pickMarker(fn (Marker $marker) => $marker->icon(asset('images/truck.png'), [14, 25]))
                    ->circular()
                    ->static()
                    ->action(
                        Action::make('select')
                            ->slideOver()
                            ->modalWidth('4xl')
                            ->modalHeading(fn (Order $record): string => 'Bản đồ — '.$record->order_code)
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('Đóng')
                            ->stickyModalFooter()
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

                                        <div>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">Điểm lấy</span>
                                            <span class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                                {{ $order->pickupLocation?->name ?? $order->pickup_address ?? '—' }}
                                            </span>
                                        </div>
                                    </div>

                                    <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                                        <x-filament-leaflet::map :config="$mapConfig" widget />
                                    </div>

                                    <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-xs text-blue-700 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-300">
                                        Bản đồ đang dùng tọa độ lấy hàng của đơn hàng và marker xe tải mặc định.
                                    </div>
                                </div>
                            BLADE, [
                                'order' => $record,
                                'mapConfig' => self::buildOrderMapConfig($record),
                            ]))),
                    ),
                TextColumn::make('customer.name')
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
                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y')
                    ->sortable(),
                TextColumn::make('planned_loading_at')
                    ->label('Thời gian đóng hàng')
                    ->dateTime('H:i d/m/Y')
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('total_packages')
                    ->label('Tải trọng')
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
                    ->sortable(),

                TextColumn::make('vehicle.plate_number')
                    ->label('Phương tiện / Lái xe')
                    ->formatStateUsing(fn (Order $record): HtmlString => self::renderTransportColumn($record))
                    ->html()
                    ->searchable(),
                TextColumn::make('notes')
                    ->label('Ghi chú')
                    ->limit(50),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->color(fn ($state): string => (is_object($state) && method_exists($state, 'getColor')) ? ($state->getColor() ?? 'gray') : 'gray')
                    ->icon(fn ($state): ?string => (is_object($state) && method_exists($state, 'getIcon')) ? $state->getIcon() : null)
                    ->sortable(),
                TextColumn::make('createdBy.name')
                    ->label('Người tạo')
                    ->searchable(),

                TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime('H:i d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->stackedOnMobile()
            ->searchable(false)
            ->filters([
                // Filter::make('my_orders')
                //     ->label('Chỉ đơn của tôi')
                //     ->default(true)
                //     ->query(fn (Builder $query): Builder => $query->where('created_by', auth()->id())),
            ])
            ->recordActions([
                AssignTransportAction::make(),
                ActionGroup::make([
                    ViewAction::make()
                        ->slideOver()
                        ->stickyModalFooter(),
                    EditAction::make()
                        ->slideOver()
                        ->stickyModalFooter()
                        ->modalDescription(fn (Order $record): string => 'Loại đơn hàng: '.($record->type?->getLabel() ?? 'Chưa xác định')),

                    SendOrderAction::make(),
                    UnsendOrderAction::make(),
                    DriverSwapAction::make(),
                    ReassignDriverAction::make(),
                    CreateReturnTripAction::make(),
                    CancelOrderAction::make(),
                    DeleteAction::make()
                        ->slideOver()
                        ->hidden(fn (Order $record): bool => ! $record->status->canDelete())
                        ->requiresConfirmation()
                        ->modalHeading('Xác nhận xóa đơn')
                        ->modalDescription('Bạn chắc chắn muốn xóa đơn hàng này? Chỉ đơn ở trạng thái Nháp hoặc Đã hủy mới có thể xóa.')
                        ->modalSubmitActionLabel('Xóa')
                        ->modalCancelActionLabel('Hủy')
                        ->stickyModalFooter(),
                    CopyTransportInfoAction::make(),
                ]),
            ], position: RecordActionsPosition::BeforeColumns);
    }

    private static function renderRouteTimeline(Order $record): HtmlString
    {
        $pickupLocationName = e($record->pickupLocation?->name ?? $record->pickup_address ?? 'Chưa có điểm lấy');
        $deliveryPoints = $record->deliveryPoints->sortBy('sequence');
        $locations = collect([$pickupLocationName]);

        foreach ($deliveryPoints as $deliveryPoint) {
            $locations->push(
                e($deliveryPoint->address ?? $deliveryPoint->location?->name ?? 'Chưa có điểm đến')
            );
        }

        if ($deliveryPoints->isEmpty()) {
            $locations->push(e($record->orderCategory?->code ?? 'Chưa có điểm đến'));
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
        $vehiclePlate = e($record->vehicle?->plate_number ?? 'Chưa phân phương tiện');
        $driverName = e($record->driver?->name ?? 'Chưa phân lái xe');

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
        $coords = $record->map_coords;

        $marker = Marker::make((float) $coords['lat'], (float) $coords['lng'])
            ->id('order-'.$record->getKey())
            ->icon(asset('images/truck.png'), [38, 38]);

        return [
            'mapId' => 'order-map-'.$record->getKey(),
            'mapHeight' => 284,
            'defaultCoord' => [(float) $coords['lat'], (float) $coords['lng']],
            'autoCenter' => false,
            'fitBounds' => false,
            'defaultZoom' => 12,
            'geoJsonColors' => [],
            'geoJsonData' => [],
            'infoText' => '',
            'tileLayersUrl' => [[
                TileLayer::OpenStreetMap->getLabel(),
                TileLayer::OpenStreetMap->getUrl(),
                TileLayer::OpenStreetMap->getAttribution(),
            ]],
            'layerGroupsData' => [],
            'layersData' => [$marker->toArray()],
            'zoomConfig' => ['max' => 19, 'min' => 0],
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
