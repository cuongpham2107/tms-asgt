<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Enums\LocationType;
use App\Enums\MapTile;
use App\Models\Area;
use EduardoRibeiroDev\FilamentLeaflet\Fields\MapPicker;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;
use EduardoRibeiroDev\FilamentLeaflet\Services\GeoSearchService;
use EduardoRibeiroDev\FilamentLeaflet\ValueObjects\Coordinate;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Http;

class LocationForm
{
    public static function configure(Schema $schema, mixed $defaultAreaId = null): Schema
    {
        return $schema
            ->components([
                Select::make('areas')
                    ->label('Khu vực áp dụng')
                    ->relationship('areas', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Area $record): string => "{$record->code} - {$record->name}")
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->native(false)
                    ->required()
                    ->default(function () use ($defaultAreaId): array {
                        $val = value($defaultAreaId);

                        if (blank($val)) {
                            return [];
                        }

                        if (is_array($val)) {
                            return array_values(array_filter(array_map(function ($item) {
                                return is_numeric($item) ? (int) $item : Area::query()->where('code', $item)->value('id');
                            }, $val)));
                        }

                        if (is_numeric($val)) {
                            return [(int) $val];
                        }

                        $id = Area::query()->where('code', $val)->value('id');

                        return $id ? [$id] : [];
                    })
                    ->helperText('Chọn các khu vực được phép sử dụng địa điểm này (ví dụ: kho ALSC thuộc cả NBA và ASG).'),
                TextInput::make('code')
                    ->label('Mã')
                    ->prefixIcon(Heroicon::OutlinedHashtag)
                    ->placeholder('VD: HAN-01')
                    ->required(),
                Select::make('billing_zone_id')
                    ->label('Khu vực tính cước')
                    ->prefixIcon(Heroicon::OutlinedSquares2x2)
                    ->relationship('billingZone', 'name')
                    ->searchable(['code', 'name'])
                    ->preload()
                    ->helperText('Các địa điểm cùng khu vực dùng chung đơn giá vận chuyển.'),
                TextInput::make('name')
                    ->label('Tên địa điểm')
                    ->prefixIcon(Heroicon::OutlinedMapPin)
                    ->placeholder('VD: Kho Hà Nội')
                    ->required(),
                Select::make('loc_type')
                    ->label('Loại địa điểm')
                    ->prefixIcon(Heroicon::OutlinedTag)
                    ->options(LocationType::class)
                    ->default(LocationType::Warehouse->value)
                    ->native(false)
                    ->required(),
                TextInput::make('geofence_radius_m')
                    ->label('Bán kính kiểm tra GPS (mét)')
                    ->numeric()
                    ->minValue(100)
                    ->maxValue(5000)
                    ->suffix('m')
                    ->placeholder(fn (): string => 'Mặc định: '.config('checkpoint_geofence.radius_m', 3000).'m')
                    ->helperText(fn (): string => 'Để trống sẽ dùng bán kính mặc định ('.config('checkpoint_geofence.radius_m', 3000).'m). Các kho/cảng cực rộng có thể đặt 3500–5000m.'),
                TextInput::make('old_address')
                    ->label('Địa chỉ cũ (trước sáp nhập)')
                    ->maxLength(255)
                    ->columnSpanFull(),
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

                MapPicker::make('coordinates')
                    ->label('Chọn vị trí trên bản đồ')
                    ->height(350)
                    ->center(21.0285, 105.8542)
                    ->zoom(13)
                    ->tileLayersUrl(MapTile::options())
                    ->geoSearchControl()
                    ->geoSearchProvider('nominatim')
                    ->fullscreenControl()
                    ->columnSpanFull()
                    ->pickMarker(fn (Marker $marker) => $marker->draggable())
                    ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                        if (! $state instanceof Coordinate) {
                            return;
                        }

                        $currentAddress = $get('address');

                        try {
                            $response = Http::withHeaders([
                                'User-Agent' => 'TMS-ASGT/1.0',
                                'Accept' => 'application/json',
                            ])->timeout(5)->get('https://nominatim.openstreetmap.org/reverse', [
                                'lat' => $state->lat,
                                'lon' => $state->lng,
                                'format' => 'json',
                                'addressdetails' => 0,
                                'accept-language' => 'vi',
                            ]);

                            if ($response->successful()) {
                                $data = $response->json();
                                if (! empty($data) && isset($data['display_name']) && $data['display_name'] !== $currentAddress) {
                                    $set('address', $data['display_name']);
                                }
                            }
                        } catch (\Throwable) {
                        }
                    })
                    ->extraAttributes([
                        'x-init' => '
                            $nextTick(() => {
                                const mapEl = (typeof this !== "undefined" && this.$el) ? this.$el.querySelector(".leaflet-container") : document.querySelector(".leaflet-container");
                                if (mapEl) {
                                    const el = mapEl.closest("[x-data]");
                                    const data = el ? Alpine.$data(el) : null;
                                    if (data) {
                                        const originalUpdatePickMarker = data.updatePickMarker.bind(data);
                                        data.updatePickMarker = function() {
                                            originalUpdatePickMarker();
                                            if (data.pickMarker) {
                                                data.pickMarker.off("dragend");
                                                data.pickMarker.on("dragend", (e) => {
                                                    const newPos = e.target.getLatLng();
                                                    data.setState(newPos.lat, newPos.lng);
                                                });
                                            }
                                        };
                                        if (data.pickMarker) {
                                            data.pickMarker.off("dragend");
                                            data.pickMarker.on("dragend", (e) => {
                                                const newPos = e.target.getLatLng();
                                                data.setState(newPos.lat, newPos.lng);
                                            });
                                        }
                                    }
                                }
                            });
                        ',
                    ])
                    ->afterStateUpdatedJs('
                        const coords = $state;
                        if (coords?.lat) {
                            const mapEl = (typeof this !== "undefined" && this.$el) ? this.$el.querySelector(".leaflet-container") : document.querySelector(".leaflet-container");
                            if (mapEl) {
                                const el = mapEl.closest("[x-data]");
                                const data = el ? Alpine.$data(el) : null;
                                data?.mapCore?.map?.setView([coords.lat, coords.lng], 15);
                            }
                        }
                    '),
                Toggle::make('is_active')
                    ->label('Đang hoạt động')
                    ->default(true)
                    ->required(),
            ]);
    }
}
