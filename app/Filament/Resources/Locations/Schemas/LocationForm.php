<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Enums\LocationType;
use EduardoRibeiroDev\FilamentLeaflet\Enums\TileLayer;
use EduardoRibeiroDev\FilamentLeaflet\Fields\MapPicker;
use EduardoRibeiroDev\FilamentLeaflet\Services\GeoSearchService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Mã')
                    ->prefixIcon(Heroicon::OutlinedHashtag)
                    ->placeholder('VD: HAN-01')
                    ->required(),
                TextInput::make('name')
                    ->label('Tên địa điểm')
                    ->prefixIcon(Heroicon::OutlinedMapPin)
                    ->placeholder('VD: Kho Hà Nội')
                    ->required(),
                Textarea::make('address')
                    ->label('Địa chỉ')
                    ->placeholder('Nhập địa chỉ đầy đủ')
                    ->columnSpanFull()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, Set $set): void {
                        if (blank($state)) {
                            return;
                        }

                        $results = app(GeoSearchService::class)->search($state);

                        if ($results !== []) {
                            $result = array_values($results)[0];
                            $set('coordinates', $result->coordinate->toArray());
                        }
                    }),
                Select::make('loc_type')
                    ->label('Loại địa điểm')
                    ->prefixIcon(Heroicon::OutlinedTag)
                    ->options(LocationType::class)
                    ->default(LocationType::Warehouse->value)
                    ->native(false)
                    ->required(),
                MapPicker::make('coordinates')
                    ->label('Chọn vị trí trên bản đồ')
                    ->height(350)
                    ->center(21.0285, 105.8542)
                    ->zoom(13)
                    ->tileLayersUrl(TileLayer::OpenStreetMap)
                    ->geoSearchControl()
                    ->geoSearchProvider('nominatim')
                    ->fullscreenControl()
                    ->columnSpanFull()
                    ->afterStateUpdatedJs('
                        const coords = $state;
                        if (coords?.lat) {
                            const mapEl = $el.querySelector(".leaflet-container");  
                            if (mapEl) {
                                const el = mapEl.closest("[x-data]");
                                const data = el ? Alpine.$data(el) : null;
                                data?.mapCore?.map?.setView([coords.lat, coords.lng], 15);
                            }
                        }
                    '),
                Toggle::make('is_active')
                    ->label('Đang hoạt động')
                    ->required(),
            ]);
    }
}
