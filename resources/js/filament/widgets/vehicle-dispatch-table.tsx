import { useEffect, useRef, useState } from 'react'
import { createRoot } from 'react-dom/client'
import type { JsonValue, JsWidgetProps, JsWidgetRenderer, JsWidgetUtilities, Snapshot } from '@filament/widgets/js-widget'

interface Row {
    id: number
    bsx: string
    ownerCategory: string
    orderType: string | null
    orderTypeColor: string | null
    statusLabel: string
    statusClass: string
    statusAction: { name: string; tooltip: string } | null
    pickup: string
    pickupTitle: string | null
    destination: string
    destinationTitle: string | null
    xeCho: string
    xeChoTone: 'danger' | 'warning' | 'success' | 'muted' | 'normal'
    xeChoTooltip: string
    gps: string
    speed: string
    speedClass: string
    movement: string
    movementClass: string
    movementTooltip: string | null
    tripCount: number
    tripId: number | null
    waitingOrders: number
    kmOver: string
    kmOverClass: string
    kmOverTooltip: string
    kmOverFill: number
    driverName: string
    station: string
    shift: string
}

interface Group {
    code: string
    title: string
    count: number
    rows: Row[]
}

interface Configuration {
    title: string
    columns: string[]
    groups: Group[]
    filters: { search: string }
    sort: { by: string; direction: string }
    refreshSeconds: number
    totalVehicles: number
}

interface PageApi {
    $set(path: string, value: JsonValue, live?: boolean): Promise<unknown>
    $refresh(): Promise<unknown>
    openTripModal(vehicleId: number): Promise<unknown>
    openGpsModal(vehicleId: number): Promise<unknown>
    openTimelineModal(tripId: number): Promise<unknown>
    mountTableAction(name: string, record: number): Promise<unknown>
    sortTable(column: string): Promise<unknown>
}

interface WidgetMethods {
    readonly $parent: PageApi
}

type Wire = JsWidgetUtilities<WidgetMethods>['$wire']

const badgeTones: Record<string, string> = {
    info: 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300',
    warning: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300',
    danger: 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-300',
    success: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
    primary: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300',
    gray: 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300',
}

const badge = (label: string, tone: string | null | undefined) => (
    <span className={`inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-semibold ring-1 ring-inset ring-black/5 dark:ring-white/10 ${badgeTones[tone ?? 'gray'] ?? badgeTones.gray}`}>
        {label}
    </span>
)

const xeChoTones: Record<string, string> = {
    danger: 'animate-pulse font-bold text-rose-600 dark:text-rose-400',
    warning: 'font-semibold text-amber-600 dark:text-amber-400',
    success: 'font-semibold text-emerald-600 dark:text-emerald-400',
    muted: 'text-gray-400',
    normal: 'text-gray-600 dark:text-gray-300',
}

const stationTones: Record<string, string> = {
    BN: 'warning',
    NB: 'success',
    NBA: 'success',
    TN: 'info',
    Province: 'primary',
    OTHER: 'gray',
}

function DispatchTable({ configuration, wire }: { configuration: Snapshot<Configuration>; wire: Wire }) {
    const page = wire.$parent
    const [search, setSearch] = useState(configuration.filters.search)
    const [secondsLeft, setSecondsLeft] = useState(configuration.refreshSeconds)
    const [refreshing, setRefreshing] = useState(false)
    const [collapsed, setCollapsed] = useState<Record<string, boolean>>({})
    const previousSearch = useRef(configuration.filters.search)

    const refresh = async () => {
        if (refreshing) {
            return
        }

        setRefreshing(true)
        try {
            await page.$refresh()
        } finally {
            setRefreshing(false)
            setSecondsLeft(configuration.refreshSeconds)
        }
    }

    // Đếm ngược làm mới; tab ẩn thì tạm dừng.
    useEffect(() => {
        const timer = setInterval(() => {
            if (document.hidden) {
                return
            }

            setSecondsLeft((current) => {
                if (current <= 1) {
                    void refresh()

                    return configuration.refreshSeconds
                }

                return current - 1
            })
        }, 1000)

        return () => clearInterval(timer)
    }, [refreshing, configuration.refreshSeconds])

    useEffect(() => {
        setSearch(configuration.filters.search)
        previousSearch.current = configuration.filters.search
    }, [configuration.filters.search])

    // Gõ tìm kiếm: debounce 400ms rồi cập nhật state của page (URL + query).
    useEffect(() => {
        if (search === configuration.filters.search) {
            return
        }

        const timer = setTimeout(() => void page.$set('searchQuery', search === '' ? null : search, true), 400)

        return () => clearTimeout(timer)
    }, [search])

    const toggleGroup = (code: string) => setCollapsed((current) => ({ ...current, [code]: !current[code] }))

    const callStatusAction = (row: Row) => {
        if (row.statusAction) {
            void page.mountTableAction(row.statusAction.name, row.id)
        }
    }

    const sortIndicator = (column: string) =>
        configuration.sort.by === column ? <span className="text-[9px] font-bold text-amber-300">{configuration.sort.direction === 'asc' ? '▲' : '▼'}</span> : null

    const headerClass = 'border-r border-b border-white/10 py-2 px-2 text-center whitespace-nowrap'
    const cellClass = 'border-r border-gray-100 dark:border-white/5 py-1.5 align-middle whitespace-nowrap'

    return (
        <div className="w-full space-y-3">
            <div className="flex items-center gap-2">
                <div className="w-full max-w-xs">
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
                                placeholder="Tìm biển số hoặc tên lái xe..."
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                            />
                        </div>
                    </div>
                </div>

                {search !== '' && (
                    <button type="button" className="fi-icon-btn fi-color-gray fi-size-md" title="Xoá tìm kiếm" onClick={() => setSearch('')}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.6} className="fi-icon fi-size-md" aria-hidden="true">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                )}

                <button
                    type="button"
                    title="Bấm để làm mới ngay"
                    disabled={refreshing}
                    onClick={() => void refresh()}
                    className="ms-auto inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1.5 text-xs font-medium text-gray-600 ring-1 ring-gray-950/10 transition hover:bg-gray-50 hover:text-primary-600 disabled:cursor-wait dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-white/5"
                >
                    <svg viewBox="0 0 20 20" fill="currentColor" className={`h-4 w-4 shrink-0 ${refreshing ? 'animate-spin' : ''}`} aria-hidden="true">
                        <path fillRule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H3.989a.75.75 0 0 0-.75.75v4.242a.75.75 0 0 0 1.5 0v-2.43l.31.31a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm1.23-3.723a.75.75 0 0 0 .219-.53V2.929a.75.75 0 0 0-1.5 0V5.36l-.31-.31A7 7 0 0 0 3.239 8.188a.75.75 0 1 0 1.448.389A5.5 5.5 0 0 1 13.89 6.11l.311.31h-2.432a.75.75 0 0 0 0 1.5h4.243a.75.75 0 0 0 .53-.219Z" clipRule="evenodd" />
                    </svg>
                    <span>{refreshing ? 'Đang làm mới…' : `Làm mới sau ${secondsLeft}s`}</span>
                </button>
            </div>

            <div className="dispatch-table-container overflow-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 bg-white dark:bg-gray-900 shadow-xs max-h-[calc(100vh-9rem)]">
                <table className="dispatch-table w-full text-xs border-collapse font-sans">
                    <thead className="sticky top-0 z-20 shadow-sm">
                        <tr className="dark:bg-slate-950" style={{ backgroundColor: 'rgb(30 58 95)' }}>
                            <th colSpan={18} className="text-white py-2.5 px-4 text-center font-bold tracking-wider text-sm sm:text-base border-b border-white/10 uppercase dark:bg-slate-950" style={{ backgroundColor: 'rgb(30 58 95)' }}>
                                {configuration.title}
                            </th>
                        </tr>
                        <tr className="text-white text-[11px] font-bold tracking-wide uppercase dark:bg-slate-950" style={{ backgroundColor: 'rgb(30 58 95)' }}>
                            {configuration.columns.map((label, index) => {
                                const sortable = index === 2 ? 'plate_number' : index === 5 ? 'status' : null

                                if (!sortable) {
                                    return (
                                        <th key={label} className={headerClass}>
                                            {label}
                                        </th>
                                    )
                                }

                                return (
                                    <th
                                        key={label}
                                        className={`${headerClass} cursor-pointer hover:bg-white/10 select-none transition`}
                                        title={`Bấm để sắp xếp theo ${label}`}
                                        onClick={() => void page.sortTable(sortable)}
                                    >
                                        <span className="inline-flex items-center justify-center gap-1">
                                            <span>{label}</span>
                                            {sortIndicator(sortable)}
                                        </span>
                                    </th>
                                )
                            })}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-white/5">
                        {configuration.groups.length === 0 && (
                            <tr>
                                <td colSpan={18} className="py-8 text-center text-gray-500 italic">
                                    Không tìm thấy xe nào phù hợp.
                                </td>
                            </tr>
                        )}

                        {configuration.groups.map((group) => {
                            const isCollapsed = collapsed[group.code] === true

                            return group.rows.map((row, index) => {
                                if (isCollapsed && index > 0) {
                                    return null
                                }

                                return (
                                    <tr
                                        key={`${group.code}-${row.id}`}
                                        className={`dispatch-row group/row cursor-default ${index === group.rows.length - 1 ? 'dispatch-group-end' : ''} ${index % 2 === 0 ? 'bg-white dark:bg-gray-900' : 'bg-gray-50/40 dark:bg-white/[0.015]'}`}
                                    >
                                        {index === 0 ? (
                                            <td className={`${cellClass} bg-gray-50/80 dark:bg-white/5 px-2 text-left font-bold text-gray-900 dark:text-gray-100 select-none`}>
                                                <button type="button" className="inline-flex items-center gap-1.5 font-bold transition hover:opacity-80" title={`Bấm để đóng/mở nhóm ${group.title}`} onClick={() => toggleGroup(group.code)}>
                                                    <span className="inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded font-mono text-[10px] font-bold leading-none text-white" style={{ backgroundColor: 'rgb(30 58 95)' }}>
                                                        {isCollapsed ? '+' : '−'}
                                                    </span>
                                                    <span className="text-xs">{group.title}</span>
                                                    {isCollapsed && <span className="text-[10px] font-semibold text-primary-600 dark:text-primary-400">({group.count} xe)</span>}
                                                </button>
                                            </td>
                                        ) : (
                                            <td className={`${cellClass} px-2`} />
                                        )}

                                        <td className={`${cellClass} px-1 text-center`}>
                                            {row.tripId ? (
                                                <button type="button" className="rounded-md p-1 text-primary-600 transition hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-primary-500/10" title="Xem hành trình chuyến hiện tại" onClick={() => void page.openTimelineModal(row.tripId as number)}>
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.6} className="mx-auto h-4 w-4" aria-hidden="true">
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                                                    </svg>
                                                </button>
                                            ) : (
                                                <span className="text-gray-400">—</span>
                                            )}
                                        </td>

                                        <td className={`${cellClass} px-2 font-medium text-gray-900 dark:text-gray-100`}>
                                            <span className="cursor-pointer font-semibold text-primary-600 hover:text-primary-500 hover:underline dark:text-primary-400" onClick={() => void page.openTripModal(row.id)}>
                                                <span dangerouslySetInnerHTML={{ __html: row.bsx }} />
                                            </span>
                                        </td>

                                        <td className={`${cellClass} px-2 text-center`}>{badge(row.ownerCategory, row.ownerCategory === 'Xe CTY' ? 'info' : 'warning')}</td>

                                        <td className={`${cellClass} px-1 text-center`}>{row.orderType ? badge(row.orderType, row.orderTypeColor) : <span className="text-gray-400">—</span>}</td>

                                        <td
                                            className={`status-cell ${cellClass} px-2 text-center text-[11px] select-none ${row.statusClass} ${row.statusAction ? 'cursor-pointer hover:opacity-85' : ''}`}
                                            title={row.statusAction?.tooltip}
                                            onClick={() => callStatusAction(row)}
                                        >
                                            {row.statusLabel}
                                        </td>

                                        <td className={`${cellClass} px-1.5 text-center font-medium text-gray-800 dark:text-gray-200`}>
                                            <span className="font-bold" title={row.pickupTitle ?? undefined}>
                                                {row.pickup}
                                            </span>
                                        </td>
                                        <td className={`${cellClass} px-2 font-medium text-gray-800 dark:text-gray-200`} title={row.destinationTitle ?? undefined}>
                                            {row.destination}
                                        </td>

                                        <td className={`${cellClass} px-1.5 text-center font-medium`} title={row.xeChoTooltip}>
                                            <span className={xeChoTones[row.xeChoTone]}>{row.xeCho}</span>
                                        </td>

                                        <td className={`${cellClass} px-2 text-gray-700 dark:text-gray-300`}>
                                            <span className="cursor-pointer font-medium text-primary-600 hover:text-primary-500 hover:underline dark:text-primary-400" title="Bấm để xem trên bản đồ" onClick={() => void page.openGpsModal(row.id)}>
                                                {row.gps}
                                            </span>
                                        </td>

                                        <td className={`${cellClass} px-1 text-center font-bold text-gray-900 dark:text-white`}>
                                            <span className={`inline-block min-w-[46px] rounded px-1 py-0.5 ${row.speedClass}`}>{row.speed}</span>
                                        </td>

                                        <td className={`status-cell ${cellClass} px-2 text-center text-[11px] select-none ${row.movementClass}`} title={row.movementTooltip ?? undefined}>
                                            {row.movement}
                                        </td>

                                        <td className={`${cellClass} px-1 text-center font-bold text-gray-800 dark:text-gray-200`}>
                                            <span className="cursor-pointer font-bold text-primary-600 hover:text-primary-500 hover:underline dark:text-primary-400" title="Bấm để xem danh sách chuyến trong ngày" onClick={() => void page.openTripModal(row.id)}>
                                                {row.tripCount}
                                            </span>
                                        </td>

                                        <td className={`${cellClass} px-1 text-center font-bold`}>
                                            <span title="Bấm để xem danh sách chuyến và đơn chờ" className={`cursor-pointer hover:underline ${row.waitingOrders > 0 ? 'text-primary-600 dark:text-primary-400' : 'text-gray-400'}`} onClick={() => void page.openTripModal(row.id)}>
                                                {row.waitingOrders}
                                            </span>
                                        </td>

                                        <td
                                            className={`${cellClass} px-1.5 text-center font-mono text-[11px] ${row.kmOverClass}`}
                                            title={row.kmOverTooltip}
                                            style={row.kmOverClass.includes('km-over-cell') ? undefined : row.kmOverFill > 0 ? { background: `linear-gradient(to right, rgb(59 130 246 / 0.22) ${row.kmOverFill}%, transparent ${row.kmOverFill}%)` } : undefined}
                                        >
                                            <span>{row.kmOver}</span>
                                        </td>

                                        <td className={`${cellClass} px-2 font-medium text-gray-800 dark:text-gray-200`}>{row.driverName}</td>

                                        <td className={`${cellClass} px-1 text-center font-bold text-[11px]`}>
                                            {row.station && row.station !== '—' && row.station !== 'OTHER' ? badge(row.station, stationTones[row.station] ?? 'gray') : <span className="text-gray-400">—</span>}
                                        </td>

                                        <td className={`px-1 py-1.5 text-center font-medium text-[11px] text-gray-700 dark:text-gray-300`}>
                                            <span className="font-bold">{row.shift}</span>
                                        </td>
                                    </tr>
                                )
                            })
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    )
}

const mountVehicleDispatchTable: JsWidgetRenderer<Configuration, WidgetMethods> = ({ host, props: initialProps, utilities }) => {
    const root = createRoot(host)

    const update = (props: JsWidgetProps<Configuration>) => root.render(<DispatchTable configuration={props.configuration} wire={utilities.$wire} />)

    update(initialProps)

    return { update, destroy: () => root.unmount() }
}

export default mountVehicleDispatchTable
