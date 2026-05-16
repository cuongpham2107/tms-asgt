<?php

namespace App\Filament\Resources\Vehicles\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VehicleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin xe')
                    ->schema([
                        TextEntry::make('type')
                            ->label('Phân loại')
                            ->formatStateUsing(fn ($state) => match ($state) {
                                'company' => 'Xe công ty',
                                'rent' => 'Xe thuê ngoài',
                                default => $state,
                            })
                            ->badge()
                            ->color('secondary'),

                        TextEntry::make('plate_number')
                            ->label('Biển số xe')
                            ->weight('bold')
                            ->size('lg')
                            ->badge()
                            ->color('primary')
                            ->icon('heroicon-o-truck'),

                        TextEntry::make('vehicle_type')
                            ->label('Loại xe')
                            ->formatStateUsing(fn ($state) => match ($state) {
                                'normal' => 'Xe tải thường',
                                'cold' => 'Xe tải lạnh',
                                'anti_vibration' => 'Xe chống rung',
                                'container' => 'Xe container',
                                'flatbed' => 'Xe fooc',
                                'bat_wing' => 'Cánh dơi',
                                'other' => 'Khác',
                                default => 'Khác',
                            })
                            ->badge()
                            ->color('info'),

                        TextEntry::make('make')
                            ->label('Hãng xe')
                            ->formatStateUsing(fn ($state) => match ($state) {
                                'hyundai' => 'Hyundai',
                                'isuzu' => 'Isuzu',
                                'kia' => 'Kia',
                                'thaco' => 'Thaco',
                                'fuso' => 'Fuso',
                                'dongfeng' => 'Dongfeng',
                                'ford' => 'Ford',
                                'toyota' => 'Toyota',
                                'mitsubishi' => 'Mitsubishi',
                                'other' => 'Khác',
                                default => $state,
                            })
                            ->placeholder('—'),

                        TextEntry::make('model_year')
                            ->label('Năm sản xuất')
                            ->placeholder('—'),

                        TextEntry::make('load_capacity')
                            ->label('Tải trọng')
                            ->suffix(' tấn')
                            ->placeholder('—'),

                        TextEntry::make('fuel_type')
                            ->label('Loại nhiên liệu')
                            ->formatStateUsing(fn ($state) => match ($state) {
                                'diesel' => 'Diesel',
                                'gasoline' => 'Xăng',
                                'electric' => 'Điện',
                                'hybrid' => 'Hybrid',
                                'other' => 'Khác',
                                default => $state,
                            })
                            ->placeholder('—'),

                        TextEntry::make('current_mileage')
                            ->label('Số km hiện tại')
                            ->suffix(' km')
                            ->placeholder('—'),

                        TextEntry::make('owner')
                            ->label('Chủ xe')
                            ->placeholder('—'),

                        TextEntry::make('driver.name')
                            ->label('Lái xe hiện tại')
                            ->icon('heroicon-m-user-circle')
                            ->placeholder('—'),

                        TextEntry::make('status')
                            ->label('Trạng thái')
                            ->formatStateUsing(fn ($state) => match ($state) {
                                'on' => 'Sẵn sàng',
                                'off' => 'Tắt',
                                'bdsc' => 'Bảo dưỡng sửa chữa',
                                'running' => 'Đang chạy',
                                default => $state,
                            })
                            ->badge()
                            ->color(fn ($state) => match ($state) {
                                'on' => 'success',
                                'off' => 'danger',
                                'bdsc' => 'warning',
                                'running' => 'info',
                                default => 'secondary',
                            }),

                        IconEntry::make('is_active')
                            ->label('Trạng thái hoạt động')
                            ->boolean()
                            ->trueIcon('heroicon-m-check-circle')
                            ->falseIcon('heroicon-m-x-circle')
                            ->trueColor('success')
                            ->falseColor('danger'),

                        TextEntry::make('notes')
                            ->label('Ghi chú')
                            ->columnSpanFull()
                            ->placeholder('—'),

                        TextEntry::make('created_at')
                            ->label('Ngày tạo')
                            ->dateTime('d/m/Y H:i')
                            ->icon('heroicon-m-calendar'),

                        TextEntry::make('updated_at')
                            ->label('Cập nhật')
                            ->dateTime('d/m/Y H:i')
                            ->icon('heroicon-m-calendar'),
                    ])
                    ->columnSpanFull()
                    ->columns(2),
            ]);
    }
}
