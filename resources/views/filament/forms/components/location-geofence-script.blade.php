<script>
    if (typeof window.locationGeofenceMap === 'undefined') {
        window.locationGeofenceMap = function($wire, config, defaultRadius) {
            const base = (typeof window.leafletMapField === 'function')
                ? window.leafletMapField($wire, config)
                : {};

            return {
                ...base,
                defaultRadius: defaultRadius || 3000,
                geofenceCircle: null,

                init() {
                    if (base.init) {
                        base.init.call(this);
                    }

                    this.setupGeofence();
                },

                getGeofenceRadius() {
                    const root = this.$el.closest('.fi-modal, form') || document;
                    const input = root.querySelector('input[data-geofence-radius-input], input[name$="geofence_radius_m"], [wire\\:model*="geofence_radius_m"]');
                    if (input && input.value !== '') {
                        const val = parseFloat(input.value);
                        if (!isNaN(val) && val > 0) return val;
                    }

                    const radiusStatePath = this.config?.state?.statePath
                        ? this.config.state.statePath.replace(/\.coordinates$/, '.geofence_radius_m')
                        : 'data.geofence_radius_m';

                    if (this.$wire) {
                        const wireVal = this.$wire.get(radiusStatePath) ?? this.$wire.get('data.geofence_radius_m');
                        if (wireVal !== undefined && wireVal !== null && wireVal !== '') {
                            const val = parseFloat(wireVal);
                            if (!isNaN(val) && val > 0) return val;
                        }
                    }

                    return this.defaultRadius;
                },

                updateGeofenceCircle(overrideRadius, overrideLatLng) {
                    if (typeof window.L === 'undefined') return;
                    const map = this.mapCore ? Alpine.raw(this.mapCore.map) : null;
                    if (!map) return;

                    let latLng = overrideLatLng;
                    if (!latLng && this.pickMarker) {
                        latLng = Alpine.raw(this.pickMarker).getLatLng();
                    }
                    if (!latLng) {
                        const state = this.getState();
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

                    if (!latLng) {
                        if (this.geofenceCircle) {
                            Alpine.raw(this.geofenceCircle).removeFrom(map);
                            this.geofenceCircle = null;
                        }
                        return;
                    }

                    const safeLatLng = Array.isArray(latLng)
                        ? [parseFloat(latLng[0]), parseFloat(latLng[1])]
                        : [parseFloat(latLng.lat), parseFloat(latLng.lng)];

                    const radius = (overrideRadius !== undefined && overrideRadius !== null)
                        ? overrideRadius
                        : this.getGeofenceRadius();

                    if (!this.geofenceCircle) {
                        const circle = L.circle(safeLatLng, {
                            radius: radius,
                            color: '#2563eb',
                            weight: 2,
                            opacity: 0.85,
                            fillColor: '#3b82f6',
                            fillOpacity: 0.15,
                            dashArray: '6, 6',
                            interactive: false,
                        });
                        circle.addTo(map);
                        this.geofenceCircle = circle;
                    } else {
                        const circle = Alpine.raw(this.geofenceCircle);
                        circle.setLatLng(safeLatLng);
                        circle.setRadius(radius);
                        if (!map.hasLayer(circle)) {
                            circle.addTo(map);
                        }
                    }
                },

                bindMarkerEvents() {
                    if (!this.pickMarker) return;
                    const rawMarker = Alpine.raw(this.pickMarker);
                    if (rawMarker._onGeofenceDrag) {
                        rawMarker.off('drag', rawMarker._onGeofenceDrag);
                    }
                    rawMarker._onGeofenceDrag = (e) => {
                        const pos = e.target.getLatLng();
                        this.updateGeofenceCircle(undefined, [pos.lat, pos.lng]);
                    };
                    rawMarker.on('drag', rawMarker._onGeofenceDrag);

                    if (rawMarker._onGeofenceDragEnd) {
                        rawMarker.off('dragend', rawMarker._onGeofenceDragEnd);
                    }
                    rawMarker._onGeofenceDragEnd = (e) => {
                        const newPos = e.target.getLatLng();
                        this.setState(newPos.lat, newPos.lng);
                        this.updateGeofenceCircle(undefined, [newPos.lat, newPos.lng]);
                    };
                    rawMarker.on('dragend', rawMarker._onGeofenceDragEnd);
                },

                updatePickMarker() {
                    if (base.updatePickMarker) {
                        base.updatePickMarker.call(this);
                    }
                    this.bindMarkerEvents();
                    this.updateGeofenceCircle();
                },

                setupGeofence() {
                    this.bindMarkerEvents();
                    this.updateGeofenceCircle();

                    const radiusStatePath = this.config?.state?.statePath
                        ? this.config.state.statePath.replace(/\.coordinates$/, '.geofence_radius_m')
                        : 'data.geofence_radius_m';

                    if (this.$wire && radiusStatePath) {
                        this.$wire.watch(radiusStatePath, (val) => {
                            const parsed = parseFloat(val);
                            const r = (!isNaN(parsed) && parsed > 0) ? parsed : this.defaultRadius;
                            this.updateGeofenceCircle(r);
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
                                this.updateGeofenceCircle();
                            }
                        }, delay);
                    });
                }
            };
        };
    }
</script>

