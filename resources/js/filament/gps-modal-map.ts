import * as L from 'leaflet'

// Cùng ô bản đồ Google với trang "Theo dõi thực tế" — OSM bị chặn trên một số mạng.
const TILE_URL = 'https://mt1.google.com/vt/lyrs=m&hl=vi&x={x}&y={y}&z={z}'

let map: L.Map | null = null

const markerIcon = () =>
    L.divIcon({
        className: 'trk-marker',
        html: `<div style="width:30px;height:38px;position:relative">
            <svg viewBox="0 0 30 38" width="30" height="38" aria-hidden="true">
                <path d="M15 1.5C8.4 1.5 3 6.9 3 13.5c0 8.6 12 23 12 23s12-14.4 12-23C27 6.9 21.6 1.5 15 1.5z" fill="#0ea5e9" stroke="#ffffff" stroke-width="2"/>
            </svg>
        </div>`,
        iconSize: [30, 38],
        iconAnchor: [15, 38],
    })

const renderMap = (): void => {
    const container = document.querySelector<HTMLElement>('[data-gps-map]')

    if (!container) {
        return
    }

    const lat = Number(container.dataset.lat)
    const lng = Number(container.dataset.lng)

    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
        return
    }

    map?.remove()
    map = L.map(container, { scrollWheelZoom: false }).setView([lat, lng], 16)
    L.tileLayer(TILE_URL, { maxZoom: 19, attribution: '&copy; Google Maps' }).addTo(map)
    L.marker([lat, lng], { icon: markerIcon() }).addTo(map)

    // Modal mở bằng x-show nên khung chỉ có kích thước sau khi hiện.
    setTimeout(() => map?.invalidateSize(), 200)
}

document.addEventListener('open-modal', (event: Event) => {
    if ((event as CustomEvent<{ id?: string }>).detail?.id !== 'vehicle-gps-modal') {
        return
    }

    requestAnimationFrame(renderMap)
})
