<?php

namespace App\Filament\Resources\Trips\Schemas;

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\User;
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
                            ->columnSpan(['default' => 1, 'sm' => fn (?Model $record) => $record?->vehicle?->type === VehicleOwnerType::Rent ? 4 : 3])
                            ->disabled(),
                        Select::make('status')
                            ->label('Trạng thái')
                            ->prefixIcon(Heroicon::OutlinedSignal)
                            ->options(TripStatus::class)
                            ->columnSpan(['default' => 1, 'sm' => fn (?Model $record) => $record?->vehicle?->type === VehicleOwnerType::Rent ? 4 : 3]),
                        Select::make('vehicle_id')
                            ->label('Phương tiện')
                            ->relationship('vehicle', 'plate_number')
                            ->prefixIcon(Heroicon::OutlinedTruck)
                            ->disabled()
                            ->searchable()
                            ->native(false)
                            ->columnSpan(['default' => 1, 'sm' => fn (?Model $record) => $record?->vehicle?->type === VehicleOwnerType::Rent ? 4 : 3]),
                        Select::make('driver_id')
                            ->label('Lái xe')
                            ->hidden(fn (?Model $record) => $record?->vehicle?->type === VehicleOwnerType::Rent)
                            ->options(fn (): array => User::query()
                                ->whereHas('roles', fn ($q) => $q->where('name', 'driver'))
                                ->pluck('name', 'id')
                                ->toArray()
                            )
                            ->prefixIcon(Heroicon::OutlinedUser)
                            ->disabled()
                            ->searchable()
                            ->native(false)
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
                        // Repeater cho xe thuê ngoài (4 cột: Loại, Đơn hàng, Giờ, Điểm giao)
                        Repeater::make('checkpoints_rent')
                            ->relationship('checkpoints')
                            ->label('Danh sách mốc hành trình')
                            ->visible(fn (?Model $record) => $record?->vehicle?->type === VehicleOwnerType::Rent)
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
                                    ->native(false),
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
                                    ->native(true),
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
                                    ->nullable(),
                            ])
                            ->addable()
                            ->deletable()
                            ->addActionLabel('Thêm mốc hành trình')
                            ->mutateRelationshipDataBeforeCreateUsing(function (array $data, $get): array {
                                $data['driver_id'] = $get('../../driver_id');
                                $data['shift_id'] = $get('../../shift_id');
                                $data['vehicle_id'] = $get('../../vehicle_id');

                                return $data;
                            })
                            ->columnSpanFull(),

                        // Repeater cho xe công ty (đầy đủ 6 cột: Loại, Đơn hàng, Tài xế, Km, Giờ, Điểm giao)
                        Repeater::make('checkpoints')
                            ->relationship('checkpoints')
                            ->label('Danh sách mốc hành trình')
                            ->visible(fn (?Model $record) => $record?->vehicle?->type !== VehicleOwnerType::Rent)
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
                                    ->native(false),
                                Select::make('driver_id')
                                    ->label('Tài xế')
                                    ->options(fn () => User::query()
                                        ->where('is_active', true)
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->toArray())
                                    ->searchable()
                                    ->native(false)
                                    ->nullable(),
                                TextInput::make('km_reading')
                                    ->label('Km')
                                    ->mask(RawJs::make('$money($input)'))
                                    ->stripCharacters(',')
                                    ->numeric()
                                    ->step(0.1)
                                    ->nullable(),
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
                                    ->native(true),
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
                                    ->nullable(),
                            ])
                            ->addable()
                            ->deletable()
                            ->addActionLabel('Thêm mốc hành trình')
                            ->mutateRelationshipDataBeforeCreateUsing(function (array $data, $get): array {
                                $data['driver_id'] = $get('../../driver_id');
                                $data['shift_id'] = $get('../../shift_id');
                                $data['vehicle_id'] = $get('../../vehicle_id');

                                return $data;
                            })
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
