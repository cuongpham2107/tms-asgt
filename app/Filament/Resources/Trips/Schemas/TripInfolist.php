<?php

namespace App\Filament\Resources\Trips\Schemas;

use App\Models\Order;
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
                                        TextEntry::make('order_code')
                                            ->label('Mã chuyến')
                                            ->weight('bold')
                                            ->size('lg')
                                            ->copyable()
                                            ->columnSpan(2),

                                        TextEntry::make('status')
                                            ->label('Trạng thái')
                                            ->badge()
                                            ->color(fn ($state) => $state?->getColor() ?? 'gray')
                                            ->formatStateUsing(fn ($state) => $state?->getLabel() ?? $state),

                                        TextEntry::make('customer.name')
                                            ->label('Khách hàng')
                                            ->icon(Heroicon::OutlinedBuildingOffice)
                                            ->weight('bold'),

                                        TextEntry::make('type')
                                            ->label('Loại đơn')
                                            ->badge()
                                            ->formatStateUsing(fn ($state) => $state?->getLabel() ?? '—')
                                            ->color(fn ($state) => $state?->getColor() ?? 'info'),

                                        TextEntry::make('orderCategory.name')
                                            ->label('Tuyến')
                                            ->badge()
                                            ->color('warning'),

                                        TextEntry::make('cargo_name')
                                            ->label('Tên hàng')
                                            ->columnSpan(2),

                                        TextEntry::make('cargo_type')
                                            ->label('Loại hàng')
                                            ->badge(),

                                        TextEntry::make('total_packages')
                                            ->label('Số kiện')
                                            ->numeric()
                                            ->suffix(' kiện'),

                                        TextEntry::make('total_weight')
                                            ->label('Tổng KL')
                                            ->numeric()
                                            ->suffix(' kg'),

                                        TextEntry::make('created_at')
                                            ->label('Tạo lúc')
                                            ->dateTime('H:i d/m/Y')
                                            ->icon(Heroicon::OutlinedClock),

                                        TextEntry::make('planned_loading_at')
                                            ->label('KH bốc hàng')
                                            ->dateTime('H:i d/m/Y')
                                            ->icon(Heroicon::OutlinedCalendarDays),

                                        TextEntry::make('sent_at')
                                            ->label('Gửi lệnh')
                                            ->dateTime('H:i d/m/Y')
                                            ->icon(Heroicon::OutlinedPaperAirplane),
                                    ])
                                    ->columns(3),

                                Section::make('Ghi chú')
                                    ->icon(Heroicon::OutlinedPencilSquare)
                                    ->schema([
                                        TextEntry::make('notes')
                                            ->label('Nội dung')
                                            ->columnSpanFull()
                                            ->placeholder('Không có ghi chú'),
                                    ]),
                            ]),

                        Tab::make('Ảnh chuyến đi')
                            ->icon(Heroicon::OutlinedPhoto)
                            ->schema([
                                RepeatableEntry::make('tripPhotos')
                                    ->label('Ảnh chuyến đi')
                                    ->state(fn (Order $record) => $record->tripPhotos()
                                        ->with('tripCheckpoint')
                                        ->latest('trip_photos.created_at')
                                        ->get())
                                    ->schema([
                                        ImageEntry::make('photo')
                                            ->label('Ảnh')
                                            ->state(fn (TripPhoto $record): ?string => $record->photo_url ?: $record->photo_path)
                                            ->disk('public')
                                            ->visibility('public')
                                            ->imageHeight(160)
                                            ->checkFileExistence(false)
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
