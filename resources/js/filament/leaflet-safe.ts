import type * as LeafletTypes from 'leaflet'

type LeafletWithNoConflict = typeof LeafletTypes & {
    noConflict?: () => typeof LeafletTypes
    markerClusterGroup?: unknown
}

/**
 * Leaflet UMD khi import qua Vite sẽ tự gán `window.L = module`, ghi đè bản `window.L`
 * đã gắn plugin Geoman (`L.PM`) và `L.HeroIcon` của filament-leaflet trên các trang SPA.
 * Hàm này trả lại `window.L` trước đó (nếu có) và chọn đúng instance Leaflet đã gắn
 * plugin cần dùng cho module gọi.
 */
export function resolveSafeLeaflet(
    leafletModule: typeof LeafletTypes,
    requireMarkerCluster = false,
): typeof LeafletTypes {
    if (typeof window === 'undefined') {
        return leafletModule
    }

    const mod = leafletModule as LeafletWithNoConflict
    const win = window as unknown as { L?: LeafletWithNoConflict }

    if (typeof mod.noConflict === 'function') {
        mod.noConflict()
        // Nếu trước đó chưa có window.L (trang độc lập không nạp filament-leaflet),
        // giữ lại leafletModule trên window.L để các UMD plugin như leaflet.markercluster không lỗi.
        if (!win.L) {
            win.L = mod
        }
    }

    if (requireMarkerCluster && typeof mod.markerClusterGroup !== 'function' && win.L && typeof win.L.markerClusterGroup === 'function') {
        return win.L
    }

    return leafletModule
}

