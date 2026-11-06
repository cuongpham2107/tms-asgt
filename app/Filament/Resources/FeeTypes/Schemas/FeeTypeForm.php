<?php

namespace App\Filament\Resources\FeeTypes\Schemas;

use App\Enums\FeeCalcMethod;
use App\Models\FeeType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class FeeTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        // Ô chọn trả về enum (khi sửa bản ghi) hoặc chuỗi (khi vừa chọn).
        $method = fn (Get $get): ?FeeCalcMethod => ($state = $get('calc_method')) instanceof FeeCalcMethod ? $state : FeeCalcMethod::tryFrom((string) $state);
        $isAuto = fn (Get $get): bool => filled($get('customer_id')) && ($method($get)?->isAuto() ?? false);

        return $schema
            ->components([
                Section::make('Loại phí')
                    ->description('Không chọn khách = phí dùng chung, kế toán nhập tay số tiền. Chọn khách để khai báo đơn giá riêng: chọn phí trong modal cước là số tiền tự điền.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('customer_id')
                            ->label('Khách hàng')
                            ->relationship('customer', 'code')
                            ->placeholder('Dùng chung')
                            ->searchable()
                            ->preload()
                            ->live(),
                        TextInput::make('name')
                            ->label('Tên phí')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->label('Mã (nhóm cột trong bảng kê)')
                            ->helperText('Nhiều mức phí cùng loại dùng chung 1 mã, VD: extra_drop cho mọi mức rớt điểm.')
                            ->datalist(fn (): array => FeeType::query()->distinct()->orderBy('code')->pluck('code')->all())
                            ->required()
                            ->maxLength(50),
                        TextInput::make('sort')
                            ->label('Thứ tự')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Đang dùng')
                            ->default(true)
                            ->inline(false),
                    ]),
                Section::make('Cách tính')
                    ->columns(2)
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => filled($get('customer_id')))
                    ->schema([
                        Select::make('calc_method')
                            ->label('Cách tính')
                            ->options(FeeCalcMethod::class)
                            ->default(FeeCalcMethod::Manual->value)
                            ->required()
                            ->live(),
                        TextInput::make('amount')
                            ->label('Đơn giá / tỷ lệ')
                            ->numeric()
                            ->minValue(0)
                            ->suffix(fn (Get $get): string => $method($get)?->unit() ?? 'đ')
                            ->required($isAuto)
                            ->visible($isAuto),
                        Toggle::make('auto_apply')
                            ->label('Tự cộng cho mọi đơn của khách')
                            ->helperText('Bật cho phí luôn có (VD: bốc xếp theo thùng). Tắt thì kế toán chọn trong modal cước.')
                            ->visible($isAuto)
                            ->inline(false),
                        Textarea::make('description')
                            ->label('Diễn giải')
                            ->placeholder('VD: Đóng hoặc trả hàng tại nhà máy Fukang')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
