<?php

namespace App\Filament\Resources\Trips\Schemas;

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Models\DriverShift;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\User;
use Carbon\Carbon;
use Closure;
use DateTimeInterface;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Model;

class TripForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin chuyến')
                    ->columns(['default' => 1, 'sm' => 12])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('trip_code')
                            ->label('Mã chuyến')
                            ->prefixIcon(Heroicon::OutlinedIdentification)
                            ->columnSpan(['default' => 1, 'sm' => 3])
                            ->disabled(),
                        Select::make('status')
                            ->label('Trạng thái')
                            ->prefixIcon(Heroicon::OutlinedSignal)
                            ->options(TripStatus::class)
                            ->columnSpan(['default' => 1, 'sm' => 3]),
                        Select::make('vehicle_id')
                            ->label('Phương tiện')
                            ->relationship('vehicle', 'plate_number')
                            ->prefixIcon(Heroicon::OutlinedTruck)
                            ->disabled()
                            ->searchable()
                            ->native(false)
                            ->columnSpan(['default' => 1, 'sm' => 3]),
                        Select::make('driver_id')
                            ->label('Lái xe')
                            ->options(fn (): array => User::query()
                                ->whereHas('roles', fn ($q) => $q->where('name', 'driver'))
                                ->pluck('name', 'id')
                                ->toArray()
                            )
                            ->prefixIcon(Heroicon::OutlinedUser)
                            ->searchable()
                            ->native(false)
                            ->nullable()
                            ->placeholder('Chọn lái xe')
                            ->columnSpan(['default' => 1, 'sm' => 3]),
                    ]),
                Section::make('Km & Thời gian')
                    ->columns(['default' => 1, 'sm' => 12])
                    ->columnSpanFull()
                    ->schema([
                        Select::make('start_location_id')
                            ->label('Điểm bắt đầu')
                            ->relationship('startLocation', 'code')
                            ->preload()
                            ->searchable()
                            ->native(false)
                            ->hidden(fn (?Model $record) => $record?->vehicle?->type !== VehicleOwnerType::Rent)
                            ->columnSpan(['default' => 1, 'sm' => 6]),
                        Select::make('end_location_id')
                            ->label('Điểm kết thúc')
                            ->options(function (?Model $record): array {
                                if (! $record) {
                                    return [];
                                }

                                $areaId = $record->orders()->first()?->area_id;
                                if (! $areaId) {
                                    return [];
                                }

                                return Location::where('area_id', $areaId)->pluck('code', 'id')->toArray();
                            })
                            ->preload()
                            ->searchable()
                            ->native(false)
                            ->hidden(fn (?Model $record) => $record?->vehicle?->type !== VehicleOwnerType::Rent)
                            ->columnSpan(['default' => 1, 'sm' => 6]),
                        TextInput::make('start_km')
                            ->label('Km bắt đầu')
                            ->disabled()
                            ->helperText('Tự lấy từ km mốc sớm nhất')
                            ->prefixIcon(Heroicon::OutlinedAdjustmentsVertical)
                            ->mask(RawJs::make('$money($input)'))
                            ->stripCharacters(',')
                            ->hidden(fn (?Model $record) => $record?->vehicle?->type === VehicleOwnerType::Rent)
                            ->numeric()
                            ->columnSpan(['default' => 1, 'sm' => 3])
                            ->afterStateHydrated(function (TextInput $component, ?Trip $record): void {
                                if ($record === null || $component->getState() !== null) {
                                    return;
                                }

                                $minKm = $record->checkpoints()
                                    ->whereNotNull('km_reading')
                                    ->min('km_reading');

                                if ($minKm !== null) {
                                    $component->state((float) $minKm);
                                }
                            }),
                        TextInput::make('end_km')
                            ->label('Km kết thúc')
                            ->disabled()
                            ->helperText('Tự lấy từ km mốc muộn nhất')
                            ->prefixIcon(Heroicon::OutlinedAdjustmentsVertical)
                            ->mask(RawJs::make('$money($input)'))
                            ->stripCharacters(',')
                            ->hidden(fn (?Model $record) => $record?->vehicle?->type === VehicleOwnerType::Rent)
                            ->columnSpan(['default' => 1, 'sm' => 3])
                            ->numeric(),
                        TextInput::make('total_km')
                            ->label('Km tổng')
                            ->prefixIcon(Heroicon::OutlinedAdjustmentsVertical)
                            ->mask(RawJs::make('$money($input)'))
                            ->stripCharacters(',')
                            ->hidden(fn (?Model $record) => $record?->vehicle?->type !== VehicleOwnerType::Rent)
                            ->columnSpan(['default' => 1, 'sm' => 4])
                            ->numeric(),
                        DateTimePicker::make('started_at')
                            ->label('Bắt đầu')
                            ->prefixIcon(Heroicon::OutlinedClock)
                            ->displayFormat('H:i d/m/Y')
                            ->seconds(false)
                            ->native(true)
                            ->columnSpan(['default' => 1, 'sm' => fn (?Model $record) => $record?->vehicle?->type === VehicleOwnerType::Rent ? 4 : 3])
                            ->afterStateHydrated(function (DateTimePicker $component, ?Trip $record): void {
                                if ($record === null || $component->getState() !== null) {
                                    return;
                                }

                                $minTime = $record->checkpoints()
                                    ->whereNotNull('occurred_at')
                                    ->min('occurred_at');

                                if ($minTime !== null) {
                                    $component->state($minTime);
                                }
                            }),
                        DateTimePicker::make('completed_at')
                            ->label('Kết thúc')
                            ->prefixIcon(Heroicon::OutlinedClock)
                            ->displayFormat('H:i d/m/Y')
                            ->seconds(false)
                            ->native(true)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, $set, ?Model $record) {
                                if (filled($state) && $record?->vehicle?->type === VehicleOwnerType::Rent) {
                                    $set('status', TripStatus::Completed);
                                }
                            })
                            ->columnSpan(['default' => 1, 'sm' => fn (?Model $record) => $record?->vehicle?->type === VehicleOwnerType::Rent ? 4 : 3]),
                    ]),
                Section::make('Các mốc hành trình')
                    ->columnSpanFull()
                    ->hidden(fn (?Model $record) => $record?->is_empty_run || $record?->checkpoints->isEmpty())
                    ->schema([
                        // Repeater cho xe thuê ngoài không có lái xe (4 cột: Loại, Đơn hàng, Giờ, Điểm giao)
                        Repeater::make('checkpoints_rent')
                            ->relationship('checkpoints')
                            ->label('Danh sách mốc hành trình')
                            ->visible(fn (?Model $record) => $record?->vehicle?->type === VehicleOwnerType::Rent && blank($record?->driver_id))
                            ->table([
                                TableColumn::make('Loại')->width('220px'),
                                TableColumn::make('Đơn hàng')->width('180px'),
                                TableColumn::make('Giờ')->width('220px'),
                                TableColumn::make('Điểm giao')->width('110px'),
                            ])
                            ->orderColumn('created_at')
                            ->compact()
                            ->schema([
                                Select::make('checkpoint_type')
                                    ->label('Loại')
                                    ->options(CheckpointType::class)
                                    ->required()
                                    ->native(false),
                                Select::make('order_id')
                                    ->label('Đơn hàng')
                                    ->options(function ($get): array {
                                        $tripId = $get('../../id');
                                        if (! $tripId) {
                                            return [];
                                        }

                                        return Order::where('trip_id', $tripId)
                                            ->pluck('order_code', 'id')
                                            ->toArray();
                                    })
                                    ->searchable()
                                    ->native(false)
                                    ->default(fn ($get) => self::getPreviousCheckpointValue(
                                        $get,
                                        'checkpoints_rent',
                                        'order_id',
                                        fn () => Order::where('trip_id', $get('../../id'))->value('id'),
                                    )),
                                DateTimePicker::make('occurred_at')
                                    ->label('Thời gian')
                                    ->required()
                                    ->displayFormat('H:i d/m/Y')
                                    ->seconds(false)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function ($state, $set, $get) {
                                        if ($get('checkpoint_type') === CheckpointType::Completed) {
                                            $set('../../completed_at', $state);
                                            $set('../../status', TripStatus::Completed);
                                        }
                                    })
                                    ->native(true)
                                    ->default(fn ($get) => self::getPreviousCheckpointValue(
                                        $get,
                                        'checkpoints_rent',
                                        'occurred_at',
                                        fn () => $get('../../started_at') ?? now()->format('Y-m-d H:i'),
                                    )),
                                Select::make('delivery_point_id')
                                    ->label('Điểm giao')
                                    ->options(function ($get): array {
                                        $orderId = $get('order_id');
                                        if (! $orderId) {
                                            return [];
                                        }

                                        $areaId = Order::find($orderId)?->area_id;
                                        if (! $areaId) {
                                            return [];
                                        }

                                        return OrderDeliveryPoint::whereHas('order', fn ($q) => $q->where('area_id', $areaId))
                                            ->with('location')
                                            ->get()
                                            ->mapWithKeys(fn ($dp) => [$dp->id => $dp->location?->code ?? 'DP#'.$dp->id])
                                            ->toArray();
                                    })
                                    ->placeholder('Chọn điểm')
                                    ->searchable()
                                    ->native(false)
                                    ->nullable()
                                    ->default(fn ($get) => self::getPreviousCheckpointValue(
                                        $get,
                                        'checkpoints_rent',
                                        'delivery_point_id',
                                        null,
                                    )),
                            ])
                            ->addable()
                            ->deletable()
                            ->addActionLabel('Thêm mốc hành trình')
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data, $get): array => self::fillCheckpointRelationshipData($data, $get))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data, $get): array => self::fillCheckpointRelationshipData($data, $get))
                            ->columnSpanFull(),

                        // Repeater cho xe công ty hoặc xe thuê ngoài có tài xế (đầy đủ 6 cột: Loại, Đơn hàng, Tài xế, Km, Giờ, Điểm giao)
                        Repeater::make('checkpoints')
                            ->relationship('checkpoints')
                            ->label('Danh sách mốc hành trình')
                            ->visible(fn (?Model $record) => $record?->vehicle?->type !== VehicleOwnerType::Rent || filled($record?->driver_id))
                            ->table([
                                TableColumn::make('Loại')->width('160px'),
                                TableColumn::make('Đơn hàng')->width('110px'),
                                TableColumn::make('Tài xế')->width('160px'),
                                TableColumn::make('Km')->width('100px'),
                                TableColumn::make('Giờ')->width('160px'),
                                TableColumn::make('Điểm giao')->width('110px'),
                            ])
                            ->orderColumn('created_at')
                            ->compact()
                            ->schema([
                                Select::make('checkpoint_type')
                                    ->label('Loại')
                                    ->options(CheckpointType::class)
                                    ->required()
                                    ->native(false),
                                Select::make('order_id')
                                    ->label('Đơn hàng')
                                    ->options(function ($get): array {
                                        $tripId = $get('../../id');
                                        if (! $tripId) {
                                            return [];
                                        }

                                        return Order::where('trip_id', $tripId)
                                            ->pluck('order_code', 'id')
                                            ->toArray();
                                    })
                                    ->searchable()
                                    ->native(false)
                                    ->default(fn ($get) => self::getPreviousCheckpointValue(
                                        $get,
                                        'checkpoints',
                                        'order_id',
                                        fn () => Order::where('trip_id', $get('../../id'))->value('id'),
                                    )),
                                Select::make('driver_id')
                                    ->label('Tài xế')
                                    ->options(fn () => User::query()
                                        ->where('is_active', true)
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->toArray())
                                    ->searchable()
                                    ->native(false)
                                    ->nullable()
                                    ->default(fn ($get) => self::getPreviousCheckpointValue(
                                        $get,
                                        'checkpoints',
                                        'driver_id',
                                        fn () => $get('../../driver_id'),
                                    )),
                                TextInput::make('km_reading')
                                    ->label('Km')
                                    ->mask(RawJs::make('$money($input)'))
                                    ->stripCharacters(',')
                                    ->numeric()
                                    ->step(0.1)
                                    ->nullable()
                                    ->default(fn ($get) => self::getPreviousCheckpointValue(
                                        $get,
                                        'checkpoints',
                                        'km_reading',
                                        fn () => $get('../../start_km'),
                                    )),
                                DateTimePicker::make('occurred_at')
                                    ->label('Thời gian')
                                    ->required()
                                    ->displayFormat('H:i d/m/Y')
                                    ->seconds(false)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function ($state, $set, $get) {
                                        if ($get('checkpoint_type') === CheckpointType::Completed) {
                                            $set('../../completed_at', $state);
                                        }
                                    })
                                    ->native(true)
                                    ->default(fn ($get) => self::getPreviousCheckpointValue(
                                        $get,
                                        'checkpoints',
                                        'occurred_at',
                                        fn () => $get('../../started_at') ?? now()->format('Y-m-d H:i'),
                                    )),
                                Select::make('delivery_point_id')
                                    ->label('Điểm giao')
                                    ->options(function ($get): array {
                                        $orderId = $get('order_id');
                                        if (! $orderId) {
                                            return [];
                                        }

                                        $areaId = Order::find($orderId)?->area_id;
                                        if (! $areaId) {
                                            return [];
                                        }

                                        return OrderDeliveryPoint::whereHas('order', fn ($q) => $q->where('area_id', $areaId))
                                            ->with('location')
                                            ->get()
                                            ->mapWithKeys(fn ($dp) => [$dp->id => $dp->location?->code ?? 'DP#'.$dp->id])
                                            ->toArray();
                                    })
                                    ->placeholder('Chọn điểm')
                                    ->searchable()
                                    ->native(false)
                                    ->nullable()
                                    ->default(fn ($get) => self::getPreviousCheckpointValue(
                                        $get,
                                        'checkpoints',
                                        'delivery_point_id',
                                        null,
                                    )),
                            ])
                            ->addable()
                            ->deletable()
                            ->addActionLabel('Thêm mốc hành trình')
                            ->rules([fn (): Closure => self::checkpointKmIncreasingRule()])
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data, $get): array => self::fillCheckpointRelationshipData($data, $get))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data, $get): array => self::fillCheckpointRelationshipData($data, $get))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Km các mốc phải tăng dần theo thời gian; các mốc cùng thời điểm được phép lệch nhau.
     */
    public static function checkpointKmIncreasingRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $rows = collect(is_array($value) ? $value : [])
                ->filter(fn ($row): bool => is_array($row) && filled($row['km_reading'] ?? null) && filled($row['occurred_at'] ?? null))
                ->map(fn (array $row): array => [
                    'km' => (float) str_replace(',', '', (string) $row['km_reading']),
                    'at' => Carbon::parse($row['occurred_at']),
                ])
                ->sortBy(fn (array $row): int => $row['at']->getTimestamp());

            $highest = null;

            foreach ($rows as $row) {
                if ($highest !== null && $row['at']->gt($highest['at']) && $row['km'] < $highest['km']) {
                    $fail(sprintf(
                        'Km mốc lúc %s (%s km) nhỏ hơn mốc lúc %s (%s km). Km phải tăng dần theo thời gian.',
                        $row['at']->format('H:i d/m'),
                        number_format($row['km'], 1, ',', '.'),
                        $highest['at']->format('H:i d/m'),
                        number_format($highest['km'], 1, ',', '.'),
                    ));

                    return;
                }

                if ($highest === null || $row['km'] >= $highest['km']) {
                    $highest = $row;
                }
            }
        };
    }

    private static function fillCheckpointRelationshipData(array $data, $get): array
    {
        $driverId = ! empty($data['driver_id']) ? $data['driver_id'] : $get('../../driver_id');
        $data['driver_id'] = $driverId;

        $vehicleId = ! empty($data['vehicle_id']) ? $data['vehicle_id'] : $get('../../vehicle_id');
        $data['vehicle_id'] = $vehicleId;

        if (empty($data['shift_id']) && $driverId) {
            $shiftId = DriverShift::where('driver_id', $driverId)
                ->when($vehicleId, fn ($q) => $q->where('vehicle_id', $vehicleId))
                ->whereNull('end_time')
                ->latest('start_time')
                ->value('id')
                ?? DriverShift::where('driver_id', $driverId)
                    ->whereNull('end_time')
                    ->latest('start_time')
                    ->value('id')
                ?? DriverShift::where('driver_id', $driverId)
                    ->when($vehicleId, fn ($q) => $q->where('vehicle_id', $vehicleId))
                    ->latest('start_time')
                    ->value('id')
                ?? DriverShift::where('driver_id', $driverId)
                    ->latest('start_time')
                    ->value('id');

            if ($shiftId) {
                $data['shift_id'] = $shiftId;
            }
        }

        return $data;
    }

    /**
     * Lấy giá trị từ mốc hành trình liền trước (dòng phía trên) để gợi ý cho mốc mới.
     */
    private static function getPreviousCheckpointValue(callable $get, string $repeaterName, string $field, mixed $fallback = null): mixed
    {
        $checkpoints = $get("../../{$repeaterName}");
        if (is_array($checkpoints) && ! empty($checkpoints)) {
            $prev = collect($checkpoints)->reverse()->first(fn ($item): bool => is_array($item) && filled($item[$field] ?? null));
            if ($prev && isset($prev[$field])) {
                $val = $prev[$field];
                if ($val instanceof DateTimeInterface) {
                    return $val->format('Y-m-d H:i');
                }

                return $val;
            }
        }

        $fallbackVal = value($fallback);
        if ($fallbackVal instanceof DateTimeInterface) {
            return $fallbackVal->format('Y-m-d H:i');
        }

        return $fallbackVal;
    }
}
