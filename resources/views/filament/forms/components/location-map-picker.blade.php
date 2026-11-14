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
        data-geofence-map-root="true"
        x-data="locationGeofenceMap(
            $wire,
            {{ \Illuminate\Support\Js::from($config) }},
            {{ $defaultRadius }},
        )"
        style="height: {{ $config['mapHeight'] }}px; width: 100%; overflow: hidden;"
        class="relative rounded-lg ring-1 ring-gray-200 dark:ring-white/10"
    >
        <div id="{{ $config['mapId'] }}" class="h-full w-full"></div>
    </div>
</x-dynamic-component>
