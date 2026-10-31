<?php

namespace App\Filament\Resources\WaitingFeeRates\Schemas;

use App\Models\WaitingFeeRate;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;

class WaitingFeeRateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Phí giờ chờ')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('customer_id')
                            ->label('Khách hàng')
                            ->relationship('customer', 'code')
                            ->searchable(['code', 'name'])
                            ->preload()
                            ->required(),
                        TextInput::make('surcharge_type')
                            ->label('Loại hình tính phụ phí')
                            ->placeholder('Để trống = chuẩn')
                            ->datalist(fn (): array => WaitingFeeRate::query()->where('surcharge_type', '!=', '')->distinct()->pluck('surcharge_type')->all())
                            ->dehydrateStateUsing(fn (?string $state): string => trim((string) $state)),
                        Select::make('waiting_mode')
                            ->label('Kiểu chờ')
                            ->options([
                                WaitingFeeRate::MODE_NORMAL => 'Thường',
                                WaitingFeeRate::MODE_ENGINE_ON => 'Nổ máy (hạ hàng)',
                                WaitingFeeRate::MODE_ENGINE_OFF => 'Không nổ máy (đóng hàng)',
                            ])
                            ->default(WaitingFeeRate::MODE_NORMAL)
                            ->required(),
                        TextInput::make('fee_block_minutes')
                            ->label('Độ dài 1 block tính phí')
                            ->numeric()
                            ->minValue(1)
                            ->default(60)
                            ->suffix('phút')
                            ->required(),
                        TextInput::make('grace_minutes')
                            ->label('Thời gian miễn thêm')
                            ->helperText('Phút lẻ vượt mức này mới tính thêm 1 block')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->suffix('phút')
                            ->required(),
                        Repeater::make('tiers')
                            ->label('Phí theo trọng tải')
                            ->table([
                                TableColumn::make('Trọng tải (tấn)')->markAsRequired(),
                                TableColumn::make('Phí mỗi block (đ)')->markAsRequired(),
                                TableColumn::make('Miễn phí (phút)')->markAsRequired(),
                            ])
                            ->compact()
                            ->columnSpanFull()
                            ->addActionLabel('Thêm bậc tải')
                            ->reorderable(false)
                            ->minItems(1)
                            ->required()
                            ->schema([
                                TextInput::make('tonnage')
                                    ->label('Trọng tải tính cước')
                                    ->numeric()
                                    ->minValue(0)
                                    ->suffix('tấn')
                                    ->required(),
                                TextInput::make('fee')
                                    ->label('Phí mỗi block')
                                    ->mask(RawJs::make('$money($input)'))
                                    ->stripCharacters(',')
                                    ->numeric()
                                    ->minValue(0)
                                    ->suffix('đ')
                                    ->required(),
                                TextInput::make('free_minutes')
                                    ->label('Thời gian miễn phí')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->suffix('phút')
                                    ->required(),
                            ]),
                        DatePicker::make('effective_from')
                            ->label('Áp dụng từ ngày'),
                        DatePicker::make('effective_to')
                            ->label('Áp dụng đến ngày')
                            ->afterOrEqual('effective_from'),
                    ]),
            ]);
    }
}
