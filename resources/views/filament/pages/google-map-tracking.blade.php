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
        .trk-popup { font-family: Inter, system-ui, -apple-system, sans-serif; min-width: 230px; max-width: 300px; line-height: 1.45; color: #0f172a; font-size: 12px; }
        .trk-popup-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 6px; padding-bottom: 6px; border-bottom: 1px solid #f1f5f9; }
        .trk-popup-plate { font-weight: 800; font-size: 15px; letter-spacing: -0.02em; }
        .trk-popup-badge { color: #ffffff; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 99px; white-space: nowrap; }
        .trk-row { margin-bottom: 4px; color: #334155; }
        .trk-muted { font-size: 11px; color: #64748b; }
        .trk-ellipsis { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .trk-stale { color: #dc2626; font-weight: 600; }
        .trk-order-item { margin-bottom: 4px; padding: 4px 8px; background: #f8fafc; border-radius: 6px; border-left: 3px solid #3b82f6; }
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
                {{-- Chỉ lần render đầu nhúng config; các lần sau wire:ignore giữ nguyên DOM bản đồ, dữ liệu đi qua sự kiện update-leaflet --}}
                <div wire:ignore class="h-full w-full">
                    @if ($this->isInitialRender())
                        <x-filament-leaflet::map :config="$this->getMapData()" widget />
                    @endif
                </div>

                <div wire:loading.delay wire:target="refreshData,updateSelectedVehicles,handleLayerClick,clearSelectedVehicles" class="absolute right-3 top-3 z-[1000]">
                    <div class="flex items-center gap-2 rounded-lg bg-white/95 px-3 py-1.5 text-xs font-semibold text-gray-700 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700">
                        <x-filament::loading-indicator class="h-4 w-4 text-primary-600" />
                        Đang cập nhật...
                    </div>
                </div>
            </div>
        </div>

        {{-- Bottom Mission Control Bar: Legend & Actions --}}
        <div class="flex flex-col lg:flex-row flex-wrap items-center justify-between gap-4 rounded-xl border border-gray-200 bg-white p-3.5 shadow-sm dark:border-gray-700 dark:bg-gray-900 shrink-0">
            {{-- Legend --}}
            <div class="flex flex-col gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <span class="font-bold text-gray-700 dark:text-gray-200">Trạng thái xe:</span>
                    @foreach ($this->getStatusLegend() as $label => $color)
                        <span class="inline-flex items-center gap-1.5 font-medium">
                            <span class="h-3 w-3 rounded-full" style="background: {{ $color }}"></span>
                            {{ $label }}
                        </span>
                    @endforeach
                    @if (($withoutGps = $this->getVehiclesWithoutGpsCount()) > 0)
                        <span class="text-gray-400">· {{ $withoutGps }} xe chưa có GPS (không hiển thị)</span>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <span class="font-bold text-gray-700 dark:text-gray-200">Lộ trình xe đang chọn:</span>
                    <span class="inline-flex items-center gap-1.5 font-medium"><span class="inline-block h-1 w-6 rounded" style="background:#2563eb"></span>Đã đi</span>
                    <span class="inline-flex items-center gap-1.5 font-medium"><span class="inline-block w-6" style="border-bottom:3px dashed #e11d48"></span>Kế hoạch còn lại</span>
                    <span class="inline-flex items-center gap-1.5 font-medium"><span class="h-3 w-3 rounded-full" style="background:#16a34a"></span>Bắt đầu</span>
                    <span class="inline-flex items-center gap-1.5 font-medium"><span class="h-3 w-3 rounded-full" style="background:#d97706"></span>Lấy hàng</span>
                    <span class="inline-flex items-center gap-1.5 font-medium"><span class="h-3 w-3 rounded-full" style="background:#7c3aed"></span>Giao hàng</span>
                    <span class="inline-flex items-center gap-1.5 font-medium"><span class="h-3 w-3 rounded-full" style="background:#dc2626"></span>Kết thúc</span>
                    <span class="inline-flex items-center gap-1.5 font-medium"><span class="h-3 w-3 rounded-full bg-white" style="border:3px solid #e11d48"></span>Điểm chưa tới</span>
                </div>
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
            function initTrackingMap() {
                let attempts = 0;

                (function tryInit() {
                    const root = document.querySelector('.map-tracking-map-container [x-data^="leafletMapWidget"]');
                    const mapCore = root && window.Alpine ? Alpine.$data(root)?.mapCore : null;

                    if (!mapCore?.map) {
                        if (++attempts < 50) setTimeout(tryInit, 100);
                        return;
                    }

                    if (mapCore._tmsReady) return;
                    mapCore._tmsReady = true;

                    window._tmsTracking = setupTrackingMap(Alpine.$data(root), mapCore, Alpine.raw(mapCore.map));
                })();
            }

            function setupTrackingMap(component, mapCore, map) {
                // Thư viện gỡ layer khỏi bản đồ nhưng không xoá khỏi registry -> fitBounds/tìm marker dính layer cũ.
                const originalRemove = mapCore.removeLayer.bind(mapCore);
                mapCore.removeLayer = (layerData) => {
                    originalRemove(layerData);
                    mapCore.layers.delete(layerData.id);
                };

                // Bấm nền bản đồ không cần gọi server.
                mapCore.callbacks.onMapClick = null;

                let selectedIds = [];
                let fittedKey = null;
                let fleetFitPending = false;

                const markerOf = (id) => Alpine.raw(mapCore.layers.get('vehicle-' + id)?.layer);
                const routeLayersOf = (id) => Array.from(mapCore.layers.entries())
                    .filter(([layerId]) => String(layerId).startsWith('trk-' + id + '-'))
                    .map(([, entry]) => Alpine.raw(entry.layer));

                // Popup riêng neo theo vị trí xe: vẫn hiện khi marker đang nằm trong cụm.
                const selectionPopup = L.popup({ maxWidth: 320, autoPanPadding: [20, 20], offset: [0, -34] });

                function openPopup(id) {
                    const marker = markerOf(id);
                    const content = marker?.getPopup()?.getContent();
                    if (!content) return;

                    // Mở lại (kể cả khi đang mở) để autoPan kéo popup vào khung nhìn sau khi bản đồ đổi.
                    map.closePopup(selectionPopup);
                    selectionPopup.setLatLng(marker.getLatLng()).setContent(content).openOn(map);
                }

                function focusSelection() {
                    if (!selectedIds.length) {
                        fittedKey = '';
                        fleetFitPending = true;
                        map.closePopup();
                        mapCore.applyFitBounds();
                        return;
                    }

                    const bounds = L.latLngBounds([]);
                    let hasRoute = false;

                    selectedIds.forEach((id) => {
                        const marker = markerOf(id);
                        if (marker) bounds.extend(marker.getLatLng());

                        routeLayersOf(id).forEach((layer) => {
                            hasRoute = true;
                            bounds.extend(layer.getLatLng ? layer.getLatLng() : layer.getBounds());
                        });
                    });

                    if (!bounds.isValid()) return;

                    fittedKey = selectedIds.join(',') + (hasRoute ? ':route' : '');
                    // Leaflet bỏ qua lệnh zoom khi đang có animation zoom -> chờ xong rồi mới fit theo tuyến.
                    const fit = () => {
                        // Mở popup sau khi fit xong để autoPan đẩy popup vào khung nhìn.
                        map.once('moveend', () => selectedIds.length === 1 ? openPopup(selectedIds[0]) : map.closePopup());
                        map.fitBounds(bounds, { padding: [60, 60], maxZoom: 15 });
                    };
                    map._animatingZoom ? map.once('zoomend', () => setTimeout(fit)) : fit();
                }

                const originalUpdate = mapCore.updateMapData.bind(mapCore);
                mapCore.updateMapData = function (newConfig) {
                    originalUpdate(newConfig);

                    // Bỏ chọn: server vừa trả lại toàn bộ xe -> fit cả đội xe.
                    if (!selectedIds.length && fleetFitPending) {
                        fleetFitPending = false;
                        mapCore.applyFitBounds();
                        return;
                    }

                    // Lộ trình của xe đang chọn vừa về: fit một lần theo cả tuyến.
                    const routeKey = selectedIds.join(',') + ':route';
                    if (selectedIds.length && fittedKey !== routeKey && selectedIds.some((id) => routeLayersOf(id).length)) {
                        focusSelection();
                    } else if (selectedIds.length === 1 && map.hasLayer(selectionPopup)) {
                        openPopup(selectedIds[0]);
                    }
                };

                mapCore.applyFitBounds();

                return {
                    component,
                    onSelectionChanged(ids) {
                        selectedIds = (ids || []).map(Number);
                        focusSelection();
                    },
                };
            }

            if (!window._tmsTrackingListeners) {
                window._tmsTrackingListeners = true;

                window.addEventListener('vehicleSelectionChanged', (e) => {
                    const ids = e.detail?.selectedIds || [];
                    window._tmsTracking?.onSelectionChanged(ids);

                    if (ids.length) {
                        document.getElementById('sidebar-vehicle-' + ids[0])?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                });

                // Đóng popup bằng nút X = bỏ chọn xe.
                document.addEventListener('click', (e) => {
                    if (e.target.closest('.map-tracking-map-container .leaflet-popup-close-button')) {
                        window._tmsTracking?.component.$wire.call('clearSelectedVehicles');
                    }
                });
            }

            document.addEventListener('livewire:navigated', initTrackingMap);
            document.addEventListener('DOMContentLoaded', initTrackingMap);
        </script>
    @endpush
</x-filament-panels::page>
