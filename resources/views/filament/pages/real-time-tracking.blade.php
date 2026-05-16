<x-filament-panels::page>
    @php
        $vehicles = $this->getVehicles();
        $stats = $this->getStats();
        $token = $this->getMapboxToken();
    @endphp

    <div class="space-y-4">
        <div class="flex gap-3 overflow-x-auto pb-2 -mx-2 sm:mx-0">
            <div class="min-w-55 shrink-0 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
                <span class="text-xs text-gray-500">Tổng xe</span>
                <p class="text-xl font-bold text-gray-900 dark:text-white">{{ $stats['total'] }}</p>
            </div>
            <div class="min-w-55 shrink-0 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-950/30">
                <span class="text-xs text-amber-600">Đang chạy</span>
                <p class="text-xl font-bold text-amber-700">{{ $stats['running'] }}</p>
            </div>
            <div class="min-w-55 shrink-0 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-800 dark:bg-emerald-950/30">
                <span class="text-xs text-emerald-600">Sẵn sàng</span>
                <p class="text-xl font-bold text-emerald-700">{{ $stats['on'] }}</p>
            </div>
            <div class="min-w-55 shrink-0 rounded-lg border border-red-200 bg-red-50 px-4 py-3 dark:border-red-800 dark:bg-red-950/30">
                <span class="text-xs text-red-600">BDSC</span>
                <p class="text-xl font-bold text-red-700">{{ $stats['bdsc'] }}</p>
            </div>
        </div>

        @if ($token)
            <script>
                window.trackingMap = function () {
                    return {
                        attempts: 0,
                        init() { this.waitForMapbox(); },
                        waitForMapbox() {
                            if (typeof mapboxgl !== 'undefined') { this.loadMap(); return; }
                            if (this.attempts < 100) { this.attempts++; setTimeout(() => this.waitForMapbox(), 100); }
                        },
                        loadMap() {
                            try {
                                const el = document.getElementById('tracking-map');
                                if (!el) return;
                                mapboxgl.accessToken = '{{ $token }}';
                                console.log('debug: mapbox token length =', (mapboxgl.accessToken || '').length);
                                const vehicles = {{ \Illuminate\Support\Js::from($vehicles) }};
                                const map = new mapboxgl.Map({ container: 'tracking-map', style: 'mapbox://styles/mapbox/streets-v12', center: [106.6297, 10.8231], zoom: 11 });
                                map.addControl(new mapboxgl.NavigationControl(), 'top-right');
                                console.log('debug: tracking-map height =', el?.clientHeight);
                                try { map.resize(); } catch (e) { console.warn('map.resize() failed', e); }
                                map.on('load', () => {
                                    console.log('map load event, styleLoaded=', map.isStyleLoaded?.() ?? false, 'style=', map.getStyle && map.getStyle());
                                });
                                map.on('error', (err) => { console.error('map error event', err); });
                                const colors = { on: '#22c55e', running: '#f59e0b', bdsc: '#ef4444', off: '#9ca3af' };
                                const truckImg = '{{ asset('images/truck.png') }}';
                                vehicles.forEach(v => {
                                    const color = colors[v.status] || '#9ca3af';
                                    const d = document.createElement('div');
                                    d.style.cssText = 'width:78px;height:78px;display:flex;align-items:center;justify-content:center;flex-direction:column;cursor:pointer;border-radius:8px;box-shadow:0 2px 6px rgba(0,0,0,.35);border:2px solid white;background:' + color + ';color:white;overflow:hidden;';
                                    d.innerHTML = '<img src="' + truckImg + '" style="width:48px;height:48px;object-fit:contain;"/>' +
                                                  '<div style="margin-top:4px;background:rgba(0,0,0,0.45);padding:2px 6px;border-radius:4px;font-size:11px;font-weight:700;white-space:nowrap;">' + v.plate + '</div>';
                                    // build a richer popup including top orders (if any)
                                    let popupHtml = '<div style="font-weight:700;margin-bottom:4px;">' + (v.plate || '') + '</div>';
                                    popupHtml += '<div style="font-size:13px;color:rgba(0,0,0,0.75);margin-bottom:6px;">' + (v.driver || 'Không lái') + ' • ' + (v.type || '') + '</div>';

                                    if (v.orders && v.orders.length) {
                                        popupHtml += '<div style="border-top:1px solid rgba(0,0,0,0.06);padding-top:6px;">';
                                        v.orders.forEach(o => {
                                            const statusLabel = o.status_label || o.status || '';
                                            const pickup = o.pickup || '—';
                                            const delivery = o.delivery || '—';
                                            const pk = o.total_packages ? (o.total_packages + ' kiện') : '';
                                            const wt = o.total_weight ? (o.total_weight + ' kg') : '';

                                            popupHtml += '<div style="margin-bottom:8px;">'
                                                + '<div style="font-weight:700;font-size:13px;">' + (o.order_code || '—') + ' <span style="background:rgba(0,0,0,0.06);padding:2px 6px;border-radius:4px;font-size:11px;margin-left:6px;">' + statusLabel + '</span></div>'
                                                + '<div style="font-size:12px;color:#444;">' + pickup + ' → ' + delivery + '</div>'
                                                + '<div style="font-size:12px;color:#666;">' + pk + ' ' + wt + '</div>'
                                            + '</div>';
                                        });
                                        popupHtml += '</div>';
                                    }

                                    new mapboxgl.Marker({ element: d }).setLngLat([v.lng, v.lat])
                                        .setPopup(new mapboxgl.Popup({ offset: 20 }).setHTML(popupHtml))
                                        .addTo(map);
                                });
                            } catch (e) {
                                console.error('map init error', e);
                                const dbg = document.createElement('div');
                                dbg.style.cssText = 'margin-top:8px;padding:8px;border-radius:6px;background:#fee2e2;color:#7f1d1d;border:1px solid #fecaca;font-size:13px';
                                dbg.textContent = 'Lỗi khởi tạo bản đồ: ' + (e?.message || e);
                                const container = document.getElementById('tracking-map');
                                if (container && container.parentNode) container.parentNode.insertBefore(dbg, container.nextSibling);
                            }
                        }
                    };
                };
            </script>
            <div x-data="trackingMap()">
                <div id="tracking-map" class="w-full rounded-xl border border-gray-200 dark:border-gray-700" style="height: calc(100vh - 16rem); min-height: 320px;"></div>
            </div>
            <div class="flex flex-wrap gap-4 text-xs text-gray-500 dark:text-gray-400">
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-full bg-emerald-500"></span> Sẵn sàng</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-full bg-amber-500"></span> Đang chạy</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-full bg-red-500"></span> BDSC</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-full bg-gray-400"></span> Tắt</span>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    console.log('debug: typeof mapboxgl =', typeof mapboxgl);
                    const info = document.createElement('div');
                    info.id = 'mapbox-debug-info';
                    info.style.cssText = 'margin-top:8px;padding:6px 10px;border-radius:6px;background:#fff;color:#111;border:1px solid #e5e7eb;font-size:12px';
                    info.textContent = 'mapboxgl: ' + (typeof mapboxgl);
                    const container = document.getElementById('tracking-map');
                    if (container && container.parentNode) {
                        container.parentNode.insertBefore(info, container.nextSibling);
                    }
                });
            </script>
        @else
            <div class="flex items-center justify-center rounded-xl border-2 border-dashed border-gray-300 p-20 dark:border-gray-700">
                <div class="text-center">
                    <x-filament::icon icon="heroicon-o-map" class="mx-auto h-10 w-10 text-gray-400" />
                    <p class="mt-3 text-sm text-gray-500">Chưa cấu hình Mapbox token</p>
                    <p class="text-xs text-gray-400">Thêm MAPBOX_TOKEN vào .env</p>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
