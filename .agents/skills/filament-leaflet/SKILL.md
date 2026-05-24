---
name: filament-leaflet
description: "Use when working with maps, locations, coordinates, or geospatial features in Filament admin panel. This includes creating map widgets, adding map picker form fields, displaying map columns in tables, rendering map entries in infolists, handling markers/clusters/shapes, GeoJSON density maps, geocoding search inputs, and any CRUD operations involving location data. Also use for integrating interactive maps with Eloquent models and Filament Resources."
license: MIT
---

# Filament Leaflet

Interactive Leaflet maps integration for Filament v5.

## Installation

Package is already installed. Assets published via:
```bash
php artisan vendor:publish --tag=filament-leaflet
php artisan vendor:publish --tag=filament-leaflet-config
```

## Core Components

### Map Widget

```php
namespace App\Filament\Widgets;

use EduardoRibeiroDev\FilamentLeaflet\Widgets\MapWidget;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;

class MyMapWidget extends MapWidget
{
    protected ?string $heading = 'Store Locations';
    protected array $mapCenter = [-14.235, -51.9253];
    protected int $defaultZoom = 12;
    protected int $mapHeight = 600;
    protected int $maxZoom = 18;
    protected int $minZoom = 2;

    // Interaction control
    protected bool $mapDraggable = true;
    protected bool $mapZoomable = true;
    protected ?int $recenterMapTimeout = 5000;
    protected bool $autoCenter = false;

    protected function getMarkers(): array
    {
        return [
            Marker::make(-23.5505, -46.6333)
                ->title('São Paulo')
                ->popupContent('The largest city in Brazil'),
        ];
    }
}
```

### MapPicker (Form Field)

```php
use EduardoRibeiroDev\FilamentLeaflet\Fields\MapPicker;
use EduardoRibeiroDev\FilamentLeaflet\Enums\TileLayer;

MapPicker::make('location')
    ->height(300)
    ->center(-23.5505, -46.6333)
    ->zoom(11)
    ->autoCenter()
    ->tileLayersUrl(TileLayer::OpenStreetMap)
    ->columnSpanFull()
```

### GeoSearchInput (Form Field)

```php
use EduardoRibeiroDev\FilamentLeaflet\Fields\GeoSearchInput;
use EduardoRibeiroDev\FilamentLeaflet\Enums\GeoSearchProvider;

GeoSearchInput::make('location')
    ->provider(GeoSearchProvider::Nominatim)
    ->limit(10)
    ->withAddressDetails()
    ->columnSpanFull()
```

### MapColumn (Table Column)

```php
use EduardoRibeiroDev\FilamentLeaflet\Tables\MapColumn;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;

MapColumn::make('location')
    ->height(100)
    ->zoom(8)
    ->pickMarker(fn(Marker $marker) => $marker->black())
    ->static()
```

### MapEntry (Infolist)

```php
use EduardoRibeiroDev\FilamentLeaflet\Infolists\MapEntry;

MapEntry::make('location')
    ->height(284)
    ->zoom(10)
    ->static()
    ->columnSpanFull()
```

## Map Elements

### Markers

```php
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;
use Filament\Support\Colors\Color;

// Simple marker
Marker::make(-23.5505, -46.6333)->title('Simple marker');

// Colored marker
Marker::make(-23.5212, -46.4243)->green()->title('Colored marker');

// Custom icon
Marker::make(-23.5266, -46.5412)->icon('https://leafletjs.com/examples/custom-icons/leaf-red.png', [32, 72]);

// Heroicon marker
Marker::make(-23.5300, -46.6400)->violet()->icon('heroicon-user-circle')->title('Heroicon marker');

// From Eloquent model
Marker::fromRecord(
    record: $store,
    coordsColumn: 'coordinates',
    titleColumn: 'name',
    descriptionColumn: 'description',
    popupFieldsColumns: ['address', 'phone'],
    color: Color::Blue,
);
```

### Layer Groups

```php
use EduardoRibeiroDev\FilamentLeaflet\LayerGroups\LayerGroup;
use EduardoRibeiroDev\FilamentLeaflet\LayerGroups\FeatureGroup;
use EduardoRibeiroDev\FilamentLeaflet\LayerGroups\MarkerCluster;

// Simple group
LayerGroup::make([
    Marker::make(-23.5505, -46.6333)->title('Store 1'),
    Marker::make(-23.5515, -46.6343)->title('Store 2'),
])->name('Active Stores');

// Feature group with polygon envelope
FeatureGroup::make([
    Marker::make(-23.5505, -46.6333),
    Marker::make(-23.5515, -46.6343),
])->name('Delivery Zone')->blue()->fillBlue()->fillOpacity(0.3);

// Marker cluster (auto-grouping nearby markers)
MarkerCluster::fromModel(
    model: Store::class,
    coordsColumn: 'coordinates',
    titleColumn: 'name',
    color: Color::Green,
)->maxClusterRadius(60);
```

### Shapes

```php
use EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\Circle;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\Polygon;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\Polyline;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\Rectangle;

Circle::make(-23.5505, -46.6333)
    ->radiusInKilometers(10)
    ->blue()->fillBlue()->fillOpacity(0.2);

Polygon::make([-23.5505, -46.6333], [-23.5515, -46.6343], [-23.5525, -46.6323])
    ->green()->fillGreen()->fillOpacity(0.3);

Polyline::make([-23.5505, -46.6333], [-23.5515, -46.6343], [-23.5525, -46.6353])
    ->blue()->weight(4)->opacity(0.7);

Rectangle::make([-23.5505, -46.6333], [-23.5525, -46.6353])
    ->orange()->fillOrange()->fillOpacity(0.2);
```

### Editable Layers

```php
// Enable draw control on widget
protected bool $hasEditLayersControl = true;
protected bool $hasDrawMarkerControl = true;
protected bool $hasDrawCircleControl = true;
protected bool $hasDrawPolygonControl = true;
protected bool $hasRemoveLayersControl = true;

// Make individual layers editable
Marker::make(-23.5505, -46.6333)->editable();
Circle::make(-23.5505, -46.6333)->radiusInKilometers(5)->editable();
```

## User Interactions

### Popups & Tooltips

```php
Marker::make(-23.5505, -46.6333)
    ->popupTitle('Store Location')
    ->popupContent('Visit our main store')
    ->popupFields([
        'address' => '123 Main Street',
        'phone' => '+55 11 1234-5678',
    ])
    ->tooltipContent('São Paulo City')
    ->tooltipPermanent(false)
    ->tooltipDirection('top');
```

### Click Handlers

```php
use Filament\Notifications\Notification;

// Marker click
Marker::make(-23.5505, -46.6333)
    ->action(function (Marker $marker) {
        Notification::make()->title('Marker Clicked!')->send();
    });

// Map click
public function handleMapClick(float $latitude, float $longitude): void
{
    Notification::make()
        ->title('Map clicked')
        ->body("{$latitude}, {$longitude}")
        ->send();
}

// MapPicker click
MapPicker::make('location')
    ->onMapClick(function (float $lat, float $lng) {
        Notification::make()->title("Lat: {$lat}, Lng: {$lng}")->send();
    });
```

## Model Integration & CRUD

```php
use App\Models\Location;
use App\Filament\Resources\LocationResource;

class LocationMapWidget extends MapWidget
{
    protected ?string $markerModel = Location::class;
    protected ?string $markerResource = LocationResource::class;
    protected ?string $markerClickAction = 'edit'; // 'view', 'edit', 'delete', or null

    protected function getFormComponents(): array
    {
        return [
            TextInput::make('name')->required(),
            Textarea::make('description')->columnSpanFull(),
        ];
    }
}
```

## Coordinate Model Cast

```php
use EduardoRibeiroDev\FilamentLeaflet\ValueObjects\Coordinate;

class Store extends Model
{
    protected $casts = [
        'coordinates' => Coordinate::class,
    ];
}
```

## GeoJSON Density Maps

```php
class DensityWidget extends MapWidget
{
    protected ?string $geoJsonUrl = 'https://example.com/states.json';

    protected array $geoJsonColors = [
        '#FED976', '#FEB24C', '#FD8D3C', '#FC4E2A', '#E31A1C',
    ];

    protected function getGeoJsonData(): array
    {
        return ['SP' => 166.23, 'RJ' => 365.23, 'MG' => 33.41];
    }
}
```

## Tile Layers

```php
use EduardoRibeiroDev\FilamentLeaflet\Enums\TileLayer;

// Single layer
protected TileLayer|string|array $tileLayersUrl = TileLayer::OpenStreetMap;

// Multiple switchable layers
protected TileLayer|string|array $tileLayersUrl = [
    'Street Map' => TileLayer::OpenStreetMap,
    'Satellite' => TileLayer::GoogleSatellite,
];
```

## Performance

- Use `MarkerCluster::fromModel()` for large datasets
- Limit queries with `modifyQueryCallback`
- Set appropriate `maxZoom`/`minZoom`
- Use `removeOutsideVisibleBounds(true)` on clusters

## Configuration

Publish config:
```bash
php artisan vendor:publish --tag=filament-leaflet-config
```

Publish translations:
```bash
php artisan vendor:publish --tag=filament-leaflet-translations
```
