<?php

namespace App\Filament\Resources\Vehicles\Schemas;

use CodeWithDennis\FilamentAdvancedChoice\Filament\Forms\Components\RadioCard;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class VehicleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin xe')
                    ->schema([
                        RadioCard::make('type')
                            ->label('Phân loại')
                            ->default('company')
                            ->required()
                            ->options([
                                'company' => 'Xe công ty',
                                'rent' => 'Xe thuê ngoài',
                            ])
                            ->descriptions([
                                'company' => 'Xe thuộc sở hữu và quản lý bởi công ty.',
                                'rent' => 'Xe được thuê từ bên ngoài.',
                            ])
                            ->color('primary')
                            ->columns(2)
                            ->columnSpanFull(),

                        TextInput::make('plate_number')
                            ->label('Biển số xe')
                            ->prefixIcon(Heroicon::OutlinedTruck)
                            ->required()
                            ->maxLength(20)
                            ->unique(ignoreRecord: true),

                        Select::make('vehicle_type')
                            ->label('Loại xe')
                            ->prefixIcon(Heroicon::OutlinedSquare3Stack3d)
                            ->required()
                            ->default('normal')
                            ->native(false)
                            ->options([
                                'normal' => 'Xe tải thường',
                                'cold' => 'Xe tải lạnh',
                                'anti_vibration' => 'Xe chống rung',
                                'container' => 'Xe container',
                                'flatbed' => 'Xe fooc',
                                'bat_wing' => 'Cánh dơi',
                                'other' => 'Khác',
                            ]),
                        Select::make('make')
                            ->label('Hãng xe')
                            ->prefixIcon(Heroicon::OutlinedBuildingOffice)
                            ->native(false)
                            ->required()
                            ->options([
                                'HYUNDAI' => 'HYUNDAI',
                                'ISUZU' => 'ISUZU',
                                'HINO' => 'HINO',
                                'KIA' => 'KIA',
                                'THACO' => 'THACO',
                                'FUSO' => 'FUSO',
                                'DONGFENG' => 'DONGFENG',
                                'FORD' => 'FORD',
                                'TOYOTA' => 'TOYOTA',
                                'MITSUBISHI' => 'MITSUBISHI',
                                'OTHER' => 'KHÁC',
                            ]),
                        TextInput::make('model_year')
                            ->label('Năm sản xuất')
                            ->numeric()
                            ->minValue(1900)
                            ->maxValue(date('Y') + 1)
                            ->step(1),
                        TextInput::make('load_capacity')
                            ->label('Tải trọng (tấn)')
                            ->numeric()
                            ->inputMode('decimal')
                            ->minValue(0)
                            ->step(0.1)
                            ->suffix(' tấn'),
                        Select::make('fuel_type')
                            ->label('Loại nhiên liệu')
                            ->native(false)
                            ->options([
                                'Diesel' => 'Diesel',
                                'Gasoline' => 'Xăng',
                                'Electric' => 'Điện',
                                'Hybrid' => 'Hybrid',
                                'Other' => 'Khác',
                            ]),
                        TextInput::make('current_mileage')
                            ->label('Số km hiện tại')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.1)
                            ->suffix(' km'),
                        TextInput::make('owner')
                            ->label('Chủ xe')
                            ->required()
                            ->maxLength(255),
                        Select::make('current_driver_id')
                            ->label('Lái xe hiện tại')
                            ->native(false)
                            ->relationship('driver', 'name')
                            ->searchable()
                            ->preload(),

                        Select::make('status')
                            ->label('Trạng thái')
                            ->native(false)
                            ->options([
                                'on' => 'Sẵn sàng',
                                'off' => 'Tắt',
                                'bdsc' => 'Bảo dưỡng sửa chữa',
                                'running' => 'Đang chạy',
                            ])
                            ->default('on'),

                        Toggle::make('is_active')
                            ->label('Trạng thái hoạt động')
                            ->default(true)
                            ->inline(true),
                        Textarea::make('notes')
                            ->label('Ghi chú')
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ])->columnSpanFull()->columns(2),
            ]);
    }
}
