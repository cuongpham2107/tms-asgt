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
        /* Màu marker theo trạng thái — biến màu semantic của Filament. */
        const colors = { on: 'var(--success-500)', running: 'var(--warning-500)', bdsc: 'var(--danger-500)', off: 'var(--gray-400)' };
        vehicles.forEach(v => {
            const color = colors[v.status] || colors.off;
            const d = document.createElement('div');
            d.className = 'flex size-8 cursor-pointer items-center justify-center rounded-full border-2 border-white text-[10px] font-bold text-white shadow-md';
            d.style.background = color;
            d.textContent = v.plate.slice(-4);
            new mapboxgl.Marker({ element: d }).setLngLat([v.lng, v.lat])
                .setPopup(new mapboxgl.Popup({ offset: 20 }).setDOMContent((() => {
                    // Dùng textContent để biển số / tên tài xế không bị chèn thành HTML.
                    const box = document.createElement('div');
                    [[v.plate, 'font-semibold'], [v.driver || '', ''], [v.type || '', '']].forEach(([text, cls]) => {
                        const line = document.createElement('div');
                        line.textContent = text;
                        if (cls) line.className = cls;
                        box.appendChild(line);
                    });
                    return box;
                })()))
                .addTo(map);
        });
    }
}">
    <div id="dashboard-map" class="h-[400px] w-full overflow-hidden rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10"></div>
</div>
