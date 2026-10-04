<?php

namespace App\Filament\Resources\Trips\Schemas;

use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripPhoto;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class TripInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('TripTabs')
                    ->tabs([
                        Tab::make('Thông tin')
                            ->icon(Heroicon::OutlinedClipboardDocumentList)
                            ->schema([
                                Section::make('Thông tin chuyến đi')
                                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                                    ->schema([
                                        TextEntry::make('vehicle.plate_number')
                                            ->label('BSX')
                                            ->weight('bold')
                                            ->size('lg')
                                            ->copyable(),

                                        TextEntry::make('status')
                                            ->label('Trạng thái')
                                            ->badge()
                                            ->color(fn (Trip $record): string => $record->getStatusColor())
                                            ->state(fn (Trip $record): string => $record->getStatusLabel()),

                                        TextEntry::make('driver.name')
                                            ->label('Lái xe')
                                            ->icon(Heroicon::OutlinedUser)
                                            ->weight('bold')
                                            ->placeholder('—'),

                                        TextEntry::make('started_at')
                                            ->label('Bắt đầu')
                                            ->dateTime('H:i d/m/Y')
                                            ->icon(Heroicon::OutlinedClock)
                                            ->placeholder('—'),

                                        TextEntry::make('completed_at')
                                            ->label('Kết thúc')
                                            ->dateTime('H:i d/m/Y')
                                            ->icon(Heroicon::OutlinedClock)
                                            ->placeholder('Đang chạy...'),

                                        TextEntry::make('total_km')
                                            ->label('Tổng quãng đường')
                                            ->state(fn (Trip $record): string => $record->total_km !== null ? number_format((float) $record->total_km, 1, ',', '.').' km' : '—'),

                                        TextEntry::make('total_km_loaded')
                                            ->label('Km có hàng')
                                            ->state(fn (Trip $record): string => $record->total_km_loaded !== null ? number_format((float) $record->total_km_loaded, 1, ',', '.').' km' : '—'),

                                        TextEntry::make('total_km_empty')
                                            ->label('Km không hàng')
                                            ->state(fn (Trip $record): string => $record->total_km_empty !== null ? number_format((float) $record->total_km_empty, 1, ',', '.').' km' : '—'),
                                    ])
                                    ->columns(4),

                                Section::make('Danh sách đơn hàng')
                                    ->icon(Heroicon::OutlinedDocumentText)
                                    ->schema([
                                        RepeatableEntry::make('orders')
                                            ->label('Các đơn hàng trong chuyến này')
                                            ->schema([
                                                TextEntry::make('order_code')
                                                    ->label('Mã đơn')
                                                    ->weight('bold')
                                                    ->copyable(),
                                                TextEntry::make('customer.name')
                                                    ->label('Khách hàng'),
                                                TextEntry::make('status')
                                                    ->label('Trạng thái')
                                                    ->badge()
                                                    ->color(fn ($state) => $state?->getColor() ?? 'gray')
                                                    ->formatStateUsing(fn ($state) => $state?->getLabel() ?? $state),
                                                TextEntry::make('total_packages')
                                                    ->label('Số kiện')
                                                    ->numeric()
                                                    ->suffix(' kiện'),
                                                TextEntry::make('total_weight')
                                                    ->label('Trọng lượng')
                                                    ->numeric()
                                                    ->suffix(' kg'),
                                            ])
                                            ->columns(5)
                                            ->grid([
                                                'default' => 1,
                                            ])
                                            ->placeholder('Không có đơn hàng nào trong chuyến'),
                                    ]),
                            ]),

                        Tab::make('Lượt lái')
                            ->icon(Heroicon::OutlinedUserGroup)
                            ->schema([
                                RepeatableEntry::make('driverAssignments')
                                    ->label('Tài xế đã giữ chuyến')
                                    ->schema([
                                        TextEntry::make('driver.name')->label('Tài xế'),
                                        TextEntry::make('shift.shift_type')->label('Ca')->placeholder('—'),
                                        TextEntry::make('started_at')->label('Bắt đầu')->dateTime('H:i d/m/Y'),
                                        TextEntry::make('ended_at')->label('Kết thúc')->dateTime('H:i d/m/Y')->placeholder('Đang giữ chuyến'),
                                        TextEntry::make('end_reason')->label('Lý do')->badge()->placeholder('—'),
                                        TextEntry::make('note')->label('Ghi chú')->placeholder('—'),
                                        TextEntry::make('createdBy.name')->label('Người gán')->placeholder('—'),
                                    ])
                                    ->columns(7)
                                    ->placeholder('Chưa có lượt lái nào'),
                            ]),

                        Tab::make('Ảnh chuyến đi')
                            ->icon(Heroicon::OutlinedPhoto)
                            ->schema([
                                RepeatableEntry::make('tripPhotos')
                                    ->label('Ảnh chụp từ các mốc hành trình')
                                    ->state(fn (Trip $record) => $record->checkpoints()
                                        ->with('photos')
                                        ->get()
                                        ->flatMap(fn (TripCheckpoint $cp) => $cp->photos)
                                        ->sortByDesc('created_at'))
                                    ->schema([
                                        ImageEntry::make('photo')
                                            ->label('Ảnh')
                                            ->state(fn (TripPhoto $record): ?string => $record->photo_url ?: $record->photo_path)
                                            ->disk('public')
                                            ->visibility('public')
                                            ->imageHeight(160)
                                            ->checkFileExists(false)
                                            ->extraImgAttributes([
                                                'class' => 'rounded-lg object-cover',
                                                'loading' => 'lazy',
                                            ])
                                            ->columnSpanFull(),

                                        TextEntry::make('tripCheckpoint.checkpoint_type')
                                            ->label('Mốc hành trình')
                                            ->badge()
                                            ->formatStateUsing(fn ($state) => $state?->getLabel() ?? '—')
                                            ->color(fn ($state) => $state?->getColor() ?? 'gray'),

                                        TextEntry::make('tripCheckpoint.occurred_at')
                                            ->label('Thời gian')
                                            ->dateTime('H:i d/m/Y')
                                            ->placeholder('—'),

                                        TextEntry::make('created_at')
                                            ->label('Tải lên')
                                            ->dateTime('H:i d/m/Y')
                                            ->placeholder('—'),
                                    ])
                                    ->columns(3)
                                    ->grid([
                                        'default' => 1,
                                        'md' => 2,
                                        'xl' => 3,
                                    ])
                                    ->placeholder('Chưa có ảnh chuyến đi')
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columnSpanFull()
                    ->persistTab()
                    ->id('trip-infolist-tabs'),
            ]);
    }
}
