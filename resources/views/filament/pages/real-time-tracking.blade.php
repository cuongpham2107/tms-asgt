<x-filament-panels::page>
    @php
        $vehicles = $this->getVehicles();
        $stats = $this->getStats();
        $token = $this->getMapboxToken();
        $trackingDateLabel = $this->getTrackingDateLabel();
    @endphp

    <div class="space-y-4">

        {{-- ── Stats bar ──────────────────────────────────────────────────────── --}}
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
                        // ── state ──────────────────────────────────────────────────
                        map: null,
                        markers: [],   // vehicle markers
                        checkpointMarkers: [],   // checkpoint annotation markers
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

                        // ── debug ──────────────────────────────────────────────────
                        log(label, data) {
                            const s = 'background:#1e40af;color:#fff;padding:2px 6px;border-radius:3px;font-weight:700';
                            data !== undefined
                                ? (console.groupCollapsed(`%c[TMS] ${label}`, s), console.log(data), console.groupEnd())
                                : console.log(`%c[TMS] ${label}`, s);
                        },

                        // ── init ───────────────────────────────────────────────────
                        init() {
                            this.log('init', { total: this.vehicles.length });
                            this.waitForMapbox();
                        },

                        waitForMapbox() {
                            if (typeof mapboxgl !== 'undefined') { this.loadMap(); return; }
                            if (this.attempts++ < 100) setTimeout(() => this.waitForMapbox(), 100);
                        },

                        loadMap() {
                            const el = document.getElementById('tracking-map');
                            if (!el) return;
                            mapboxgl.accessToken = config.token;
                            this.map = new mapboxgl.Map({
                                container: 'tracking-map',
                                style: 'mapbox://styles/mapbox/streets-v12',
                                center: [105.95, 21.125],
                                zoom: 11,
                            });
                            this.map.addControl(new mapboxgl.NavigationControl(), 'top-right');
                            this.map.on('load', () => {
                                this.ensureRouteLayers();
                                this.renderMap();
                                this.fitVisible();
                            });
                            this.map.on('idle', () => { if (!this.skipRender) this.renderMap(); });
                            this.map.on('error', e => this.showMapError(e?.error?.message || 'Không tải được bản đồ'));
                        },

                        // ── filters ────────────────────────────────────────────────
                        setStatusFilter(v) { this.statusFilter = v; this.syncSelection(); this.renderMap(); this.fitVisible(); },
                        setTodayFilter(v) { this.todayFilter = v; this.syncSelection(); this.renderMap(); this.fitVisible(); },
                        setAlertFilter(v) { this.alertFilter = v; this.syncSelection(); this.renderMap(); this.fitVisible(); },

                        syncSelection() {
                            if (!this.vehicles.find(v => v.id === this.selectedVehicleId && this.isVisible(v)))
                                this.selectedVehicleId = null;
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

                        // ── selection ──────────────────────────────────────────────
                        selectVehicle(vehicleId) {
                            this.selectedVehicleId = vehicleId;
                            const vehicle = this.vehicles.find(v => v.id === vehicleId);
                            this.log('selectVehicle', {
                                plate: vehicle?.plate,
                                checkpoints: vehicle?.checkpoints?.length ?? 0,
                                route_color: vehicle?.route_color,
                            });
                            this.renderMap();
                            this.skipRender = true;
                            setTimeout(() => { this.skipRender = false; }, 2500);
                            if (vehicle) this.focusVehicle(vehicle);
                        },

                        // ── Mapbox layers ──────────────────────────────────────────
                        ensureRouteLayers() {
                            if (!this.map || this.map.getSource('vehicle-routes')) return;

                            // Route line
                            this.map.addSource('vehicle-routes', { type: 'geojson', data: this.emptyFC() });
                            this.map.addLayer({
                                id: 'vehicle-routes-line',
                                type: 'line',
                                source: 'vehicle-routes',
                                paint: {
                                    'line-color': ['get', 'color'],
                                    'line-width': 4,
                                    'line-opacity': 0.85,
                                    'line-dasharray': ['case', ['get', 'dashed'], ['literal', [4, 3]], ['literal', [1, 0]]],
                                },
                            });

                            // Start / end circles
                            this.map.addSource('vehicle-route-points', { type: 'geojson', data: this.emptyFC() });
                            this.map.addLayer({
                                id: 'vehicle-route-points-circle',
                                type: 'circle',
                                source: 'vehicle-route-points',
                                paint: {
                                    'circle-radius': 7,
                                    'circle-stroke-width': 2,
                                    'circle-stroke-color': '#ffffff',
                                    'circle-color': ['match', ['get', 'type'],
                                        'start', '#22c55e',
                                        'end', '#ef4444',
                                        '#3b82f6',
                                    ],
                                },
                            });
                        },

                        // ── render ─────────────────────────────────────────────────
                        async renderMap() {
                            if (!this.map || !this.map.isStyleLoaded()) {
                                clearTimeout(this.renderRetry);
                                this.renderRetry = setTimeout(() => this.renderMap(), 150);
                                return;
                            }

                            this.syncSelection();
                            this.clearMarkers();               // vehicle markers
                            this.clearCheckpointMarkers();     // annotation markers
                            this.ensureRouteLayers();

                            const routeFeatures = [];
                            const pointFeatures = [];
                            const rv = this.routeVehicle();

                            if (rv) {
                                const checkpoints = rv.checkpoints ?? rv.route ?? [];
                                const rawCoords = checkpoints.map(p => [p.lng, p.lat]);
                                const dedupCoords = this.deduplicateCoords(rawCoords);
                                const isPlanned = !rv.route_color || rv.route_color === '#9ca3af';

                                this.log('renderMap — route', {
                                    plate: rv.plate,
                                    color: rv.route_color,
                                    checkpoints: checkpoints.length,
                                    dedup: dedupCoords.length,
                                });

                                if (dedupCoords.length >= 2) {
                                    // Chỉ gửi điểm đầu + cuối lên Directions API
                                    const endpointCoords = [dedupCoords[0], dedupCoords[dedupCoords.length - 1]];
                                    const resolved = isPlanned
                                        ? dedupCoords                             // kế hoạch: vẽ thẳng
                                        : await this.resolveRoute(endpointCoords);

                                    this.log('renderMap — resolved', {
                                        input: endpointCoords.length,
                                        output: resolved.length,
                                        method: isPlanned ? 'raw' : 'directions',
                                    });

                                    routeFeatures.push({
                                        type: 'Feature',
                                        geometry: { type: 'LineString', coordinates: resolved },
                                        properties: {
                                            color: rv.route_color ?? '#3b82f6',
                                            dashed: isPlanned,
                                            plate: rv.plate,
                                        },
                                    });

                                    // Start / end circle markers
                                    pointFeatures.push(
                                        this.pointFeature(rv, checkpoints[0], 'start'),
                                        this.pointFeature(rv, checkpoints[checkpoints.length - 1], 'end'),
                                    );

                                    // Checkpoint annotation markers (numbered)
                                    checkpoints.forEach((pt, idx) => {
                                        const isFirst = idx === 0;
                                        const isLast = idx === checkpoints.length - 1;
                                        const el = this.checkpointMarkerEl(pt, idx + 1, isFirst, isLast);
                                        const m = new mapboxgl.Marker({ element: el, anchor: 'bottom' })
                                            .setLngLat([pt.lng, pt.lat])
                                            .setPopup(
                                                new mapboxgl.Popup({ offset: 12, maxWidth: '260px' })
                                                    .setHTML(this.checkpointPopupHtml(pt))
                                            )
                                            .addTo(this.map);
                                        this.checkpointMarkers.push(m);
                                    });
                                }
                            }

                            // Vehicle markers
                            this.filteredVehicles().forEach(vehicle => {
                                const popup = new mapboxgl.Popup({ maxWidth: '380px', offset: 22 })
                                    .setHTML(this.popupHtml(vehicle));
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

                            this.map.getSource('vehicle-routes')?.setData(
                                { type: 'FeatureCollection', features: routeFeatures }
                            );
                            this.map.getSource('vehicle-route-points')?.setData(
                                { type: 'FeatureCollection', features: pointFeatures }
                            );
                        },

                        // ── route resolution ───────────────────────────────────────
                        async resolveRoute(coords) {
                            const key = JSON.stringify(coords);
                            if (this.matchCache[key]) return this.matchCache[key];
                            if (this.routeMatchingInProgress) return coords;

                            this.routeMatchingInProgress = true;
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

                                if (!resp.ok) return coords;

                                const data = await resp.json();
                                this.log('resolveRoute — server', {
                                    matched: data.matched,
                                    method: data.method,
                                    points: data.geometry?.coordinates?.length ?? 0,
                                });

                                const resolved = data?.geometry?.coordinates;
                                if (!resolved?.length) return coords;

                                const final = (data.method === 'matching' && resolved.length > 50)
                                    ? this.simplify(resolved, 0.00005)
                                    : resolved;

                                this.matchCache[key] = final;
                                return final;
                            } catch {
                                return coords;
                            } finally {
                                this.routeMatchingInProgress = false;
                            }
                        },

                        // ── camera ─────────────────────────────────────────────────
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

                        // ── markers ────────────────────────────────────────────────
                        clearMarkers() {
                            this.markers.forEach(({ marker, popup }) => {
                                try { popup?.remove(); } catch { }
                                try { marker?.remove(); } catch { }
                            });
                            this.markers = [];
                        },

                        clearCheckpointMarkers() {
                            this.checkpointMarkers.forEach(m => { try { m.remove(); } catch { } });
                            this.checkpointMarkers = [];
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

                        /**
                         * Checkpoint annotation marker — hình pin nhỏ có số thứ tự.
                         * Màu: xanh lá = điểm đầu, đỏ = điểm cuối, xanh dương = giữa.
                         */
                        checkpointMarkerEl(point, seq, isFirst, isLast) {
                            const bg = isFirst ? '#16a34a' : (isLast ? '#dc2626' : '#2563eb');
                            const el = document.createElement('div');
                            el.style.cssText = [
                                'display:flex;flex-direction:column;align-items:center',
                                'cursor:pointer',
                            ].join(';');
                            // Circle
                            const circle = document.createElement('div');
                            circle.style.cssText = [
                                'width:24px;height:24px;border-radius:50%',
                                `background:${bg}`,
                                'border:2.5px solid white',
                                'box-shadow:0 2px 8px rgba(0,0,0,.3)',
                                'display:flex;align-items:center;justify-content:center',
                                'color:white;font-size:11px;font-weight:700',
                            ].join(';');
                            circle.textContent = isFirst ? '▲' : (isLast ? '■' : seq);
                            // Stem
                            const stem = document.createElement('div');
                            stem.style.cssText = `width:2px;height:8px;background:${bg};opacity:.7`;
                            el.append(circle, stem);
                            return el;
                        },

                        // ── popups ─────────────────────────────────────────────────
                        openPopup(popup) {
                            if (!popup || !this.map) return;
                            try {
                                popup.addTo(this.map);
                                const btn = popup.getElement?.()?.querySelector('.mapboxgl-popup-close-button')
                                    ?? document.querySelector('.mapboxgl-popup-close-button');
                                if (btn) { btn.removeAttribute('aria-hidden'); btn.tabIndex = 0; btn.focus?.(); }
                            } catch { }
                        },

                        /** Popup nhỏ khi click vào checkpoint marker */
                        checkpointPopupHtml(pt) {
                            let h = `<div style="font-family:Inter,system-ui,sans-serif;font-size:12px;min-width:160px">`;
                            h += `<div style="font-weight:700;color:#111827;margin-bottom:4px">${this.esc(pt.label)}</div>`;
                            if (pt.occurred_at)
                                h += `<div style="color:#6b7280">🕐 ${this.esc(pt.occurred_at)}</div>`;
                            if (pt.km_reading != null)
                                h += `<div style="color:#6b7280">📍 ${pt.km_reading.toLocaleString()} km</div>`;
                            if (pt.voice_note)
                                h += `<div style="margin-top:4px;color:#374151;background:#f9fafb;border-radius:4px;padding:4px 6px;border-left:2px solid #3b82f6">
                                        🎤 ${this.esc(pt.voice_note)}</div>`;
                            if (pt.photo_count)
                                h += `<div style="margin-top:4px;color:#3b82f6">📷 ${pt.photo_count} ảnh</div>`;
                            return h + '</div>';
                        },

                        /** Popup lớn của vehicle marker */
                        popupHtml(vehicle) {
                            const alerts = vehicle.alerts || [];
                            const orders = vehicle.orders || [];
                            let h = `<div style="font-family:Inter,system-ui,sans-serif;min-width:300px;max-width:380px">`;

                            // Header: biển số + trạng thái
                            h += `<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
                                <div>
                                    <div style="font-weight:800;font-size:15px">${this.esc(vehicle.plate)}</div>
                                    <div style="font-size:12px;color:#4b5563">
                                        ${this.esc(vehicle.driver || 'Không lái')} &bull;
                                        ${this.esc(vehicle.vehicle_type_label || '')}
                                    </div>
                                </div>
                                <span style="border-radius:999px;background:${this.statusColor(vehicle.status)};
                                             color:white;font-size:11px;font-weight:700;padding:3px 10px;white-space:nowrap">
                                    ${this.esc(vehicle.status_label || '')}
                                </span>
                            </div>`;

                            h += `<div style="margin-top:6px;font-size:12px;color:#6b7280">
                                    Vị trí: ${this.esc(vehicle.position_source || '—')}
                                  </div>`;

                            // Cảnh báo
                            if (alerts.length) {
                                h += `<div style="margin-top:8px;border-top:1px solid #fee2e2;padding-top:8px">`;
                                alerts.forEach(a => {
                                    const c = a.level === 'danger' ? '#dc2626' : '#d97706';
                                    h += `<div style="display:flex;gap:5px;margin-bottom:4px;font-size:12px;color:${c};font-weight:600">
                                            <span>!</span><span>${this.esc(a.label)}</span>
                                          </div>`;
                                });
                                h += `</div>`;
                            }

                            // Đơn hàng + điểm giao
                            if (orders.length) {
                                h += `<div style="margin-top:8px;border-top:1px solid #e5e7eb;padding-top:8px">`;
                                orders.forEach(o => {
                                    const meta = [
                                        o.total_packages ? `${o.total_packages} kiện` : '',
                                        o.total_weight ? `${o.total_weight} kg` : '',
                                    ].filter(Boolean).join(' • ');

                                    const overdueTag = o.is_overdue
                                        ? `<span style="background:#fee2e2;color:#dc2626;padding:1px 6px;border-radius:4px;font-size:10px;margin-left:4px">Quá giờ</span>`
                                        : '';

                                    h += `<div style="margin-bottom:10px">
                                        <div style="font-weight:700;font-size:13px;color:#111827;display:flex;align-items:center;flex-wrap:wrap;gap:2px">
                                            ${this.esc(o.order_code || '—')}
                                            <span style="background:#f3f4f6;color:#374151;padding:2px 6px;border-radius:4px;font-size:11px;margin-left:4px">
                                                ${this.esc(o.status_label || '')}
                                            </span>
                                            ${overdueTag}
                                        </div>`;

                                    if (o.planned_loading_at)
                                        h += `<div style="font-size:11px;color:#9ca3af;margin-top:1px">Dự kiến: ${this.esc(o.planned_loading_at)}</div>`;

                                    if (meta)
                                        h += `<div style="font-size:12px;color:#6b7280">${this.esc(meta)}</div>`;

                                    // Điểm giao hàng timeline
                                    const dps = o.delivery_points ?? [];
                                    if (dps.length) {
                                        h += `<div style="margin-top:6px">`;
                                        dps.forEach(dp => {
                                            const dpBg = dp.status === 'delivered' ? '#16a34a'
                                                : dp.status === 'arrived' ? '#2563eb'
                                                    : '#9ca3af';
                                            const dpLabel = dp.status === 'delivered' ? 'Xong'
                                                : dp.status === 'arrived' ? 'Đến nơi'
                                                    : 'Chờ';
                                            h += `<div style="display:flex;align-items:center;gap:7px;padding:4px 0;border-bottom:1px solid #f3f4f6">
                                                <div style="display:flex;flex-direction:column;align-items:center;gap:0">
                                                    <div style="width:18px;height:18px;border-radius:50%;background:${dpBg};
                                                                border:2px solid white;box-shadow:0 0 0 1px ${dpBg};
                                                                color:white;font-size:9px;font-weight:700;
                                                                display:flex;align-items:center;justify-content:center;flex-shrink:0">
                                                        ${dp.sequence}
                                                    </div>
                                                </div>
                                                <div style="flex:1;min-width:0">
                                                    <div style="font-size:12px;color:#111827;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                                        ${this.esc(dp.name)}
                                                    </div>
                                                    ${dp.contact ? `<div style="font-size:11px;color:#6b7280">${this.esc(dp.contact)}</div>` : ''}
                                                </div>
                                                <span style="font-size:10px;font-weight:600;color:${dpBg};white-space:nowrap">${dpLabel}</span>
                                            </div>`;
                                        });
                                        h += `</div>`;
                                    } else {
                                        // Không có delivery_points, hiện pickup → delivery cũ
                                        h += `<div style="font-size:12px;color:#4b5563;margin-top:3px">
                                                ${this.esc(o.pickup || '—')} &rarr; ${this.esc(o.delivery || '—')}
                                              </div>`;
                                    }

                                    // Checkpoint mới nhất
                                    if (o.latest_checkpoint) {
                                        h += `<div style="margin-top:4px;font-size:11px;color:#2563eb">
                                                ✓ ${this.esc(o.latest_checkpoint)}
                                                ${o.latest_checkpoint_at ? `<span style="color:#9ca3af">· ${this.esc(o.latest_checkpoint_at)}</span>` : ''}
                                              </div>`;
                                    }

                                    h += `</div>`; // end order
                                });
                                h += `</div>`;
                            }

                            return h + '</div>';
                        },

                        // ── GeoJSON helpers ────────────────────────────────────────
                        pointFeature(vehicle, point, type) {
                            return {
                                type: 'Feature',
                                geometry: { type: 'Point', coordinates: [point.lng, point.lat] },
                                properties: { plate: vehicle.plate, type },
                            };
                        },

                        emptyFC() { return { type: 'FeatureCollection', features: [] }; },

                        // ── coord utils ────────────────────────────────────────────
                        deduplicateCoords(coords) {
                            return coords.filter((c, i) => {
                                if (i === 0) return true;
                                const p = coords[i - 1];
                                return Math.abs(c[0] - p[0]) > 0.0001 || Math.abs(c[1] - p[1]) > 0.0001;
                            });
                        },

                        simplify(coords, tol = 0.0001) {
                            if (coords.length < 3) return coords;
                            const dp = (pts, t) => {
                                if (pts.length < 3) return pts;
                                let maxD = 0, maxI = 0;
                                const a = pts[0], b = pts[pts.length - 1];
                                for (let i = 1; i < pts.length - 1; i++) {
                                    const d = this.perpDist(pts[i], a, b);
                                    if (d > maxD) { maxD = d; maxI = i; }
                                }
                                return maxD > t
                                    ? [...dp(pts.slice(0, maxI + 1), t).slice(0, -1), ...dp(pts.slice(maxI), t)]
                                    : [a, b];
                            };
                            return dp(coords, tol);
                        },

                        perpDist([x0, y0], [x1, y1], [x2, y2]) {
                            if (x1 === x2 && y1 === y2) return Math.hypot(x0 - x1, y0 - y1);
                            return Math.abs((x2 - x1) * (y1 - y0) - (x1 - x0) * (y2 - y1)) / Math.hypot(x2 - x1, y2 - y1);
                        },

                        // ── misc ───────────────────────────────────────────────────
                        statusColor(status) {
                            return { running: '#f59e0b', on: '#22c55e', bdsc: '#ef4444', off: '#9ca3af' }[status] ?? '#64748b';
                        },

                        filterButtonClass(active) {
                            return active
                                ? 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900'
                                : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800';
                        },

                        esc(v) {
                            return String(v ?? '').replace(/[&<>"']/g,
                                c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]));
                        },

                        showMapError(msg) {
                            const el = document.getElementById('tracking-map-error');
                            if (el) { el.textContent = msg; el.classList.remove('hidden'); }
                        },
                    };
                };
            </script>

            <style>
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

                {{-- ── Sidebar ──────────────────────────────────────────────── --}}
                <aside class="space-y-4 xl:w-1/3 rt-sidebar">

                    {{-- Bộ lọc --}}
                    <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Bộ lọc</h2>
                            <span
                                class="rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300"
                                x-text="filteredVehicles().length + ' xe'"></span>
                        </div>
                        <div class="space-y-3">
                            <div>
                                <p class="mb-2 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Trạng thái xe
                                </p>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" x-on:click="setStatusFilter('all')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(statusFilter==='all')">Tất cả</button>
                                    <button type="button" x-on:click="setStatusFilter('running')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(statusFilter==='running')">Đang chạy
                                        {{ $stats['running'] }}</button>
                                    <button type="button" x-on:click="setStatusFilter('on')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(statusFilter==='on')">Sẵn sàng
                                        {{ $stats['on'] }}</button>
                                    <button type="button" x-on:click="setStatusFilter('bdsc')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(statusFilter==='bdsc')">BDSC
                                        {{ $stats['bdsc'] }}</button>
                                    <button type="button" x-on:click="setStatusFilter('off')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(statusFilter==='off')">Tắt
                                        {{ $stats['off'] }}</button>
                                </div>
                            </div>
                            <div>
                                <p class="mb-2 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Phân loại
                                    ngày {{ $trackingDateLabel }}</p>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" x-on:click="setTodayFilter('all')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(todayFilter==='all')">Tất cả</button>
                                    <button type="button" x-on:click="setTodayFilter('planned_today')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(todayFilter==='planned_today')">Kế hoạch
                                        {{ $stats['today_planned'] }}</button>
                                    <button type="button" x-on:click="setTodayFilter('running_today')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(todayFilter==='running_today')">Đang chạy
                                        {{ $stats['today_running'] }}</button>
                                    <button type="button" x-on:click="setTodayFilter('completed_today')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(todayFilter==='completed_today')">Đã xong
                                        {{ $stats['today_completed'] }}</button>
                                    <button type="button" x-on:click="setTodayFilter('idle_today')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(todayFilter==='idle_today')">Chưa có
                                        {{ $stats['today_idle'] }}</button>
                                </div>
                            </div>
                            <div>
                                <p class="mb-2 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Cảnh báo</p>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" x-on:click="setAlertFilter('all')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(alertFilter==='all')">Tất cả</button>
                                    <button type="button" x-on:click="setAlertFilter('alerts')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(alertFilter==='alerts')">Có cảnh báo</button>
                                    <button type="button" x-on:click="setAlertFilter('normal')"
                                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                        x-bind:class="filterButtonClass(alertFilter==='normal')">Bình thường</button>
                                </div>
                            </div>
                        </div>
                    </section>

                    {{-- Danh sách xe --}}
                    <section class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Xe trên bản đồ</h2>
                            <span
                                class="rounded-full bg-blue-100 px-2 py-1 text-xs font-semibold text-blue-700 dark:bg-blue-950/40 dark:text-blue-300"
                                x-text="filteredVehicles().length"></span>
                        </div>
                        <div class="max-h-72 overflow-y-auto pr-1 flex flex-col gap-2">
                            <template x-if="filteredVehicles().length === 0">
                                <p
                                    class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
                                    Không có xe phù hợp bộ lọc.</p>
                            </template>
                            <template x-for="vehicle in filteredVehicles()" x-bind:key="vehicle.id">
                                <button type="button" x-on:click="selectVehicle(vehicle.id)"
                                    class="w-full rounded-lg border p-3 text-left transition hover:bg-gray-50 dark:hover:bg-gray-800"
                                    x-bind:class="vehicle.id === selectedVehicleId
                                            ? 'border-blue-500 bg-blue-50 dark:border-blue-500 dark:bg-blue-950/30'
                                            : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900'">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <span class="truncate text-sm font-bold text-gray-900 dark:text-white"
                                                    x-text="vehicle.plate"></span>
                                                <span class="h-2.5 w-2.5 rounded-full"
                                                    x-bind:style="'background:' + statusColor(vehicle.status)"></span>
                                                {{-- Quá giờ badge --}}
                                                <template x-if="vehicle.orders?.some(o => o.is_overdue)">
                                                    <span
                                                        class="rounded-full bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-950/40 dark:text-red-300">Quá
                                                        giờ</span>
                                                </template>
                                            </div>
                                            <p class="truncate text-xs text-gray-500 dark:text-gray-400"
                                                x-text="vehicle.driver + ' • ' + vehicle.vehicle_type_label"></p>
                                            <p class="truncate text-xs text-gray-500 dark:text-gray-400"
                                                x-show="vehicle.route_order_code"
                                                x-text="'Tuyến ' + vehicle.route_order_code + ' • ' + (vehicle.checkpoints?.length || 0) + ' mốc'">
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

                    {{-- Route detail panel — chỉ hiện khi chọn xe --}}
                    <section x-show="selectedVehicle()" x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        class="rounded-lg border border-blue-200 bg-white p-4 dark:border-blue-800 dark:bg-gray-900">
                        <template x-if="selectedVehicle()">
                            <div>
                                <div class="mb-3 flex items-center justify-between gap-2">
                                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                                        Tuyến đường —
                                        <span x-text="selectedVehicle()?.plate"
                                            class="text-blue-600 dark:text-blue-400"></span>
                                    </h2>
                                    <button type="button" x-on:click="selectedVehicleId = null; renderMap()"
                                        class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>

                                {{-- Checkpoints timeline --}}
                                <template x-if="selectedVehicle()?.checkpoints?.length">
                                    <div class="mb-4">
                                        <p class="mb-2 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Mốc
                                            thực hiện</p>
                                        <div class="space-y-1">
                                            <template x-for="(pt, idx) in selectedVehicle().checkpoints" x-bind:key="idx">
                                                <div class="flex items-start gap-2">
                                                    {{-- dot --}}
                                                    <div class="mt-0.5 flex flex-col items-center">
                                                        <div class="flex h-5 w-5 items-center justify-center rounded-full text-white text-xs font-bold"
                                                            x-bind:style="'background:' + (idx===0 ? '#16a34a' : idx===selectedVehicle().checkpoints.length-1 ? '#dc2626' : '#2563eb')">
                                                            <span
                                                                x-text="idx===0 ? '▲' : idx===selectedVehicle().checkpoints.length-1 ? '■' : idx+1"></span>
                                                        </div>
                                                        <div class="w-px flex-1 bg-gray-200 dark:bg-gray-700"
                                                            x-show="idx < selectedVehicle().checkpoints.length - 1"
                                                            style="min-height:12px"></div>
                                                    </div>
                                                    {{-- info --}}
                                                    <div class="pb-2 min-w-0">
                                                        <p class="text-xs font-semibold text-gray-900 dark:text-white"
                                                            x-text="pt.label"></p>
                                                        <p class="text-xs text-gray-500 dark:text-gray-400"
                                                            x-show="pt.occurred_at" x-text="pt.occurred_at"></p>
                                                        <p class="text-xs text-gray-400" x-show="pt.km_reading"
                                                            x-text="'📍 ' + (pt.km_reading?.toLocaleString() ?? '') + ' km'">
                                                        </p>
                                                        <p class="mt-0.5 rounded bg-blue-50 px-1.5 py-0.5 text-xs text-blue-700 dark:bg-blue-950/30 dark:text-blue-300"
                                                            x-show="pt.voice_note" x-text="'🎤 ' + pt.voice_note"></p>
                                                        <p class="text-xs text-blue-500" x-show="pt.photo_count"
                                                            x-text="'📷 ' + pt.photo_count + ' ảnh'"></p>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </template>

                                {{-- Delivery points của đơn chính --}}
                                <template x-if="selectedVehicle()?.orders?.[0]?.delivery_points?.length">
                                    <div>
                                        <p class="mb-2 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Điểm
                                            giao hàng</p>
                                        <div class="space-y-1">
                                            <template x-for="dp in selectedVehicle().orders[0].delivery_points"
                                                x-bind:key="dp.sequence">
                                                <div class="flex items-center gap-2 rounded-lg px-2 py-1.5"
                                                    x-bind:class="dp.status==='delivered' ? 'bg-green-50 dark:bg-green-950/20' : dp.status==='arrived' ? 'bg-blue-50 dark:bg-blue-950/20' : 'bg-gray-50 dark:bg-gray-800'">
                                                    <div class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-white text-xs font-bold"
                                                        x-bind:style="'background:' + (dp.status==='delivered' ? '#16a34a' : dp.status==='arrived' ? '#2563eb' : '#9ca3af')">
                                                        <span x-text="dp.sequence"></span>
                                                    </div>
                                                    <div class="min-w-0 flex-1">
                                                        <p class="truncate text-xs font-medium text-gray-900 dark:text-white"
                                                            x-text="dp.name"></p>
                                                        <p class="text-xs text-gray-500" x-show="dp.contact"
                                                            x-text="dp.contact"></p>
                                                    </div>
                                                    <span class="shrink-0 text-xs font-semibold"
                                                        x-bind:class="dp.status==='delivered' ? 'text-green-600' : dp.status==='arrived' ? 'text-blue-600' : 'text-gray-400'"
                                                        x-text="dp.status==='delivered' ? 'Xong' : dp.status==='arrived' ? 'Đến nơi' : 'Chờ'"></span>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </section>

                </aside>

                {{-- ── Main: bản đồ ─────────────────────────────────────────── --}}
                <section class="space-y-3 xl:w-2/3 rt-main">
                    <div id="tracking-map" class="w-full rounded-xl border border-gray-200 dark:border-gray-700"
                        style="height: calc(100vh - 16rem); min-height: 520px;"></div>

                    <div id="tracking-map-error"
                        class="hidden rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">
                    </div>

                    {{-- Legend --}}
                    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-gray-500 dark:text-gray-400">
                        <span class="inline-flex items-center gap-1.5"><span
                                class="h-3 w-3 rounded-full bg-emerald-500"></span> Sẵn sàng</span>
                        <span class="inline-flex items-center gap-1.5"><span
                                class="h-3 w-3 rounded-full bg-amber-500"></span> Đang chạy</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-full bg-red-500"></span>
                            BDSC</span>
                        <span class="inline-flex items-center gap-1.5"><span
                                class="h-3 w-3 rounded-full bg-gray-400"></span> Tắt</span>
                        <span class="inline-flex items-center gap-1.5"><span
                                class="inline-block h-1 w-7 rounded bg-blue-500"></span> Đang chạy</span>
                        <span class="inline-flex items-center gap-1.5"><span
                                class="inline-block h-1 w-7 rounded bg-green-500"></span> Hoàn thành</span>
                        <span class="inline-flex items-center gap-1.5"><span
                                class="inline-block h-1 w-7 rounded bg-red-500"></span> Quá giờ</span>
                        <span class="inline-flex items-center gap-1.5">
                            <span class="inline-block h-1 w-7 rounded bg-gray-400"
                                style="border-top:2px dashed #9ca3af;height:0;background:none"></span> Kế hoạch
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <span class="flex h-4 w-4 items-center justify-center rounded-full bg-green-600 text-white"
                                style="font-size:8px">▲</span> Xuất phát
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <span class="flex h-4 w-4 items-center justify-center rounded-full bg-blue-600 text-white"
                                style="font-size:8px">2</span> Mốc giữa
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <span class="flex h-4 w-4 items-center justify-center rounded-full bg-red-600 text-white"
                                style="font-size:8px">■</span> Kết thúc
                        </span>
                        <span class="inline-flex items-center gap-1.5"><span
                                class="h-3 w-3 rounded-full bg-rose-500 ring-4 ring-rose-200"></span> Có cảnh báo</span>
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