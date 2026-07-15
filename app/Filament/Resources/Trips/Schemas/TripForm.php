<?php

namespace App\Filament\Resources\Trips\Schemas;

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
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
                        TextInput::make('start_location_code')
                            ->label('Điểm bắt đầu')
                            ->hidden(fn (Model $record) => ! $record->is_empty_run)
                            ->afterStateHydrated(fn (TextInput $component, ?Trip $record) => $component->state($record?->startLocation?->code))
                            ->columnSpan(2),
                        TextInput::make('end_location_code')
                            ->label('Điểm kết thúc')
                            ->hidden(fn (Model $record) => ! $record->is_empty_run)
                            ->afterStateHydrated(fn (TextInput $component, ?Trip $record) => $component->state($record?->endLocation?->code))
                            ->columnSpan(2),
                        TextInput::make('start_km')
                            ->label('Km bắt đầu')
                            ->prefixIcon(Heroicon::OutlinedAdjustmentsVertical)
                            ->mask(RawJs::make('$money($input)'))
                            ->stripCharacters(',')
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
                            ->mask(RawJs::make('$money($input)'))
                            ->stripCharacters(',')
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
                Section::make('Các mốc hành trình')
                    ->columnSpanFull()
                    ->hidden(fn (Model $record) => $record->is_empty_run)
                    ->schema([
                        Repeater::make('checkpoints')
                            ->relationship('checkpoints')
                            ->label('Danh sách mốc hành trình')
                            ->table([
                                TableColumn::make('Loại')->width('180px'),
                                TableColumn::make('Đơn hàng')->width('120px'),
                                TableColumn::make('Km')->width('120px'),
                                TableColumn::make('Giờ')->width('180px'),
                                TableColumn::make('Điểm giao')->width('120px'),
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
                                    ->native(true),
                                Select::make('delivery_point_id')
                                    ->label('Điểm giao')
                                    ->options(function ($get): array {
                                        $orderId = $get('order_id');
                                        if (! $orderId) {
                                            return [];
                                        }

                                        return OrderDeliveryPoint::where('order_id', $orderId)
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
                            ->mutateRelationshipDataBeforeSaveUsing(function (array $data, $get): array {
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
