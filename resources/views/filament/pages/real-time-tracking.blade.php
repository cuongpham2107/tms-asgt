<x-filament-panels::page>
    @php
        $vehicles = $this->getVehicles();
        $stats = $this->getStats();
        $token = $this->getMapboxToken();
        $trackingDateLabel = $this->getTrackingDateLabel();
    @endphp

    <div class="space-y-4">
        <div class="-mx-2 flex gap-3 overflow-x-auto pb-2 sm:mx-0">
            <div
                class="min-w-55 shrink-0 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
                <span class="text-xs text-gray-500 dark:text-gray-400">Tổng xe</span>
                <p class="text-xl font-bold text-gray-900 dark:text-white">{{ $stats['total'] }}</p>
            </div>
            <div
                class="min-w-55 shrink-0 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-950/30">
                <span class="text-xs text-amber-600 dark:text-amber-400">Đang chạy</span>
                <p class="text-xl font-bold text-amber-700 dark:text-amber-300">{{ $stats['running'] }}</p>
            </div>
            <div
                class="min-w-55 shrink-0 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-800 dark:bg-emerald-950/30">
                <span class="text-xs text-emerald-600 dark:text-emerald-400">Sẵn sàng</span>
                <p class="text-xl font-bold text-emerald-700 dark:text-emerald-300">{{ $stats['on'] }}</p>
            </div>
            <div
                class="min-w-55 shrink-0 rounded-lg border border-red-200 bg-red-50 px-4 py-3 dark:border-red-800 dark:bg-red-950/30">
                <span class="text-xs text-red-600 dark:text-red-400">BDSC</span>
                <p class="text-xl font-bold text-red-700 dark:text-red-300">{{ $stats['bdsc'] }}</p>
            </div>
            <div
                class="min-w-55 shrink-0 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 dark:border-sky-800 dark:bg-sky-950/30">
                <span class="text-xs text-sky-600 dark:text-sky-400">Có chuyến {{ $trackingDateLabel }}</span>
                <p class="text-xl font-bold text-sky-700 dark:text-sky-300">{{ $stats['today_total'] }}</p>
            </div>
            <div
                class="min-w-55 shrink-0 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-800 dark:bg-rose-950/30">
                <span class="text-xs text-rose-600 dark:text-rose-400">Cảnh báo</span>
                <p class="text-xl font-bold text-rose-700 dark:text-rose-300">{{ $stats['alerts'] }}</p>
            </div>
        </div>

        @if ($token)
            <script>
                window.trackingMap = function (config) {
                    return {
                        map: null,
                        markers: [],
                        matchCache: {},
                        routeMatchingInProgress: false,
                        renderRetry: null,
                        skipRender: false,
                        attempts: 0,

                        alertFilter: 'all',
                        statusFilter: 'all',
                        todayFilter: 'all',
                        selectedVehicleId: null,
                        vehicles: config.vehicles || [],

                        // ─── debug helper ─────────────────────────────────────────────────────
                        log(label, data) {
                            const style = 'background:#1e40af;color:#fff;padding:2px 6px;border-radius:3px;font-weight:700';
                            if (data !== undefined) {
                                console.groupCollapsed(`%c[TMS] ${label}`, style);
                                console.log(data);
                                console.groupEnd();
                            } else {
                                console.log(`%c[TMS] ${label}`, style);
                            }
                        },

                        // ─── init ─────────────────────────────────────────────────────────────
                        init() {
                            this.log('init — vehicles loaded', {
                                total: this.vehicles.length,
                                vehicles: this.vehicles.map(v => ({
                                    id: v.id,
                                    plate: v.plate,
                                    status: v.status,
                                    route_points: v.route?.length ?? 0,
                                    has_alerts: v.has_alerts,
                                })),
                            });
                            this.waitForMapbox();
                        },

                        waitForMapbox() {
                            if (typeof mapboxgl !== 'undefined') { this.loadMap(); return; }
                            if (this.attempts++ < 100) setTimeout(() => this.waitForMapbox(), 100);
                        },

                        loadMap() {
                            const el = document.getElementById('tracking-map');
                            if (!el) { this.log('loadMap — #tracking-map not found'); return; }

                            mapboxgl.accessToken = config.token;
                            this.map = new mapboxgl.Map({
                                container: 'tracking-map',
                                style: 'mapbox://styles/mapbox/streets-v12',
                                center: [105.95, 21.125],
                                zoom: 11,
                            });
                            this.map.addControl(new mapboxgl.NavigationControl(), 'top-right');
                            this.map.on('load', () => {
                                this.log('map loaded');
                                this.ensureRouteLayers();
                                this.renderMap();
                                this.fitVisible();
                            });
                            this.map.on('idle', () => {
                                if (!this.skipRender) this.renderMap();
                            });
                            this.map.on('error', e => {
                                this.log('map error', e?.error?.message);
                                this.showMapError(e?.error?.message || 'Không tải được bản đồ');
                            });
                        },

                        // ─── filters ──────────────────────────────────────────────────────────
                        setStatusFilter(v) { this.statusFilter = v; this.syncSelection(); this.renderMap(); this.fitVisible(); },
                        setTodayFilter(v) { this.todayFilter = v; this.syncSelection(); this.renderMap(); this.fitVisible(); },
                        setAlertFilter(v) { this.alertFilter = v; this.syncSelection(); this.renderMap(); this.fitVisible(); },

                        syncSelection() {
                            if (!this.vehicles.find(v => v.id === this.selectedVehicleId && this.isVisible(v))) {
                                this.selectedVehicleId = null;
                            }
                        },

                        filteredVehicles() { return this.vehicles.filter(v => this.isVisible(v)); },

                        isVisible(vehicle) {
                            const statusOk = this.statusFilter === 'all' || vehicle.status === this.statusFilter;
                            const todayOk = this.todayFilter === 'all' || vehicle.today_category === this.todayFilter;
                            const alertOk = this.alertFilter === 'all'
                                || (this.alertFilter === 'alerts' && vehicle.has_alerts)
                                || (this.alertFilter === 'normal' && !vehicle.has_alerts);
                            return statusOk && todayOk && alertOk;
                        },

                        selectedVehicle() {
                            return this.filteredVehicles().find(v => v.id === this.selectedVehicleId) ?? null;
                        },

                        routeVehicle() {
                            const sel = this.selectedVehicle();
                            return sel?.route?.length >= 2 ? sel : null;
                        },

                        // ─── selection ────────────────────────────────────────────────────────
                        selectVehicle(vehicleId) {
                            this.selectedVehicleId = vehicleId;
                            const vehicle = this.vehicles.find(v => v.id === vehicleId);

                            this.log('selectVehicle', {
                                vehicleId,
                                plate: vehicle?.plate,
                                route_points: vehicle?.route?.length ?? 0,
                                route_raw: vehicle?.route,
                            });

                            this.renderMap();
                            this.skipRender = true;
                            setTimeout(() => { this.skipRender = false; }, 2500);
                            if (vehicle) this.focusVehicle(vehicle);
                        },

                        // ─── route layers ─────────────────────────────────────────────────────
                        ensureRouteLayers() {
                            if (!this.map || this.map.getSource('vehicle-routes')) return;
                            this.map.addSource('vehicle-routes', { type: 'geojson', data: this.emptyFC() });
                            this.map.addLayer({
                                id: 'vehicle-routes-line', type: 'line', source: 'vehicle-routes',
                                paint: { 'line-color': ['get', 'color'], 'line-width': 4, 'line-opacity': 0.85 },
                            });
                            this.map.addSource('vehicle-route-points', { type: 'geojson', data: this.emptyFC() });
                            this.map.addLayer({
                                id: 'vehicle-route-points-circle', type: 'circle', source: 'vehicle-route-points',
                                paint: {
                                    'circle-radius': 6, 'circle-stroke-width': 2, 'circle-stroke-color': '#ffffff',
                                    'circle-color': ['match', ['get', 'type'], 'start', '#22c55e', 'end', '#ef4444', '#3b82f6'],
                                },
                            });
                        },

                        // ─── render ───────────────────────────────────────────────────────────
                        async renderMap() {
                            if (!this.map || !this.map.isStyleLoaded()) {
                                clearTimeout(this.renderRetry);
                                this.renderRetry = setTimeout(() => this.renderMap(), 150);
                                return;
                            }

                            this.syncSelection();
                            this.clearMarkers();
                            this.ensureRouteLayers();

                            const routeFeatures = [];
                            const pointFeatures = [];
                            const rv = this.routeVehicle();

                            if (rv) {
                                const rawCoords = rv.route.map(p => [p.lng, p.lat]);
                                const dedupCoords = this.deduplicateCoords(rawCoords);

                                this.log('renderMap — route vehicle selected', {
                                    plate: rv.plate,
                                    order_code: rv.route_order_code,
                                    raw_points: rawCoords.length,
                                    dedup_points: dedupCoords.length,
                                    removed_dupes: rawCoords.length - dedupCoords.length,
                                    checkpoints: rv.route.map(p => ({
                                        type: p.checkpoint_type,
                                        lat: p.lat,
                                        lng: p.lng,
                                        occurred_at: p.occurred_at,
                                    })),
                                });

                                if (dedupCoords.length >= 2) {
                                    const resolved = await this.resolveRoute(dedupCoords);

                                    this.log('renderMap — resolved coords', {
                                        input_points: dedupCoords.length,
                                        output_points: resolved.length,
                                        first: resolved[0],
                                        last: resolved[resolved.length - 1],
                                    });

                                    routeFeatures.push({
                                        type: 'Feature',
                                        geometry: { type: 'LineString', coordinates: resolved },
                                        properties: { color: '#3b82f6', plate: rv.plate },
                                    });
                                    pointFeatures.push(
                                        this.pointFeature(rv, rv.route[0], 'start'),
                                        this.pointFeature(rv, rv.route[rv.route.length - 1], 'end'),
                                    );
                                } else {
                                    this.log('renderMap — skipped route (< 2 points after dedup)', { dedupCoords });
                                }
                            }

                            this.filteredVehicles().forEach(vehicle => {
                                const popup = new mapboxgl.Popup({ maxWidth: '360px', offset: 22 }).setHTML(this.popupHtml(vehicle));
                                const marker = new mapboxgl.Marker({ element: this.markerEl(vehicle) })
                                    .setLngLat([vehicle.lng, vehicle.lat])
                                    .setPopup(popup)
                                    .addTo(this.map);

                                marker.getElement().addEventListener('click', e => {
                                    e?.stopPropagation();
                                    this.selectedVehicleId = vehicle.id;
                                    this.renderMap();
                                    this.markers.forEach(m => { try { m.popup?.remove(); } catch { } });
                                    this.skipRender = true;
                                    setTimeout(() => { this.skipRender = false; }, 2500);
                                    try { this.openPopup(popup); } catch { marker.togglePopup?.(); }
                                    this.focusVehicle(vehicle);
                                });

                                this.markers.push({ marker, vehicleId: vehicle.id, popup });
                            });

                            this.map.getSource('vehicle-routes')?.setData({ type: 'FeatureCollection', features: routeFeatures });
                            this.map.getSource('vehicle-route-points')?.setData({ type: 'FeatureCollection', features: pointFeatures });
                        },

                        // ─── route resolution ─────────────────────────────────────────────────
                        async resolveRoute(coords) {
                            const key = JSON.stringify(coords);

                            if (this.matchCache[key]) {
                                this.log('resolveRoute — cache hit', { points: this.matchCache[key].length });
                                return this.matchCache[key];
                            }

                            if (this.routeMatchingInProgress) {
                                this.log('resolveRoute — request in progress, returning raw');
                                return coords;
                            }

                            this.routeMatchingInProgress = true;
                            this.log('resolveRoute — calling /mapbox/match', {
                                input_coords: coords,
                                count: coords.length,
                            });

                            try {
                                const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
                                const resp = await fetch('/mapbox/match', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': csrf,
                                        'X-Requested-With': 'XMLHttpRequest',
                                    },
                                    body: JSON.stringify({ coordinates: coords }),
                                });

                                this.log('resolveRoute — HTTP response', { status: resp.status, ok: resp.ok });

                                if (!resp.ok) {
                                    this.log('resolveRoute — non-OK, falling back to raw');
                                    return coords;
                                }

                                const data = await resp.json();
                                this.log('resolveRoute — server response', {
                                    matched: data.matched,
                                    method: data.method,
                                    error: data.error ?? null,
                                    coord_count: data.geometry?.coordinates?.length ?? 0,
                                    first: data.geometry?.coordinates?.[0],
                                    last: data.geometry?.coordinates?.slice(-1)?.[0],
                                });

                                const resolved = data?.geometry?.coordinates;
                                if (!resolved?.length) {
                                    this.log('resolveRoute — empty geometry, falling back to raw');
                                    return coords;
                                }

                                const final = (data.method === 'matching' && resolved.length > 50)
                                    ? this.simplify(resolved, 0.00005)
                                    : resolved;

                                this.log('resolveRoute — final coords', {
                                    before_simplify: resolved.length,
                                    after_simplify: final.length,
                                    method: data.method,
                                });

                                this.matchCache[key] = final;
                                return final;

                            } catch (err) {
                                this.log('resolveRoute — exception', { message: err.message, stack: err.stack });
                                return coords;
                            } finally {
                                this.routeMatchingInProgress = false;
                            }
                        },

                        // ─── camera ───────────────────────────────────────────────────────────
                        fitVisible() {
                            const vehicles = this.filteredVehicles();
                            if (!this.map || !vehicles.length) return;
                            const bounds = new mapboxgl.LngLatBounds();
                            vehicles.forEach(v => bounds.extend([v.lng, v.lat]));
                            const sel = this.selectedVehicle();
                            sel?.route?.forEach(p => bounds.extend([p.lng, p.lat]));
                            this.map.fitBounds(bounds, { maxZoom: 13, padding: 64 });
                        },

                        focusVehicle(vehicle) {
                            if (!this.map) return;
                            const bounds = new mapboxgl.LngLatBounds();
                            bounds.extend([vehicle.lng, vehicle.lat]);
                            vehicle.route?.forEach(p => bounds.extend([p.lng, p.lat]));
                            vehicle.route?.length >= 2
                                ? this.map.fitBounds(bounds, { maxZoom: 13, padding: 72 })
                                : this.map.flyTo({ center: [vehicle.lng, vehicle.lat], zoom: 13 });

                            setTimeout(() => {
                                this.markers.forEach(m => { try { m.popup?.remove(); } catch { } });
                                const item = this.markers.find(m => m.vehicleId === vehicle.id);
                                try { item?.popup ? this.openPopup(item.popup) : item?.marker?.togglePopup?.(); }
                                catch { item?.marker?.togglePopup?.(); }
                            }, 50);
                        },

                        // ─── markers & popups ─────────────────────────────────────────────────
                        clearMarkers() {
                            this.markers.forEach(({ marker, popup }) => {
                                try { popup?.remove(); } catch { }
                                try { marker?.remove(); } catch { }
                            });
                            this.markers = [];
                        },

                        markerEl(vehicle) {
                            const selected = vehicle.id === this.selectedVehicleId;
                            const alertRing = vehicle.has_alerts
                                ? '0 0 0 4px rgba(244,63,94,.26),0 8px 20px rgba(0,0,0,.28)'
                                : '0 8px 20px rgba(0,0,0,.24)';
                            const truckImg = '{{ asset('images/truck.png') }}';
                            const el = document.createElement('div');
                            el.style.cssText = [
                                'width:78px;height:78px',
                                'display:flex;align-items:center;justify-content:center;flex-direction:column',
                                'cursor:pointer;border-radius:10px;overflow:hidden;border:2px solid white;color:white',
                                `background:${this.statusColor(vehicle.status)}`,
                                `box-shadow:${alertRing}`,
                                selected ? 'outline:3px solid #2563eb;outline-offset:3px' : '',
                            ].filter(Boolean).join(';');
                            el.innerHTML = `<img src="${truckImg}" alt="" style="width:46px;height:46px;object-fit:contain"/>
                        <div style="margin-top:4px;background:rgba(0,0,0,.45);padding:2px 6px;border-radius:4px;font-size:11px;font-weight:700;white-space:nowrap">
                            ${this.esc(vehicle.plate || '')}
                        </div>`;
                            return el;
                        },

                        openPopup(popup) {
                            if (!popup || !this.map) return;
                            try {
                                popup.addTo(this.map);
                                const btn = popup.getElement?.()?.querySelector('.mapboxgl-popup-close-button')
                                    ?? document.querySelector('.mapboxgl-popup-close-button');
                                if (btn) { btn.removeAttribute('aria-hidden'); btn.tabIndex = 0; btn.focus?.(); }
                            } catch { }
                        },

                        popupHtml(vehicle) {
                            const alerts = vehicle.alerts || [];
                            const orders = vehicle.orders || [];
                            let h = `<div style="font-family:Inter,system-ui,sans-serif;min-width:280px">`;
                            h += `<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
                              <div>
                                  <div style="font-weight:800;font-size:15px">${this.esc(vehicle.plate)}</div>
                                  <div style="font-size:12px;color:#4b5563">${this.esc(vehicle.driver || 'Không lái')} &bull; ${this.esc(vehicle.vehicle_type_label || '')}</div>
                              </div>
                              <span style="border-radius:999px;background:${this.statusColor(vehicle.status)};color:white;font-size:11px;font-weight:700;padding:3px 8px">
                                  ${this.esc(vehicle.status_label || vehicle.status || '')}
                              </span>
                          </div>`;
                            h += `<div style="margin-top:8px;font-size:12px;color:#4b5563">Vị trí: ${this.esc(vehicle.position_source || '—')}</div>`;
                            if (vehicle.route_order_code) {
                                h += `<div style="margin-top:8px;border-top:1px solid #e5e7eb;padding-top:8px;font-size:12px">
                                  <div style="font-weight:700;color:#111827">Tuyến ${this.esc(vehicle.route_order_code)}</div>
                                  <div style="color:#4b5563">${this.esc(vehicle.route_start || '?')} &rarr; ${this.esc(vehicle.route_end || '?')}</div>
                                  <div style="color:#6b7280">${vehicle.route?.length || 0} GPS checkpoint</div>
                              </div>`;
                            }
                            if (alerts.length) {
                                h += `<div style="margin-top:8px;border-top:1px solid #fee2e2;padding-top:8px">`;
                                alerts.forEach(a => {
                                    h += `<div style="display:flex;gap:6px;margin-bottom:5px;font-size:12px;color:${a.level === 'danger' ? '#dc2626' : '#d97706'};font-weight:600">
                                      <span>!</span><span>${this.esc(a.label || '')}</span></div>`;
                                });
                                h += `</div>`;
                            }
                            if (orders.length) {
                                h += `<div style="margin-top:8px;border-top:1px solid #e5e7eb;padding-top:8px">`;
                                orders.forEach(o => {
                                    const meta = [o.total_packages ? `${o.total_packages} kiện` : '', o.total_weight ? `${o.total_weight} kg` : ''].filter(Boolean).join(' • ');
                                    h += `<div style="margin-bottom:8px">
                                      <div style="font-weight:700;font-size:13px;color:#111827">${this.esc(o.order_code || '—')}
                                          <span style="background:#f3f4f6;color:#374151;padding:2px 6px;border-radius:4px;font-size:11px;margin-left:6px">${this.esc(o.status_label || '')}</span>
                                      </div>
                                      <div style="font-size:12px;color:#4b5563">${this.esc(o.pickup || '—')} &rarr; ${this.esc(o.delivery || '—')}</div>
                                      ${meta ? `<div style="font-size:12px;color:#6b7280">${this.esc(meta)}</div>` : ''}
                                  </div>`;
                                });
                                h += `</div>`;
                            }
                            return h + `</div>`;
                        },

                        // ─── GeoJSON helpers ──────────────────────────────────────────────────
                        pointFeature(vehicle, point, type) {
                            return {
                                type: 'Feature',
                                geometry: { type: 'Point', coordinates: [point.lng, point.lat] },
                                properties: { plate: vehicle.plate, type },
                            };
                        },

                        emptyFC() { return { type: 'FeatureCollection', features: [] }; },

                        // ─── coordinate utils ─────────────────────────────────────────────────
                        deduplicateCoords(coords) {
                            return coords.filter((c, i) => {
                                if (i === 0) return true;
                                const p = coords[i - 1];
                                return Math.abs(c[0] - p[0]) > 0.0001 || Math.abs(c[1] - p[1]) > 0.0001;
                            });
                        },

                        simplify(coords, tolerance = 0.0001) {
                            if (coords.length < 3) return coords;
                            const dp = (pts, tol) => {
                                if (pts.length < 3) return pts;
                                let maxD = 0, maxI = 0;
                                const a = pts[0], b = pts[pts.length - 1];
                                for (let i = 1; i < pts.length - 1; i++) {
                                    const d = this.perpDist(pts[i], a, b);
                                    if (d > maxD) { maxD = d; maxI = i; }
                                }
                                if (maxD > tol) {
                                    return [...dp(pts.slice(0, maxI + 1), tol).slice(0, -1), ...dp(pts.slice(maxI), tol)];
                                }
                                return [a, b];
                            };
                            return dp(coords, tolerance);
                        },

                        perpDist([x0, y0], [x1, y1], [x2, y2]) {
                            if (x1 === x2 && y1 === y2) return Math.hypot(x0 - x1, y0 - y1);
                            return Math.abs((x2 - x1) * (y1 - y0) - (x1 - x0) * (y2 - y1)) / Math.hypot(x2 - x1, y2 - y1);
                        },

                        // ─── misc ─────────────────────────────────────────────────────────────
                        statusColor(status) {
                            return { running: '#f59e0b', on: '#22c55e', bdsc: '#ef4444', off: '#9ca3af' }[status] ?? '#64748b';
                        },

                        filterButtonClass(active) {
                            return active
                                ? 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900'
                                : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800';
                        },

                        esc(value) {
                            return String(value ?? '').replace(/[&<>"']/g, c =>
                                ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]));
                        },

                        showMapError(message) {
                            const el = document.getElementById('tracking-map-error');
                            if (el) { el.textContent = message; el.classList.remove('hidden'); }
                        },
                    };
                };
            </script>

            <style>
                /* Strong fallback layout if Tailwind classes are overridden elsewhere */
                @media (min-width: 1280px) {
                    .rt-tracking {
                        display: flex !important;
                        flex-direction: row !important;
                        flex-wrap: nowrap !important;
                        gap: 1rem !important;
                        align-items: flex-start !important;
                        width: 100% !important;
                        box-sizing: border-box !important;
                    }

                    .rt-tracking>* {
                        box-sizing: border-box !important;
                        min-width: 0 !important;
                    }

                    .rt-sidebar {
                        flex: 0 0 33.3333% !important;
                        max-width: 33.3333% !important;
                        width: 33.3333% !important;
                    }

                    .rt-main {
                        flex: 0 0 66.6666% !important;
                        max-width: 66.6666% !important;
                        width: 66.6666% !important;
                    }
                }
            </style>

            <div x-data="trackingMap({ vehicles: {{ \Illuminate\Support\Js::from($vehicles) }}, token: {{ \Illuminate\Support\Js::from($token) }} })"
                class="flex flex-col xl:flex-row gap-4 rt-tracking">
                <aside class="space-y-4 xl:w-1/3 rt-sidebar">
                    <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Bộ lọc</h2>
                            <span
                                class="rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300"
                                x-text="filteredVehicles().length + ' xe'"></span>
                        </div>

                        <div class="space-y-3">
                            <div>
                                <p class="mb-2 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Trạng
                                    thái xe</p>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" x-on:click="setStatusFilter('all')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(statusFilter === 'all')">Tất cả</button>
                                    <button type="button" x-on:click="setStatusFilter('running')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(statusFilter === 'running')">Đang chạy
                                        {{ $stats['running'] }}</button>
                                    <button type="button" x-on:click="setStatusFilter('on')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(statusFilter === 'on')">Sẵn sàng
                                        {{ $stats['on'] }}</button>
                                    <button type="button" x-on:click="setStatusFilter('bdsc')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(statusFilter === 'bdsc')">BDSC
                                        {{ $stats['bdsc'] }}</button>
                                    <button type="button" x-on:click="setStatusFilter('off')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(statusFilter === 'off')">Tắt
                                        {{ $stats['off'] }}</button>
                                </div>
                            </div>

                            <div>
                                <p class="mb-2 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Phân loại
                                    ngày {{ $trackingDateLabel }}</p>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" x-on:click="setTodayFilter('all')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(todayFilter === 'all')">Tất cả</button>
                                    <button type="button" x-on:click="setTodayFilter('planned_today')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(todayFilter === 'planned_today')">Có kế hoạch
                                        {{ $stats['today_planned'] }}</button>
                                    <button type="button" x-on:click="setTodayFilter('running_today')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(todayFilter === 'running_today')">Đang chạy
                                        {{ $stats['today_running'] }}</button>
                                    <button type="button" x-on:click="setTodayFilter('completed_today')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(todayFilter === 'completed_today')">Đã xong
                                        {{ $stats['today_completed'] }}</button>
                                    <button type="button" x-on:click="setTodayFilter('idle_today')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(todayFilter === 'idle_today')">Chưa có chuyến
                                        {{ $stats['today_idle'] }}</button>
                                </div>
                            </div>

                            <div>
                                <p class="mb-2 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Cảnh báo
                                </p>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" x-on:click="setAlertFilter('all')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(alertFilter === 'all')">Tất cả</button>
                                    <button type="button" x-on:click="setAlertFilter('alerts')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(alertFilter === 'alerts')">Có cảnh
                                        báo</button>
                                    <button type="button" x-on:click="setAlertFilter('normal')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(alertFilter === 'normal')">Bình
                                        thường</button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Xe trên bản đồ</h2>
                            <span
                                class="rounded-full bg-blue-100 px-2 py-1 text-xs font-semibold text-blue-700 dark:bg-blue-950/40 dark:text-blue-300"
                                x-text="filteredVehicles().length"></span>
                        </div>

                        <div class="max-h-96 overflow-y-auto pr-1 flex flex-col gap-2">
                            <template x-if="filteredVehicles().length === 0">
                                <p
                                    class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
                                    Không có xe phù hợp bộ lọc.</p>
                            </template>

                            <template x-for="vehicle in filteredVehicles()" x-bind:key="vehicle.id">
                                <button type="button" x-on:click="selectVehicle(vehicle.id)"
                                    class="w-full rounded-lg border p-3 text-left transition hover:bg-gray-50 dark:hover:bg-gray-800"
                                    x-bind:class="vehicle.id === selectedVehicleId ?
                                                    'border-blue-500 bg-blue-50 dark:border-blue-500 dark:bg-blue-950/30' :
                                                    'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900'">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <span class="truncate text-sm font-bold text-gray-900 dark:text-white"
                                                    x-text="vehicle.plate"></span>
                                                <span class="h-2.5 w-2.5 rounded-full"
                                                    x-bind:style="'background:' + statusColor(vehicle.status)"></span>
                                            </div>
                                            <p class="truncate text-xs text-gray-500 dark:text-gray-400"
                                                x-text="vehicle.driver + ' • ' + vehicle.vehicle_type_label"></p>
                                            <p class="truncate text-xs text-gray-500 dark:text-gray-400"
                                                x-show="vehicle.route_order_code"
                                                x-text="'Tuyến ' + vehicle.route_order_code + ' • ' + (vehicle.route?.length || 0) + ' GPS'">
                                            </p>
                                        </div>
                                        <div class="flex shrink-0 flex-col items-end gap-1">
                                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold text-white"
                                                x-bind:style="'background:' + statusColor(vehicle.status)"
                                                x-text="vehicle.status_label"></span>
                                            <span x-show="vehicle.has_alerts"
                                                class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-950/40 dark:text-rose-300"
                                                x-text="vehicle.alerts.length + ' cảnh báo'"></span>
                                        </div>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </section>
                </aside>

                <section class="space-y-3 xl:w-2/3 rt-main">
                    <div id="tracking-map" class="w-full rounded-xl border border-gray-200 dark:border-gray-700"
                        style="height: calc(100vh - 16rem); min-height: 520px;"></div>
                    <div id="tracking-map-error"
                        class="hidden rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">
                    </div>

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-gray-500 dark:text-gray-400">
                        <span class="inline-flex items-center gap-1.5"><span
                                class="h-3 w-3 rounded-full bg-emerald-500"></span> Sẵn sàng</span>
                        <span class="inline-flex items-center gap-1.5"><span
                                class="h-3 w-3 rounded-full bg-amber-500"></span> Đang chạy</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-full bg-red-500"></span>
                            BDSC</span>
                        <span class="inline-flex items-center gap-1.5"><span
                                class="h-3 w-3 rounded-full bg-gray-400"></span> Tắt</span>
                        <span class="inline-flex items-center gap-1.5"><span
                                class="h-3 w-8 rounded-full bg-blue-500"></span> Đường GPS checkpoint</span>
                        <span class="inline-flex items-center gap-1.5"><span
                                class="h-3 w-3 rounded-full bg-rose-500 ring-4 ring-rose-200"></span> Có cảnh
                            báo</span>
                    </div>
                </section>
            </div>
        @else
            <div
                class="flex items-center justify-center rounded-xl border-2 border-dashed border-gray-300 p-20 dark:border-gray-700">
                <div class="text-center">
                    <x-filament::icon icon="heroicon-o-map" class="mx-auto h-10 w-10 text-gray-400" />
                    <p class="mt-3 text-sm text-gray-500">Chưa cấu hình Mapbox token</p>
                    <p class="text-xs text-gray-400">Thêm MAPBOX_TOKEN vào .env</p>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>