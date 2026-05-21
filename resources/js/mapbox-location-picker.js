// resources/js/mapbox-location-picker.js

document.addEventListener("alpine:init", function () {
    window.mapboxLocationPicker = function (config) {
        return {
            lat: null,
            lng: null,
            map: null,
            marker: null,
            ready: false,
            _cfg: null,

            init: function () {
                this._cfg = {
                    defaultLat: parseFloat(this.$el.dataset.defaultLat),
                    defaultLng: parseFloat(this.$el.dataset.defaultLng),
                    defaultZoom: parseFloat(this.$el.dataset.defaultZoom),
                    accessToken: this.$el.dataset.accessToken,
                    latField: this.$el.dataset.latField,
                    lngField: this.$el.dataset.lngField,
                };

                attempts = 0;
                var tryInit = function () {
                    if (self.ready) return;
                    if (!self.$refs.map) {
                        if (++attempts < 50) setTimeout(tryInit, 100);
                        return;
                    }
                    self.initMap();
                };
                this.$nextTick(function () {
                    tryInit();
                });
            },

            findFieldInput: function (fieldName) {
                var form = this.$el.closest("form");

                if (!form) {
                    return null;
                }

                return form.querySelector(
                    '[name="' +
                        fieldName +
                        '"]' +
                        ', [name$="[' +
                        fieldName +
                        ']"]' +
                        ', [wire\\:model="' +
                        fieldName +
                        '"]' +
                        ', [wire\\:model$=".' +
                        fieldName +
                        '"]',
                );
            },

            syncFieldValue: function (fieldName, value) {
                var input = this.findFieldInput(fieldName);

                if (!input) {
                    return;
                }

                input.value = value;
                input.dispatchEvent(new Event("input", { bubbles: true }));
                input.dispatchEvent(new Event("change", { bubbles: true }));
            },

            initMap: function () {
                if (this.ready) return;
                if (typeof window.mapboxgl === "undefined") {
                    var self = this;
                    return setTimeout(function () {
                        self.initMap();
                    }, 200);
                }

                this.ready = true;
                var cfg = this._cfg;
                var gl = window.mapboxgl;
                gl.accessToken = cfg.accessToken;

                this.map = new gl.Map({
                    container: this.$refs.map,
                    style: "mapbox://styles/mapbox/streets-v12",
                    center: [cfg.defaultLng, cfg.defaultLat],
                    zoom: cfg.defaultZoom,
                });
                this.map.addControl(new gl.NavigationControl(), "top-right");

                var self = this;
                this.map.on("click", function (e) {
                    self.setLocation(e.lngLat.lat, e.lngLat.lng);
                });

                setTimeout(function () {
                    try {
                        var ilat = self.$wire.get("data." + self._cfg.latField);
                        var ilng = self.$wire.get("data." + self._cfg.lngField);
                        if (ilat && ilng) self.setLocation(+ilat, +ilng, false);
                    } catch (e) {}
                }, 300);
            },

            setLocation: function (latVal, lngVal, fly) {
                if (fly === undefined) fly = true;
                var cfg = this._cfg;

                this.lat = latVal;
                this.lng = lngVal;
                this.syncFieldValue(cfg.latField, (+latVal).toFixed(7));
                this.syncFieldValue(cfg.lngField, (+lngVal).toFixed(7));

                if (this.marker) {
                    this.marker.setLngLat([lngVal, latVal]);
                } else {
                    var self = this;
                    this.marker = new window.mapboxgl.Marker({
                        draggable: true,
                    })
                        .setLngLat([lngVal, latVal])
                        .addTo(this.map);
                    this.marker.on("dragend", function () {
                        var p = self.marker.getLngLat();
                        self.setLocation(p.lat, p.lng);
                    });
                }

                if (fly && this.map) {
                    this.map.flyTo({ center: [lngVal, latVal], zoom: 15 });
                }
            },

            clear: function () {
                var cfg = this._cfg;
                this.lat = null;
                this.lng = null;
                this.syncFieldValue(cfg.latField, null);
                this.syncFieldValue(cfg.lngField, null);
                if (this.marker) {
                    this.marker.remove();
                    this.marker = null;
                }
            },
        };
    };

    window.Alpine.data("mapboxLocationPicker", window.mapboxLocationPicker);
});
