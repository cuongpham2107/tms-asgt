<?php

namespace App\Filament\Resources\Trips\Schemas;

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
use Filament\Schemas\Components\View;
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
                        View::make('checkpoints_grouped')
                            ->view('filament.resources.trips.components.grouped-checkpoints')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
