<x-filament-panels::page>
    @php
        $vehicles = $this->getVehicles();
        $stats = $this->getStats();
        $apiKey = $this->getApiKey();
    @endphp

    <div class="space-y-4">
        {{-- Stats bar --}}
        <div class="flex gap-3 overflow-x-auto pb-2">
            <div class="min-w-[120px] shrink-0 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
                <span class="text-xs text-gray-500 dark:text-gray-400">Tổng xe</span>
                <p class="text-xl font-bold text-gray-900 dark:text-white">{{ $stats['total'] }}</p>
            </div>
            <div class="min-w-[120px] shrink-0 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-950/30">
                <span class="text-xs text-amber-600 dark:text-amber-400">Đang chạy</span>
                <p class="text-xl font-bold text-amber-700 dark:text-amber-300">{{ $stats['running'] }}</p>
            </div>
            <div class="min-w-[120px] shrink-0 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-800 dark:bg-emerald-950/30">
                <span class="text-xs text-emerald-600 dark:text-emerald-400">Sẵn sàng</span>
                <p class="text-xl font-bold text-emerald-700 dark:text-emerald-300">{{ $stats['on'] }}</p>
            </div>
            <div class="min-w-[120px] shrink-0 rounded-lg border border-red-200 bg-red-50 px-4 py-3 dark:border-red-800 dark:bg-red-950/30">
                <span class="text-xs text-red-600 dark:text-red-400">BDSC</span>
                <p class="text-xl font-bold text-red-700 dark:text-red-300">{{ $stats['bdsc'] }}</p>
            </div>
            <div class="min-w-[120px] shrink-0 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-900/50">
                <span class="text-xs text-gray-500 dark:text-gray-400">Tắt</span>
                <p class="text-xl font-bold text-gray-600 dark:text-gray-300">{{ $stats['off'] }}</p>
            </div>
        </div>

        {{-- Map container --}}
        <div id="google-tracking-map" class="h-[65vh] w-full rounded-xl border border-gray-200 dark:border-gray-700"></div>

        {{-- Info bar --}}
        <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-700 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-300">
            <strong>🔍 Google Maps Tracking</strong> — dùng thư viện <code>cheesegrits/filament-google-maps</code> (API key từ plugin).
            Có Directions API vẽ đường thực tế, custom truck icon, route line từ checkpoint GPS.
        </div>
    </div>

    @if ($apiKey)
        <script>
            (function() {
                const vehicles = @json($vehicles);
                let map, directionsService, directionsRenderers = [];
                const markers = [];
                const polylines = [];

                function initMap() {
                    if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
                        setTimeout(initMap, 200);
                        return;
                    }

                    map = new google.maps.Map(document.getElementById('google-tracking-map'), {
                        center: { lat: 10.8231, lng: 106.6297 },
                        zoom: 11,
                        mapTypeControl: true,
                        streetViewControl: true,
                        fullscreenControl: true,
                        mapId: 'tms-tracking',
                    });

                    directionsService = new google.maps.DirectionsService();

                    const bounds = new google.maps.LatLngBounds();

                    vehicles.forEach((vehicle, idx) => {
                        // ── Custom truck icon ──────────────────────────
                        const statusColors = {
                            running: '#f59e0b', on: '#10b981',
                            off: '#6b7280', bdsc: '#ef4444',
                        };
                        const bgColor = statusColors[vehicle.status] || '#6b7280';

                        const iconSvg = `<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40">
                            <rect x="2" y="10" width="36" height="20" rx="4" fill="${bgColor}" stroke="#fff" stroke-width="2"/>
                            <rect x="4" y="4" width="16" height="10" rx="3" fill="${bgColor}" stroke="#fff" stroke-width="1.5"/>
                            <circle cx="10" cy="32" r="4" fill="#374151" stroke="#fff" stroke-width="1.5"/>
                            <circle cx="30" cy="32" r="4" fill="#374151" stroke="#fff" stroke-width="1.5"/>
                            <text x="12" y="12" font-size="7" fill="#fff" font-weight="bold">${vehicle.plate?.slice(-4) || ''}</text>
                        </svg>`;

                        const marker = new google.maps.Marker({
                            position: { lat: vehicle.lat, lng: vehicle.lng },
                            map: map,
                            title: vehicle.plate,
                            icon: {
                                url: 'data:image/svg+xml,' + encodeURIComponent(iconSvg),
                                scaledSize: new google.maps.Size(42, 42),
                                anchor: new google.maps.Point(21, 21),
                            },
                        });

                        // ── Info window ────────────────────────────────
                        const ordersHtml = (vehicle.orders || []).map(o => `
                            <div style="margin-bottom:4px;padding:4px 6px;background:#f9fafb;border-radius:4px;border-left:3px solid #3b82f6">
                                <div style="font-weight:700;font-size:12px">#${o.order_code}</div>
                                <div style="font-size:11px;color:#6b7280">
                                    ${o.customer || '—'} &bull; ${o.status_label || ''}
                                    ${o.total_packages ? ' &bull; ' + o.total_packages + ' kiện' : ''}
                                </div>
                                <div style="font-size:10px;color:#9ca3af">${o.pickup || '—'} → ${o.delivery || '—'}</div>
                            </div>
                        `).join('');

                        const infoWindow = new google.maps.InfoWindow({
                            content: `<div style="font-family:Inter,system-ui,sans-serif;min-width:260px;max-width:360px">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                                    <span style="font-weight:800;font-size:15px">${vehicle.plate}</span>
                                    <span style="background:${bgColor};color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px">${vehicle.status_label || ''}</span>
                                </div>
                                <div style="font-size:12px;color:#4b5563;margin-bottom:4px">
                                    🧑 ${vehicle.driver} &bull; ${vehicle.vehicle_type_label}
                                </div>
                                ${ordersHtml ? '<div style="margin-top:6px;border-top:1px solid #e5e7eb;padding-top:6px">' + ordersHtml + '</div>' : '<div style="font-size:11px;color:#9ca3af">Không có đơn hàng</div>'}
                            </div>`,
                        });

                        marker.addListener('click', () => infoWindow.open(map, marker));

                        markers.push(marker);
                        bounds.extend(marker.getPosition());

                        // ── Route line từ checkpoints ──────────────────
                        const route = vehicle.route || [];
                        if (route.length >= 2) {
                            const path = route.map(p => ({ lat: p.lat, lng: p.lng }));
                            const poly = new google.maps.Polyline({
                                path: path,
                                geodesic: true,
                                strokeColor: vehicle.status === 'running' ? '#f59e0b' : '#3b82f6',
                                strokeOpacity: 0.8,
                                strokeWeight: 4,
                                map: map,
                            });
                            polylines.push(poly);

                            // Start/end markers
                            const startPt = path[0];
                            const endPt = path[path.length - 1];
                            new google.maps.Marker({
                                position: startPt,
                                map: map,
                                icon: {
                                    path: google.maps.SymbolPath.CIRCLE,
                                    scale: 7,
                                    fillColor: '#22c55e',
                                    fillOpacity: 1,
                                    strokeColor: '#fff',
                                    strokeWeight: 2,
                                },
                                title: route[0].label,
                            });
                            new google.maps.Marker({
                                position: endPt,
                                map: map,
                                icon: {
                                    path: google.maps.SymbolPath.CIRCLE,
                                    scale: 7,
                                    fillColor: '#ef4444',
                                    fillOpacity: 1,
                                    strokeColor: '#fff',
                                    strokeWeight: 2,
                                },
                                title: route[route.length - 1].label,
                            });

                            bounds.extend(startPt);
                            bounds.extend(endPt);

                            // ── Directions từ điểm đầu → cuối ─────────
                            directionsService.route({
                                origin: startPt,
                                destination: endPt,
                                travelMode: google.maps.TravelMode.DRIVING,
                            }, (result, status) => {
                                if (status === 'OK') {
                                    const dr = new google.maps.DirectionsRenderer({
                                        map: map,
                                        directions: result,
                                        polylineOptions: {
                                            strokeColor: '#3b82f6',
                                            strokeOpacity: 0.5,
                                            strokeWeight: 5,
                                        },
                                        suppressMarkers: true,
                                    });
                                    directionsRenderers.push(dr);
                                }
                            });
                        }
                    });

                    if (vehicles.length > 0) {
                        map.fitBounds(bounds, { top: 40, right: 40, bottom: 40, left: 40, maxZoom: 13 });
                    }
                }

                // ── Load Google Maps API ──────────────────────────────
                if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
                    const script = document.createElement('script');
                    script.src = `https://maps.googleapis.com/maps/api/js?key={{ $apiKey }}&libraries=places&loading=async`;
                    script.onload = initMap;
                    document.head.appendChild(script);
                } else {
                    initMap();
                }
            })();
        </script>
    @else
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-950/30 dark:text-red-300">
            ⚠️ Chưa có <code>GOOGLE_MAPS_API_KEY</code> trong <code>.env</code>.
            Thêm key từ <a href="https://console.cloud.google.com" class="underline" target="_blank">Google Cloud Console</a>.
        </div>
    @endif
</x-filament-panels::page>
