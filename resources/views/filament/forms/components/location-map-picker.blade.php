@include('filament.forms.components.location-geofence-script')

@php
    $config = $getMapData();
    $defaultRadius = (int) config('checkpoint_geofence.radius_m', 3000);
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        wire:ignore
        x-data="locationGeofenceMap(
            $wire,
            {{ \Illuminate\Support\Js::from($config) }},
            {{ $defaultRadius }},
        )"
        style="height: {{ $config['mapHeight'] }}px; width: 100%; overflow: hidden;"
    >
        <div id="{{ $config['mapId'] }}"></div>
    </div>
</x-dynamic-component>
