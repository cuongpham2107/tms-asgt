<?php

namespace App\Filament\Resources\Trips\Schemas;

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class TripForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin chuyến')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('trip_code')
                            ->label('Mã chuyến')
                            ->prefixIcon(Heroicon::OutlinedIdentification)
                            ->disabled(),
                        Select::make('status')
                            ->label('Trạng thái')
                            ->prefixIcon(Heroicon::OutlinedSignal)
                            ->options(TripStatus::class),
                        Select::make('vehicle_id')
                            ->label('Phương tiện')
                            ->relationship('vehicle', 'plate_number')
                            ->prefixIcon(Heroicon::OutlinedTruck)
                            ->searchable()
                            ->native(false),
                        Select::make('driver_id')
                            ->label('Lái xe')
                            ->options(fn (): array => User::query()
                                ->whereHas('roles', fn ($q) => $q->where('name', 'driver'))
                                ->pluck('name', 'id')
                                ->toArray()
                            )
                            ->prefixIcon(Heroicon::OutlinedUser)
                            ->searchable()
                            ->native(false),
                    ]),
                Section::make('Km & Thời gian')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('start_km')
                            ->label('Km bắt đầu')
                            ->prefixIcon(Heroicon::OutlinedAdjustmentsVertical)
                            ->numeric()
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
                            ->numeric(),
                        DateTimePicker::make('started_at')
                            ->label('Bắt đầu')
                            ->prefixIcon(Heroicon::OutlinedClock)
                            ->displayFormat('H:i d/m/Y')
                            ->seconds(false)
                            ->native(true)
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
                            ->native(true),
                    ]),
                // Section::make('Đơn hàng trong chuyến')
                //     ->columnSpanFull()
                //     ->schema([
                //         Repeater::make('orders')
                //             ->relationship('orders')
                //             ->label('Danh sách đơn hàng')
                //             ->table([
                //                 TableColumn::make('Mã đơn')->width('150px'),
                //                 TableColumn::make('Khách hàng')->width('200px'),
                //                 TableColumn::make('Trạng thái')->width('120px'),
                //             ])
                //             ->schema([
                //                 Placeholder::make('order_code')
                //                     ->label('Mã đơn')
                //                     ->content(fn ($record) => $record?->order_code ?? '-'),
                //                 Placeholder::make('customer_name')
                //                     ->label('Khách hàng')
                //                     ->content(fn ($record) => $record?->customer?->name ?? '-'),
                //                 Placeholder::make('order_status')
                //                     ->label('Trạng thái')
                //                     ->content(fn ($record) => $record?->status?->getLabel() ?? '-'),
                //             ])
                //             ->addable(false)
                //             ->deletable(false)
                //             ->reorderable(false),
                //     ]),
                Section::make('Các mốc hành trình')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('checkpoints')
                            ->relationship('checkpoints')
                            ->label('Danh sách mốc hành trình')
                            ->table([
                                TableColumn::make('Loại')->width('150px'),
                                TableColumn::make('Đơn hàng')->width('120px'),
                                TableColumn::make('Km')->width('80px'),
                                TableColumn::make('Giờ')->width('150px'),
                                TableColumn::make('Ghi chú')->width('150px'),
                            ])
                            ->schema([
                                Select::make('checkpoint_type')
                                    ->label('Loại')
                                    ->options(CheckpointType::class)
                                    ->required()
                                    ->native(false),
                                Select::make('order_id')
                                    ->label('Đơn hàng')
                                    ->relationship('order', 'order_code')
                                    ->searchable()
                                    ->native(false)
                                    ->modifyQueryUsing(fn ($query, $get) => $query->where('trip_id', $get('../../id'))),
                                TextInput::make('km_reading')
                                    ->label('Km')
                                    ->numeric()
                                    ->step(0.1)
                                    ->nullable(),
                                DateTimePicker::make('occurred_at')
                                    ->label('Thời gian')
                                    ->required()
                                    ->displayFormat('H:i d/m/Y')
                                    ->seconds(false)
                                    ->native(true),
                                TextInput::make('voice_note')
                                    ->label('Ghi chú')
                                    ->nullable(),
                                Select::make('delivery_point_id')
                                    ->label('Điểm giao')
                                    ->relationship('deliveryPoint', 'address')
                                    ->searchable()
                                    ->native(false)
                                    ->nullable()
                                    ->modifyQueryUsing(fn ($query, $get) => $query->where('order_id', $get('order_id'))),
                                TextInput::make('gps_lat')
                                    ->label('GPS Lat')
                                    ->numeric()
                                    ->step(0.0000001)
                                    ->nullable(),
                                TextInput::make('gps_lng')
                                    ->label('GPS Lng')
                                    ->numeric()
                                    ->step(0.0000001)
                                    ->nullable(),
                                Select::make('driver_id')
                                    ->relationship('driver', 'name')
                                    ->hidden()
                                    ->default(fn ($get) => $get('../../driver_id')),
                                Select::make('shift_id')
                                    ->relationship('shift', 'id')
                                    ->hidden()
                                    ->default(fn ($get) => $get('../../shift_id')),
                                Select::make('vehicle_id')
                                    ->relationship('vehicle', 'plate_number')
                                    ->hidden()
                                    ->default(fn ($get) => $get('../../vehicle_id')),
                            ])
                            ->addable()
                            ->deletable()
                            ->mutateRelationshipDataBeforeCreateUsing(function (array $data, Trip $record): array {
                                $data['driver_id'] = $record->driver_id;
                                $data['shift_id'] = $record->shift_id;
                                $data['vehicle_id'] = $record->vehicle_id;

                                return $data;
                            })
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
