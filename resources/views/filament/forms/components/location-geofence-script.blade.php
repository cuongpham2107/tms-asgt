<script>
    if (typeof window.locationGeofenceMap === 'undefined') {
        window.locationGeofenceMap = function($wire, config, defaultRadius) {
            const base = (typeof window.leafletMapField === 'function')
                ? window.leafletMapField($wire, config)
                : {};

            return {
                ...base,
                defaultRadius: defaultRadius || 3000,
                geofenceType: 'circle',
                geofenceCircle: null,
                geofenceRect: null,
                cornerHandles: [],
                rectBounds: null, // { south, west, north, east }
                isDrawingBox: false,
                drawStartLatLng: null,
                dimensionSummary: '',

                init() {
                    if (base.init) {
                        base.init.call(this);
                    }

                    this.setupGeofence();
                },

                resolveFieldPath(fieldName) {
                    return this.config?.state?.statePath
                        ? this.config.state.statePath.replace(/\.coordinates$/, '.' + fieldName)
                        : 'data.' + fieldName;
                },

                getWireValue(fieldName) {
                    if (!this.$wire) return null;
                    const path = this.resolveFieldPath(fieldName);
                    return this.$wire.get(path) ?? this.$wire.get('data.' + fieldName);
                },

                setWireValue(fieldName, value) {
                    if (!this.$wire) return;
                    const path = this.resolveFieldPath(fieldName);
                    try {
                        this.$wire.set(path, value, false);
                    } catch (e) {}
                },

                getGeofenceRadius() {
                    const root = this.$el.closest('.fi-modal, form') || document;
                    const input = root.querySelector('input[data-geofence-radius-input], input[name$="geofence_radius_m"], [wire\\:model*="geofence_radius_m"]');
                    if (input && input.value !== '') {
                        const val = parseFloat(input.value);
                        if (!isNaN(val) && val > 0) return val;
                    }

                    const wireVal = this.getWireValue('geofence_radius_m');
                    if (wireVal !== undefined && wireVal !== null && wireVal !== '') {
                        const val = parseFloat(wireVal);
                        if (!isNaN(val) && val > 0) return val;
                    }

                    return this.defaultRadius;
                },

                getCurrentCenterLatLng() {
                    if (this.pickMarker) {
                        try {
                            const pos = Alpine.raw(this.pickMarker).getLatLng();
                            if (pos && typeof pos.lat === 'number' && typeof pos.lng === 'number') {
                                return [pos.lat, pos.lng];
                            }
                        } catch (e) {}
                    }

                    const state = this.getState ? this.getState() : null;
                    if (state) {
                        if (typeof state.lat === 'number' && typeof state.lng === 'number') {
                            return [state.lat, state.lng];
                        }
                        if (Array.isArray(state) && state.length >= 2) {
                            return [parseFloat(state[0]), parseFloat(state[1])];
                        }
                    }

                    if (this.config?.state?.state) {
                        const s = this.config.state.state;
                        if (s.lat && s.lng) {
                            return [parseFloat(s.lat), parseFloat(s.lng)];
                        }
                    }

                    const map = this.mapCore ? Alpine.raw(this.mapCore.map) : null;
                    if (map) {
                        const c = map.getCenter();
                        return [c.lat, c.lng];
                    }

                    return [21.0285, 105.8542];
                },

                metersToLatDelta(meters) {
                    return meters / 111320;
                },

                metersToLngDelta(meters, lat) {
                    const cosLat = Math.cos((lat * Math.PI) / 180);
                    return meters / (111320 * Math.max(0.1, cosLat));
                },

                haversineMeters(lat1, lng1, lat2, lng2) {
                    const R = 6371000;
                    const dLat = ((lat2 - lat1) * Math.PI) / 180;
                    const dLng = ((lng2 - lng1) * Math.PI) / 180;
                    const a =
                        Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                        Math.cos((lat1 * Math.PI) / 180) *
                            Math.cos((lat2 * Math.PI) / 180) *
                            Math.sin(dLng / 2) *
                            Math.sin(dLng / 2);
                    return Math.round(2 * R * Math.asin(Math.min(1, Math.sqrt(a))));
                },

                createDefaultBoundsAroundCenter(centerLat, centerLng, isSquare = false) {
                    const baseR = Math.min(2000, Math.max(200, this.getGeofenceRadius() || 500));
                    const halfHeightM = baseR;
                    const halfWidthM = isSquare ? baseR : Math.round(baseR * 1.35);

                    const dLat = this.metersToLatDelta(halfHeightM);
                    const dLng = this.metersToLngDelta(halfWidthM, centerLat);

                    return {
                        south: Number((centerLat - dLat).toFixed(7)),
                        west: Number((centerLng - dLng).toFixed(7)),
                        north: Number((centerLat + dLat).toFixed(7)),
                        east: Number((centerLng + dLng).toFixed(7)),
                    };
                },

                normalizeBounds(b) {
                    if (!b || typeof b !== 'object') return null;
                    const s = parseFloat(b.south);
                    const w = parseFloat(b.west);
                    const n = parseFloat(b.north);
                    const e = parseFloat(b.east);
                    if (isNaN(s) || isNaN(w) || isNaN(n) || isNaN(e)) return null;
                    const south = Math.min(s, n);
                    const north = Math.max(s, n);
                    const west = Math.min(w, e);
                    const east = Math.max(w, e);
                    if (north - south < 0.0001 || east - west < 0.0001) return null;
                    return {
                        south: Number(south.toFixed(7)),
                        west: Number(west.toFixed(7)),
                        north: Number(north.toFixed(7)),
                        east: Number(east.toFixed(7)),
                    };
                },

                computeCornerBounds(anchorLat, anchorLng, targetLat, targetLng, forceSquare = false) {
                    let endLat = targetLat;
                    let endLng = targetLng;

                    if (forceSquare) {
                        const midLat = (anchorLat + targetLat) / 2;
                        const heightM = this.haversineMeters(anchorLat, anchorLng, targetLat, anchorLng);
                        const widthM = this.haversineMeters(midLat, anchorLng, midLat, targetLng);
                        const sideM = Math.max(100, Math.max(heightM, widthM));

                        const latSign = targetLat >= anchorLat ? 1 : -1;
                        const lngSign = targetLng >= anchorLng ? 1 : -1;

                        endLat = anchorLat + latSign * this.metersToLatDelta(sideM);
                        endLng = anchorLng + lngSign * this.metersToLngDelta(sideM, anchorLat);
                    }

                    return this.normalizeBounds({
                        south: Math.min(anchorLat, endLat),
                        north: Math.max(anchorLat, endLat),
                        west: Math.min(anchorLng, endLng),
                        east: Math.max(anchorLng, endLng),
                    });
                },

                updateDimensionSummary() {
                    if (this.geofenceType === 'circle') {
                        const r = this.getGeofenceRadius();
                        this.dimensionSummary = `Bán kính: ${r.toLocaleString('vi-VN')} m`;
                        return;
                    }

                    if (!this.rectBounds) {
                        this.dimensionSummary = 'Chưa có khung';
                        return;
                    }

                    const b = this.rectBounds;
                    const midLat = (b.south + b.north) / 2;
                    const midLng = (b.west + b.east) / 2;
                    const widthM = this.haversineMeters(midLat, b.west, midLat, b.east);
                    const heightM = this.haversineMeters(b.south, midLng, b.north, midLng);

                    if (this.geofenceType === 'square') {
                        this.dimensionSummary = `Hình vuông: ${widthM.toLocaleString('vi-VN')}m × ${heightM.toLocaleString('vi-VN')}m`;
                    } else {
                        this.dimensionSummary = `Hình chữ nhật: Rộng ${widthM.toLocaleString('vi-VN')}m × Dài ${heightM.toLocaleString('vi-VN')}m`;
                    }
                },

                setGeofenceType(type, syncToWire = true) {
                    const normalized = ['circle', 'rectangle', 'square'].includes(type) ? type : 'circle';
                    this.geofenceType = normalized;
                    this.isDrawingBox = false;

                    if (syncToWire) {
                        this.setWireValue('geofence_type', normalized);
                    }

                    if (normalized === 'rectangle' || normalized === 'square') {
                        const center = this.getCurrentCenterLatLng();
                        if (!this.rectBounds) {
                            const wireBounds = this.normalizeBounds(this.getWireValue('geofence_bounds'));
                            this.rectBounds = wireBounds || this.createDefaultBoundsAroundCenter(center[0], center[1], normalized === 'square');
                        } else if (normalized === 'square') {
                            const b = this.rectBounds;
                            const cLat = (b.south + b.north) / 2;
                            const cLng = (b.west + b.east) / 2;
                            const widthM = this.haversineMeters(cLat, b.west, cLat, b.east);
                            const heightM = this.haversineMeters(b.south, cLng, b.north, cLng);
                            const halfSideM = Math.max(100, Math.round((widthM + heightM) / 4));
                            const dLat = this.metersToLatDelta(halfSideM);
                            const dLng = this.metersToLngDelta(halfSideM, cLat);
                            this.rectBounds = this.normalizeBounds({
                                south: cLat - dLat,
                                north: cLat + dLat,
                                west: cLng - dLng,
                                east: cLng + dLng,
                            });
                        }

                        if (syncToWire && this.rectBounds) {
                            this.setWireValue('geofence_bounds', this.rectBounds);
                        }
                    }

                    this.updateGeofenceCircle();
                },

                toggleDrawMode() {
                    this.isDrawingBox = !this.isDrawingBox;
                    const map = this.mapCore ? Alpine.raw(this.mapCore.map) : null;
                    if (!map) return;

                    const container = map.getContainer();
                    if (this.isDrawingBox) {
                        container.style.cursor = 'crosshair';
                    } else {
                        container.style.cursor = '';
                        map.dragging.enable();
                    }
                },

                clearCircleLayer(map) {
                    if (this.geofenceCircle) {
                        try {
                            Alpine.raw(this.geofenceCircle).removeFrom(map);
                        } catch (e) {}
                        this.geofenceCircle = null;
                    }
                },

                clearRectangleLayers(map) {
                    if (this.geofenceRect) {
                        try {
                            Alpine.raw(this.geofenceRect).removeFrom(map);
                        } catch (e) {}
                        this.geofenceRect = null;
                    }
                    if (this.cornerHandles && this.cornerHandles.length) {
                        this.cornerHandles.forEach((h) => {
                            try {
                                Alpine.raw(h).removeFrom(map);
                            } catch (e) {}
                        });
                        this.cornerHandles = [];
                    }
                },

                renderRectangleAndHandles(map) {
                    if (!this.rectBounds || typeof window.L === 'undefined') return;

                    const b = this.rectBounds;
                    const boundsArr = [
                        [b.south, b.west],
                        [b.north, b.east],
                    ];

                    if (!this.geofenceRect) {
                        const rect = window.L.rectangle(boundsArr, {
                            color: '#059669',
                            weight: 2.5,
                            opacity: 0.9,
                            fillColor: '#10b981',
                            fillOpacity: 0.18,
                            dashArray: '6, 6',
                            interactive: false,
                        });
                        Alpine.raw(rect).addTo(map);
                        this.geofenceRect = rect;
                    } else {
                        const rect = Alpine.raw(this.geofenceRect);
                        rect.setBounds(boundsArr);
                        if (!map.hasLayer(rect)) {
                            rect.addTo(map);
                        }
                    }

                    const corners = [
                        { key: 'nw', lat: b.north, lng: b.west, oppLat: b.south, oppLng: b.east },
                        { key: 'ne', lat: b.north, lng: b.east, oppLat: b.south, oppLng: b.west },
                        { key: 'se', lat: b.south, lng: b.east, oppLat: b.north, oppLng: b.west },
                        { key: 'sw', lat: b.south, lng: b.west, oppLat: b.north, oppLng: b.east },
                    ];

                    if (this.cornerHandles.length !== 4) {
                        this.cornerHandles.forEach((h) => {
                            try {
                                Alpine.raw(h).removeFrom(map);
                            } catch (e) {}
                        });
                        this.cornerHandles = [];

                        corners.forEach((c, idx) => {
                            const icon = window.L.divIcon({
                                className: 'geofence-corner-handle',
                                html: '<div style="width:12px;height:12px;background:#ffffff;border:2.5px solid #059669;border-radius:3px;box-shadow:0 1px 3px rgba(0,0,0,0.35);cursor:nwse-resize;"></div>',
                                iconSize: [12, 12],
                                iconAnchor: [6, 6],
                            });

                            const handle = window.L.marker([c.lat, c.lng], {
                                icon: icon,
                                draggable: true,
                                zIndexOffset: 1000,
                            });

                            handle.on('dragstart', () => {
                                const cur = this.rectBounds;
                                if (!cur) return;
                                handle.__anchor = idx === 0
                                    ? [cur.south, cur.east]
                                    : idx === 1
                                    ? [cur.south, cur.west]
                                    : idx === 2
                                    ? [cur.north, cur.west]
                                    : [cur.north, cur.east];
                            });

                            handle.on('drag', (ev) => {
                                const pos = ev.target.getLatLng();
                                const anchor = handle.__anchor;
                                if (!anchor) return;
                                const nextBounds = this.computeCornerBounds(
                                    anchor[0],
                                    anchor[1],
                                    pos.lat,
                                    pos.lng,
                                    this.geofenceType === 'square'
                                );
                                if (nextBounds) {
                                    this.rectBounds = nextBounds;
                                    this.renderRectangleAndHandles(map);
                                    this.updateDimensionSummary();
                                }
                            });

                            handle.on('dragend', () => {
                                if (this.rectBounds) {
                                    this.setWireValue('geofence_bounds', this.rectBounds);
                                    this.updateDimensionSummary();
                                }
                            });

                            Alpine.raw(handle).addTo(map);
                            this.cornerHandles.push(handle);
                        });
                    } else {
                        corners.forEach((c, idx) => {
                            const h = Alpine.raw(this.cornerHandles[idx]);
                            if (h) {
                                h.setLatLng([c.lat, c.lng]);
                                if (!map.hasLayer(h)) {
                                    h.addTo(map);
                                }
                            }
                        });
                    }
                },

                updateGeofenceCircle(overrideRadius, overrideLatLng) {
                    const map = this.mapCore ? Alpine.raw(this.mapCore.map) : null;
                    if (!map) return;

                    let latLng = overrideLatLng;
                    if (!latLng && this.pickMarker) {
                        try {
                            latLng = Alpine.raw(this.pickMarker).getLatLng();
                        } catch (e) {}
                    }
                    if (!latLng) {
                        const state = this.getState ? this.getState() : null;
                        if (state) {
                            if (typeof state.lat === 'number' && typeof state.lng === 'number') {
                                latLng = [state.lat, state.lng];
                            } else if (Array.isArray(state) && state.length >= 2) {
                                latLng = [parseFloat(state[0]), parseFloat(state[1])];
                            }
                        }
                    }
                    if (!latLng && this.config?.state?.state) {
                        const s = this.config.state.state;
                        if (s.lat && s.lng) {
                            latLng = [parseFloat(s.lat), parseFloat(s.lng)];
                        }
                    }

                    if (this.geofenceType === 'rectangle' || this.geofenceType === 'square') {
                        this.clearCircleLayer(map);
                        if (!this.rectBounds) {
                            const center = latLng
                                ? (Array.isArray(latLng) ? [parseFloat(latLng[0]), parseFloat(latLng[1])] : [parseFloat(latLng.lat), parseFloat(latLng.lng)])
                                : this.getCurrentCenterLatLng();
                            this.rectBounds = this.createDefaultBoundsAroundCenter(center[0], center[1], this.geofenceType === 'square');
                            this.setWireValue('geofence_bounds', this.rectBounds);
                        }
                        this.renderRectangleAndHandles(map);
                        this.updateDimensionSummary();
                        return;
                    }

                    this.clearRectangleLayers(map);

                    if (!latLng) {
                        this.clearCircleLayer(map);
                        this.updateDimensionSummary();
                        return;
                    }

                    const safeLatLng = Array.isArray(latLng)
                        ? [parseFloat(latLng[0]), parseFloat(latLng[1])]
                        : [parseFloat(latLng.lat), parseFloat(latLng.lng)];

                    if (isNaN(safeLatLng[0]) || isNaN(safeLatLng[1])) return;

                    const radius = (overrideRadius !== undefined && overrideRadius !== null)
                        ? overrideRadius
                        : this.getGeofenceRadius();

                    if (!this.geofenceCircle) {
                        let circle = null;
                        if (this.mapCore && typeof this.mapCore.createCircle === 'function') {
                            circle = this.mapCore.createCircle({
                                center: safeLatLng,
                                options: {
                                    radius: radius,
                                    color: '#2563eb',
                                    weight: 2,
                                    opacity: 0.85,
                                    fillColor: '#3b82f6',
                                    fillOpacity: 0.15,
                                    dashArray: '6, 6',
                                    interactive: false,
                                },
                            });
                        } else if (typeof window.L !== 'undefined' && typeof window.L.circle === 'function') {
                            circle = window.L.circle(safeLatLng, {
                                radius: radius,
                                color: '#2563eb',
                                weight: 2,
                                opacity: 0.85,
                                fillColor: '#3b82f6',
                                fillOpacity: 0.15,
                                dashArray: '6, 6',
                                interactive: false,
                            });
                        }

                        if (circle) {
                            Alpine.raw(circle).addTo(map);
                            this.geofenceCircle = circle;
                        }
                    } else {
                        const circle = Alpine.raw(this.geofenceCircle);
                        if (circle && typeof circle.setLatLng === 'function') {
                            circle.setLatLng(safeLatLng);
                            circle.setRadius(radius);
                            if (!map.hasLayer(circle)) {
                                circle.addTo(map);
                            }
                        }
                    }

                    this.updateDimensionSummary();
                },

                shiftRectangleToNewCenter(newLat, newLng) {
                    if (!this.rectBounds) return;
                    const oldCenterLat = (this.rectBounds.south + this.rectBounds.north) / 2;
                    const oldCenterLng = (this.rectBounds.west + this.rectBounds.east) / 2;
                    const dLat = newLat - oldCenterLat;
                    const dLng = newLng - oldCenterLng;

                    this.rectBounds = this.normalizeBounds({
                        south: this.rectBounds.south + dLat,
                        north: this.rectBounds.north + dLat,
                        west: this.rectBounds.west + dLng,
                        east: this.rectBounds.east + dLng,
                    });
                },

                bindMarkerEvents() {
                    if (!this.pickMarker) return;
                    const rawMarker = Alpine.raw(this.pickMarker);
                    if (rawMarker._onGeofenceDrag) {
                        rawMarker.off('drag', rawMarker._onGeofenceDrag);
                    }
                    rawMarker._onGeofenceDrag = (e) => {
                        const pos = e.target.getLatLng();
                        if (this.geofenceType === 'rectangle' || this.geofenceType === 'square') {
                            this.shiftRectangleToNewCenter(pos.lat, pos.lng);
                        }
                        this.updateGeofenceCircle(undefined, [pos.lat, pos.lng]);
                    };
                    rawMarker.on('drag', rawMarker._onGeofenceDrag);

                    if (rawMarker._onGeofenceDragEnd) {
                        rawMarker.off('dragend', rawMarker._onGeofenceDragEnd);
                    }
                    rawMarker._onGeofenceDragEnd = (e) => {
                        const newPos = e.target.getLatLng();
                        this.setState(newPos.lat, newPos.lng);
                        if (this.geofenceType === 'rectangle' || this.geofenceType === 'square') {
                            this.shiftRectangleToNewCenter(newPos.lat, newPos.lng);
                            this.setWireValue('geofence_bounds', this.rectBounds);
                        }
                        this.updateGeofenceCircle(undefined, [newPos.lat, newPos.lng]);
                    };
                    rawMarker.on('dragend', rawMarker._onGeofenceDragEnd);
                },

                bindMapBoxDrawEvents() {
                    const map = this.mapCore ? Alpine.raw(this.mapCore.map) : null;
                    if (!map || map.__boxDrawBound) return;
                    map.__boxDrawBound = true;

                    map.on('mousedown', (e) => {
                        const shiftPressed = e.originalEvent && e.originalEvent.shiftKey;
                        if (!this.isDrawingBox && !(shiftPressed && (this.geofenceType === 'rectangle' || this.geofenceType === 'square'))) {
                            return;
                        }

                        map.dragging.disable();
                        this.drawStartLatLng = [e.latlng.lat, e.latlng.lng];
                    });

                    map.on('mousemove', (e) => {
                        if (!this.drawStartLatLng) return;
                        const nextBounds = this.computeCornerBounds(
                            this.drawStartLatLng[0],
                            this.drawStartLatLng[1],
                            e.latlng.lat,
                            e.latlng.lng,
                            this.geofenceType === 'square'
                        );
                        if (nextBounds) {
                            this.rectBounds = nextBounds;
                            this.renderRectangleAndHandles(map);
                            this.updateDimensionSummary();
                        }
                    });

                    map.on('mouseup', () => {
                        if (!this.drawStartLatLng) return;
                        this.drawStartLatLng = null;
                        this.isDrawingBox = false;
                        map.getContainer().style.cursor = '';
                        map.dragging.enable();

                        if (this.rectBounds) {
                            const cLat = Number(((this.rectBounds.south + this.rectBounds.north) / 2).toFixed(7));
                            const cLng = Number(((this.rectBounds.west + this.rectBounds.east) / 2).toFixed(7));

                            if (this.pickMarker) {
                                try {
                                    Alpine.raw(this.pickMarker).setLatLng([cLat, cLng]);
                                } catch (e) {}
                            }
                            if (typeof this.setState === 'function') {
                                this.setState(cLat, cLng);
                            }
                            this.setWireValue('geofence_bounds', this.rectBounds);
                            this.renderRectangleAndHandles(map);
                            this.updateDimensionSummary();
                        }
                    });
                },

                updatePickMarker() {
                    if (base.updatePickMarker) {
                        base.updatePickMarker.call(this);
                    }
                    this.bindMarkerEvents();
                    this.updateGeofenceCircle();
                },

                setupGeofence() {
                    const initialType = this.getWireValue('geofence_type');
                    if (['circle', 'rectangle', 'square'].includes(initialType)) {
                        this.geofenceType = initialType;
                    }

                    const initialBounds = this.normalizeBounds(this.getWireValue('geofence_bounds'));
                    if (initialBounds) {
                        this.rectBounds = initialBounds;
                    }

                    this.bindMarkerEvents();
                    this.bindMapBoxDrawEvents();
                    this.updateGeofenceCircle();

                    const radiusStatePath = this.resolveFieldPath('geofence_radius_m');
                    if (this.$wire && radiusStatePath) {
                        this.$wire.watch(radiusStatePath, (val) => {
                            const parsed = parseFloat(val);
                            const r = (!isNaN(parsed) && parsed > 0) ? parsed : this.defaultRadius;
                            this.updateGeofenceCircle(r);
                        });
                    }

                    const typeStatePath = this.resolveFieldPath('geofence_type');
                    if (this.$wire && typeStatePath) {
                        this.$wire.watch(typeStatePath, (val) => {
                            if (val && val !== this.geofenceType) {
                                this.setGeofenceType(val, false);
                            }
                        });
                    }

                    const root = this.$el.closest('.fi-modal, form') || document;
                    if (!root.__geofenceInputBound) {
                        root.__geofenceInputBound = true;
                        const onInput = (e) => {
                            if (!e.target) return;
                            const isRadius = e.target.hasAttribute('data-geofence-radius-input') ||
                                e.target.name === 'geofence_radius_m' ||
                                (e.target.name && e.target.name.endsWith('geofence_radius_m')) ||
                                (e.target.getAttribute('wire:model') && e.target.getAttribute('wire:model').includes('geofence_radius_m'));
                            if (isRadius) {
                                const val = parseFloat(e.target.value);
                                const r = (!isNaN(val) && val > 0) ? val : this.defaultRadius;
                                this.updateGeofenceCircle(r);
                            }
                        };
                        root.addEventListener('input', onInput);
                        root.addEventListener('change', onInput);
                    }

                    [100, 300, 600].forEach((delay) => {
                        setTimeout(() => {
                            if (this.mapCore && this.mapCore.map) {
                                Alpine.raw(this.mapCore.map).invalidateSize();
                                this.bindMapBoxDrawEvents();
                                this.updateGeofenceCircle();
                            }
                        }, delay);
                    });
                }
            };
        };
    }
</script>
