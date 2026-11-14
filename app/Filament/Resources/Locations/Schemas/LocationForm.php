<?php

namespace App\Filament\Resources\Locations\Schemas;

use App\Enums\LocationType;
use App\Enums\MapTile;
use App\Models\Area;
use App\Models\Location;
use EduardoRibeiroDev\FilamentLeaflet\Fields\MapPicker;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;
use EduardoRibeiroDev\FilamentLeaflet\Services\GeoSearchService;
use EduardoRibeiroDev\FilamentLeaflet\ValueObjects\Coordinate;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class LocationForm
{
    public static function configure(Schema $schema, mixed $defaultAreaId = null): Schema
    {
        // Embedded createOptionForm modals on non-Location forms (e.g. Order, Customer)
        // pass the parent schema's model unless Location is bound explicitly.
        if ($schema->getModel() !== Location::class) {
            $schema->model(Location::class);
        }

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
                Select::make('geofence_type')
                    ->label('Hình dạng vùng kiểm tra GPS')
                    ->options([
                        'circle' => 'Hình tròn (Theo bán kính)',
                        'rectangle' => 'Hình chữ nhật (Kéo chuột vẽ trên bản đồ)',
                        'square' => 'Hình vuông (Kéo chuột vẽ trên bản đồ)',
                    ])
                    ->default('circle')
                    ->native(false)
                    ->live()
                    ->afterStateUpdatedJs('
                        const mapEl = document.querySelector(".leaflet-container");
                        if (mapEl) {
                            const el = mapEl.closest("[x-data]");
                            const data = el ? Alpine.$data(el) : null;
                            data?.setGeofenceType?.($state, false);
                        }
                    ')
                    ->helperText('Chọn Hình chữ nhật / Hình vuông để kéo chuột vẽ trực tiếp hoặc kéo 4 góc trên bản đồ bên dưới.'),
                TextInput::make('geofence_radius_m')
                    ->label('Bán kính kiểm tra GPS (mét)')
                    ->numeric()
                    ->minValue(100)
                    ->maxValue(5000)
                    ->suffix('m')
                    ->live(onBlur: true)
                    ->extraInputAttributes(['data-geofence-radius-input' => 'true'])
                    ->placeholder(fn (): string => 'Mặc định: '.config('checkpoint_geofence.radius_m', 3000).'m')
                    ->helperText(fn (): string => 'Áp dụng khi chọn Hình tròn (hoặc làm kích thước khởi tạo ban đầu cho Hình vuông/chữ nhật).'),
                Hidden::make('geofence_bounds'),
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
                    ->view('filament.forms.components.location-map-picker')
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
                    ->afterStateUpdatedJs('
                        const coords = $state;
                        if (coords?.lat) {
                            const mapEl = (typeof this !== "undefined" && this.$el) ? this.$el.querySelector(".leaflet-container") : document.querySelector(".leaflet-container");
                            if (mapEl) {
                                const el = mapEl.closest("[x-data]");
                                const data = el ? Alpine.$data(el) : null;
                                data?.mapCore?.map?.setView([coords.lat, coords.lng], 15);
                                data?.updateGeofenceCircle?.(undefined, [coords.lat, coords.lng]);
                            }
                        }
                    '),
                Toggle::make('is_active')
                    ->label('Đang hoạt động')
                    ->default(true)
                    ->required(),
            ]);
    }

    /**
     * Persist a Location created from a Select::createOptionForm() modal and sync its relationships.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createFromOptionForm(
        array $data,
        Schema $schema,
        mixed $defaultAreaId = null,
        ?string $defaultLocType = null,
    ): int {
        $legacyAreas = $data['areas'] ?? [];
        unset($data['areas']);

        $areaId = $data['area_id'] ?? value($defaultAreaId);
        if (is_string($areaId) && ! is_numeric($areaId)) {
            $areaId = Area::query()->where('code', $areaId)->value('id');
        }
        $resolvedAreaId = is_numeric($areaId) ? (int) $areaId : null;

        $normalizedLegacyAreas = array_values(array_filter(array_map(function ($item): ?int {
            if (is_numeric($item)) {
                return (int) $item;
            }

            if (is_string($item) && $item !== '') {
                $id = Area::query()->where('code', $item)->value('id');

                return $id ? (int) $id : null;
            }

            return null;
        }, is_array($legacyAreas) ? $legacyAreas : [])));

        $location = Location::create(array_merge($data, [
            'area_id' => $resolvedAreaId ?? ($normalizedLegacyAreas[0] ?? null),
            'loc_type' => $data['loc_type'] ?? $defaultLocType ?? LocationType::Warehouse->value,
            'is_active' => $data['is_active'] ?? true,
        ]));

        $schema->model($location)->saveRelationships();

        $syncedAreaIds = $location->areas()->pluck('areas.id')->map(fn ($id): int => (int) $id)->all();

        if ($syncedAreaIds === []) {
            $fallbackAreas = $normalizedLegacyAreas !== []
                ? $normalizedLegacyAreas
                : ($resolvedAreaId ? [$resolvedAreaId] : []);

            if ($fallbackAreas !== []) {
                $location->areas()->sync($fallbackAreas);
                $syncedAreaIds = $fallbackAreas;
            }
        }

        if (! $location->area_id && $syncedAreaIds !== []) {
            $location->update(['area_id' => $syncedAreaIds[0]]);
        }

        Cache::forget('location-options-all');
        foreach ($syncedAreaIds as $syncedAreaId) {
            Cache::forget('location-options-'.$syncedAreaId);
        }

        return $location->getKey();
    }
}
