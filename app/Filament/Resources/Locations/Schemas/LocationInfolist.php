<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Enums\LocationType;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class LocationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin địa điểm')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('code')
                            ->label('Mã địa điểm')
                            ->beforeLabel(Icon::make(Heroicon::InformationCircle)),
                        TextEntry::make('name')
                            ->label('Tên địa điểm')
                            ->beforeLabel(Icon::make(Heroicon::BookOpen)),
                        TextEntry::make('loc_type')
                            ->label('Loại điểm')
                            ->beforeLabel(Icon::make(Heroicon::InformationCircle))
                            ->badge()
                            ->color(fn ($state) => $state instanceof LocationType ? $state->getColor() : match ($state) {
                                'pickup' => 'blue',
                                'delivery' => 'green',
                                'warehouse' => 'purple',
                                'other' => 'gray',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn ($state) => $state instanceof LocationType ? $state->getLabel() : $state),
                        TextEntry::make('address')
                            ->label('Địa chỉ')
                            ->beforeLabel(Icon::make(Heroicon::BookOpen))
                            ->columnSpanFull(),
                        TextEntry::make('is_active')
                            ->label('Trạng thái')
                            ->beforeLabel(Icon::make(Heroicon::CheckBadge))
                            ->badge()
                            ->color(fn ($state) => $state ? 'success' : 'danger')
                            ->formatStateUsing(fn ($state) => $state ? 'Hoạt động' : 'Không hoạt động'),
                        TextEntry::make('created_at')
                            ->label('Tạo lúc')
                            ->beforeLabel(Icon::make(Heroicon::Clock))
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->label('Cập nhật')
                            ->beforeLabel(Icon::make(Heroicon::Clock))
                            ->dateTime(),
                    ])
                    ->columns(2),
            ]);
    }
}
