<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div wire:ignore x-data="{
        lat: null,
        lng: null,
        map: null,
        marker: null,
        ready: false,
        cfg: {
            defaultLat: {{ $getDefaultLat() }},
            defaultLng: {{ $getDefaultLng() }},
            defaultZoom: {{ $getDefaultZoom() }},
            accessToken: '{{ $getAccessToken() }}',
            latStatePath: '{{ $getLatStatePath() }}',
            lngStatePath: '{{ $getLngStatePath() }}',
        },
        init() {
            var self = this,
                a = 0;
            var t = function() {
                if (self.ready) return;
                if (!self.$refs.map) { if (++a < 50) setTimeout(t, 100); return; }
                self.initMap();
            };
            this.$nextTick(function() { t(); });
        },
        initMap() {
            if (this.ready) return;
            if (typeof window.mapboxgl === 'undefined') {
                var s = this;
                return setTimeout(function() { s.initMap(); }, 200);
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
            this.map.on('click', function(e) { s.setLocation(e.lngLat.lat, e.lngLat.lng); });
    
            if (typeof window.ResizeObserver !== 'undefined') {
                var ro = new ResizeObserver(function() {
                    if (s.map) s.map.resize();
                });
                ro.observe(s.$refs.map);
            }
    
            setTimeout(function() {
                try {
                    var il = s.$wire.get(s.cfg.latStatePath);
                    var ig = s.$wire.get(s.cfg.lngStatePath);
                    if (il && ig) s.setLocation(+il, +ig, false);
                } catch (e) {}
            }, 300);
        },
        setLocation(la, lo, f) {
            if (f === undefined) f = true;
            this.lat = la;
            this.lng = lo;
            this.$wire.set(this.cfg.latStatePath, (+la).toFixed(7));
            this.$wire.set(this.cfg.lngStatePath, (+lo).toFixed(7));
            if (this.marker) {
                this.marker.setLngLat([lo, la]);
            } else {
                var s = this;
                this.marker = new window.mapboxgl.Marker({ draggable: true })
                    .setLngLat([lo, la]).addTo(this.map);
                this.marker.on('dragend', function() {
                    var p = s.marker.getLngLat();
                    s.setLocation(p.lat, p.lng);
                });
            }
            if (this.map) {
                if (f) {
                    this.map.flyTo({ center: [lo, la], zoom: 15 });
                } else {
                    this.map.setCenter([lo, la]);
                    this.map.setZoom(15);
                }
            }
        },
        clear() {
            this.lat = null;
            this.lng = null;
            this.$wire.set(this.cfg.latStatePath, null);
            this.$wire.set(this.cfg.lngStatePath, null);
            if (this.marker) {
                this.marker.remove();
                this.marker = null;
            }
        },
    }" x-init="init()" class="space-y-3">
        <div x-ref="map" class="w-full rounded-lg border border-gray-200 dark:border-gray-700" style="height: 400px;">
        </div>

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
