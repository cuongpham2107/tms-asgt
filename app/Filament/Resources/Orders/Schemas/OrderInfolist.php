<?php

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Fieldset::make('Thông tin đơn hàng')
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 3,
                    ])
                    ->schema([
                        TextEntry::make('order_code')
                            ->label('Mã đơn')
                            ->weight('bold')
                            ->size('lg')
                            ->copyable()
                            ->icon('heroicon-o-hashtag'),
                        TextEntry::make('orderType.name')
                            ->label('Loại đơn')
                            ->badge()
                            ->color('primary')
                            ->icon('heroicon-o-bookmark'),
                        TextEntry::make('orderCategory.code')
                            ->label('Phân tách khu vực')
                            ->badge()
                            ->icon('heroicon-o-tag'),
                        TextEntry::make('priority')
                            ->label('Mức ưu tiên')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state?->getLabel() ?? '—')
                            ->color(fn ($state) => $state?->getColor() ?? null)
                            ->icon(fn ($state) => (is_object($state) && method_exists($state, 'getIcon')) ? $state->getIcon() : 'heroicon-o-flag'),
                        TextEntry::make('status')
                            ->label('Trạng thái')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state?->getLabel() ?? '—')
                            ->color(fn ($state) => $state?->getColor() ?? null)
                            ->icon(fn ($state) => (is_object($state) && method_exists($state, 'getIcon')) ? $state->getIcon() : 'heroicon-o-ellipsis-horizontal-circle'),
                        TextEntry::make('customer.name')
                            ->label('Khách hàng')
                            ->weight('bold')
                            ->icon('heroicon-o-user'),
                        TextEntry::make('cargo_name')
                            ->label('Tên hàng')
                            ->placeholder('—')
                            ->icon('heroicon-o-cube'),
                        TextEntry::make('cargo_type')
                            ->label('Loại hàng')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state?->getLabel() ?? '—')
                            ->color(fn ($state) => $state?->getColor() ?? null)
                            ->icon(fn ($state) => (is_object($state) && method_exists($state, 'getIcon')) ? $state->getIcon() : 'heroicon-o-cube')
                            ->placeholder('—'),
                        TextEntry::make('total_packages')
                            ->label('Số kiện')
                            ->numeric()
                            ->placeholder('—')
                            ->icon('heroicon-o-squares-2x2'),
                        TextEntry::make('total_weight')
                            ->label('Trọng lượng')
                            ->numeric()
                            ->suffix(' kg')
                            ->placeholder('—')
                            ->icon('heroicon-o-scale'),
                        TextEntry::make('planned_loading_at')
                            ->label('Thời gian dự kiến đóng hàng')
                            ->dateTime('H:i d/m/Y')
                            ->placeholder('—')
                            ->icon('heroicon-o-calendar'),
                        TextEntry::make('notes')
                            ->label('Ghi chú')
                            ->columnSpanFull()
                            ->placeholder('—')
                            ->icon('heroicon-o-document-text'),
                    ])
                    ->columnSpanFull(),

                Fieldset::make('Hành trình')
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 3,
                    ])
                    ->schema([
                        TextEntry::make('pickupLocation.name')
                            ->label('Điểm đi')
                            ->placeholder('—')
                            ->icon('heroicon-o-map-pin'),
                        TextEntry::make('pickup_address')
                            ->label('Địa chỉ nhận hàng')
                            ->placeholder('—')
                            ->icon('heroicon-o-map'),
                        TextEntry::make('pickup_contact')
                            ->label('Người liên hệ nhận hàng')
                            ->placeholder('—')
                            ->icon('heroicon-o-user-group'),
                        TextEntry::make('pickup_phone')
                            ->label('Số điện thoại nhận hàng')
                            ->copyable()
                            ->placeholder('—')
                            ->icon('heroicon-o-phone'),
                        RepeatableEntry::make('deliveryPoints')
                            ->label('Điểm đến')
                            ->table([
                                TableColumn::make('Địa điểm'),
                                TableColumn::make('Địa chỉ'),
                                TableColumn::make('Số kiện')
                                    ->alignCenter(),
                                TableColumn::make('Trọng lượng'),
                                TableColumn::make('Người liên hệ'),
                                TableColumn::make('Số điện thoại'),
                                TableColumn::make('Trạng thái'),
                            ])
                            ->schema([
                                TextEntry::make('location.name')
                                    ->placeholder('—'),
                                TextEntry::make('address')
                                    ->placeholder('—'),
                                TextEntry::make('total_packages')
                                    ->numeric()
                                    ->placeholder('—'),
                                TextEntry::make('total_weight')
                                    ->numeric()
                                    ->placeholder('—'),
                                TextEntry::make('contact_person')
                                    ->placeholder('—'),
                                TextEntry::make('contact_phone')
                                    ->copyable()
                                    ->placeholder('—'),
                                TextEntry::make('status')
                                    ->badge(),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Fieldset::make('Phân xe và lái xe')
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 3,
                    ])
                    ->schema([
                        TextEntry::make('vehicle.plate_number')
                            ->label('Phương tiện')
                            ->badge()
                            ->placeholder('—')
                            ->icon('heroicon-o-truck'),
                        TextEntry::make('driver.name')
                            ->label('Lái xe')
                            ->placeholder('—')
                            ->icon('heroicon-o-user'),
                        TextEntry::make('createdBy.name')
                            ->label('Người tạo')
                            ->placeholder('—')
                            ->icon('heroicon-o-user-plus'),
                        TextEntry::make('created_at')
                            ->label('Ngày tạo')
                            ->dateTime('H:i d/m/Y')
                            ->icon('heroicon-o-calendar'),
                        TextEntry::make('sent_at')
                            ->label('Ngày gửi')
                            ->dateTime('H:i d/m/Y')
                            ->placeholder('—')
                            ->icon('heroicon-o-paper-airplane'),
                        TextEntry::make('cancelled_at')
                            ->label('Ngày hủy')
                            ->dateTime('H:i d/m/Y')
                            ->placeholder('—')
                            ->icon('heroicon-o-x-circle'),
                        TextEntry::make('cancel_reason')
                            ->label('Lý do hủy')
                            ->columnSpanFull()
                            ->placeholder('—')
                            ->icon('heroicon-o-no-symbol'),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
