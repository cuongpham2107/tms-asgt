<?php

namespace App\Filament\Resources\Orders\Actions\Concerns;

use App\Enums\CheckpointType;
use App\Enums\LocationType;
use App\Enums\OrderStatus;
use App\Enums\Priority;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

abstract class CreatesOrderTransportCards
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function resolveDriverCards(): array
    {
        return User::query()
            ->role('driver')
            ->select([
                'id',
                'name',
                'phone',
                'email',
                'license_class',
                'license_number',
            ])
            ->selectSub(
                Order::selectRaw('COUNT(*)')
                    ->whereIn('trip_id', Trip::select('id')->whereColumn('trips.driver_id', 'users.id'))
                    ->whereIn('status', [OrderStatus::Assigned->value, OrderStatus::Sent->value]),
                'active_orders_count'
            )
            ->selectSub(
                Trip::selectRaw('COUNT(*)')->whereColumn('trips.driver_id', 'users.id'),
                'orders_count'
            )
            ->with([
                'driverShifts' => fn ($query) => $query->latest('start_time')->limit(1),
                'vehiclesAsDriver' => fn ($query) => $query->select('id', 'current_driver_id', 'plate_number', 'gps_lat', 'gps_lng'),
            ])
            ->orderBy('name')
            ->get()
            ->map(function (User $driver): array {
                $latestShift = $driver->driverShifts->first();
                $hasActiveShift = $latestShift && $latestShift->end_time === null;
                $assignedVehicle = $driver->vehiclesAsDriver->first();
                $activeOrders = (int) $driver->active_orders_count;
                $isAvailable = $activeOrders === 0;

                $driverLocation = null;
                if ($assignedVehicle?->gps_lat && $assignedVehicle?->gps_lng) {
                    $driverLocation = self::findNearestLocation(
                        (float) $assignedVehicle->gps_lat,
                        (float) $assignedVehicle->gps_lng,
                    );
                }

                return [
                    'value' => $driver->id,
                    'leading' => '👤',
                    'title' => $driver->name,
                    'subtitle' => $driver->phone ?: ($driver->email ?? ''),
                    'badge' => $isAvailable ? 'Sẵn sàng' : 'Đang chạy ('.$activeOrders.')',
                    'badgeClasses' => $isAvailable
                        ? 'border border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-200'
                        : 'border border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200',
                    'statusDot' => $isAvailable ? 'success' : 'warning',
                    'details' => array_values(array_filter([
                        ['icon' => 'heroicon-m-identification', 'label' => 'GPLX', 'value' => $driver->license_class ? ($driver->license_class.($driver->license_number ? ' · '.$driver->license_number : '')) : 'Chưa cập nhật'],
                        ['icon' => 'heroicon-m-truck', 'label' => 'Xe gán', 'value' => $assignedVehicle?->plate_number ?? 'Chưa gán xe'],
                        ['icon' => 'heroicon-m-clock', 'label' => 'Ca trực', 'value' => $hasActiveShift ? ('Đang '.$latestShift->shift_type?->getLabel()) : ($latestShift?->shift_type?->getLabel() ?? 'Chưa có ca')],
                        ['icon' => 'heroicon-m-map-pin', 'label' => 'Vị trí', 'value' => $driverLocation['name'] ?? 'Chưa xác định'],
                        ['icon' => 'heroicon-m-document-text', 'label' => 'Tổng chuyến', 'value' => number_format((int) $driver->orders_count, 0, ',', '.')],
                    ])),
                    'meta' => [
                        $driver->license_class ?? '',
                        $assignedVehicle?->plate_number ?? '',
                    ],
                    'isSuggested' => $isAvailable && $hasActiveShift,
                    'suggestionScore' => $isAvailable ? ($hasActiveShift ? 1000 : 500) : 0,
                    'suggestedBadge' => 'Gợi ý',
                    'suggestedBadgeClasses' => 'border border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-800/40 dark:bg-primary-900/30 dark:text-primary-200',
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function resolveVehicleCards(?float $requiredWeight, ?int $pickupLocationId, ?int $selectedVehicleId = null): array
    {
        $vehicles = Vehicle::query()
            ->select([
                'id',
                'plate_number',
                'vehicle_type',
                'make',
                'load_capacity',
                'current_driver_id',
                'status',
                'current_mileage',
                'type',
                'gps_lat',
                'gps_lng',
            ])
            ->where(function ($query) use ($selectedVehicleId): void {
                $query->whereIn('status', ['on', 'running']);

                if ($selectedVehicleId) {
                    $query->orWhere('id', $selectedVehicleId);
                }
            })
            ->selectSub(
                Order::selectRaw('COUNT(*)')
                    ->whereIn('trip_id', Trip::select('id')->whereColumn('trips.vehicle_id', 'vehicles.id'))
                    ->whereIn('status', [OrderStatus::Assigned->value, OrderStatus::Sent->value]),
                'active_orders_count'
            )
            ->with([
                'driver' => fn ($q) => $q
                    ->with(['driverShifts' => fn ($q) => $q
                        ->whereNull('end_time'),
                    ]),
            ])
            ->orderBy('plate_number')
            ->get();

        $vehicleLocations = self::resolveVehicleLocations($vehicles);

        return $vehicles
            ->map(function (Vehicle $vehicle) use ($requiredWeight, $pickupLocationId, $vehicleLocations): array {
                $loadCapacity = number_format((float) $vehicle->load_capacity, 1, ',', '.');
                $make = $vehicle->make ?: 'Chưa rõ hãng';

                $requiredWeight = $requiredWeight ?? 0;
                $isCapacityMatch = $requiredWeight <= 0 || (float) $vehicle->load_capacity >= $requiredWeight;

                $currentLocation = $vehicleLocations[$vehicle->id] ?? ['id' => null, 'name' => null];
                $isLocationMatch = ! $pickupLocationId || (($currentLocation['id'] ?? null) === $pickupLocationId);

                $hasActiveShift = $vehicle->driver?->driverShifts->isNotEmpty() ?? false;
                $hasDriver = $vehicle->current_driver_id !== null;
                $isAvailable = (int) $vehicle->active_orders_count === 0;

                $isSuggested = $isCapacityMatch && $isLocationMatch && $hasActiveShift && $hasDriver && $isAvailable;
                $capacityDelta = max(0, (float) $vehicle->load_capacity - $requiredWeight);
                $suggestionScore = $isSuggested
                    ? (1000 - min(999, (int) round($capacityDelta * 10)))
                    : 0;

                $statusLabel = $vehicle->getStatusLabel();
                $statusColor = $vehicle->getStatusColor();
                $statusClasses = self::getStatusBadgeClasses($statusColor);
                $activeOrders = (int) $vehicle->active_orders_count;
                $mileage = $vehicle->current_mileage ? number_format((float) $vehicle->current_mileage, 0, ',', '.').' km' : 'N/A';
                $ordersInShift = (int) ($vehicle->driver?->driverShifts->first()?->orders_in_shift ?? 0);

                return [
                    'value' => $vehicle->id,
                    'leading' => '🚚',
                    'title' => $vehicle->plate_number,
                    'subtitle' => $make.' · '.$vehicle->getVehicleTypeLabel(),
                    'badge' => $statusLabel,
                    'badgeClasses' => $statusClasses,
                    'statusDot' => $statusColor,
                    'details' => array_values(array_filter([
                        ['icon' => 'heroicon-m-scale', 'label' => 'Tải trọng', 'value' => $loadCapacity.' tấn'.($requiredWeight > 0 ? ($isCapacityMatch ? ' ✓' : ' ✗') : '')],
                        ['icon' => 'heroicon-m-user', 'label' => 'Lái xe', 'value' => $vehicle->driver?->name ?? 'Chưa phân lái'],
                        ['icon' => 'heroicon-m-map-pin', 'label' => 'Vị trí', 'value' => $currentLocation['name'] ?? 'Chưa xác định'],
                        ['icon' => 'heroicon-m-cog-6-tooth', 'label' => 'ODO', 'value' => $mileage],
                        $activeOrders > 0 ? ['icon' => 'heroicon-m-document-text', 'label' => 'Đơn đang chạy', 'value' => (string) $activeOrders] : null,
                        ['icon' => 'heroicon-m-document-text', 'label' => 'Đơn trong ca', 'value' => (string) $ordersInShift],
                    ])),
                    'meta' => [
                        $vehicle->driver?->name ?? '',
                        $vehicle->getVehicleTypeLabel(),
                        $loadCapacity.' tấn',
                        $currentLocation['name'] ?? '',
                    ],
                    // 'suggestedBadge' => 'Phù hợp nhất',
                    'suggestedBadgeClasses' => 'border border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-800/40 dark:bg-primary-900/30 dark:text-primary-200',
                    'isSuggested' => $isSuggested,
                    'suggestionScore' => $suggestionScore,
                    'capacityMatch' => $isCapacityMatch,
                    'type' => $vehicle->type?->value,
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, Vehicle>  $vehicles
     * @return array<int, array{id: ?int, name: ?string}>
     */
    protected static function resolveVehicleLocations(Collection $vehicles): array
    {
        $vehicleIds = $vehicles->pluck('id')->filter()->all();

        if ($vehicleIds === []) {
            return [];
        }

        $tripVehicleMap = Trip::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->select('id', 'vehicle_id')
            ->pluck('vehicle_id', 'id');

        $tripIds = $tripVehicleMap->keys()->all();

        $ordersByVehicle = collect();
        if ($tripIds !== []) {
            $ordersByVehicle = Order::query()
                ->select('orders.*', 'trips.vehicle_id')
                ->join('trips', 'orders.trip_id', '=', 'trips.id')
                ->whereIn('trips.vehicle_id', $vehicleIds)
                ->whereIn('orders.status', [
                    OrderStatus::Assigned->value,
                    OrderStatus::Sent->value,
                ])
                ->with('pickupLocation')
                ->orderBy('orders.created_at', 'desc')
                ->get()
                ->groupBy('vehicle_id')
                ->map
                ->first();
        }

        $orderIds = $ordersByVehicle->pluck('id')->filter()->all();

        $checkpoints = collect();
        if ($orderIds !== []) {
            $checkpoints = TripCheckpoint::query()
                ->whereIn('order_id', $orderIds)
                ->with('deliveryPoint.location')
                ->orderBy('occurred_at', 'desc')
                ->get()
                ->groupBy('order_id')
                ->map
                ->first();
        }

        $locations = [];

        foreach ($vehicleIds as $vehicleId) {
            $order = $ordersByVehicle[$vehicleId] ?? null;

            if ($order) {
                $checkpoint = $checkpoints[$order->id] ?? null;
                $deliveryLocation = $checkpoint?->deliveryPoint?->location;

                if ($deliveryLocation) {
                    $locations[$vehicleId] = ['id' => (int) $deliveryLocation->id, 'name' => $deliveryLocation->name];
                } else {
                    $pickupLocation = $order->pickupLocation;
                    $locations[$vehicleId] = [
                        'id' => $pickupLocation?->id ? (int) $pickupLocation->id : null,
                        'name' => $pickupLocation?->name,
                    ];
                }
            } else {
                $vehicle = $vehicles->firstWhere('id', $vehicleId);

                $locations[$vehicleId] = $vehicle !== null
                    ? self::resolveVehicleGpsLocation($vehicle)
                    : ['id' => null, 'name' => null];
            }
        }

        return $locations;
    }

    /**
     * @return array{id: ?int, name: ?string}
     */
    protected static function resolveCurrentVehicleLocation(Vehicle $vehicle): array
    {
        /** @var Order|null $activeOrder */
        $activeOrder = Order::query()
            ->whereHas('trip', fn ($q) => $q->where('vehicle_id', $vehicle->id))
            ->whereIn('status', [
                OrderStatus::Assigned->value,
                OrderStatus::Sent->value,
            ])
            ->with('pickupLocation')
            ->latest('created_at')
            ->first();

        if ($activeOrder) {
            $latestCheckpoint = $activeOrder->tripCheckpoints()
                ->with('deliveryPoint.location')
                ->latest('occurred_at')
                ->first();

            $deliveryLocation = $latestCheckpoint?->deliveryPoint?->location;

            if ($deliveryLocation) {
                return ['id' => (int) $deliveryLocation->id, 'name' => $deliveryLocation->name];
            }

            $pickupLocation = $activeOrder->pickupLocation;

            return [
                'id' => $pickupLocation?->id ? (int) $pickupLocation->id : null,
                'name' => $pickupLocation?->name,
            ];
        }

        return self::resolveVehicleGpsLocation($vehicle);
    }

    /**
     * @return array{id: ?int, name: ?string}
     */
    protected static function resolveVehicleGpsLocation(Vehicle $vehicle): array
    {
        if (! $vehicle->gps_lat || ! $vehicle->gps_lng) {
            return ['id' => null, 'name' => null];
        }

        return self::findNearestLocation(
            (float) $vehicle->gps_lat,
            (float) $vehicle->gps_lng,
        );
    }

    /**
     * @return array{id: ?int, name: ?string}
     */
    public static function findNearestLocation(float $lat, float $lng): array
    {
        /** @var array<int, array<string, mixed>> $locations */
        $locations = Cache::remember('active-locations-with-coords-v2', now()->addHour(), function (): array {
            return Location::query()
                ->whereNotNull('lat')
                ->whereNotNull('lng')
                ->where('is_active', true)
                ->get(['id', 'name', 'code', 'lat', 'lng'])
                ->toArray();
        });

        $nearest = null;
        $minDistance = PHP_FLOAT_MAX;

        foreach ($locations as $loc) {
            $dist = self::haversineDistance($lat, $lng, (float) $loc['lat'], (float) $loc['lng']);
            if ($dist < $minDistance) {
                $minDistance = $dist;
                $nearest = $loc;
            }
        }

        if ($nearest !== null && $minDistance <= 50) {
            return [
                'id' => (int) $nearest['id'],
                'name' => $nearest['name'].' ('.$nearest['code'].')',
            ];
        }

        return ['id' => null, 'name' => null];
    }

    protected static function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    protected static function normalizeDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    protected static function getLocationTypesForOrderType(string $orderType): array
    {
        return match ($orderType) {
            'HHHK' => [LocationType::Pickup, LocationType::Warehouse],
            default => [LocationType::Pickup, LocationType::Other],
        };
    }

    protected static function normalizeInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    public static function generateOrderCode(): string
    {
        $prefix = 'ASG-';

        $latestOrderCode = Order::query()
            ->withTrashed()
            ->where('order_code', 'like', $prefix.'%')
            ->orderByRaw('CAST(REPLACE(order_code, ?, \'\') AS INTEGER) DESC', [$prefix])
            ->value('order_code');

        $nextSequence = 1;

        if (is_string($latestOrderCode) && str_starts_with($latestOrderCode, $prefix)) {
            $suffix = substr($latestOrderCode, strlen($prefix));

            if (ctype_digit($suffix)) {
                $nextSequence = ((int) $suffix) + 1;
            }
        }

        return sprintf('%s%d', $prefix, $nextSequence);
    }

    protected static function isOrderCodeDuplicate(Throwable $throwable): bool
    {
        return str_contains($throwable->getMessage(), 'UNIQUE constraint failed: orders.order_code');
    }

    protected static function getStatusBadgeClasses(string $color): string
    {
        return match ($color) {
            'danger' => 'border border-red-200 bg-red-50 text-red-700 dark:border-red-900/40 dark:bg-red-950/30 dark:text-red-200',
            'warning' => 'border border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200',
            'success' => 'border border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-200',
            'info' => 'border border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-900/40 dark:bg-sky-950/30 dark:text-sky-200',
            'primary' => 'border border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-800/40 dark:bg-primary-900/30 dark:text-primary-200',
            default => 'border border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-900/30 dark:text-gray-200',
        };
    }

    /**
     * @return array<int|string, string>
     */
    public static function getProvinceOptions(): array
    {
        return Cache::remember('open-api-v1-provinces', now()->addDay(), function (): array {
            try {
                $response = Http::acceptJson()
                    ->timeout(10)
                    ->get('https://provinces.open-api.vn/api/v1/p');

                if (! $response->successful()) {
                    return [];
                }

                return collect($response->json())
                    ->filter(fn ($item): bool => isset($item['code'], $item['name']))
                    ->mapWithKeys(fn ($item): array => [(string) $item['code'] => $item['name']])
                    ->all();
            } catch (Throwable) {
                return [];
            }
        });
    }

    /**
     * @return array<int|string, string>
     */
    public static function getWardOptions(int|string|null $provinceCode): array
    {
        if (blank($provinceCode)) {
            return [];
        }

        return Cache::remember("open-api-v2-wards-{$provinceCode}", now()->addDay(), function () use ($provinceCode): array {
            try {
                $response = Http::acceptJson()
                    ->timeout(10)
                    ->get("https://provinces.open-api.vn/api/v2/w/?province={$provinceCode}");

                if (! $response->successful()) {
                    return [];
                }

                return collect($response->json())
                    ->filter(fn ($item): bool => isset($item['code'], $item['name']))
                    ->mapWithKeys(fn ($item): array => [(string) $item['code'] => $item['name']])
                    ->all();
            } catch (Throwable) {
                return [];
            }
        });
    }

    public static function resolveProvinceName(int|string|null $provinceCode): ?string
    {
        if (blank($provinceCode)) {
            return null;
        }

        return self::getProvinceOptions()[(string) $provinceCode] ?? null;
    }

    public static function resolveWardName(int|string|null $provinceCode, int|string|null $wardCode): ?string
    {
        if (blank($provinceCode) || blank($wardCode)) {
            return null;
        }

        return self::getWardOptions($provinceCode)[(string) $wardCode] ?? null;
    }

    public static function getCustomerIdFormField(bool $setAreaId = false): Select
    {
        return Select::make('customer_id')
            ->label('Khách hàng')
            ->options(fn (Get $get): array => Customer::query()
                ->get(['id', 'code', 'name'])
                ->mapWithKeys(fn (Customer $customer): array => [
                    $customer->id => "{$customer->code} - {$customer->name}",
                ])
                ->toArray()
            )
            ->native(false)
            ->required()
            ->searchable()
            ->columnSpanFull()
            ->live()
            ->afterStateUpdated(function ($state, Set $set): void {
                if (blank($state)) {
                    return;
                }

                $customer = Customer::query()->find($state);
                if ($customer !== null) {
                    $firstLocation = $customer->locations()->first();
                    if ($firstLocation !== null) {
                        $set('pickup_location_id', $firstLocation->id);
                    }
                }
            })
            ->createOptionForm(fn (Schema $schema): array => CustomerForm::configure($schema)->getComponents());
    }

    public static function getDeliveryPointsRepeaterField(string|Closure $orderType = 'normal'): Repeater
    {
        return Repeater::make('deliveryPoints')
            ->label('Điểm giao hàng')
            ->helperText(function (Get $get): string {
                $area = Area::query()->find($get('area_id'));

                if ($area !== null) {
                    return 'Chưa có điểm đến phụ. Mặc định đến: '.$area->code;
                }

                return 'Thêm một hoặc nhiều điểm đến cho đơn hàng';
            })
            ->minItems(function (Get $get) use ($orderType): int {
                $type = $orderType instanceof Closure ? $orderType($get) : $orderType;

                return $type === 'external' ? 1 : 0;
            })
            ->defaultItems(function (Get $get) use ($orderType): int {
                $type = $orderType instanceof Closure ? $orderType($get) : $orderType;

                return $type === 'external' ? 1 : 0;
            })
            ->collapsible()
            ->itemLabel(function (array $state): ?string {
                $parts = [];

                if (isset($state['location_id']) && $location = Location::query()->find($state['location_id'])) {
                    $parts[] = $location->name;
                }
                if (! empty($state['contact_person'])) {
                    $parts[] = $state['contact_person'];
                }

                if (! empty($state['contact_phone'])) {
                    $parts[] = $state['contact_phone'];
                }

                return count($parts) > 0 ? implode(' - ', $parts) : 'Điểm giao hàng mới';
            })
            ->reorderableWithDragAndDrop()
            ->schema([
                Grid::make(4)
                    ->schema([
                        Select::make('location_id')
                            ->label('Điểm giao hàng')
                            ->options(fn (Get $get): array => Location::query()
                                ->whereIn('loc_type', self::getLocationTypesForOrderType(
                                    $orderType instanceof Closure ? ($orderType($get) ?? 'normal') : ($orderType ?? 'normal'),
                                ))
                                ->when($get('../../area_id'), function ($q, $areaId) {
                                    $area = Area::find($areaId);
                                    if ($area === null) {
                                        return $q;
                                    }

                                    // Filter by area, but fallback to all if area has no matching locations
                                    $areaLocations = (clone $q)->whereRelation('area', 'id', $area->id)->pluck('id');
                                    if ($areaLocations->isNotEmpty()) {
                                        return $q->whereRelation('area', 'id', $area->id);
                                    }

                                    return $q;
                                })
                                ->pluck('code', 'id')
                                ->toArray()
                            )
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->live(onBlur: true)
                            ->columnSpan(function (Get $get) use ($orderType): string|int {
                                $type = $orderType instanceof Closure ? $orderType($get) : $orderType;

                                return $type === 'HHHK' ? 'full' : 2;
                            })
                            ->createOptionForm(fn (Schema $schema): array => LocationForm::configure($schema)->getComponents()),
                        TextInput::make('contact_person')
                            ->label('Người nhận')
                            ->placeholder('Họ tên')
                            ->live(onBlur: true)
                            ->visible(function (Get $get) use ($orderType): bool {
                                $type = $orderType instanceof Closure ? $orderType($get) : $orderType;

                                return $type !== 'HHHK';
                            }),
                        TextInput::make('contact_phone')
                            ->label('SĐT nhận')
                            ->placeholder('Số điện thoại')
                            ->tel()
                            ->live(onBlur: true)
                            ->visible(function (Get $get) use ($orderType): bool {
                                $type = $orderType instanceof Closure ? $orderType($get) : $orderType;

                                return $type !== 'HHHK';
                            }),
                    ]),
            ])
            ->columnSpanFull();
    }

    public static function createSingleOrder(
        array $data,
        Schema $schema,
        string $orderTypeCode,
        bool $forceAssignedWhenTransportProvided = true,
        ?int $createdBy = null
    ): Order {
        if ($createdBy === null) {
            $createdBy = auth()->id();
        }

        $order = null;

        DB::transaction(function () use (
            $data,
            $schema,
            $orderTypeCode,
            $forceAssignedWhenTransportProvided,
            $createdBy,
            &$order
        ): void {
            $pickupAddress = null;
            if (filled($data['pickup_location_id'] ?? null)) {
                $pickupAddress = Location::query()->find($data['pickup_location_id'])?->address;
            }

            for ($attempt = 0; $attempt < 5; $attempt++) {
                $orderCode = self::generateOrderCode();

                try {
                    $order = Order::query()->create([
                        'order_code' => $orderCode,
                        'type' => $orderTypeCode,
                        'area_id' => $data['area_id'] ?? null,
                        'customer_id' => $data['customer_id'] ?? null,
                        'cargo_name' => $data['cargo_name'] ?? null,
                        'cargo_type' => $data['cargo_type'] ?? 'GCR',
                        'total_packages' => $data['total_packages'] ?? null,
                        'total_weight' => $data['total_weight'] ?? null,
                        'pickup_location_id' => $data['pickup_location_id'] ?? null,
                        'pickup_address' => $pickupAddress,
                        'pickup_contact' => $data['pickup_contact'] ?? null,
                        'pickup_phone' => $data['pickup_phone'] ?? null,
                        'planned_loading_at' => $data['planned_loading_at'] ?? null,
                        'status' => filled($data['vehicle_id'] ?? null)
                            ? OrderStatus::Assigned->value
                            : OrderStatus::Draft->value,
                        'priority' => $data['priority'] ?? Priority::Medium->value,
                        'created_by' => $createdBy,
                        'notes' => $data['notes'] ?? null,
                    ]);

                    break;
                } catch (Throwable $e) {
                    if (! self::isOrderCodeDuplicate($e) || $attempt === 4) {
                        throw $e;
                    }
                }
            }

            if ($order === null) {
                throw new \RuntimeException('Không thể tạo mã đơn hàng sau nhiều lần thử.');
            }

            $deliveryPoints = collect($schema->getRawState()['deliveryPoints'] ?? [])
                ->values()
                ->map(function (array $deliveryPoint, int $index): array {
                    $address = null;
                    if (filled($deliveryPoint['location_id'] ?? null)) {
                        $address = Location::query()->find($deliveryPoint['location_id'])?->address;
                    }

                    return [
                        'address' => $address,
                        'location_id' => $deliveryPoint['location_id'] ?? null,
                        'contact_person' => $deliveryPoint['contact_person'] ?? null,
                        'contact_phone' => $deliveryPoint['contact_phone'] ?? null,
                        'total_packages' => $deliveryPoint['total_packages'] ?? null,
                        'total_weight' => $deliveryPoint['total_weight'] ?? null,
                        'sequence' => $index + 1,
                    ];
                })
                ->all();

            if ($deliveryPoints !== []) {
                $order->deliveryPoints()->createMany($deliveryPoints);
            }

            if ($forceAssignedWhenTransportProvided && filled($data['vehicle_id'] ?? null)) {
                $trip = Trip::create([
                    'trip_code' => Trip::generateTripCode(),
                    'vehicle_id' => $data['vehicle_id'],
                    'driver_id' => $data['driver_id'] ?? null,
                    'status' => TripStatus::Pending,
                    'start_location_id' => $order->pickup_location_id,
                    'end_location_id' => $order->deliveryPoints()
                        ->orderBy('sequence', 'desc')
                        ->first()?->location_id,
                ]);

                $updated = $order->update([
                    'trip_id' => $trip->id,
                    'status' => OrderStatus::Assigned->value,
                ]);

                if (! $updated) {
                    throw new \RuntimeException('Không thể gán đơn hàng vào chuyến.');
                }

                static::createCheckpointsForExternalVehicle($trip, collect([$order]));

                $vehicle = Vehicle::query()->find($data['vehicle_id']);

                if ($vehicle !== null) {
                    $vehicle->update(['status' => VehicleStatus::Running]);
                }
            }
        });

        assert($order !== null);

        return $order;
    }

    public static function createCheckpointsForExternalVehicle(Trip $trip, \Illuminate\Support\Collection $orders): void
    {
        $vehicle = $trip->vehicle;

        if ($vehicle?->type !== VehicleOwnerType::Rent) {
            return;
        }

        $driverId = $trip->driver_id;
        $shiftId = $trip->shift_id;
        $now = now();
        $offset = 0;

        foreach ($orders as $order) {
            $base = (clone $now)->addSeconds($offset);

            TripCheckpoint::create([
                'trip_id' => $trip->id,
                'order_id' => $order->id,
                'checkpoint_type' => CheckpointType::Started,
                'occurred_at' => $base,
                'km_reading' => null,
                'driver_id' => $driverId,
                'shift_id' => $shiftId,
            ]);

            $offset++;

            TripCheckpoint::create([
                'trip_id' => $trip->id,
                'order_id' => $order->id,
                'checkpoint_type' => CheckpointType::ArrivedPickup,
                'occurred_at' => (clone $now)->addSeconds($offset),
                'km_reading' => null,
                'driver_id' => $driverId,
                'shift_id' => $shiftId,
            ]);

            $offset++;

            TripCheckpoint::create([
                'trip_id' => $trip->id,
                'order_id' => $order->id,
                'checkpoint_type' => CheckpointType::LeftPickup,
                'occurred_at' => (clone $now)->addSeconds($offset),
                'km_reading' => null,
                'driver_id' => $driverId,
                'shift_id' => $shiftId,
            ]);

            $offset++;

            foreach ($order->deliveryPoints as $dp) {
                TripCheckpoint::create([
                    'trip_id' => $trip->id,
                    'order_id' => $order->id,
                    'delivery_point_id' => $dp->id,
                    'checkpoint_type' => CheckpointType::ArrivedDelivery,
                    'occurred_at' => (clone $now)->addSeconds($offset),
                    'km_reading' => null,
                    'driver_id' => $driverId,
                    'shift_id' => $shiftId,
                ]);

                $offset++;

                TripCheckpoint::create([
                    'trip_id' => $trip->id,
                    'order_id' => $order->id,
                    'delivery_point_id' => $dp->id,
                    'checkpoint_type' => CheckpointType::Completed,
                    'occurred_at' => (clone $now)->addSeconds($offset),
                    'km_reading' => null,
                    'driver_id' => $driverId,
                    'shift_id' => $shiftId,
                ]);

                $offset++;
            }
        }
    }
}
