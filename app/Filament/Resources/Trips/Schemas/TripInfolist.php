<?php

namespace App\Filament\Resources\Trips\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class TripInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin chuyến đi')
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->columnSpanFull()
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

                        TextEntry::make('orderType.name')
                            ->label('Loại đơn')
                            ->badge()
                            ->color('info'),

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
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('notes')
                            ->label('Nội dung')
                            ->columnSpanFull()
                            ->placeholder('Không có ghi chú'),
                    ]),
            ]);
    }
}
