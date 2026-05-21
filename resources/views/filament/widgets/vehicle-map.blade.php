<div x-data="{
    attempts: 0,
    init() { this.waitForMapbox(); },
    waitForMapbox() {
        if (typeof mapboxgl !== 'undefined') { this.loadMap(); return; }
        if (this.attempts < 100) { this.attempts++; setTimeout(() => this.waitForMapbox(), 100); }
    },
    loadMap() {
        const el = document.getElementById('dashboard-map');
        if (!el) return;
        mapboxgl.accessToken = '{{ config('services.mapbox.token') }}';
        const vehicles = {{ \Illuminate\Support\Js::from($this->getVehicles()) }};
        const map = new mapboxgl.Map({ container: 'dashboard-map', style: 'mapbox://styles/mapbox/streets-v12', center: [105.95, 21.125], zoom: 10 });
        map.addControl(new mapboxgl.NavigationControl(), 'top-right');
        const colors = { on: '#22c55e', running: '#f59e0b', bdsc: '#ef4444', off: '#9ca3af' };
        vehicles.forEach(v => {
            const color = colors[v.status] || '#9ca3af';
            const d = document.createElement('div');
            d.style.cssText = 'background:' + color + ';color:white;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:bold;border:2px solid white;box-shadow:0 2px 4px rgba(0,0,0,.3);cursor:pointer';
            d.textContent = v.plate.slice(-4);
            new mapboxgl.Marker({ element: d }).setLngLat([v.lng, v.lat])
                .setPopup(new mapboxgl.Popup({ offset: 20 }).setHTML('<b>' + v.plate + '</b><br>' + (v.driver || '') + '<br>' + v.type))
                .addTo(map);
        });
    }
}">
    <div id="dashboard-map" class="h-[400px] w-full rounded-xl border border-gray-200 dark:border-gray-700"></div>
</div>
