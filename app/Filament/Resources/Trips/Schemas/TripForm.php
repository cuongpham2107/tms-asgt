<?php

namespace App\Filament\Resources\Trips\Schemas;

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Models\DriverShift;
use App\Models\FreightRate;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Services\Km\TripKmLimitService;
use Carbon\Carbon;
use Closure;
use DateTimeInterface;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
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
                Tabs::make('trip_edit_tabs')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Chuyến đi')
                            ->icon(Heroicon::OutlinedTruck)
                            ->schema([
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
                                            ->disabled()
                                            ->dehydrated()
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
                                            ->options(fn (?Trip $record): array => User::query()
                                                ->role('driver')
                                                ->where(fn ($q) => $q->where('is_active', true)->when($record?->driver_id, fn ($sub) => $sub->orWhere('id', $record->driver_id)))
                                                ->orderBy('name')
                                                ->pluck('name', 'id')
                                                ->toArray()
                                            )
                                            ->prefixIcon(Heroicon::OutlinedUser)
                                            ->searchable()
                                            ->native(false)
                                            ->nullable()
                                            ->placeholder('Chọn lái xe')
                                            ->hidden(fn (?Model $record): bool => $record?->vehicle?->type === VehicleOwnerType::Rent)
                                            ->columnSpan(['default' => 1, 'sm' => 3]),
                                        TextInput::make('external_driver_name')
                                            ->label('Lái xe thuê ngoài')
                                            ->placeholder('Nhập tên lái xe ngoài')
                                            ->maxLength(255)
                                            ->prefixIcon(Heroicon::OutlinedUser)
                                            ->visible(fn (?Model $record): bool => $record?->vehicle?->type === VehicleOwnerType::Rent)
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

                                                return Location::where(function ($q) use ($areaId) {
                                                    $q->whereHas('areas', fn ($sq) => $sq->where('areas.id', $areaId))
                                                        ->orWhere('area_id', $areaId);
                                                })->pluck('code', 'id')->toArray();
                                            })
                                            ->preload()
                                            ->searchable()
                                            ->native(false)
                                            ->hidden(fn (?Model $record) => $record?->vehicle?->type !== VehicleOwnerType::Rent)
                                            ->columnSpan(['default' => 1, 'sm' => 6]),
                                        TextInput::make('start_km')
                                            ->label('Km bắt đầu')
                                            ->disabled()
                                            ->dehydrated()
                                            ->hint(function (?Model $record): ?string {
                                                if (! $record instanceof Trip) {
                                                    return null;
                                                }

                                                $record->loadMissing('vehicle');
                                                $mileage = $record->vehicle?->current_mileage;

                                                return $mileage !== null
                                                    ? 'Km xe: '.number_format((float) $mileage, 1, ',', '.').' km'
                                                    : null;
                                            })
                                            ->hintIcon(Heroicon::OutlinedTruck)
                                            ->hintColor('primary')
                                            ->helperText(function (?Model $record): string {
                                                if (! $record instanceof Trip) {
                                                    return 'Lấy từ Km xe (kết thúc chuyến trước)';
                                                }

                                                $hasCheckpointKm = $record->checkpoints()
                                                    ->whereNotNull('km_reading')
                                                    ->where('km_reading', '>', 0)
                                                    ->exists();

                                                $baseNote = $hasCheckpointKm
                                                    ? 'Đồng bộ từ mốc bắt đầu chuyến'
                                                    : 'Lấy từ Km xe (kết thúc chuyến trước)';

                                                $record->loadMissing('vehicle');
                                                $vehicleKm = $record->vehicle?->current_mileage;

                                                return $vehicleKm !== null
                                                    ? $baseNote.' · Km hiện tại của xe: '.number_format((float) $vehicleKm, 1, ',', '.').' km'
                                                    : $baseNote;
                                            })
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
                                                } elseif ($record->start_km !== null) {
                                                    $component->state((float) $record->start_km);
                                                } elseif ($record->vehicle?->current_mileage !== null) {
                                                    $component->state((float) $record->vehicle->current_mileage);
                                                }
                                            }),
                                        TextInput::make('end_km')
                                            ->label('Km kết thúc')
                                            ->disabled()
                                            ->dehydrated()
                                            ->helperText(function (?Model $record): string {
                                                if (! $record instanceof Trip) {
                                                    return 'Tự cập nhật khi hoàn thành chuyến';
                                                }

                                                $hasEndCheckpoint = $record->checkpoints()
                                                    ->where('checkpoint_type', CheckpointType::End->value)
                                                    ->exists();

                                                if ($hasEndCheckpoint) {
                                                    return 'Đồng bộ từ mốc kết thúc chuyến';
                                                }

                                                if ($record->status === TripStatus::Completed || $record->end_km !== null) {
                                                    return 'Chưa có mốc kết thúc xe (đồng bộ theo Km mốc cuối)';
                                                }

                                                return 'Chưa kết thúc (tự cập nhật khi hoàn thành chuyến)';
                                            })
                                            ->prefixIcon(Heroicon::OutlinedAdjustmentsVertical)
                                            ->mask(RawJs::make('$money($input)'))
                                            ->stripCharacters(',')
                                            ->hidden(fn (?Model $record) => $record?->vehicle?->type === VehicleOwnerType::Rent)
                                            ->columnSpan(['default' => 1, 'sm' => 3])
                                            ->numeric()
                                            ->afterStateHydrated(function (TextInput $component, ?Trip $record): void {
                                                if ($record === null || $component->getState() !== null) {
                                                    return;
                                                }

                                                $endKm = $record->checkpoints()
                                                    ->whereNotNull('km_reading')
                                                    ->where('km_reading', '>', 0)
                                                    ->whereIn('checkpoint_type', [CheckpointType::Completed->value, CheckpointType::End->value])
                                                    ->latest('occurred_at')
                                                    ->value('km_reading');

                                                if ($endKm !== null) {
                                                    $component->state((float) $endKm);
                                                }
                                            }),
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
                                            ->disabled()
                                            ->dehydrated(false)
                                            ->helperText(function (?Model $record): string {
                                                if (! $record instanceof Trip) {
                                                    return 'Tự cập nhật từ mốc bắt đầu chuyến';
                                                }

                                                $hasStartedCheckpoint = $record->checkpoints()
                                                    ->where('checkpoint_type', CheckpointType::Started->value)
                                                    ->whereNotNull('occurred_at')
                                                    ->exists();

                                                if ($hasStartedCheckpoint || $record->started_at !== null) {
                                                    return 'Đồng bộ từ mốc bắt đầu chuyến';
                                                }

                                                return 'Chưa bắt đầu (tự cập nhật từ mốc bắt đầu chuyến)';
                                            })
                                            ->columnSpan(['default' => 1, 'sm' => fn (?Model $record) => $record?->vehicle?->type === VehicleOwnerType::Rent ? 4 : 3])
                                            ->afterStateHydrated(function (DateTimePicker $component, ?Trip $record): void {
                                                if ($record === null) {
                                                    return;
                                                }

                                                $startedTime = $record->checkpoints()
                                                    ->whereNotNull('occurred_at')
                                                    ->where('checkpoint_type', CheckpointType::Started->value)
                                                    ->orderBy('occurred_at')
                                                    ->orderBy('id')
                                                    ->value('occurred_at');

                                                if ($startedTime !== null) {
                                                    $component->state($startedTime);
                                                }
                                            }),
                                        DateTimePicker::make('completed_at')
                                            ->label('Kết thúc')
                                            ->prefixIcon(Heroicon::OutlinedClock)
                                            ->displayFormat('H:i d/m/Y')
                                            ->seconds(false)
                                            ->native(true)
                                            ->disabled()
                                            ->dehydrated(false)
                                            ->helperText(function (?Model $record): string {
                                                if (! $record instanceof Trip) {
                                                    return 'Tự cập nhật từ mốc kết thúc chuyến';
                                                }

                                                $hasEndCheckpoint = $record->checkpoints()
                                                    ->where('checkpoint_type', CheckpointType::End->value)
                                                    ->whereNotNull('occurred_at')
                                                    ->exists();

                                                if ($hasEndCheckpoint) {
                                                    return 'Đồng bộ từ mốc kết thúc chuyến';
                                                }

                                                if ($record->status === TripStatus::Completed && $record->completed_at !== null) {
                                                    return 'Chưa có mốc kết thúc xe';
                                                }

                                                return 'Chưa kết thúc (tự cập nhật từ mốc kết thúc chuyến)';
                                            })
                                            ->afterStateHydrated(function (DateTimePicker $component, ?Trip $record): void {
                                                if ($record === null) {
                                                    return;
                                                }

                                                $endTime = $record->checkpoints()
                                                    ->whereNotNull('occurred_at')
                                                    ->where('checkpoint_type', CheckpointType::End->value)
                                                    ->latest('occurred_at')
                                                    ->orderByDesc('id')
                                                    ->value('occurred_at');

                                                if ($endTime !== null) {
                                                    $component->state($endTime);
                                                }
                                            })
                                            ->columnSpan(['default' => 1, 'sm' => fn (?Model $record) => $record?->vehicle?->type === VehicleOwnerType::Rent ? 4 : 3]),
                                    ]),
                            ]),
                        Tab::make('Mốc hành trình')
                            ->icon(Heroicon::OutlinedMapPin)
                            ->visible(fn (?Model $record): bool => ! (bool) $record?->is_empty_run)
                            ->schema([
                                Section::make('Các mốc hành trình')
                                    ->columnSpanFull()
                                    ->hidden(fn (?Model $record) => (bool) $record?->is_empty_run)
                                    ->schema([
                                        // Repeater cho xe thuê ngoài (4 cột: Loại, Đơn hàng, Giờ, Điểm; luôn nhập tên lái xe ngoài)
                                        Repeater::make('checkpoints_rent')
                                            ->relationship('checkpoints', modifyQueryUsing: fn ($query) => $query->chronological())
                                            ->label('Danh sách mốc hành trình')
                                            ->visible(fn (?Model $record): bool => $record?->vehicle?->type === VehicleOwnerType::Rent)
                                            ->table([
                                                TableColumn::make('Loại')->width('220px'),
                                                TableColumn::make('Đơn hàng')->width('180px'),
                                                TableColumn::make('Giờ')->width('220px'),
                                                TableColumn::make('Điểm')->width('110px'),
                                            ])
                                            ->compact()
                                            ->schema([
                                                Select::make('checkpoint_type')
                                                    ->label('Loại')
                                                    ->options(CheckpointType::class)
                                                    ->required()
                                                    ->native(false)
                                                    ->live()
                                                    ->afterStateUpdated(function ($state, $set, $get) {
                                                        $type = $state instanceof CheckpointType ? $state : (is_string($state) ? CheckpointType::tryFrom($state) : null);
                                                        if ($type === CheckpointType::End) {
                                                            $set('../../status', TripStatus::Completed);

                                                            $occurredAt = $get('occurred_at');
                                                            if (filled($occurredAt)) {
                                                                $set('../../completed_at', $occurredAt);
                                                            }
                                                        } elseif ($type === CheckpointType::Started) {
                                                            $set('../../status', TripStatus::Started);

                                                            $occurredAt = $get('occurred_at');
                                                            if (filled($occurredAt)) {
                                                                $set('../../started_at', $occurredAt);
                                                            }
                                                        } elseif ($type !== null) {
                                                            $tripStatus = $type->toTripStatus();
                                                            if ($tripStatus !== null) {
                                                                $set('../../status', $tripStatus);
                                                            }
                                                        }
                                                        self::syncDeliveryPointOnTypeChange($type, $set, $get, 'checkpoints_rent');
                                                    })
                                                    ->default(fn ($get) => self::getNextCheckpointType($get, 'checkpoints_rent')),
                                                Select::make('order_id')
                                                    ->label('Đơn hàng')
                                                    ->options(function ($get, ?Model $record): array {
                                                        $tripId = $get('../../id')
                                                            ?? ($record instanceof TripCheckpoint ? $record->trip_id : ($record instanceof Trip ? $record->id : null));
                                                        if (! $tripId) {
                                                            return [];
                                                        }

                                                        return Order::where('trip_id', $tripId)
                                                            ->pluck('order_code', 'id')
                                                            ->toArray();
                                                    })
                                                    ->searchable()
                                                    ->native(false)
                                                    ->live()
                                                    ->afterStateUpdated(fn ($state, $set, $get) => self::syncDeliveryPointOnOrderChange($state, $set, $get))
                                                    ->default(fn ($get, ?Model $record) => self::getPreviousCheckpointValue(
                                                        $get,
                                                        'checkpoints_rent',
                                                        'order_id',
                                                        fn () => Order::where('trip_id', $get('../../id') ?? ($record instanceof TripCheckpoint ? $record->trip_id : ($record instanceof Trip ? $record->id : null)))->value('id'),
                                                    )),
                                                DateTimePicker::make('occurred_at')
                                                    ->label('Thời gian')
                                                    ->required()
                                                    ->displayFormat('H:i d/m/Y')
                                                    ->seconds(false)
                                                    ->live(onBlur: true)
                                                    ->afterStateUpdated(function ($state, $set, $get) {
                                                        $raw = $get('checkpoint_type');
                                                        $type = $raw instanceof CheckpointType ? $raw : (is_string($raw) ? CheckpointType::tryFrom($raw) : null);
                                                        if (($type === CheckpointType::End) && filled($state)) {
                                                            $set('../../completed_at', $state);
                                                            $set('../../status', TripStatus::Completed);
                                                        } elseif ($type === CheckpointType::Started && filled($state)) {
                                                            $set('../../started_at', $state);
                                                        }
                                                    })
                                                    ->native(true)
                                                    ->default(fn ($get, ?Model $record): string => self::defaultCheckpointOccurredAt($get, 'checkpoints_rent', $record)),
                                                Select::make('delivery_point_id')
                                                    ->label('Điểm giao')
                                                    ->options(fn ($get): array => self::deliveryPointOptionsForCheckpoint($get))
                                                    ->placeholder(fn ($get): string => self::pickupPointLabel($get) ?? 'Chọn điểm')
                                                    ->disabled(fn ($get): bool => self::isPickupCheckpoint($get))
                                                    ->searchable()
                                                    ->native(false)
                                                    ->nullable()
                                                    ->default(fn ($get, ?Model $record): ?int => self::defaultDeliveryPointId($get, 'checkpoints_rent', null, $record)),
                                            ])
                                            ->addable()
                                            ->deletable()
                                            ->addActionLabel('Thêm mốc hành trình')
                                            ->rules([fn (): Closure => self::checkpointTimeOrderRule()])
                                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data, $get): array => self::fillCheckpointRelationshipData($data, $get, isRent: true))
                                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data, $get): array => self::fillCheckpointRelationshipData($data, $get, isRent: true))
                                            ->columnSpanFull(),

                                        // Repeater cho xe công ty (đầy đủ 6 cột: Loại, Đơn hàng, Tài xế, Km, Giờ, Điểm)
                                        Repeater::make('checkpoints')
                                            ->relationship('checkpoints', modifyQueryUsing: fn ($query) => $query->chronological())
                                            ->label('Danh sách mốc hành trình')
                                            ->visible(fn (?Model $record): bool => $record?->vehicle?->type !== VehicleOwnerType::Rent)
                                            ->table([
                                                TableColumn::make('Loại')->width('160px'),
                                                TableColumn::make('Đơn hàng')->width('110px'),
                                                TableColumn::make('Tài xế')->width('160px'),
                                                TableColumn::make('Km')->width('100px'),
                                                TableColumn::make('Giờ')->width('160px'),
                                                TableColumn::make('Điểm')->width('110px'),
                                            ])
                                            ->compact()
                                            ->schema([
                                                Select::make('checkpoint_type')
                                                    ->label('Loại')
                                                    ->options(CheckpointType::class)
                                                    ->required()
                                                    ->native(false)
                                                    ->live()
                                                    ->afterStateUpdated(function ($state, $set, $get) {
                                                        $type = $state instanceof CheckpointType ? $state : (is_string($state) ? CheckpointType::tryFrom($state) : null);
                                                        if ($type === CheckpointType::End) {
                                                            $set('../../status', TripStatus::Completed);

                                                            $km = $get('km_reading');
                                                            if (filled($km)) {
                                                                $set('../../end_km', $km);
                                                            }

                                                            $occurredAt = $get('occurred_at');
                                                            if (filled($occurredAt)) {
                                                                $set('../../completed_at', $occurredAt);
                                                            }
                                                        } elseif ($type === CheckpointType::Started) {
                                                            $set('../../status', TripStatus::Started);

                                                            $km = $get('km_reading');
                                                            if (filled($km)) {
                                                                $set('../../start_km', $km);
                                                            }

                                                            $occurredAt = $get('occurred_at');
                                                            if (filled($occurredAt)) {
                                                                $set('../../started_at', $occurredAt);
                                                            }
                                                        } elseif ($type !== null) {
                                                            $tripStatus = $type->toTripStatus();
                                                            if ($tripStatus !== null) {
                                                                $set('../../status', $tripStatus);
                                                            }
                                                        }
                                                        self::syncDeliveryPointOnTypeChange($type, $set, $get, 'checkpoints');
                                                    })
                                                    ->default(fn ($get) => self::getNextCheckpointType($get, 'checkpoints')),
                                                Select::make('order_id')
                                                    ->label('Đơn hàng')
                                                    ->options(function ($get, ?Model $record): array {
                                                        $tripId = $get('../../id')
                                                            ?? ($record instanceof TripCheckpoint ? $record->trip_id : ($record instanceof Trip ? $record->id : null));
                                                        if (! $tripId) {
                                                            return [];
                                                        }

                                                        return Order::where('trip_id', $tripId)
                                                            ->pluck('order_code', 'id')
                                                            ->toArray();
                                                    })
                                                    ->searchable()
                                                    ->native(false)
                                                    ->live()
                                                    ->afterStateUpdated(fn ($state, $set, $get) => self::syncDeliveryPointOnOrderChange($state, $set, $get))
                                                    ->default(fn ($get, ?Model $record) => self::getPreviousCheckpointValue(
                                                        $get,
                                                        'checkpoints',
                                                        'order_id',
                                                        fn () => Order::where('trip_id', $get('../../id') ?? ($record instanceof TripCheckpoint ? $record->trip_id : ($record instanceof Trip ? $record->id : null)))->value('id'),
                                                    )),
                                                Select::make('driver_id')
                                                    ->label('Tài xế')
                                                    ->options(fn () => User::query()
                                                        ->role('driver')
                                                        ->where('is_active', true)
                                                        ->orderBy('name')
                                                        ->pluck('name', 'id')
                                                        ->toArray())
                                                    ->searchable()
                                                    ->native(false)
                                                    ->nullable()
                                                    ->default(fn ($get, ?Model $record) => self::getPreviousCheckpointValue(
                                                        $get,
                                                        'checkpoints',
                                                        'driver_id',
                                                        fn () => $get('../../driver_id') ?? $record?->driver_id,
                                                    )),
                                                TextInput::make('km_reading')
                                                    ->label('Km')
                                                    ->mask(RawJs::make('$money($input)'))
                                                    ->stripCharacters(',')
                                                    ->numeric()
                                                    ->step(0.1)
                                                    ->nullable()
                                                    ->live(onBlur: true)
                                                    ->afterStateUpdated(function ($state, $set, $get) {
                                                        $raw = $get('checkpoint_type');
                                                        $type = $raw instanceof CheckpointType ? $raw : (is_string($raw) ? CheckpointType::tryFrom($raw) : null);
                                                        // Chỉ mốc Kết thúc mới đóng chuyến; mốc Hoàn thành chỉ là giao xong một điểm.
                                                        if ($type === CheckpointType::End && filled($state)) {
                                                            $set('../../end_km', $state);
                                                            $set('../../status', TripStatus::Completed);
                                                        } elseif ($type === CheckpointType::Started && filled($state)) {
                                                            $set('../../start_km', $state);
                                                        }
                                                    })
                                                    ->default(fn ($get, ?Model $record) => self::getPreviousCheckpointValue(
                                                        $get,
                                                        'checkpoints',
                                                        'km_reading',
                                                        fn () => $get('../../start_km') ?? ($record?->vehicle?->current_mileage !== null ? (float) $record->vehicle->current_mileage : null),
                                                    )),
                                                DateTimePicker::make('occurred_at')
                                                    ->label('Thời gian')
                                                    ->required()
                                                    ->displayFormat('H:i d/m/Y')
                                                    ->seconds(false)
                                                    ->live(onBlur: true)
                                                    ->afterStateUpdated(function ($state, $set, $get) {
                                                        $raw = $get('checkpoint_type');
                                                        $type = $raw instanceof CheckpointType ? $raw : (is_string($raw) ? CheckpointType::tryFrom($raw) : null);
                                                        if (($type === CheckpointType::End) && filled($state)) {
                                                            $set('../../completed_at', $state);
                                                            $set('../../status', TripStatus::Completed);
                                                        } elseif ($type === CheckpointType::Started && filled($state)) {
                                                            $set('../../started_at', $state);
                                                        }
                                                    })
                                                    ->native(true)
                                                    ->default(fn ($get, ?Model $record): string => self::defaultCheckpointOccurredAt($get, 'checkpoints', $record)),
                                                Select::make('delivery_point_id')
                                                    ->label('Điểm giao')
                                                    ->options(fn ($get): array => self::deliveryPointOptionsForCheckpoint($get))
                                                    ->placeholder(fn ($get): string => self::pickupPointLabel($get) ?? 'Chọn điểm')
                                                    ->disabled(fn ($get): bool => self::isPickupCheckpoint($get))
                                                    ->searchable()
                                                    ->native(false)
                                                    ->nullable()
                                                    ->default(fn ($get, ?Model $record): ?int => self::defaultDeliveryPointId($get, 'checkpoints', null, $record)),
                                            ])
                                            ->addable()
                                            ->deletable()
                                            ->addActionLabel('Thêm mốc hành trình')
                                            ->rules([
                                                fn (?Model $record): Closure => self::checkpointKmIncreasingRule($record),
                                                fn (): Closure => self::checkpointTimeOrderRule(),
                                                fn (): Closure => self::newDeliveryCheckpointNeedsPointRule(),
                                            ])
                                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data, $get): array => self::fillCheckpointRelationshipData($data, $get))
                                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data, $get): array => self::fillCheckpointRelationshipData($data, $get))
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tab::make('Đơn hàng')
                            ->icon(Heroicon::OutlinedShoppingCart)
                            ->visible(fn (?Model $record): bool => $record instanceof Trip && $record->orders()->exists())
                            ->schema([
                                Tabs::make('trip_order_tabs')
                                    ->contained(false)
                                    ->tabs(fn (?Trip $record): array => self::orderEditTabs($record)),
                            ]),
                    ]),
            ]);
    }

    /**
     * Km các mốc phải tăng dần theo thời gian (các mốc cùng thời điểm được phép lệch nhau)
     * và bước nhảy giữa hai mốc liên tiếp không vượt quá trần của TripKmLimitService (+100 km / +600 km).
     */
    public static function checkpointKmIncreasingRule(?Model $record = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            $limitService = app(TripKmLimitService::class);
            $index = 0;

            $rows = collect(is_array($value) ? $value : [])
                ->filter(fn ($row): bool => is_array($row) && filled($row['km_reading'] ?? null) && filled($row['occurred_at'] ?? null))
                ->map(function (array $row) use (&$index): array {
                    $rawType = $row['checkpoint_type'] ?? null;
                    $type = $rawType instanceof CheckpointType
                        ? $rawType
                        : (is_string($rawType) ? CheckpointType::tryFrom($rawType) : null);

                    return [
                        'index' => $index++,
                        'type' => $type,
                        'km' => (float) str_replace(',', '', (string) $row['km_reading']),
                        'at' => Carbon::parse($row['occurred_at']),
                    ];
                })
                ->sortBy(fn (array $row): array => [$row['at']->getTimestamp(), $row['index']])
                ->values();

            if ($rows->isEmpty()) {
                return;
            }

            if ($record instanceof Trip) {
                $baselineKm = ($record->start_km !== null && (float) $record->start_km > 0)
                    ? (float) $record->start_km
                    : (($record->vehicle?->current_mileage !== null && (float) $record->vehicle->current_mileage > 0)
                        ? (float) $record->vehicle->current_mileage
                        : null);
                $baselineLabel = ($record->start_km !== null && (float) $record->start_km > 0)
                    ? 'Km bắt đầu chuyến'
                    : 'Km hiện tại của xe';
                $firstRow = $rows->first();

                if ($baselineKm !== null && ($firstRow['km'] - $baselineKm) > TripKmLimitService::DELTA_LONG) {
                    $maxAllowed = $baselineKm + TripKmLimitService::DELTA_LONG;
                    $firstLabel = $firstRow['type']?->getLabel() ?? 'Mốc đầu tiên';
                    $fail(sprintf(
                        'Km mốc "%s" lúc %s (%s km) vượt quá giới hạn cho phép (+%.0f km) so với %s (%s km). Tối đa cho phép: %s km.',
                        $firstLabel,
                        $firstRow['at']->format('H:i d/m'),
                        number_format($firstRow['km'], 1, ',', '.'),
                        TripKmLimitService::DELTA_LONG,
                        $baselineLabel,
                        number_format($baselineKm, 1, ',', '.'),
                        number_format($maxAllowed, 1, ',', '.'),
                    ));

                    return;
                }
            }

            $highest = null;
            $previous = null;

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

                if ($previous !== null) {
                    $maxDelta = ($previous['type'] !== null && $row['type'] !== null)
                        ? $limitService->getMaxAllowedDelta($previous['type'], $row['type'])
                        : TripKmLimitService::DELTA_LONG;

                    if (($row['km'] - $previous['km']) > $maxDelta) {
                        $maxAllowed = $previous['km'] + $maxDelta;
                        $currLabel = $row['type']?->getLabel() ?? 'Mốc sau';
                        $prevLabel = $previous['type']?->getLabel() ?? 'Mốc trước';
                        $fail(sprintf(
                            'Km mốc "%s" lúc %s (%s km) vượt quá giới hạn cho phép (+%.0f km) so với mốc "%s" lúc %s (%s km). Tối đa cho phép: %s km.',
                            $currLabel,
                            $row['at']->format('H:i d/m'),
                            number_format($row['km'], 1, ',', '.'),
                            $maxDelta,
                            $prevLabel,
                            $previous['at']->format('H:i d/m'),
                            number_format($previous['km'], 1, ',', '.'),
                            number_format($maxAllowed, 1, ',', '.'),
                        ));

                        return;
                    }
                }

                if ($highest === null || $row['km'] >= $highest['km']) {
                    $highest = $row;
                }
                $previous = $row;
            }
        };
    }

    /**
     * Thời gian mốc kết thúc xe (end) không được trước mốc bắt đầu chuyến (started).
     */
    /**
     * Mốc Đến giao hàng / Hoàn thành mới thêm cho đơn đã có điểm giao phải chọn Điểm:
     * để trống thì không biết mốc thuộc điểm nào, đơn và chuyến không mở lại và App tài xế nhảy thẳng tới Kết thúc.
     * Mốc đã lưu (key `record-*`) không kiểm tra lại để vẫn sửa được chuyến cũ.
     */
    public static function newDeliveryCheckpointNeedsPointRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $missingPoint = collect(is_array($value) ? $value : [])
                ->filter(fn ($row, $key): bool => is_array($row) && ! str_starts_with((string) $key, 'record-'))
                ->first(function (array $row): bool {
                    $raw = $row['checkpoint_type'] ?? null;
                    $type = $raw instanceof CheckpointType ? $raw : (is_string($raw) ? CheckpointType::tryFrom($raw) : null);

                    return in_array($type, [CheckpointType::ArrivedDelivery, CheckpointType::Completed], true)
                        && blank($row['delivery_point_id'] ?? null)
                        && filled($row['order_id'] ?? null)
                        && OrderDeliveryPoint::query()->where('order_id', $row['order_id'])->exists();
                });

            if ($missingPoint !== null) {
                $fail('Mốc "Đến giao hàng" / "Hoàn thành" mới phải chọn Điểm giao để đơn và App tài xế chuyển đúng bước.');
            }
        };
    }

    public static function checkpointTimeOrderRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $rows = collect(is_array($value) ? $value : [])
                ->filter(fn ($row): bool => is_array($row) && filled($row['occurred_at'] ?? null))
                ->map(function (array $row): array {
                    $raw = $row['checkpoint_type'] ?? null;
                    $type = $raw instanceof CheckpointType
                        ? $raw
                        : (is_string($raw) ? CheckpointType::tryFrom($raw) : null);

                    return [
                        'type' => $type,
                        'at' => Carbon::parse($row['occurred_at']),
                    ];
                });

            $now = now();
            foreach ($rows as $row) {
                if ($row['at']->gt($now)) {
                    $typeLabel = $row['type']?->getLabel() ?? 'Mốc hành trình';
                    $fail(sprintf(
                        'Thời gian mốc "%s" (%s) không được lớn hơn thời gian hiện tại (%s).',
                        $typeLabel,
                        $row['at']->format('H:i d/m/Y'),
                        $now->format('H:i d/m/Y'),
                    ));

                    return;
                }
            }

            $startedAt = $rows
                ->where('type', CheckpointType::Started)
                ->sortBy(fn (array $row): int => $row['at']->getTimestamp())
                ->first()['at'] ?? null;

            if ($startedAt === null) {
                return;
            }

            $endAt = $rows
                ->where('type', CheckpointType::End)
                ->sortByDesc(fn (array $row): int => $row['at']->getTimestamp())
                ->first()['at'] ?? null;

            if ($endAt !== null && $endAt->lt($startedAt)) {
                $fail(sprintf(
                    'Thời gian mốc kết thúc xe (%s) không được sớm hơn mốc bắt đầu chuyến (%s).',
                    $endAt->format('H:i d/m/Y'),
                    $startedAt->format('H:i d/m/Y'),
                ));
            }
        };
    }

    private static function fillCheckpointRelationshipData(array $data, $get, bool $isRent = false): array
    {
        $vehicleId = ! empty($data['vehicle_id']) ? $data['vehicle_id'] : $get('../../vehicle_id');
        $data['vehicle_id'] = $vehicleId;

        if ($isRent) {
            $data['driver_id'] = null;
            $data['shift_id'] = null;

            return $data;
        }

        $driverId = ! empty($data['driver_id']) ? $data['driver_id'] : $get('../../driver_id');
        $data['driver_id'] = $driverId;

        if (empty($data['shift_id']) && $driverId) {
            $shiftId = DriverShift::where('driver_id', $driverId)
                ->whereNull('end_time')
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

    /**
     * Gợi ý thời gian cho mốc hành trình mới: tăng +1 phút so với mốc liền trước (nếu đã có mốc),
     * hoặc lấy thời gian bắt đầu chuyến / hiện tại cho mốc đầu tiên.
     */
    private static function defaultCheckpointOccurredAt(callable $get, string $repeaterName, ?Model $record): string
    {
        $tz = config('app.timezone');
        $parseFormatted = static function (mixed $raw) use ($tz): Carbon {
            if ($raw instanceof DateTimeInterface) {
                return Carbon::instance($raw)->setTimezone($tz);
            }

            return Carbon::parse((string) $raw, $tz)->setTimezone($tz);
        };

        $checkpoints = $get("../../{$repeaterName}");
        if (is_array($checkpoints) && ! empty($checkpoints)) {
            $prev = collect($checkpoints)->reverse()->first(fn ($item): bool => is_array($item) && filled($item['occurred_at'] ?? null));
            if ($prev && filled($prev['occurred_at'] ?? null)) {
                try {
                    $suggested = $parseFormatted($prev['occurred_at'])->copy()->addMinute();
                    $now = now($tz);

                    return ($suggested->gt($now) ? $now : $suggested)->format('Y-m-d H:i');
                } catch (\Throwable) {
                    // Fallback to trip start time or now() when intermediate value is invalid.
                }
            }
        }

        $fallback = collect([
            $get('../../started_at'),
            $record instanceof Trip ? $record->started_at : null,
            now(),
        ])->first(fn ($value): bool => filled($value));

        try {
            return $parseFormatted($fallback)->format('Y-m-d H:i');
        } catch (\Throwable) {
            return now()->format('Y-m-d H:i');
        }
    }

    /**
     * Xác định loại mốc hành trình tiếp theo dựa trên lịch sử các mốc trước đó.
     * Nếu mốc gần nhất là đảo lái (driver_swap) hoặc huỷ chuyến (cancelled),
     * bỏ qua và lấy trạng thái tiếp theo của mốc hợp lệ trước đó.
     */
    private static function getNextCheckpointType(callable $get, string $repeaterName): ?CheckpointType
    {
        $checkpoints = $get("../../{$repeaterName}");
        if (! is_array($checkpoints) || empty($checkpoints)) {
            return CheckpointType::Started;
        }

        $lastCheckpoint = collect($checkpoints)
            ->reverse()
            ->first(function ($item): bool {
                if (! is_array($item) || blank($item['checkpoint_type'] ?? null)) {
                    return false;
                }

                $raw = $item['checkpoint_type'];
                $type = $raw instanceof CheckpointType
                    ? $raw
                    : (is_string($raw) ? CheckpointType::tryFrom($raw) : null);

                return $type !== null && $type !== CheckpointType::DriverSwap && $type !== CheckpointType::Cancelled;
            });

        if (! $lastCheckpoint) {
            return CheckpointType::Started;
        }

        $raw = $lastCheckpoint['checkpoint_type'];
        $type = $raw instanceof CheckpointType
            ? $raw
            : (is_string($raw) ? CheckpointType::tryFrom($raw) : null);

        if ($type === CheckpointType::Completed && filled($lastCheckpoint['order_id'] ?? null)) {
            $orderId = (int) $lastCheckpoint['order_id'];
            $completedIds = self::completedDeliveryPointIdsForOrder($get, $repeaterName, $orderId);
            if (self::resolveOrderDeliveryPointId($orderId, $completedIds) !== null) {
                return CheckpointType::ArrivedDelivery;
            }
        }

        return $type?->getNextType();
    }

    /**
     * Danh sách điểm giao hiển thị trong dropdown cột Điểm: gồm điểm giao của chính đơn và các điểm cùng khu vực,
     * gộp trùng theo địa điểm để mỗi mã kho chỉ hiện 1 lần (ưu tiên giá trị đang chọn rồi đến điểm của đơn hiện tại).
     *
     * @return array<int, string>
     */
    private static function deliveryPointOptionsForCheckpoint(callable $get): array
    {
        $orderId = $get('order_id');
        $currentId = $get('delivery_point_id');

        if (! $orderId && ! $currentId) {
            return [];
        }

        $areaId = $orderId ? Order::find($orderId)?->area_id : null;

        $points = OrderDeliveryPoint::query()
            ->where(function ($query) use ($orderId, $areaId, $currentId): void {
                if ($currentId) {
                    $query->whereKey($currentId);
                }
                if ($orderId) {
                    $query->orWhere('order_id', $orderId);
                }
                if ($areaId) {
                    $query->orWhereHas('order', fn ($sub) => $sub->where('area_id', $areaId));
                }
            })
            ->with('location')
            ->get()
            ->sortBy(function (OrderDeliveryPoint $dp) use ($currentId, $orderId): array {
                $priority = match (true) {
                    $currentId && (int) $dp->id === (int) $currentId => 0,
                    $orderId && (int) $dp->order_id === (int) $orderId => 1,
                    default => 2,
                };

                return [$priority, (int) ($dp->sequence ?? 0), (int) $dp->id];
            });

        $options = [];
        $seen = [];

        foreach ($points as $dp) {
            $dedupKey = $dp->location_id !== null
                ? 'loc:'.$dp->location_id
                : (filled($dp->address) ? 'addr:'.mb_strtolower(trim((string) $dp->address)) : 'dp:'.$dp->id);

            if (isset($seen[$dedupKey])) {
                continue;
            }

            $seen[$dedupKey] = true;
            $options[$dp->id] = $dp->location?->code ?? 'DP#'.$dp->id;
        }

        natcasesort($options);

        return $options;
    }

    /**
     * Tự động gợi ý điểm giao cho mốc mới:
     * - Bỏ qua các mốc đầu / lấy hàng (`started`, `arrived_pickup`, `left_pickup`)
     * - Với đơn có nhiều điểm giao: sau khi hoàn thành điểm trước sẽ tự gợi ý điểm giao chưa hoàn thành tiếp theo
     * - Mốc kết thúc (`end`) lấy lại điểm giao cuối cùng.
     */
    private static function defaultDeliveryPointId(
        callable $get,
        string $repeaterName,
        ?CheckpointType $explicitType = null,
        ?Model $record = null,
    ): ?int {
        $raw = $get('checkpoint_type');
        $type = $explicitType
            ?? ($raw instanceof CheckpointType ? $raw : (is_string($raw) ? CheckpointType::tryFrom($raw) : null))
            ?? self::getNextCheckpointType($get, $repeaterName);

        if (in_array($type, [CheckpointType::Started, CheckpointType::ArrivedPickup, CheckpointType::LeftPickup], true)) {
            return null;
        }

        $tripId = $get('../../id')
            ?? ($record instanceof TripCheckpoint ? $record->trip_id : ($record instanceof Trip ? $record->id : null));

        $orderId = $get('order_id') ?? self::getPreviousCheckpointValue(
            $get,
            $repeaterName,
            'order_id',
            fn () => $tripId ? Order::where('trip_id', $tripId)->value('id') : null,
        );

        $completedIds = $orderId
            ? self::completedDeliveryPointIdsForOrder($get, $repeaterName, (int) $orderId)
            : [];

        $prevId = self::getPreviousCheckpointValue($get, $repeaterName, 'delivery_point_id', null);
        if (filled($prevId)) {
            $prevInt = (int) $prevId;
            if ($type === CheckpointType::End || ! in_array($prevInt, $completedIds, true)) {
                return $prevInt;
            }
        }

        if (! in_array($type, [CheckpointType::ArrivedDelivery, CheckpointType::Completed, CheckpointType::End], true)) {
            return null;
        }

        $nextId = self::resolveOrderDeliveryPointId($orderId ? (int) $orderId : null, $completedIds);
        if ($nextId !== null) {
            return $nextId;
        }

        return filled($prevId) ? (int) $prevId : null;
    }

    private static function syncDeliveryPointOnTypeChange(?CheckpointType $type, callable $set, callable $get, string $repeaterName): void
    {
        if (in_array($type, [CheckpointType::Started, CheckpointType::ArrivedPickup, CheckpointType::LeftPickup], true)) {
            $set('delivery_point_id', null);

            return;
        }

        if (filled($get('delivery_point_id'))) {
            return;
        }

        $suggested = self::defaultDeliveryPointId($get, $repeaterName, $type);
        if ($suggested !== null) {
            $set('delivery_point_id', $suggested);
        }
    }

    private static function syncDeliveryPointOnOrderChange(mixed $orderId, callable $set, callable $get): void
    {
        $raw = $get('checkpoint_type');
        $type = $raw instanceof CheckpointType ? $raw : (is_string($raw) ? CheckpointType::tryFrom($raw) : null);

        if (! in_array($type, [CheckpointType::ArrivedDelivery, CheckpointType::Completed, CheckpointType::End], true)) {
            return;
        }

        $set('delivery_point_id', $orderId ? self::resolveOrderDeliveryPointId((int) $orderId) : null);
    }

    /**
     * @param  list<int>  $excludeIds
     */
    private static function resolveOrderDeliveryPointId(?int $orderId, array $excludeIds = []): ?int
    {
        if (! $orderId) {
            return null;
        }

        $dpId = OrderDeliveryPoint::where('order_id', $orderId)
            ->when($excludeIds !== [], fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->orderBy('sequence')
            ->value('id');

        return $dpId ? (int) $dpId : null;
    }

    /**
     * Danh sách delivery_point_id của đơn hàng đã có mốc `completed` ở các dòng phía trên.
     *
     * @return list<int>
     */
    private static function completedDeliveryPointIdsForOrder(callable $get, string $repeaterName, int $orderId): array
    {
        $checkpoints = $get("../../{$repeaterName}");
        if (! is_array($checkpoints) || empty($checkpoints)) {
            return [];
        }

        $completedIds = [];
        foreach ($checkpoints as $item) {
            if (! is_array($item)) {
                continue;
            }
            if ((int) ($item['order_id'] ?? 0) !== $orderId) {
                continue;
            }
            if (blank($item['delivery_point_id'] ?? null)) {
                continue;
            }

            $raw = $item['checkpoint_type'] ?? null;
            $type = $raw instanceof CheckpointType ? $raw : (is_string($raw) ? CheckpointType::tryFrom($raw) : null);
            if ($type === CheckpointType::Completed) {
                $completedIds[] = (int) $item['delivery_point_id'];
            }
        }

        return array_values(array_unique($completedIds));
    }

    /**
     * Mốc lấy hàng không lưu điểm riêng: điểm lấy chính là điểm lấy hàng của đơn.
     */
    private static function isPickupCheckpoint(callable $get): bool
    {
        $raw = $get('checkpoint_type');
        $type = $raw instanceof CheckpointType ? $raw : (is_string($raw) ? CheckpointType::tryFrom($raw) : null);

        return in_array($type, [CheckpointType::ArrivedPickup, CheckpointType::LeftPickup], true);
    }

    private static function pickupPointLabel(callable $get): ?string
    {
        if (! self::isPickupCheckpoint($get) || blank($get('order_id'))) {
            return null;
        }

        $location = Order::with('pickupLocation:id,code,name')->find($get('order_id'))?->pickupLocation;

        return $location?->code ?? $location?->name;
    }

    /**
     * Mỗi đơn hàng trong chuyến là một tab để điều hành sửa đơn ngay trong form Sửa chuyến.
     *
     * @return array<int, Tab>
     */
    private static function orderEditTabs(?Trip $record): array
    {
        if (! $record instanceof Trip) {
            return [];
        }

        $record->loadMissing(['orders.customer', 'orders.area', 'orders.deliveryPoints.location']);

        return $record->orders
            ->sortBy('id')
            ->map(fn (Order $order): Tab => self::orderEditTab($order))
            ->values()
            ->all();
    }

    private static function orderEditTab(Order $order): Tab
    {
        $path = "orders_data.{$order->id}";

        return Tab::make($order->order_code)
            ->badge($order->status?->getLabel())
            ->schema([
                Section::make('Thông tin đơn hàng')
                    ->columns(['default' => 1, 'sm' => 12])
                    ->schema([
                        TextInput::make("order_{$order->id}_code")
                            ->label('Mã đơn')
                            ->statePath("{$path}.order_code")
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpan(['default' => 1, 'sm' => 3]),
                        TextInput::make("order_{$order->id}_customer")
                            ->label('Khách hàng')
                            ->statePath("{$path}.customer_name")
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpan(['default' => 1, 'sm' => 3]),
                        TextInput::make("order_{$order->id}_area")
                            ->label('Khu vực')
                            ->statePath("{$path}.area_code")
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpan(['default' => 1, 'sm' => 3]),
                        DateTimePicker::make("order_{$order->id}_planned_loading_at")
                            ->label('Thời gian đóng hàng')
                            ->statePath("{$path}.planned_loading_at")
                            ->seconds(false)
                            ->native(true)
                            ->columnSpan(['default' => 1, 'sm' => 3]),
                        TextInput::make("order_{$order->id}_cargo_name")
                            ->label('Tên hàng hoá')
                            ->statePath("{$path}.cargo_name")
                            ->maxLength(255)
                            ->columnSpan(['default' => 1, 'sm' => 4]),
                        TextInput::make("order_{$order->id}_total_packages")
                            ->label('Tổng số kiện')
                            ->statePath("{$path}.total_packages")
                            ->numeric()
                            ->columnSpan(['default' => 1, 'sm' => 2]),
                        TextInput::make("order_{$order->id}_total_weight")
                            ->label('Tổng trọng lượng (kg)')
                            ->statePath("{$path}.total_weight")
                            ->numeric()
                            ->columnSpan(['default' => 1, 'sm' => 2]),
                        Select::make("order_{$order->id}_chargeable_weight")
                            ->label('Tải trọng tính cước (tấn)')
                            ->statePath("{$path}.chargeable_weight")
                            ->options(OrderForm::CHARGEABLE_WEIGHT_OPTIONS)
                            ->formatStateUsing(function (mixed $state): ?string {
                                $key = FreightRate::tonnageKey($state);

                                return $key !== '' ? $key : null;
                            })
                            ->native(false)
                            ->visible(fn (): bool => $order->isExternal())
                            ->columnSpan(['default' => 1, 'sm' => 2]),
                        Select::make("order_{$order->id}_pickup_location_id")
                            ->label('Điểm nhận hàng')
                            ->statePath("{$path}.pickup_location_id")
                            ->options(fn (): array => self::orderLocationOptions($order))
                            ->searchable()
                            ->native(false)
                            ->columnSpan(['default' => 1, 'sm' => 2]),
                        Textarea::make("order_{$order->id}_notes")
                            ->label('Yêu cầu đặc biệt')
                            ->statePath("{$path}.notes")
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
                Section::make('Điểm giao hàng')
                    ->schema([
                        Repeater::make("order_{$order->id}_delivery_points")
                            ->statePath("{$path}.deliveryPoints")
                            ->label('Danh sách điểm giao')
                            ->table([
                                TableColumn::make('Điểm giao')->width('180px'),
                                TableColumn::make('Địa chỉ')->width('220px'),
                                TableColumn::make('Người nhận')->width('160px'),
                                TableColumn::make('SĐT')->width('140px'),
                                TableColumn::make('Kiện')->width('90px'),
                                TableColumn::make('KL (kg)')->width('110px'),
                            ])
                            ->compact()
                            ->schema([
                                Hidden::make('id'),
                                Select::make('location_id')
                                    ->label('Điểm giao')
                                    ->options(fn (): array => self::orderLocationOptions($order))
                                    ->searchable()
                                    ->native(false)
                                    ->required(),
                                TextInput::make('address')
                                    ->label('Địa chỉ')
                                    ->maxLength(255),
                                TextInput::make('contact_person')
                                    ->label('Người nhận')
                                    ->maxLength(255),
                                TextInput::make('contact_phone')
                                    ->label('SĐT')
                                    ->maxLength(20),
                                TextInput::make('total_packages')
                                    ->label('Kiện')
                                    ->numeric(),
                                TextInput::make('total_weight')
                                    ->label('KL (kg)')
                                    ->numeric(),
                            ])
                            ->addActionLabel('Thêm điểm giao')
                            ->reorderableWithDragAndDrop()
                            ->defaultItems(0)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Địa điểm chọn được trong tab Đơn hàng: theo khu vực của đơn, luôn giữ địa điểm đang chọn.
     *
     * @return array<int, string>
     */
    private static function orderLocationOptions(Order $order): array
    {
        $referencedLocationIds = $order->deliveryPoints()
            ->pluck('location_id')
            ->filter()
            ->all();

        if ($order->pickup_location_id !== null) {
            $referencedLocationIds[] = $order->pickup_location_id;
        }

        return Location::query()
            ->where(function ($query) use ($referencedLocationIds): void {
                $query->where('is_active', true);

                if ($referencedLocationIds !== []) {
                    $query->orWhereIn('locations.id', $referencedLocationIds);
                }
            })
            ->orderBy('code')
            ->pluck('code', 'id')
            ->toArray();
    }
}
