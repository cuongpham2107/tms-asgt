import mapboxgl from 'mapbox-gl';

window.mapboxgl = mapboxgl;

// Register Alpine component — handle both initial load and Livewire navigation
function register() {
    if (window.Alpine && window.Alpine.data) {
        // Alpine already initialized — register directly
        if (!window.Alpine.data('mapboxLocationPicker')) {
            window.Alpine.data('mapboxLocationPicker', pickerFactory);
        }
    }
}

// If Alpine hasn't initialized yet, wait for it
document.addEventListener('alpine:init', () => {
    Alpine.data('mapboxLocationPicker', pickerFactory);
});

// Also try immediately in case Alpine already loaded
register();

// Factory function
function pickerFactory(config) {
    return {
        lat: null, lng: null, map: null, marker: null, ready: false,

        init() {
            let attempts = 0;
            const tryInit = () => {
                if (this.ready) return;
                if (!this.$refs.map) { if (++attempts < 50) setTimeout(tryInit, 100); return; }
                this.initMap();
            };
            this.$nextTick(() => tryInit());
        },

        initMap() {
            if (this.ready) return;
            if (typeof window.mapboxgl === 'undefined') return setTimeout(() => this.initMap(), 200);
            this.ready = true;
            const gl = window.mapboxgl;
            gl.accessToken = config.accessToken;

            this.map = new gl.Map({
                container: this.$refs.map,
                style: 'mapbox://styles/mapbox/streets-v12',
                center: [config.defaultLng, config.defaultLat],
                zoom: config.defaultZoom,
            });
            this.map.addControl(new gl.NavigationControl(), 'top-right');
            this.map.on('click', (e) => this.setLocation(e.lngLat.lat, e.lngLat.lng));

            setTimeout(() => {
                try {
                    const ilat = this.$wire.get('data.' + config.latField);
                    const ilng = this.$wire.get('data.' + config.lngField);
                    if (ilat && ilng) this.setLocation(+ilat, +ilng, false);
                } catch (e) {}
            }, 300);
        },

        setLocation(latVal, lngVal, fly = true) {
            this.lat = latVal; this.lng = lngVal;
            this.$wire.set('data.' + config.latField, (+latVal).toFixed(7));
            this.$wire.set('data.' + config.lngField, (+lngVal).toFixed(7));

            if (this.marker) { this.marker.setLngLat([lngVal, latVal]); }
            else {
                this.marker = new window.mapboxgl.Marker({ draggable: true })
                    .setLngLat([lngVal, latVal]).addTo(this.map);
                this.marker.on('dragend', () => {
                    const p = this.marker.getLngLat();
                    this.setLocation(p.lat, p.lng);
                });
            }
            if (fly && this.map) this.map.flyTo({ center: [lngVal, latVal], zoom: 15 });
        },

        clear() {
            this.lat = null; this.lng = null;
            this.$wire.set('data.' + config.latField, null);
            this.$wire.set('data.' + config.lngField, null);
            if (this.marker) { this.marker.remove(); this.marker = null; }
        },
    };
}
