<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        x-data="{
            lat: null, lng: null, map: null, marker: null, ready: false,
            cfg: {
                defaultLat:  {{ $getDefaultLat() }},
                defaultLng:  {{ $getDefaultLng() }},
                defaultZoom: {{ $getDefaultZoom() }},
                accessToken: '{{ $getAccessToken() }}',
                latField:    '{{ $getLatField() }}',
                lngField:    '{{ $getLngField() }}',
            },
            init() {
                var self = this, a = 0;
                var t = function () {
                    if (self.ready) return;
                    if (!self.$refs.map) { if (++a < 50) setTimeout(t, 100); return; }
                    self.initMap();
                };
                this.$nextTick(function () { t(); });
            },
            initMap() {
                if (this.ready) return;
                if (typeof window.mapboxgl === 'undefined') {
                    var s = this;
                    return setTimeout(function () { s.initMap(); }, 200);
                }
                this.ready = true;
                var gl = window.mapboxgl;
                gl.accessToken = this.cfg.accessToken;
                this.map = new gl.Map({
                    container: this.$refs.map,
                    style: 'mapbox://styles/mapbox/streets-v12',
                    center: [this.cfg.defaultLng, this.cfg.defaultLat],
                    zoom: this.cfg.defaultZoom,
                });
                this.map.addControl(new gl.NavigationControl(), 'top-right');
                var s = this;
                this.map.on('click', function (e) { s.setLocation(e.lngLat.lat, e.lngLat.lng); });
                setTimeout(function () {
                    try {
                        var il = s.$wire.get('data.' + s.cfg.latField);
                        var ig = s.$wire.get('data.' + s.cfg.lngField);
                        if (il && ig) s.setLocation(+il, +ig, false);
                    } catch (e) {}
                }, 300);
            },
            setLocation(la, lo, f) {
                if (f === undefined) f = true;
                this.lat = la; this.lng = lo;
                this.$wire.set('data.' + this.cfg.latField, (+la).toFixed(7));
                this.$wire.set('data.' + this.cfg.lngField, (+lo).toFixed(7));
                if (this.marker) {
                    this.marker.setLngLat([lo, la]);
                } else {
                    var s = this;
                    this.marker = new window.mapboxgl.Marker({ draggable: true })
                        .setLngLat([lo, la]).addTo(this.map);
                    this.marker.on('dragend', function () {
                        var p = s.marker.getLngLat();
                        s.setLocation(p.lat, p.lng);
                    });
                }
                if (f && this.map) this.map.flyTo({ center: [lo, la], zoom: 15 });
            },
            clear() {
                this.lat = null; this.lng = null;
                this.$wire.set('data.' + this.cfg.latField, null);
                this.$wire.set('data.' + this.cfg.lngField, null);
                if (this.marker) { this.marker.remove(); this.marker = null; }
            },
        }"
        x-init="init()"
        class="space-y-3"
    >
        <div
            x-ref="map"
            class="w-full rounded-lg border border-gray-200 dark:border-gray-700"
            style="height: 400px;"
        ></div>

        <div class="flex items-center gap-4 text-sm text-gray-600 dark:text-gray-400">
            <span class="inline-flex items-center gap-1">
                <x-filament::icon icon="heroicon-o-map-pin" class="h-4 w-4" />
                <span x-text="lat ? lat.toFixed(6) : '—'"></span>
            </span>
            <span class="inline-flex items-center gap-1">
                <x-filament::icon icon="heroicon-o-globe-alt" class="h-4 w-4" />
                <span x-text="lng ? lng.toFixed(6) : '—'"></span>
            </span>
        </div>

        <x-filament::button type="button" color="gray" size="sm" x-on:click="clear()">
            <x-filament::icon icon="heroicon-o-x-mark" class="h-4 w-4" />
            <span class="ml-1">Xóa vị trí</span>
        </x-filament::button>
    </div>
</x-dynamic-component>