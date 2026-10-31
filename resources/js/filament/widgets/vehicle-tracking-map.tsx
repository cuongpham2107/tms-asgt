import { useEffect, useRef, useState } from 'react'
import { createRoot } from 'react-dom/client'
import * as L from 'leaflet'
import 'leaflet.markercluster'
import type { JsonValue, JsWidgetProps, JsWidgetRenderer, JsWidgetUtilities, Snapshot } from '@filament/widgets/js-widget'

interface PopupOrder {
    code: string | null
    customer: string
    from: string
    to: string
}

interface VehicleRow {
    id: number
    plate: string | null
    lat: number | null
    lng: number | null
    hasGps: boolean
    status: string
    statusLabel: string
    color: string
    selected: boolean
    driver: string
    phone: string | null
    vehicleType: string
    speed: number | null
    updated: string | null
    stale: boolean
    tripCode: string | null
    ordersCount: number
    orders: PopupOrder[]
}

interface RouteShape {
    vehicleId: number
    traveled: [number, number][]
    planned: [number, number][]
    checkpoints: { lat: number; lng: number; color: string; label: string }[]
    stops: { lat: number; lng: number; label: string }[]
}

interface Configuration {
    vehicles: VehicleRow[]
    visibleCount: number
    totalCount: number
    hasMore: boolean
    selectedIds: number[]
    selectedPlates: string
    routes: RouteShape[]
    filters: { search: string; status: string }
    statusOptions: { key: string; label: string; color: string | null }[]
    legend: Record<string, string>
    withoutGpsCount: number
    tiles: [string, string, string][]
    center: [number, number]
    zoom: number
    lastUpdated: string | null
}

interface WireMethods {
    selectVehicle(id: number): Promise<unknown>
    toggleCheckbox(id: number): Promise<unknown>
    selectAll(): Promise<unknown>
    deselectAll(): Promise<unknown>
    selectRunningOnly(): Promise<unknown>
    loadMore(): Promise<unknown>
    refreshData(): Promise<unknown>
    $set(path: string, value: JsonValue, live?: boolean): Promise<unknown>
}

type Wire = JsWidgetUtilities<WireMethods>['$wire']

const TRAVELED_COLOR = '#4338ca'
const PLANNED_COLOR = '#ea580c'

const escapeHtml = (value: unknown): string =>
    String(value ?? '').replace(
        /[&<>"']/g,
        (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char] as string,
    )

const markerIcon = (color: string) =>
    L.divIcon({
        className: 'trk-marker',
        html: `<div style="width:30px;height:38px;position:relative">
            <svg viewBox="0 0 30 38" width="30" height="38" aria-hidden="true">
                <path d="M15 1.5C8.4 1.5 3 6.9 3 13.5c0 8.6 12 23 12 23s12-14.4 12-23C27 6.9 21.6 1.5 15 1.5z" fill="${color}" stroke="#ffffff" stroke-width="2"/>
            </svg>
            <svg viewBox="0 0 24 24" width="15" height="15" fill="#ffffff" style="position:absolute;left:7.5px;top:6px" aria-hidden="true">
                <path d="M2.5 6.5A1.5 1.5 0 014 5h9a1 1 0 011 1v1h2.6a2 2 0 011.7.9l2.4 3.6a2 2 0 01.3 1V15a1 1 0 01-1 1h-1.1a2.4 2.4 0 00-4.8 0H9.9a2.4 2.4 0 00-4.8 0H4a1.5 1.5 0 01-1.5-1.5v-8zm12.5 1.5v3h4.4l-2-3H15zM5.5 17.5a1.2 1.2 0 100-2.4 1.2 1.2 0 000 2.4zm9 0a1.2 1.2 0 100-2.4 1.2 1.2 0 000 2.4z"/>
            </svg>
        </div>`,
        iconSize: [30, 38],
        iconAnchor: [15, 38],
        popupAnchor: [0, -34],
    })

const vehiclePopup = (vehicle: Snapshot<VehicleRow>): string => `
    <div class="trk-popup">
        <div class="trk-popup-head">
            <span class="trk-popup-plate">${escapeHtml(vehicle.plate)}</span>
            <span class="trk-popup-badge" style="background:${vehicle.color}">${escapeHtml(vehicle.statusLabel)}</span>
        </div>
        <div class="trk-row">👤 <strong>${escapeHtml(vehicle.driver)}</strong>${vehicle.phone ? ` · ${escapeHtml(vehicle.phone)}` : ''}</div>
        ${
            vehicle.tripCode
                ? `<div class="trk-row">🚚 Chuyến <strong>${escapeHtml(vehicle.tripCode)}</strong> · ${vehicle.ordersCount} đơn</div>`
                : '<div class="trk-row trk-muted">Không có chuyến đang chạy</div>'
        }
        ${vehicle.orders
            .map(
                (order) =>
                    `<div class="trk-order-item"><strong>#${escapeHtml(order.code)}</strong> · ${escapeHtml(order.customer)}<div class="trk-muted trk-ellipsis">${escapeHtml(order.from)} → ${escapeHtml(order.to)}</div></div>`,
            )
            .join('')}
        <div class="trk-muted">${vehicle.speed && vehicle.speed > 0 ? `${Math.round(vehicle.speed)} km/h · ` : ''}<span class="${vehicle.stale ? 'trk-stale' : ''}">Cập nhật: ${escapeHtml(vehicle.updated ?? 'chưa có GPS')}</span></div>
    </div>`

function MapCanvas({ configuration, selectedIds, onSelect }: { configuration: Snapshot<Configuration>; selectedIds: number[]; onSelect: (id: number) => void }) {
    const containerRef = useRef<HTMLDivElement | null>(null)
    const mapRef = useRef<L.Map | null>(null)
    const clusterRef = useRef<L.MarkerClusterGroup | null>(null)
    const markersRef = useRef<Map<number, L.Marker>>(new Map())
    const routeLayersRef = useRef<L.Layer[]>([])
    const fitKeyRef = useRef<string | null>(null)
    const onSelectRef = useRef(onSelect)
    const vehiclesRef = useRef<readonly Snapshot<VehicleRow>[]>([])
    const selectionKey = selectedIds.join(',')
    const selectedRef = useRef(selectedIds)
    const popupKeyRef = useRef('')

    selectedRef.current = selectedIds

    onSelectRef.current = onSelect
    vehiclesRef.current = configuration.vehicles

    // Khởi tạo bản đồ đúng một lần; cập nhật dữ liệu qua các effect bên dưới.
    useEffect(() => {
        if (!containerRef.current) {
            return
        }

        const map = L.map(containerRef.current, { zoomControl: true })
        map.setView(L.latLng(configuration.center[0], configuration.center[1]), configuration.zoom)

        const tileLayers: Record<string, L.TileLayer> = {}
        configuration.tiles.forEach(([label, url, attribution], index) => {
            const layer = L.tileLayer(url, { attribution, maxZoom: 19 })
            tileLayers[label] = layer

            if (index === 0) {
                layer.addTo(map)
            }
        })

        if (Object.keys(tileLayers).length > 0) {
            L.control.layers(tileLayers, {}, { position: 'topright' }).addTo(map)
        }

        L.control.scale({ position: 'bottomleft' }).addTo(map)

        // Nếu plugin gom cụm không nạp được (CJS/UMD interop) thì vẫn hiển thị marker bình thường.
        const cluster =
            typeof L.markerClusterGroup === 'function'
                ? L.markerClusterGroup({
                      maxClusterRadius: 45,
                      spiderfyOnMaxZoom: true,
                      removeOutsideVisibleBounds: true,
                      zoomToBoundsOnClick: true,
                  })
                : (L.layerGroup() as unknown as L.MarkerClusterGroup)
        map.addLayer(cluster)

        mapRef.current = map
        clusterRef.current = cluster
        fitKeyRef.current = null

        return () => {
            map.remove()
            mapRef.current = null
            clusterRef.current = null
            markersRef.current.clear()
            routeLayersRef.current = []
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [])

    // Marker: cập nhật theo id để bản đồ không nhảy khi có dữ liệu mới. Đang chọn xe thì chỉ hiện các xe đó.
    useEffect(() => {
        const cluster = clusterRef.current
        const map = mapRef.current

        if (!cluster || !map) {
            return
        }

        const selected = new Set(selectedIds)
        const tracked = configuration.vehicles.filter(
            (vehicle) => vehicle.hasGps && vehicle.lat !== null && vehicle.lng !== null && (selected.size === 0 || selected.has(vehicle.id)),
        )
        const seen = new Set<number>()

        tracked.forEach((vehicle) => {
            seen.add(vehicle.id)

            const latlng = L.latLng(vehicle.lat as number, vehicle.lng as number)
            const icon = markerIcon(vehicle.color)
            const popup = vehiclePopup(vehicle)
            const marker = markersRef.current.get(vehicle.id)

            if (marker) {
                marker.setLatLng(latlng)
                marker.setIcon(icon)
                marker.setPopupContent(popup)
                marker.setTooltipContent(vehicle.plate ?? '')

                return
            }

            const created = L.marker(latlng, { icon, title: vehicle.plate ?? '' })
            created.bindPopup(popup, { maxWidth: 320 })
            created.bindTooltip(vehicle.plate ?? '', { direction: 'top', offset: [0, -34] })
            // Bấm marker xe đang chọn chỉ mở popup, không bỏ chọn.
            created.on('click', () => {
                if (!selectedRef.current.includes(vehicle.id)) {
                    onSelectRef.current(vehicle.id)
                }
            })
            cluster.addLayer(created)
            markersRef.current.set(vehicle.id, created)
        })

        markersRef.current.forEach((marker, id) => {
            if (!seen.has(id)) {
                cluster.removeLayer(marker)
                markersRef.current.delete(id)
            }
        })

        // Vừa chọn đúng 1 xe: mở popup thông tin của xe đó (một lần cho mỗi lần chọn).
        if (selectionKey !== popupKeyRef.current) {
            popupKeyRef.current = selectionKey
            const only = selectedIds.length === 1 ? markersRef.current.get(selectedIds[0]) : undefined

            if (only) {
                setTimeout(() => only.openPopup(), 0)
            } else {
                map.closePopup()
            }
        }

        if (fitKeyRef.current === null && tracked.length > 0) {
            fitKeyRef.current = ''

            const bounds = L.latLngBounds(tracked.map((vehicle) => L.latLng(vehicle.lat as number, vehicle.lng as number)))

            if (bounds.isValid()) {
                map.fitBounds(bounds, { padding: [40, 40], maxZoom: 14 })
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [configuration.vehicles, selectionKey])

    // Lộ trình của xe đang chọn + fit khung nhìn khi đổi lựa chọn.
    useEffect(() => {
        const map = mapRef.current

        if (!map) {
            return
        }

        routeLayersRef.current.forEach((layer) => map.removeLayer(layer))
        routeLayersRef.current = []

        // Lộ trình cũ của xe vừa bỏ chọn ẩn ngay, không chờ server trả về.
        configuration.routes.filter((route) => selectedIds.includes(route.vehicleId)).forEach((route) => {
            // Viền trắng dưới mỗi đường để tách khỏi đường xá của nền bản đồ; kế hoạch vẽ sau để nằm trên.
            const drawLine = (points: readonly (readonly [number, number])[], options: L.PolylineOptions, tooltip: string) => {
                if (points.length < 2) {
                    return
                }

                const latlngs = points.map(([lat, lng]) => [lat, lng] as [number, number])
                const casing = L.polyline(latlngs, { color: '#ffffff', weight: (options.weight ?? 4) + 4, opacity: 0.95, interactive: false })
                const line = L.polyline(latlngs, { ...options, lineCap: 'round', lineJoin: 'round' })
                line.bindTooltip(tooltip, { sticky: true })
                casing.addTo(map)
                line.addTo(map)
                routeLayersRef.current.push(casing, line)
            }

            drawLine(route.traveled, { color: TRAVELED_COLOR, weight: 5, opacity: 1 }, 'Đã đi')
            drawLine(route.planned, { color: PLANNED_COLOR, weight: 4, opacity: 1, dashArray: '2 9' }, 'Kế hoạch còn lại')

            route.checkpoints.forEach((checkpoint) => {
                const dot = L.circleMarker([checkpoint.lat, checkpoint.lng], {
                    radius: 6,
                    color: '#ffffff',
                    fillColor: checkpoint.color,
                    fillOpacity: 1,
                    weight: 2,
                })
                dot.bindTooltip(checkpoint.label)
                dot.addTo(map)
                routeLayersRef.current.push(dot)
            })

            route.stops.forEach((stop) => {
                const dot = L.circleMarker([stop.lat, stop.lng], {
                    radius: 7,
                    color: PLANNED_COLOR,
                    fillColor: '#ffffff',
                    fillOpacity: 1,
                    weight: 3,
                })
                dot.bindTooltip(stop.label)
                dot.addTo(map)
                routeLayersRef.current.push(dot)
            })
        })

        const fitKey = selectionKey + (routeLayersRef.current.some((layer) => layer instanceof L.Polyline) ? ':route' : '')

        if (fitKey === fitKeyRef.current) {
            return
        }

        fitKeyRef.current = fitKey
        const bounds = L.latLngBounds([])

        if (selectedIds.length === 0) {
            vehiclesRef.current.forEach((vehicle) => {
                if (vehicle.hasGps && vehicle.lat !== null && vehicle.lng !== null) {
                    bounds.extend([vehicle.lat, vehicle.lng])
                }
            })
        } else {
            selectedIds.forEach((id) => {
                const marker = markersRef.current.get(id)

                if (marker) {
                    bounds.extend(marker.getLatLng())
                }
            })

            routeLayersRef.current.forEach((layer) => {
                if (layer instanceof L.Polyline) {
                    bounds.extend(layer.getBounds())
                }
            })
        }

        if (bounds.isValid()) {
            map.flyToBounds(bounds, { padding: [60, 60], maxZoom: 15, duration: 0.6 })
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [configuration.routes, selectionKey])

    return <div ref={containerRef} className="h-full w-full" />
}

interface VehicleCardProps {
    vehicle: Snapshot<VehicleRow>
    selected: boolean
    onSelect: (id: number) => void
    onToggle: (id: number) => void
}

function VehicleCard({ vehicle, selected, onSelect, onToggle }: VehicleCardProps) {
    const pulse = vehicle.status === 'running'

    return (
        <div
            role="button"
            tabIndex={0}
            onClick={() => onSelect(vehicle.id)}
            onKeyDown={(event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault()
                    onSelect(vehicle.id)
                }
            }}
            className={`group relative flex cursor-pointer flex-col gap-1.5 rounded-lg border p-2.5 transition-all ${
                selected
                    ? 'border-l-4 bg-gray-50 shadow-xs dark:bg-gray-800'
                    : 'border-gray-200 bg-white hover:border-primary-400 hover:bg-gray-50/80 dark:border-gray-700 dark:bg-gray-850 dark:hover:bg-gray-800'
            }`}
            style={selected ? { borderLeftColor: vehicle.color, borderColor: vehicle.color } : undefined}
        >
            <div className="flex items-center justify-between gap-2">
                <div className="flex min-w-0 items-center gap-2.5">
                    <button
                        type="button"
                        className={`flex h-4 w-4 shrink-0 items-center justify-center rounded border transition-colors ${
                            selected
                                ? 'border-primary-600 bg-primary-600 text-white'
                                : 'border-gray-400 bg-white hover:border-primary-500 dark:border-gray-500 dark:bg-gray-800'
                        }`}
                        title="Chọn/Bỏ chọn để so sánh nhiều xe"
                        onClick={(event) => {
                            event.stopPropagation()
                            onToggle(vehicle.id)
                        }}
                    >
                        {selected && (
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={3} className="h-3.5 w-3.5" aria-hidden="true">
                                <path d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                        )}
                    </button>
                    <span className="truncate text-xs font-black tracking-tight text-gray-950 group-hover:text-primary-600 dark:text-white dark:group-hover:text-primary-400">
                        {vehicle.plate ?? '—'}
                    </span>
                    {pulse && (
                        <span className="relative flex h-2.5 w-2.5">
                            <span className="absolute inline-flex h-full w-full animate-ping rounded-full opacity-75" style={{ backgroundColor: vehicle.color }} />
                            <span className="relative inline-flex h-2.5 w-2.5 rounded-full" style={{ backgroundColor: vehicle.color }} />
                        </span>
                    )}
                    {!pulse && <span className="inline-block h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: vehicle.color }} />}
                </div>
                <span className="shrink-0 rounded-md px-2 py-0.5 text-[10px] font-bold text-white" style={{ backgroundColor: vehicle.color }}>
                    {vehicle.statusLabel}
                </span>
            </div>

            <div className="flex items-center justify-between pl-6.5 text-xs text-gray-700 dark:text-gray-300">
                <div className="flex items-center gap-1.5 truncate">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="h-3.5 w-3.5 shrink-0 text-gray-500" aria-hidden="true">
                        <path d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.25a7.5 7.5 0 1115 0v.75h-15v-.75z" />
                    </svg>
                    <span className="truncate font-semibold text-gray-900 dark:text-gray-100">{vehicle.driver}</span>
                    {vehicle.phone && <span className="text-[11px] font-medium text-gray-500">({vehicle.phone})</span>}
                </div>
                <span className="shrink-0 text-[11px] font-medium text-gray-500 dark:text-gray-400">{vehicle.vehicleType}</span>
            </div>

            {(vehicle.tripCode || (vehicle.speed !== null && vehicle.speed > 0)) && (
                <div className="flex items-center justify-between pl-6.5 pt-0.5 text-xs">
                    {vehicle.tripCode ? (
                        <span className="inline-flex items-center gap-1 font-mono font-bold text-blue-700 dark:text-blue-300">
                            {vehicle.tripCode} ({vehicle.ordersCount} đơn)
                        </span>
                    ) : (
                        <span />
                    )}
                    {vehicle.speed !== null && vehicle.speed > 0 && (
                        <span className="inline-flex items-center gap-1 rounded bg-amber-100/70 px-1.5 py-0.5 font-bold text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">
                            {Math.round(vehicle.speed * 10) / 10} km/h
                        </span>
                    )}
                </div>
            )}
        </div>
    )
}

function VehicleTracking({ configuration, wire }: { configuration: Snapshot<Configuration>; wire: Wire }) {
    const [search, setSearch] = useState(configuration.filters.search)

    useEffect(() => {
        setSearch(configuration.filters.search)
    }, [configuration.filters.search])

    useEffect(() => {
        if (search === configuration.filters.search) {
            return
        }

        const timer = setTimeout(() => void wire.$set('vehicleSearch', search, true), 300)

        return () => clearTimeout(timer)
    }, [search])

    // Reverb chưa chạy thì tự làm mới; có Echo thì thưa hơn vì đã có event đẩy về.
    useEffect(() => {
        const timer = setInterval(() => void wire.refreshData(), (window as Window & { Echo?: unknown }).Echo ? 60000 : 30000)

        return () => clearInterval(timer)
    }, [])

    // Chọn xe phản hồi ngay trên client (ẩn xe khác, bay tới xe); server trả lộ trình sau.
    const serverSelectionKey = configuration.selectedIds.join(',')
    const [pendingSelection, setPendingSelection] = useState<number[] | null>(null)

    useEffect(() => setPendingSelection(null), [serverSelectionKey])

    const selectedIds = pendingSelection ?? [...configuration.selectedIds]
    const selectedSet = new Set(selectedIds)
    const selectedPlates = configuration.vehicles
        .filter((vehicle) => selectedSet.has(vehicle.id))
        .map((vehicle) => vehicle.plate)
        .join(', ')

    const selectVehicle = (id: number) => {
        setPendingSelection(selectedIds.length === 1 && selectedIds[0] === id ? [] : [id])
        void wire.selectVehicle(id)
    }

    const toggleVehicle = (id: number) => {
        setPendingSelection(selectedSet.has(id) ? selectedIds.filter((value) => value !== id) : [...selectedIds, id])
        void wire.toggleCheckbox(id)
    }

    const deselectAll = () => {
        setPendingSelection([])
        void wire.deselectAll()
    }

    const visible = configuration.vehicles.slice(0, configuration.visibleCount)

    return (
        <div className="flex flex-col gap-4">
            <div className="trk-layout">
                {/* Danh sách xe */}
                <div className="trk-sidebar flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <div className="flex shrink-0 items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                        <div className="flex items-center gap-2">
                            <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-100 text-primary-700 dark:bg-primary-950 dark:text-primary-400">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="h-4 w-4" aria-hidden="true">
                                    <path d="M2.25 6.75h9l.75 1.5h6l1.5 4.5v4.5H3.75V9.75M5.25 17.25a1.5 1.5 0 100-3 1.5 1.5 0 000 3m11.25 0a1.5 1.5 0 100-3 1.5 1.5 0 000 3" />
                                </svg>
                            </span>
                            <span className="text-sm font-bold tracking-tight text-gray-900 dark:text-white">Danh sách xe</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="inline-flex items-center rounded-full bg-primary-100 px-2.5 py-0.5 text-xs font-bold text-primary-800 ring-1 ring-inset ring-primary-700/20 dark:bg-primary-950 dark:text-primary-300" title="Đã chọn / Tổng số xe">
                                Đã chọn: {selectedIds.length} / {configuration.totalCount}
                            </span>
                            <button
                                type="button"
                                className="flex h-7 w-7 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-800 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                                title="Làm mới dữ liệu"
                                onClick={() => void wire.refreshData()}
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.6} className="h-4 w-4" aria-hidden="true">
                                    <path d="M16.023 9.348h4.992V4.356M2.985 19.644v-4.992h4.992M4.532 9.348A8.25 8.25 0 0117.94 5.94l1.71 1.71M2.985 14.652a8.25 8.25 0 0013.408 3.408l1.71-1.71" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div className="shrink-0 border-b border-gray-100 px-3.5 py-2.5 dark:border-gray-700">
                        <div className="fi-input-wrp">
                            <div className="fi-input-wrp-prefix fi-input-wrp-prefix-has-content">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" className="fi-icon fi-size-md fi-color-gray" aria-hidden="true">
                                    <path d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607z" />
                                </svg>
                            </div>
                            <div className="fi-input-wrp-content-ctn">
                                <input
                                    type="search"
                                    className="fi-input"
                                    placeholder="Tìm biển số, tài xế, chuyến..."
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                />
                            </div>
                        </div>
                    </div>

                    <div className="shrink-0 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                        <div className="flex flex-wrap gap-1.5">
                            {configuration.statusOptions.map((option) => {
                                const active = configuration.filters.status === option.key

                                return (
                                    <button
                                        key={option.key}
                                        type="button"
                                        className={`inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-xs font-semibold transition-all ${
                                            active
                                                ? 'bg-primary-600 text-white shadow-sm'
                                                : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700'
                                        }`}
                                        onClick={() => void wire.$set('filterStatus', option.key, true)}
                                    >
                                        {option.color && (
                                            <span
                                                className="h-2 w-2 rounded-full"
                                                style={{ backgroundColor: active ? '#ffffff' : option.color }}
                                            />
                                        )}
                                        {option.label}
                                    </button>
                                )
                            })}
                        </div>
                    </div>

                    <div className="flex shrink-0 items-center justify-between border-b border-gray-100 bg-gray-50 px-3.5 py-2 dark:border-gray-700 dark:bg-gray-800/60">
                        <button
                            type="button"
                            className="inline-flex items-center gap-1 rounded-md bg-amber-100/80 px-2.5 py-1 text-xs font-bold text-amber-800 transition-colors hover:bg-amber-200 dark:bg-amber-950/60 dark:text-amber-200"
                            title="Chỉ chọn các xe đang chạy trên đường"
                            onClick={() => void wire.selectRunningOnly()}
                        >
                            ⚡ Xe đang chạy
                        </button>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                className="rounded-md px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-200 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700"
                                onClick={() => void wire.selectAll()}
                            >
                                Chọn hết
                            </button>
                            <span className="text-gray-300 dark:text-gray-600">|</span>
                            <button
                                type="button"
                                className="rounded-md px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-200 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700"
                                onClick={deselectAll}
                            >
                                Bỏ chọn
                            </button>
                        </div>
                    </div>

                    <div className="flex-1 space-y-1.5 overflow-y-auto overscroll-contain p-2">
                        {visible.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-10 text-center">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="mb-2 h-8 w-8 text-gray-400 dark:text-gray-600" aria-hidden="true">
                                    <path d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607z" />
                                </svg>
                                <p className="text-xs font-semibold text-gray-700 dark:text-gray-300">Không tìm thấy phương tiện nào</p>
                                <p className="mt-0.5 text-[11px] text-gray-500">Thử đổi từ khóa hoặc bộ lọc trạng thái</p>
                            </div>
                        ) : (
                            visible.map((vehicle) => (
                                <VehicleCard key={vehicle.id} vehicle={vehicle} selected={selectedSet.has(vehicle.id)} onSelect={selectVehicle} onToggle={toggleVehicle} />
                            ))
                        )}

                        {configuration.hasMore && (
                            <div className="flex flex-col items-center justify-center py-3 text-center">
                                <button
                                    type="button"
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300"
                                    onClick={() => void wire.loadMore()}
                                >
                                    Hiển thị thêm xe ({visible.length}/{configuration.totalCount})
                                </button>
                            </div>
                        )}
                    </div>
                </div>

                {/* Bản đồ */}
                <div className="trk-map relative overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <MapCanvas configuration={configuration} selectedIds={selectedIds} onSelect={selectVehicle} />
                </div>
            </div>

            {/* Chú thích + trạng thái chọn + làm mới */}
            <div className="trk-legend shrink-0 rounded-xl border border-gray-200 bg-white p-3.5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div className="flex flex-col gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <span className="font-bold text-gray-700 dark:text-gray-200">Trạng thái xe:</span>
                        {Object.entries(configuration.legend).map(([label, color]) => (
                            <span key={label} className="inline-flex items-center gap-1.5 font-medium">
                                <span className="h-3 w-3 rounded-full" style={{ backgroundColor: color }} />
                                {label}
                            </span>
                        ))}
                        {configuration.withoutGpsCount > 0 && (
                            <span className="text-gray-400">· {configuration.withoutGpsCount} xe chưa có GPS (không hiển thị)</span>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <span className="font-bold text-gray-700 dark:text-gray-200">Lộ trình xe đang chọn:</span>
                        <span className="inline-flex items-center gap-1.5 font-medium">
                            <span className="inline-block h-1.5 w-6 rounded" style={{ backgroundColor: TRAVELED_COLOR }} />
                            Đã đi
                        </span>
                        <span className="inline-flex items-center gap-1.5 font-medium">
                            <span className="inline-block w-6" style={{ borderBottom: `3px dotted ${PLANNED_COLOR}` }} />
                            Kế hoạch còn lại
                        </span>
                        <span className="inline-flex items-center gap-1.5 font-medium">
                            <span className="h-3 w-3 rounded-full" style={{ backgroundColor: '#16a34a' }} />
                            Bắt đầu
                        </span>
                        <span className="inline-flex items-center gap-1.5 font-medium">
                            <span className="h-3 w-3 rounded-full" style={{ backgroundColor: '#d97706' }} />
                            Lấy hàng
                        </span>
                        <span className="inline-flex items-center gap-1.5 font-medium">
                            <span className="h-3 w-3 rounded-full" style={{ backgroundColor: '#7c3aed' }} />
                            Giao hàng
                        </span>
                        <span className="inline-flex items-center gap-1.5 font-medium">
                            <span className="h-3 w-3 rounded-full" style={{ backgroundColor: '#dc2626' }} />
                            Kết thúc
                        </span>
                        <span className="inline-flex items-center gap-1.5 font-medium">
                            <span className="h-3 w-3 rounded-full border-[3px] bg-white" style={{ borderColor: PLANNED_COLOR }} />
                            Điểm chưa tới
                        </span>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    {selectedIds.length > 0 && (
                        <div className="flex items-center gap-2 rounded-lg bg-primary-50 px-3 py-1.5 text-xs font-semibold text-primary-700 ring-1 ring-inset ring-primary-700/20 dark:bg-primary-950 dark:text-primary-300">
                            <span className="flex h-2 w-2 animate-pulse rounded-full bg-primary-600" />
                            <span>
                                Đang chọn: <strong>{selectedPlates}</strong>
                            </span>
                            <button
                                type="button"
                                className="ml-1 inline-flex items-center rounded bg-primary-200/70 px-1.5 py-0.5 text-[11px] font-bold text-primary-800 transition-colors hover:bg-primary-300 dark:bg-primary-800 dark:text-primary-200 dark:hover:bg-primary-700"
                                title="Bỏ chọn để xem tất cả xe"
                                onClick={deselectAll}
                            >
                                ✕ Bỏ chọn
                            </button>
                        </div>
                    )}

                    <button
                        type="button"
                        className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 hover:text-primary-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-primary-400"
                        onClick={() => void wire.refreshData()}
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.6} className="h-3.5 w-3.5" aria-hidden="true">
                            <path d="M16.023 9.348h4.992V4.356M2.985 19.644v-4.992h4.992M4.532 9.348A8.25 8.25 0 0117.94 5.94l1.71 1.71M2.985 14.652a8.25 8.25 0 0013.408 3.408l1.71-1.71" />
                        </svg>
                        Làm mới
                    </button>

                    {configuration.lastUpdated && (
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-950 dark:text-emerald-300">
                            <span className="relative flex h-2 w-2">
                                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                                <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
                            </span>
                            {configuration.lastUpdated}
                        </span>
                    )}
                </div>
            </div>
        </div>
    )
}

const mountVehicleTrackingMap: JsWidgetRenderer<Configuration, WireMethods> = ({ host, props: initialProps, utilities }) => {
    const root = createRoot(host)

    const update = (props: JsWidgetProps<Configuration>) => root.render(<VehicleTracking configuration={props.configuration} wire={utilities.$wire} />)

    update(initialProps)

    return { update, destroy: () => root.unmount() }
}

export default mountVehicleTrackingMap
