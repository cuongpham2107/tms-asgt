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
        /* Popup optimization classes (drastically reduces HTML payload) */
        .trk-popup { font-family: Inter, system-ui, -apple-system, sans-serif; min-width: 250px; max-width: 340px; line-height: 1.4; color: #0f172a; }
        .trk-popup-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px; padding-bottom: 6px; border-bottom: 1px solid #f1f5f9; }
        .trk-popup-plate { font-weight: 800; font-size: 15px; color: #0f172a; letter-spacing: -0.02em; }
        .trk-popup-sub { font-size: 11px; color: #64748b; margin-top: 2px; }
        .trk-popup-badge { color: #ffffff; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 99px; white-space: nowrap; }
        .trk-popup-driver { display: flex; align-items: center; justify-content: space-between; background: #f8fafc; padding: 5px 8px; border-radius: 6px; margin-bottom: 6px; font-size: 11px; color: #334155; }
        .trk-popup-order-title { font-size: 10px; font-weight: 700; color: #64748b; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.04em; }
        .trk-order-item { margin-bottom: 5px; padding: 6px 8px; background: #f8fafc; border-radius: 6px; border-left: 3px solid #3b82f6; box-shadow: 0 1px 2px rgba(0,0,0,0.03); font-size: 11px; }
        .trk-speed { display: inline-flex; align-items: center; gap: 3px; color: #d97706; font-weight: 700; font-size: 11px; }

        /* Hardware acceleration hints for map elements on low-spec GPUs */
        .map-tracking-map-container .leaflet-container {
            contain: layout paint;
            will-change: transform;
            transform: translateZ(0);
        }
        .map-tracking-map-container .leaflet-tile {
            will-change: transform;
        }
    </style>

    <div class="flex flex-col gap-4">
        {{-- Main Row: Sidebar (Left) + Map (Right) --}}
        <div class="map-tracking-layout">
            {{-- Left Fleet Sidebar --}}
            <div class="map-tracking-sidebar">
                <livewire:app.filament.widgets.google-map-sidebar :selected-vehicle-ids="$selectedVehicleIds" wire:key="fleet-sidebar" />
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

            {{-- Selected Vehicle Indicator & Clear Action --}}
            @if (!empty($selectedVehicleIds))
                <div class="flex items-center gap-2 rounded-lg bg-primary-50 px-3 py-1.5 text-xs font-semibold text-primary-700 ring-1 ring-inset ring-primary-700/20 dark:bg-primary-950 dark:text-primary-300">
                    <span class="flex h-2 w-2 rounded-full bg-primary-600 animate-pulse"></span>
                    <span>Đang chọn: <strong>{{ $this->getSelectedVehiclePlatesString() }}</strong></span>
                    <button
                        wire:click="clearSelectedVehicles"
                        type="button"
                        class="ml-1 inline-flex items-center rounded bg-primary-200/70 px-1.5 py-0.5 text-[11px] font-bold text-primary-800 hover:bg-primary-300 dark:bg-primary-800 dark:text-primary-200 dark:hover:bg-primary-700 transition-colors"
                        title="Bỏ chọn để xem tất cả xe"
                    >
                        ✕ Bỏ chọn
                    </button>
                </div>
            @endif

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
                        if (++attempts < maxAttempts) setTimeout(tryInit, 100);
                        return;
                    }

                    const component = Alpine.$data(mapContainer);
                    if (!component?.mapCore?.map) {
                        if (++attempts < maxAttempts) setTimeout(tryInit, 100);
                        return;
                    }

                    const mapCore = component.mapCore;
                    const map = mapCore.map;

                    if (mapCore._tmsOptimized) {
                        return;
                    }
                    mapCore._tmsOptimized = true;

                    const REF_ZOOM = 13;

                    // Debounced resize handler using requestAnimationFrame
                    let resizeRaf = null;
                    const scheduleInvalidate = () => {
                        if (resizeRaf) cancelAnimationFrame(resizeRaf);
                        resizeRaf = requestAnimationFrame(() => {
                            if (map && map._container) {
                                map.invalidateSize({ debounceMoveend: true });
                            }
                        });
                    };

                    // Initial invalidate
                    scheduleInvalidate();
                    setTimeout(scheduleInvalidate, 250);

                    // ResizeObserver with debounce to avoid layout thrashing
                    const mapWrapper = document.querySelector('.map-tracking-map-container');
                    if (mapWrapper && window.ResizeObserver) {
                        const ro = new ResizeObserver(() => {
                            scheduleInvalidate();
                        });
                        ro.observe(mapWrapper);
                    }

                    window.addEventListener('resize', scheduleInvalidate, { passive: true });

                    function storeBaseValues() {
                        mapCore.layers.forEach(({ layer, data }) => {
                            if (layer instanceof L.Polyline && layer._baseWeight === undefined) {
                                layer._baseWeight = layer.options.weight || data?.options?.weight || 3;
                            } else if (layer instanceof L.CircleMarker && layer._baseRadius === undefined) {
                                layer._baseRadius = layer.options.radius || data?.options?.radius || 6;
                            }
                        });
                    }

                    let zoomRaf = null;
                    function applyZoomStyles() {
                        if (zoomRaf) cancelAnimationFrame(zoomRaf);
                        zoomRaf = requestAnimationFrame(() => {
                            const zoom = map.getZoom();
                            const scale = Math.max(0.4, Math.min(2.2, Math.pow(1.4, zoom - REF_ZOOM)));

                            mapCore.layers.forEach(({ layer }) => {
                                if (layer instanceof L.Polyline && layer._baseWeight) {
                                    layer.setStyle({ weight: layer._baseWeight * scale });
                                } else if (layer instanceof L.CircleMarker && layer._baseRadius) {
                                    layer.setRadius(layer._baseRadius * scale);
                                }
                            });
                        });
                    }

                    storeBaseValues();

                    // Dynamic vehicle focus on map when selected from sidebar or map
                    function handleVehicleSelection(selectedIds) {
                        if (!map || !mapCore) return;

                        window._tmsCurrentSelectedVehicleId = (selectedIds && selectedIds.length === 1) ? selectedIds[0] : null;

                        if (selectedIds && selectedIds.length === 1) {
                            const vehicleId = selectedIds[0];
                            const layerId = 'vehicle-' + vehicleId;
                            const entry = mapCore.layers.get(layerId);
                            const marker = Alpine.raw(entry?.layer);

                            if (!marker) {
                                return;
                            }

                            let showedInCluster = false;
                            if (mapCore.layerGroups) {
                                mapCore.layerGroups.forEach(({ layer: group }) => {
                                    const rawGroup = Alpine.raw(group);
                                    if (rawGroup && typeof rawGroup.zoomToShowLayer === 'function' && rawGroup.hasLayer(marker)) {
                                        rawGroup.zoomToShowLayer(marker, function() {
                                            if (typeof marker.openPopup === 'function') {
                                                marker.openPopup();
                                            }
                                        });
                                        showedInCluster = true;
                                    }
                                });
                            }

                            if (!showedInCluster) {
                                const latLng = marker.getLatLng();
                                if (latLng) {
                                    const targetZoom = Math.max(map.getZoom(), 15);
                                    map.flyTo(latLng, targetZoom, {
                                        animate: true,
                                        duration: 0.7
                                    });
                                    setTimeout(function() {
                                        if (typeof marker.openPopup === 'function') {
                                            marker.openPopup();
                                        }
                                    }, 750);
                                }
                            }
                        } else if (selectedIds && selectedIds.length > 1) {
                            // Multiple vehicles selected (e.g. running only or select all): fit bounds
                            const latLngs = [];
                            selectedIds.forEach(function(id) {
                                const m = Alpine.raw(mapCore.layers.get('vehicle-' + id)?.layer);
                                if (m && typeof m.getLatLng === 'function') {
                                    latLngs.push(m.getLatLng());
                                }
                            });

                            if (latLngs.length > 0) {
                                map.closePopup();
                                map.fitBounds(L.latLngBounds(latLngs), {
                                    padding: [50, 50],
                                    maxZoom: 16
                                });
                            }
                        } else {
                            // Deselected: close popup and fit bounds to fleet
                            map.closePopup();
                            if (typeof mapCore.applyFitBounds === 'function') {
                                mapCore.applyFitBounds();
                            }
                        }
                    }

                    const origUpdate = mapCore.updateMapData.bind(mapCore);
                    mapCore.updateMapData = function(newConfig) {
                        origUpdate(newConfig);
                        storeBaseValues();
                        applyZoomStyles();
                        scheduleInvalidate();

                        // Keep popup open for the active selected vehicle when route/layer data updates
                        if (window._tmsCurrentSelectedVehicleId) {
                            setTimeout(function() {
                                const entry = mapCore.layers.get('vehicle-' + window._tmsCurrentSelectedVehicleId);
                                const marker = Alpine.raw(entry?.layer);
                                if (marker && typeof marker.openPopup === 'function' && !marker.isPopupOpen()) {
                                    marker.openPopup();
                                }
                            }, 120);
                        }
                    };

                    map.on('zoomend', applyZoomStyles);
                    setTimeout(applyZoomStyles, 100);

                    // Neutralize empty map click callback to prevent unwanted network requests
                    // when dragging, zooming, or clicking near markers
                    if (component?.mapCore?.callbacks) {
                        component.mapCore.callbacks.onMapClick = null;
                    }

                    // Bidirectional sync handler: Map focus + Sidebar scroll
                    if (!window._tmsVehicleSyncHandlerAttached) {
                        window._tmsVehicleSyncHandlerAttached = true;
                        window.addEventListener('vehicleSelectionChanged', function(e) {
                            const ids = e.detail?.selectedIds || [];

                            // 1. Zoom/fly map and open popup
                            handleVehicleSelection(ids);

                            // 2. Smoothly scroll sidebar list to selected vehicle
                            if (ids.length >= 1) {
                                setTimeout(function() {
                                    const card = document.getElementById('sidebar-vehicle-' + ids[0]);
                                    if (card) {
                                        card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                                    }
                                }, 150);
                            }
                        });
                    }

                    // When user clicks the popup close button ('x'), clear selection so map resets
                    if (!window._tmsPopupCloseHandlerAttached) {
                        window._tmsPopupCloseHandlerAttached = true;
                        document.addEventListener('click', function(e) {
                            if (e.target.closest('.leaflet-popup-close-button')) {
                                component.$wire.call('clearSelectedVehicles');
                            }
                        });
                    }
                }

                tryInit();
            }

            document.addEventListener('livewire:navigated', handleMapInitAndResize);
            document.addEventListener('DOMContentLoaded', handleMapInitAndResize);
        </script>
    @endpush
</x-filament-panels::page>
