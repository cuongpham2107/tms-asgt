<x-filament-panels::page>
    @php
        $lastUpdated = $this->getLastUpdated();
    @endphp

    <style>
        .map-tracking-layout {
            display: flex;
            flex-direction: row;
            gap: 20px;
            align-items: stretch;
            width: 100%;
            height: calc(100vh - 340px);
            min-height: 580px;
        }
        .map-tracking-sidebar {
            width: 360px;
            min-width: 340px;
            max-width: 400px;
            height: 100%;
            flex-shrink: 0;
        }
        .map-tracking-map-container {
            flex: 1 1 0%;
            min-width: 0;
            width: 100%;
            height: 100%;
            position: relative;
        }
        .map-tracking-map-container,
        .map-tracking-map-container > div,
        .map-tracking-map-container [class*="leafletMapWidget"],
        .map-tracking-map-container [x-data*="leafletMapWidget"],
        .map-tracking-map-container .leaflet-container,
        .map-tracking-map-container [id^="map-"] {
            height: 100% !important;
            min-height: 100% !important;
            width: 100% !important;
        }
        @media (max-width: 1024px) {
            .map-tracking-layout {
                flex-direction: column;
                height: auto;
            }
            .map-tracking-sidebar {
                width: 100%;
                max-width: 100%;
                height: 480px;
            }
            .map-tracking-map-container {
                height: 520px;
            }
        }
    </style>

    <div class="flex flex-col gap-4">
        {{-- Main Row: Sidebar (Left) + Map (Right) --}}
        <div class="map-tracking-layout">
            {{-- Left Fleet Sidebar --}}
            <div class="map-tracking-sidebar">
                @livewire(\App\Filament\Widgets\GoogleMapSidebar::class)
            </div>

            {{-- Right Map Container --}}
            <div class="map-tracking-map-container overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div wire:loading.delay.class="opacity-40" wire:target="refreshData,updateSelectedVehicles" class="h-full w-full transition-opacity duration-300">
                    <x-filament-leaflet::map
                        :config="$this->getMapData()"
                        widget
                    />
                </div>

                {{-- Loading Overlay --}}
                <div wire:loading wire:target="refreshData,updateSelectedVehicles" class="absolute inset-0 z-50 flex items-center justify-center rounded-xl" style="background: rgba(255, 255, 255, 0.45); backdrop-filter: blur(4px);">
                    <div class="flex items-center gap-3 rounded-xl bg-white px-6 py-4 shadow-xl ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                        <x-filament::loading-indicator class="h-6 w-6 text-primary-600 dark:text-primary-400" />
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">Đang cập nhật lộ trình xe...</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Bottom Mission Control Bar: Legend & Actions --}}
        <div class="flex flex-col lg:flex-row flex-wrap items-center justify-between gap-4 rounded-xl border border-gray-200 bg-white p-3.5 shadow-sm dark:border-gray-700 dark:bg-gray-900 shrink-0">
            {{-- Route Legend --}}
            <div class="flex flex-wrap items-center gap-4 text-xs">
                <span class="font-bold text-gray-700 dark:text-gray-300">Chú thích lộ trình:</span>
                <span class="inline-flex items-center gap-1.5 font-medium text-gray-600 dark:text-gray-300">
                    <span class="h-3 w-3 rounded-full bg-emerald-500"></span>
                    Xuất phát
                </span>
                <span class="inline-flex items-center gap-1.5 font-medium text-gray-600 dark:text-gray-300">
                    <span class="h-3 w-3 rounded-full bg-blue-600"></span>
                    Đang di chuyển
                </span>
                <span class="inline-flex items-center gap-1.5 font-medium text-gray-600 dark:text-gray-300">
                    <span class="h-3 w-3 rounded-full bg-purple-600"></span>
                    Điểm giao
                </span>
                <span class="inline-flex items-center gap-1.5 font-medium text-gray-600 dark:text-gray-300">
                    <span class="h-3 w-3 rounded-full bg-red-600"></span>
                    Điểm kết thúc
                </span>
                <span class="inline-flex items-center gap-1.5 font-medium text-gray-600 dark:text-gray-300">
                    <span class="inline-block h-0 w-5 border-b-2 border-dashed border-gray-400"></span>
                    GPS Breadcrumbs
                </span>
            </div>

            {{-- Live Indicator & Refresh --}}
            <div class="flex items-center gap-3">
                <button
                    wire:click="refreshData"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm hover:bg-gray-50 hover:text-primary-600 transition-colors dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-primary-400"
                    wire:loading.attr="disabled"
                >
                    <x-filament::icon icon="heroicon-o-arrow-path" class="h-3.5 w-3.5" wire:loading.class="animate-spin" wire:target="refreshData" />
                    <span wire:loading.remove wire:target="refreshData">Làm mới</span>
                    <span wire:loading wire:target="refreshData">Đang tải...</span>
                </button>

                @if ($lastUpdated)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-950 dark:text-emerald-300">
                        <span class="relative flex h-2 w-2">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                        </span>
                        {{ $lastUpdated->format('H:i:s') }}
                    </span>
                @endif
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            function handleMapInitAndResize() {
                let attempts = 0;
                const maxAttempts = 30;

                function tryInit() {
                    const mapContainer = document.querySelector('[id^="map-"]');
                    if (!mapContainer) {
                        if (++attempts < maxAttempts) setTimeout(tryInit, 150);
                        return;
                    }

                    const component = Alpine.$data(mapContainer);
                    if (!component?.mapCore?.map) {
                        if (++attempts < maxAttempts) setTimeout(tryInit, 150);
                        return;
                    }

                    const mapCore = component.mapCore;
                    const map = mapCore.map;
                    const REF_ZOOM = 13;

                    // Ensure Leaflet recalculates dimensions immediately
                    map.invalidateSize();
                    setTimeout(() => map.invalidateSize(), 100);
                    setTimeout(() => map.invalidateSize(), 300);
                    setTimeout(() => map.invalidateSize(), 600);

                    // ResizeObserver to automatically resize map when container dimensions change
                    const mapWrapper = document.querySelector('.map-tracking-map-container');
                    if (mapWrapper && window.ResizeObserver) {
                        const ro = new ResizeObserver(() => {
                            map.invalidateSize();
                        });
                        ro.observe(mapWrapper);
                    }

                    window.addEventListener('resize', () => {
                        map.invalidateSize();
                    });

                    function storeBaseValues() {
                        mapCore.layers.forEach(({ layer, data }) => {
                            if (layer instanceof L.Polyline && layer._baseWeight === undefined) {
                                layer._baseWeight = layer.options.weight || data?.options?.weight || 3;
                            } else if (layer instanceof L.CircleMarker && layer._baseRadius === undefined) {
                                layer._baseRadius = layer.options.radius || data?.options?.radius || 6;
                            }
                        });
                    }

                    function applyZoomStyles() {
                        const zoom = map.getZoom();
                        const scale = Math.max(0.4, Math.min(2.2, Math.pow(1.4, zoom - REF_ZOOM)));

                        mapCore.layers.forEach(({ layer }) => {
                            if (layer instanceof L.Polyline && layer._baseWeight) {
                                layer.setStyle({ weight: layer._baseWeight * scale });
                            } else if (layer instanceof L.CircleMarker && layer._baseRadius) {
                                layer.setRadius(layer._baseRadius * scale);
                            }
                        });
                    }

                    storeBaseValues();

                    const origUpdate = mapCore.updateMapData.bind(mapCore);
                    mapCore.updateMapData = function(newConfig) {
                        origUpdate(newConfig);
                        storeBaseValues();
                        applyZoomStyles();
                        setTimeout(() => map.invalidateSize(), 100);
                    };

                    map.on('zoomend', applyZoomStyles);
                    setTimeout(applyZoomStyles, 150);
                }

                tryInit();
            }

            document.addEventListener('livewire:navigated', handleMapInitAndResize);
            document.addEventListener('DOMContentLoaded', handleMapInitAndResize);
        </script>
    @endpush
</x-filament-panels::page>
