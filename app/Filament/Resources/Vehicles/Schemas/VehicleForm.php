<?php

namespace App\Filament\Resources\Vehicles\Schemas;

use App\Enums\DriverWorkShift;
use App\Models\Vehicle;
use CodeWithDennis\FilamentAdvancedChoice\Filament\Forms\Components\RadioCard;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;

class VehicleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin xe')
                    ->columns(['default' => 1, 'md' => 2, 'lg' => 3])
                    ->columnSpanFull()
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
                            ->maxLength(8)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, string $state): mixed => $set('plate_number', preg_replace('/[\s\-\.]+/', '', $state)))
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

                        TextInput::make('load_capacity')
                            ->label('Tải trọng (tấn)')
                            ->prefixIcon('heroicon-o-scale')
                            ->mask(RawJs::make('$money($input)'))
                            ->stripCharacters(',')
                            ->numeric()
                            ->inputMode('decimal')
                            ->minValue(0)
                            ->step(0.1)
                            ->dataList(['1.25', '1.5', '2.5', '3.5', '5', '7', '8', '10', '14'])
                            ->suffix(' tấn'),

                        TextInput::make('current_mileage')
                            ->label('Số km hiện tại')
                            ->prefixIcon('heroicon-o-variable')
                            ->mask(RawJs::make('$money($input)'))
                            ->stripCharacters(',')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.1)
                            ->suffix(' km'),

                        TextInput::make('model_year')
                            ->label('Năm sản xuất')
                            ->prefixIcon('heroicon-o-calendar')
                            ->mask(RawJs::make('$money($input)'))
                            ->stripCharacters(',')
                            ->numeric()
                            ->minValue(1900)
                            ->maxValue(date('Y') + 1)
                            ->step(1),

                        Select::make('even_driver_id')
                            ->label('Lái xe ca chẵn')
                            ->prefixIcon('heroicon-o-user')
                            ->native(false)
                            ->relationship('evenDriver', 'name', fn ($query) => $query->role('driver')->where(fn ($q) => $q->where('work_shift', DriverWorkShift::Even)->orWhereNull('work_shift')))
                            ->searchable()
                            ->preload()
                            ->placeholder('Chọn lái xe ca chẵn'),

                        Select::make('odd_driver_id')
                            ->label('Lái xe ca lẻ')
                            ->prefixIcon('heroicon-o-user')
                            ->native(false)
                            ->relationship('oddDriver', 'name', fn ($query) => $query->role('driver')->where(fn ($q) => $q->where('work_shift', DriverWorkShift::Odd)->orWhereNull('work_shift')))
                            ->searchable()
                            ->preload()
                            ->placeholder('Chọn lái xe ca lẻ'),

                        Select::make('current_driver_id')
                            ->label('Lái xe hiện tại (Tùy chọn)')
                            ->prefixIcon('heroicon-o-user-circle')
                            ->native(false)
                            ->relationship('driver', 'name', fn ($query) => $query->role('driver'))
                            ->searchable()
                            ->preload()
                            ->helperText('Tự động theo ca hôm nay nếu để trống'),

                        TextInput::make('owner')
                            ->label('Chủ xe')
                            ->prefixIcon('heroicon-o-identification')
                            ->datalist(fn (): array => Vehicle::distinct()
                                ->whereNotNull('owner')
                                ->where('owner', '!=', '')
                                ->pluck('owner')
                                ->toArray())
                            ->required()
                            ->maxLength(255),

                        Select::make('status')
                            ->label('Trạng thái')
                            ->prefixIcon('heroicon-o-check-circle')
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
                            ->helperText('Cho phép xe hoạt động')
                            ->default(true)
                            ->inline(false),

                        Textarea::make('notes')
                            ->label('Ghi chú')
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ]),

                Section::make('Giấy phép Vận chuyển Hàng nguy hiểm')
                    ->icon('heroicon-o-shield-exclamation')
                    ->columns(['default' => 1, 'md' => 3])
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        TextInput::make('dangerous_goods_permit_number')
                            ->label('Số giấy phép Hàng nguy hiểm')
                            ->prefixIcon('heroicon-o-document-text'),
                        DatePicker::make('dangerous_goods_permit_issue_date')
                            ->label('Ngày cấp giấy phép')
                            ->prefixIcon('heroicon-o-calendar'),
                        DatePicker::make('dangerous_goods_permit_expiry_date')
                            ->label('Ngày hết hạn giấy phép')
                            ->prefixIcon('heroicon-o-calendar')
                            ->helperText(fn (?Vehicle $record): ?string => $record?->getDangerousGoodsPermitStatus()['label']),
                        FileUpload::make('dangerous_goods_permit_image')
                            ->label('Ảnh / File giấy phép')
                            ->image()
                            ->imageEditor()
                            ->directory('permits/dangerous_goods')
                            ->openable()
                            ->downloadable()
                            ->disk('public')
                            ->previewable()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
