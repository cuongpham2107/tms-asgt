import { useDeferredValue, useEffect, useMemo, useRef, useState } from 'react'
import type { CSSProperties, ReactNode } from 'react'
import { createRoot } from 'react-dom/client'
import type { JsWidgetProps, JsWidgetRenderer, JsWidgetUtilities } from '@filament/widgets/js-widget'
import { type Deferred, requestDataOnce, TableSkeleton } from './table-skeleton'

interface Driver {
    id: number
    name: string
    code: string | null
    station: string
    stationLabel: string
    trips: number
    emptyTrips: number
    kmTotal: number
    kmLoaded: number
    kmEmpty: number
}

interface Trip {
    id: number
    driverId: number
    code: string
    plate: string | null
    day: string | null
    time: string | null
    status: 'cancelled' | 'swap' | null
    orderCodes: readonly string[]
    isEmpty: boolean
    kmTotal: number
    kmLoaded: number
    kmEmpty: number
}

interface StatementRow {
    key: string
    tripId: number
    driverId: number | null
    driver: string
    date: string | null
    code: string
    type: string
    plate: string | null
    started: string | null
    ended: string | null
    journey: string
    times: (string | null)[]
    durations: string[]
    customer: string
    warehouse: string
    vehicle: string
    kmLoaded: number
    kmEmpty: number
    pcs: number
    gw: number
    cargoType: string
    note: string
}

type Period = 'today' | 'week' | 'month'

interface Configuration {
    period: Period
    date: string
    periodLabel: string
    isCurrent: boolean
    hasNext: boolean
    drivers: readonly Driver[]
    trips: readonly Trip[]
    totals: { trips: number; emptyTrips: number; kmTotal: number; kmLoaded: number; kmEmpty: number }
}

/** Hàm của page: mở popup hành trình chuyến (checkpoint, ảnh, vị trí). */
interface PageApi {
    openTripTimeline(tripId: number): Promise<unknown>
}

interface WidgetMethods {
    readonly $parent: PageApi
    loadReport(period: Period, date: string): Promise<Configuration>
    loadDetails(period: Period, date: string): Promise<{ statement: StatementRow[]; evaluation: Evaluation }>
}

type Wire = JsWidgetUtilities<WidgetMethods>['$wire']

type Tab = 'summary' | 'statement' | 'evaluation'

const NAVY: CSSProperties = { backgroundColor: 'rgb(30 58 95)' }
const PER_PAGE_OPTIONS = [25, 50, 100]
const WEEKDAYS = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7']
const STATEMENT_HEADERS = ['STT', 'Ngày', 'Mã đơn / chuyến', 'Loại', 'BSX', 'Lái xe', 'Bắt đầu chuyến', 'Kết thúc chuyến', 'Hành trình', 'A: Đến đóng', 'B: Xe chạy', 'C: Đến trả', 'D: Hạ xong', 'TG đóng', 'TG chạy', 'TG hạ', 'Tổng TG', 'Khách hàng', 'Kho', 'Xe', 'Km CH', 'Km KH', 'PCS', 'GW', 'Loại hàng', 'Ghi chú']

const km = (value: number): string => value.toFixed(1)
const optional = (value: number, decimals = 1): string => (value > 0 ? value.toFixed(decimals) : '')
const loadedPercent = (loaded: number, total: number): number => (total > 0 ? Math.round((loaded / total) * 100) : 0)
const fill = (percent: number): CSSProperties | undefined =>
    percent > 0 ? { background: `linear-gradient(to right, rgb(16 185 129 / 0.25) ${percent}%, transparent ${percent}%)` } : undefined
const normalize = (text: string): string => text.toLocaleLowerCase('vi')
const dayLabel = (day: string | null): string => {
    if (!day) {
        return '—'
    }
    const [year, month, date] = day.split('-').map(Number)

    return `${WEEKDAYS[new Date(year, month - 1, date).getDay()]}, ${String(date).padStart(2, '0')}/${String(month).padStart(2, '0')}`
}

/** Tab, ô tìm, lái xe đang chọn và số dòng/trang nằm trên URL để F5 hay gửi link vẫn giữ nguyên. */
const readUrlState = () => {
    const params = new URLSearchParams(window.location.search)
    const driverId = Number(params.get('driverId'))
    const perPage = Number(params.get('perPage'))

    return {
        tab: (['statement', 'evaluation'].includes(params.get('tab') ?? '') ? params.get('tab') : 'summary') as Tab,
        search: params.get('search') ?? '',
        driverId: driverId > 0 ? driverId : null,
        perPage: PER_PAGE_OPTIONS.includes(perPage) ? perPage : PER_PAGE_OPTIONS[0],
    }
}

const writeUrlState = (state: Record<string, string | number | null>) => {
    const url = new URL(window.location.href)
    Object.entries(state).forEach(([key, value]) => (value === null || value === '' ? url.searchParams.delete(key) : url.searchParams.set(key, String(value))))
    window.history.replaceState(window.history.state, '', url)
}

const th = 'whitespace-nowrap border-b border-r border-white/10 px-2 py-2 text-center last:border-r-0'
const td = 'whitespace-nowrap border-r border-gray-100 px-2 py-1.5 dark:border-white/5'
const footTd = 'border-r border-t-2 border-gray-300 px-2 py-2 dark:border-white/10'
const card = 'dispatch-table-container overflow-auto rounded-xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10'
const zebra = (index: number): string => (index % 2 === 0 ? 'bg-white dark:bg-gray-900' : 'bg-gray-50/40 dark:bg-white/[0.015]')

const Badge = ({ tone, children }: { tone: 'warning' | 'danger' | 'info' | 'primary'; children: ReactNode }) => {
    const tones = {
        warning: 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-400/10 dark:text-amber-400',
        danger: 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-400/10 dark:text-rose-400',
        info: 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-400/10 dark:text-sky-400',
        primary: 'bg-primary-50 text-primary-700 ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-400',
    }

    return <span className={`inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-semibold ring-1 ring-inset ${tones[tone]}`}>{children}</span>
}

const Icon = ({ path, className = 'h-4 w-4' }: { path: string; className?: string }) => (
    <svg className={className} viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fillRule="evenodd" clipRule="evenodd" d={path} />
    </svg>
)

const ICONS = {
    search: 'M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.45 4.39l3.08 3.08a.75.75 0 1 1-1.06 1.06l-3.08-3.08A7 7 0 0 1 2 9Z',
    close: 'M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z',
    chart: 'M15.5 2A1.5 1.5 0 0 0 14 3.5v13a1.5 1.5 0 0 0 3 0v-13A1.5 1.5 0 0 0 15.5 2ZM9.5 6A1.5 1.5 0 0 0 8 7.5v9a1.5 1.5 0 0 0 3 0v-9A1.5 1.5 0 0 0 9.5 6ZM3.5 10A1.5 1.5 0 0 0 2 11.5v5a1.5 1.5 0 0 0 3 0v-5A1.5 1.5 0 0 0 3.5 10Z',
    document: 'M4.5 2A1.5 1.5 0 0 0 3 3.5v13A1.5 1.5 0 0 0 4.5 18h11a1.5 1.5 0 0 0 1.5-1.5V7.62a1.5 1.5 0 0 0-.44-1.06l-4.12-4.12A1.5 1.5 0 0 0 11.38 2H4.5Zm2.25 8.5a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Zm0 3a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Z',
    chevronLeft: 'M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z',
    calendar: 'M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2Zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75Z',
    clock: 'M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 0 0 0-1.5h-3.25V5Z',
    chevronRight: 'M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z',
}

const EmptyState = ({ heading, description }: { heading: string; description: string }) => (
    <div className="px-6 py-12 text-center">
        <div className="text-sm font-semibold text-gray-900 dark:text-white">{heading}</div>
        <div className="mt-1 text-xs text-gray-500 dark:text-gray-400">{description}</div>
    </div>
)

const DriverTable = ({ drivers, totals, totalDrivers, activeDrivers, periodLabel, selectedId, onSelect }: {
    drivers: readonly Driver[]
    totals: Configuration['totals']
    totalDrivers: number
    activeDrivers: number
    periodLabel: string
    selectedId: number | null
    onSelect: (id: number) => void
}) => {
    const [collapsed, setCollapsed] = useState<Record<string, boolean>>({})
    const groups = useMemo(() => {
        const byStation = new Map<string, Driver[]>()
        drivers.forEach((driver) => byStation.set(driver.station, [...(byStation.get(driver.station) ?? []), driver]))

        return [...byStation.entries()]
    }, [drivers])

    return (
        <div className={`${card} xl:col-span-3`}>
            <table className="dispatch-table w-full border-collapse font-sans text-xs">
                <thead className="sticky top-0 z-20 shadow-sm">
                    <tr style={NAVY}>
                        <th colSpan={9} className="border-b border-white/10 px-4 py-2.5 text-center text-sm font-bold uppercase tracking-wider text-white sm:text-base">
                            Thống kê chuyến & km lái xe · {periodLabel}
                        </th>
                    </tr>
                    <tr className="text-[11px] font-bold uppercase tracking-wide text-white" style={NAVY}>
                        <th className={`${th} min-w-[110px]`}>Điểm trực</th>
                        <th className={`${th} min-w-[150px]`}>Tên lái xe</th>
                        <th className={`${th} min-w-[90px]`}>Mã NV</th>
                        <th className={`${th} min-w-[55px]`}>Chuyến</th>
                        <th className={`${th} min-w-[70px]`} title="Chuyến không có đơn">Chuyến KH</th>
                        <th className={`${th} min-w-[70px]`}>Km CH</th>
                        <th className={`${th} min-w-[70px]`}>Km KH</th>
                        <th className={`${th} min-w-[75px]`}>Tổng km</th>
                        <th className={`${th} min-w-[80px]`}>% có hàng</th>
                    </tr>
                </thead>

                <tbody className="divide-y divide-gray-100 dark:divide-white/5">
                    {groups.length === 0 && (
                        <tr>
                            <td colSpan={9} className="px-3 py-8 text-center text-gray-500">Không có lái xe phù hợp.</td>
                        </tr>
                    )}
                    {groups.map(([station, rows]) => {
                        const isCollapsed = collapsed[station] ?? false
                        const visible = isCollapsed ? rows.slice(0, 1) : rows

                        return visible.map((driver, index) => {
                            const isSelected = driver.id === selectedId
                            const isIdle = driver.trips === 0
                            const percent = loadedPercent(driver.kmLoaded, driver.kmTotal)
                            const isGroupEnd = index === visible.length - 1

                            return (
                                <tr
                                    key={driver.id}
                                    onClick={() => onSelect(driver.id)}
                                    className={`dispatch-row cursor-pointer ${isGroupEnd ? 'dispatch-group-end' : ''} ${isSelected ? 'bg-primary-50 dark:bg-primary-500/15' : zebra(index)}`}
                                >
                                    {index === 0 ? (
                                        <td className={`${td} select-none bg-gray-50/80 text-left font-bold text-gray-900 dark:bg-white/5 dark:text-gray-100`}>
                                            <button
                                                type="button"
                                                className="inline-flex cursor-pointer items-center gap-1.5 font-bold transition hover:opacity-80"
                                                title={`Bấm để đóng/mở nhóm ${driver.stationLabel}`}
                                                onClick={(event) => {
                                                    event.stopPropagation()
                                                    setCollapsed((current) => ({ ...current, [station]: !isCollapsed }))
                                                }}
                                            >
                                                <span className="inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded font-mono text-[10px] font-bold leading-none text-white" style={NAVY}>
                                                    {isCollapsed ? '+' : '−'}
                                                </span>
                                                <span className="text-xs">{driver.stationLabel}</span>
                                                {isCollapsed && <span className="text-[10px] font-semibold text-primary-600 dark:text-primary-400">({rows.length} LX)</span>}
                                            </button>
                                        </td>
                                    ) : (
                                        <td className={td} />
                                    )}
                                    <td className={`${td} font-semibold ${isIdle ? 'text-gray-400 dark:text-gray-500' : 'text-primary-600 dark:text-primary-400'}`} title={`Bấm để xem chuyến của ${driver.name}`}>
                                        <span className="hover:underline">{driver.name}</span>
                                    </td>
                                    <td className={`${td} text-center font-mono text-[11px] text-gray-600 dark:text-gray-400`}>{driver.code ?? '—'}</td>
                                    <td className={`${td} px-1 text-center font-bold text-gray-800 dark:text-gray-200`}>{driver.trips}</td>
                                    <td className={`${td} px-1 text-center ${driver.emptyTrips > 0 ? 'font-bold text-amber-700 dark:text-amber-400' : 'text-gray-400 dark:text-gray-500'}`}>{driver.emptyTrips}</td>
                                    <td className={`${td} text-right font-mono text-[11px] text-emerald-700 dark:text-emerald-400`}>{km(driver.kmLoaded)}</td>
                                    <td className={`${td} text-right font-mono text-[11px] text-amber-700 dark:text-amber-400`}>{km(driver.kmEmpty)}</td>
                                    <td className={`${td} text-right font-mono text-[11px] font-bold text-gray-900 dark:text-white`}>{km(driver.kmTotal)}</td>
                                    <td
                                        className="km-over-cell whitespace-nowrap px-2 py-1.5 text-center font-mono text-[11px] font-semibold text-gray-800 dark:text-gray-200"
                                        title={`Có hàng ${km(driver.kmLoaded)} / ${km(driver.kmTotal)} km`}
                                        style={fill(percent)}
                                    >
                                        {isIdle ? '—' : `${percent}%`}
                                    </td>
                                </tr>
                            )
                        })
                    })}
                </tbody>

                <tfoot className="sticky bottom-0 z-10">
                    <tr className="bg-gray-100 text-xs font-bold text-gray-900 dark:bg-gray-800 dark:text-white">
                        <td colSpan={3} className={`${footTd} uppercase`}>
                            Tổng · {activeDrivers}/{totalDrivers} lái xe có chạy
                        </td>
                        <td className={`${footTd} px-1 text-center`}>{totals.trips.toLocaleString('vi-VN')}</td>
                        <td className={`${footTd} px-1 text-center text-amber-700 dark:text-amber-400`}>{totals.emptyTrips.toLocaleString('vi-VN')}</td>
                        <td className={`${footTd} text-right font-mono text-[11px] text-emerald-700 dark:text-emerald-400`}>{km(totals.kmLoaded)}</td>
                        <td className={`${footTd} text-right font-mono text-[11px] text-amber-700 dark:text-amber-400`}>{km(totals.kmEmpty)}</td>
                        <td className={`${footTd} text-right font-mono text-[11px]`}>{km(totals.kmTotal)}</td>
                        <td className="border-t-2 border-gray-300 px-2 py-2 text-center font-mono text-[11px] dark:border-white/10">{loadedPercent(totals.kmLoaded, totals.kmTotal)}%</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    )
}

const DriverTrips = ({ driver, trips, periodLabel, onClose, onOpenTrip }: { driver: Driver | null; trips: readonly Trip[]; periodLabel: string; onClose: () => void; onOpenTrip: (tripId: number) => void }) => {
    const days = useMemo(() => {
        const byDay = new Map<string, Trip[]>()
        trips.forEach((trip) => byDay.set(trip.day ?? '', [...(byDay.get(trip.day ?? '') ?? []), trip]))

        return [...byDay.entries()]
    }, [trips])

    if (driver === null) {
        return (
            <div className={`${card} xl:sticky xl:top-20 xl:col-span-2`}>
                <div className="border-b border-white/10 px-3 py-2.5 text-center text-sm font-bold uppercase tracking-wider text-white" style={NAVY}>
                    Chi tiết chuyến
                </div>
                <EmptyState heading="Chọn một lái xe" description="Bấm vào một dòng trong bảng để xem các chuyến và km trong kỳ." />
            </div>
        )
    }

    return (
        <div className={`${card} xl:sticky xl:top-20 xl:col-span-2`}>
            <table className="dispatch-table w-full border-collapse font-sans text-xs">
                <thead className="sticky top-0 z-20 shadow-sm">
                    <tr style={NAVY}>
                        <th colSpan={6} className="border-b border-white/10 px-3 py-2.5 text-left text-white">
                            <div className="flex items-center justify-between gap-2">
                                <div className="min-w-0">
                                    <div className="truncate text-sm font-bold uppercase tracking-wider">{driver.name}</div>
                                    <div className="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px] font-medium normal-case text-white/70">
                                        {[driver.code, driver.station === 'none' ? null : driver.stationLabel, periodLabel].filter(Boolean).join(' · ')}
                                    </div>
                                </div>
                                <button type="button" className="shrink-0 rounded p-1 text-white/70 transition hover:bg-white/10 hover:text-white" title="Đóng" onClick={onClose}>
                                    <Icon path={ICONS.close} />
                                </button>
                            </div>
                        </th>
                    </tr>
                    <tr className="text-[11px] font-bold uppercase tracking-wide text-white" style={NAVY}>
                        {['Ngày', 'Mã đơn', 'BSX', 'Km CH', 'Km KH', 'Tổng'].map((label) => (
                            <th key={label} className={th}>
                                {label}
                            </th>
                        ))}
                    </tr>
                </thead>

                <tbody className="divide-y divide-gray-100 dark:divide-white/5">
                    {days.length === 0 && (
                        <tr>
                            <td colSpan={6}>
                                <EmptyState heading="Chưa có chuyến" description="Lái xe chưa có chuyến nào được chốt trong kỳ này." />
                            </td>
                        </tr>
                    )}
                    {days.map(([day, dayTrips]) =>
                        dayTrips.map((trip, index) => {
                            const percent = loadedPercent(trip.kmLoaded, trip.kmTotal)

                            return (
                                <tr key={`${trip.id}-${trip.driverId}`} onClick={() => onOpenTrip(trip.id)} title="Bấm để xem hành trình chuyến" className={`dispatch-row cursor-pointer ${index === dayTrips.length - 1 ? 'dispatch-group-end' : ''} ${zebra(index)}`}>
                                    {index === 0 ? (
                                        <td className={`${td} bg-gray-50/80 font-bold text-gray-900 dark:bg-white/5 dark:text-gray-100`}>{dayLabel(day || null)}</td>
                                    ) : (
                                        <td className={td} />
                                    )}
                                    <td className="border-r border-gray-100 px-2 py-1.5 dark:border-white/5" title={`Chuyến ${trip.code}`}>
                                        <div className="flex flex-wrap items-center gap-1">
                                            <span className="font-mono text-[10px] text-gray-500 dark:text-gray-400">{trip.time}</span>
                                            {trip.isEmpty ? (
                                                <>
                                                    <span className="font-semibold text-gray-500 dark:text-gray-400">{trip.code}</span>
                                                    <Badge tone="warning">Không hàng</Badge>
                                                </>
                                            ) : (
                                                <span className="font-semibold text-gray-900 dark:text-gray-100">{trip.orderCodes.join(', ')}</span>
                                            )}
                                            {trip.status === 'cancelled' && <Badge tone="danger">Huỷ</Badge>}
                                            {trip.status === 'swap' && <Badge tone="info">Đảo lái</Badge>}
                                        </div>
                                    </td>
                                    <td className={`${td} text-center font-semibold text-gray-800 dark:text-gray-200`}>{trip.plate ?? '—'}</td>
                                    <td className={`${td} text-right font-mono text-[11px] text-emerald-700 dark:text-emerald-400`}>{km(trip.kmLoaded)}</td>
                                    <td className={`${td} text-right font-mono text-[11px] text-amber-700 dark:text-amber-400`}>{km(trip.kmEmpty)}</td>
                                    <td className="km-over-cell whitespace-nowrap px-2 py-1.5 text-right font-mono text-[11px] font-bold text-gray-900 dark:text-white" title={`Có hàng ${percent}%`} style={fill(percent)}>
                                        {km(trip.kmTotal)}
                                    </td>
                                </tr>
                            )
                        }),
                    )}
                </tbody>

                <tfoot className="sticky bottom-0 z-10">
                    <tr className="bg-gray-100 text-xs font-bold text-gray-900 dark:bg-gray-800 dark:text-white">
                        <td colSpan={3} className={`${footTd} uppercase`}>
                            {driver.trips} chuyến ({driver.emptyTrips} không hàng) · {loadedPercent(driver.kmLoaded, driver.kmTotal)}% có hàng
                        </td>
                        <td className={`${footTd} text-right font-mono text-[11px] text-emerald-700 dark:text-emerald-400`}>{km(driver.kmLoaded)}</td>
                        <td className={`${footTd} text-right font-mono text-[11px] text-amber-700 dark:text-amber-400`}>{km(driver.kmEmpty)}</td>
                        <td className="border-t-2 border-gray-300 px-2 py-2 text-right font-mono text-[11px] dark:border-white/10">{km(driver.kmTotal)}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    )
}

const range = (from: number, to: number): number[] => Array.from({ length: to - from + 1 }, (_, index) => from + index)

/** Dải số trang giống UrlWindow của Laravel (onEachSide = 3) mà <x-filament::pagination> dùng. */
const pageNumbers = (current: number, last: number): (number | '...')[] => {
    const onEachSide = 3
    const window = onEachSide + 4

    if (last < onEachSide * 2 + 8) {
        return range(1, last)
    }
    if (current <= window) {
        return [...range(1, window + onEachSide), '...', last - 1, last]
    }
    if (current > last - window) {
        return [1, 2, '...', ...range(last - (window + onEachSide - 1), last)]
    }

    return [1, 2, '...', ...range(current - onEachSide, current + onEachSide), '...', last - 1, last]
}

const PaginationItem = ({ label, icon, active = false, disabled = false, ariaLabel, onClick }: {
    label?: string | number
    icon?: string
    active?: boolean
    disabled?: boolean
    ariaLabel?: string
    onClick?: () => void
}) => (
    <li className={`fi-pagination-item ${disabled ? 'fi-disabled' : ''} ${active ? 'fi-active' : ''}`}>
        <button type="button" className="fi-pagination-item-btn" aria-label={ariaLabel} aria-current={active ? 'page' : undefined} aria-hidden={disabled || undefined} disabled={disabled} onClick={onClick}>
            {icon && <Icon path={icon} className="fi-pagination-item-icon fi-icon fi-size-md" />}
            {label !== undefined && <span className="fi-pagination-item-label">{typeof label === 'number' ? label.toLocaleString('vi-VN') : label}</span>}
        </button>
    </li>
)

const PerPageSelect = ({ value, onChange, prefix }: { value: number; onChange: (perPage: number) => void; prefix?: string }) => (
    <div className="fi-input-wrp">
        {prefix && (
            <div className="fi-input-wrp-prefix fi-input-wrp-prefix-has-content fi-input-wrp-prefix-has-label">
                <span className="fi-input-wrp-label">{prefix}</span>
            </div>
        )}
        <div className="fi-input-wrp-content-ctn">
            <select className="fi-select-input" value={value} onChange={(event) => onChange(Number(event.target.value))}>
                {PER_PAGE_OPTIONS.map((option) => (
                    <option key={option} value={option}>
                        {option}
                    </option>
                ))}
            </select>
        </div>
    </div>
)

/** Cùng markup/class với <x-filament::pagination> để giao diện đồng bộ với các bảng Filament khác. */
const Pagination = ({ page, lastPage, total, perPage, onPage, onPerPage }: {
    page: number
    lastPage: number
    total: number
    perPage: number
    onPage: (page: number) => void
    onPerPage: (perPage: number) => void
}) => {
    const first = (page - 1) * perPage + 1
    const last = Math.min(page * perPage, total)
    const format = (value: number): string => value.toLocaleString('vi-VN')

    return (
        <nav aria-label="Điều hướng phân trang" className="fi-pagination">
            {page > 1 && (
                <button type="button" rel="prev" className="fi-btn fi-size-md fi-pagination-previous-btn" onClick={() => onPage(page - 1)}>
                    Trước đó
                </button>
            )}

            <span className="fi-pagination-overview">{total === 1 ? 'Đang hiện 1 kết quả' : `Đang hiện từ ${format(first)} đến ${format(last)} của ${format(total)} kết quả`}</span>

            <div className="fi-pagination-records-per-page-select-ctn">
                <label className="fi-pagination-records-per-page-select fi-compact">
                    <PerPageSelect value={perPage} onChange={onPerPage} />
                    <span className="fi-sr-only">Mỗi trang</span>
                </label>
                <label className="fi-pagination-records-per-page-select">
                    <PerPageSelect value={perPage} onChange={onPerPage} prefix="Mỗi trang" />
                </label>
            </div>

            {page < lastPage && (
                <button type="button" rel="next" className="fi-btn fi-size-md fi-pagination-next-btn" onClick={() => onPage(page + 1)}>
                    Tiếp theo
                </button>
            )}

            {lastPage > 1 && (
                <ol className="fi-pagination-items">
                    {page > 1 && <PaginationItem icon={ICONS.chevronLeft} ariaLabel="Trước đó" onClick={() => onPage(page - 1)} />}
                    {pageNumbers(page, lastPage).map((number, index) =>
                        number === '...' ? (
                            <PaginationItem key={`gap-${index}`} label="..." disabled />
                        ) : (
                            <PaginationItem key={number} label={number} active={number === page} ariaLabel={`Đi tới trang ${number}`} onClick={() => onPage(number)} />
                        ),
                    )}
                    {page < lastPage && <PaginationItem icon={ICONS.chevronRight} ariaLabel="Tiếp theo" onClick={() => onPage(page + 1)} />}
                </ol>
            )}
        </nav>
    )
}

/** Độ rộng thanh skeleton thay đổi theo ô để trông giống dữ liệu thật thay vì một khối đều. */
const skeletonWidth = (row: number, column: number): string => `${45 + ((row * 7 + column * 13) % 50)}%`

const SkeletonRows = ({ columns, rows = 10 }: { columns: number; rows?: number }) => (
    <tbody className="divide-y divide-gray-100 dark:divide-white/5" aria-busy="true">
        {Array.from({ length: rows }, (_, row) => (
            <tr key={row} className={zebra(row)}>
                {Array.from({ length: columns }, (_, column) => (
                    <td key={column} className="border-r border-gray-100 px-2 py-2.5 last:border-r-0 dark:border-white/5">
                        <div className="h-2.5 animate-pulse rounded bg-gray-200 motion-reduce:animate-none dark:bg-white/10" style={{ width: skeletonWidth(row, column) }} />
                    </td>
                ))}
            </tr>
        ))}
    </tbody>
)

/** Khung bảng đang tải: giữ đầu bảng navy như bảng thật để bố cục không nhảy khi dữ liệu về. */
const SkeletonCard = ({ title, columns, rows = 12, className = '' }: { title: string; columns: number; rows?: number; className?: string }) => (
    <div className={`${card} ${className}`} role="status" aria-label="Đang tải dữ liệu">
        <table className="dispatch-table w-full border-collapse font-sans text-xs">
            <thead>
                <tr style={NAVY}>
                    <th colSpan={columns} className="border-b border-white/10 px-4 py-2.5 text-center text-sm font-bold uppercase tracking-wider text-white sm:text-base">
                        {title}
                    </th>
                </tr>
                <tr style={NAVY}>
                    {Array.from({ length: columns }, (_, column) => (
                        <th key={column} className={th}>
                            <div className="mx-auto h-2.5 w-3/5 rounded bg-white/20" />
                        </th>
                    ))}
                </tr>
            </thead>
            <SkeletonRows columns={columns} rows={rows} />
        </table>
    </div>
)

const Statement = ({ rows, loading, error, periodLabel, perPage, onPerPage, onOpenTrip }: {
    rows: StatementRow[]
    loading: boolean
    error: string | null
    periodLabel: string
    perPage: number
    onPerPage: (perPage: number) => void
    onOpenTrip: (tripId: number) => void
}) => {
    const [page, setPage] = useState(1)
    const lastPage = Math.max(1, Math.ceil(rows.length / perPage))
    const current = Math.min(page, lastPage)
    const offset = (current - 1) * perPage
    const visible = rows.slice(offset, offset + perPage)

    useEffect(() => setPage(1), [rows, perPage])

    return (
        <>
            <div className={card}>
                <table className="dispatch-table w-full border-collapse font-sans text-xs">
                    <thead className="sticky top-0 z-20 shadow-sm">
                        <tr style={NAVY}>
                            <th colSpan={STATEMENT_HEADERS.length} className="border-b border-white/10 px-4 py-2.5 text-center text-sm font-bold uppercase tracking-wider text-white sm:text-base">
                                Bảng kê chuyến · {periodLabel}
                            </th>
                        </tr>
                        <tr className="text-[11px] font-bold uppercase tracking-wide text-white" style={NAVY}>
                            {STATEMENT_HEADERS.map((header) => (
                                <th key={header} className={th}>
                                    {header}
                                </th>
                            ))}
                        </tr>
                    </thead>

                    {loading ? (
                        <SkeletonRows columns={STATEMENT_HEADERS.length} />
                    ) : (
                    <tbody className="divide-y divide-gray-100 dark:divide-white/5">
                        {visible.length === 0 && (
                            <tr>
                                <td colSpan={STATEMENT_HEADERS.length} className={`px-3 py-8 text-center ${error ? 'text-rose-600' : 'text-gray-500'}`}>
                                    {error ?? (loading ? 'Đang tải bảng kê…' : 'Không có chuyến hoàn thành nào trong kỳ.')}
                                </td>
                            </tr>
                        )}
                        {visible.map((row, index) => (
                            <tr key={row.key} onClick={() => onOpenTrip(row.tripId)} title="Bấm để xem hành trình chuyến" className={`dispatch-row cursor-pointer ${zebra(index)}`}>
                                <td className={`${td} text-center text-gray-500`}>{offset + index + 1}</td>
                                <td className={td}>{row.date ?? '—'}</td>
                                <td className={`${td} font-semibold text-gray-900 dark:text-gray-100`}>{row.code}</td>
                                <td className={td}>{row.type}</td>
                                <td className={`${td} text-center font-semibold`}>{row.plate ?? '—'}</td>
                                <td className={td}>{row.driver}</td>
                                <td className={`${td} text-center font-mono text-[11px] font-semibold text-emerald-700 dark:text-emerald-400`}>{row.started ?? '—'}</td>
                                <td className={`${td} text-center font-mono text-[11px] font-semibold text-rose-700 dark:text-rose-400`}>{row.ended ?? '—'}</td>
                                <td className={td}>{row.journey}</td>
                                {row.times.map((time, i) => (
                                    <td key={`t${i}`} className={`${td} text-center font-mono text-[11px]`}>
                                        {time ?? '—'}
                                    </td>
                                ))}
                                {row.durations.map((duration, i) => (
                                    <td key={`d${i}`} className={`${td} text-center font-mono text-[11px]`}>
                                        {duration}
                                    </td>
                                ))}
                                <td className={td}>{row.customer}</td>
                                <td className={td}>{row.warehouse}</td>
                                <td className={td}>{row.vehicle}</td>
                                <td className={`${td} text-right font-mono text-[11px] text-emerald-700 dark:text-emerald-400`}>{optional(row.kmLoaded)}</td>
                                <td className={`${td} text-right font-mono text-[11px] text-amber-700 dark:text-amber-400`}>{optional(row.kmEmpty)}</td>
                                <td className={`${td} text-right font-mono text-[11px]`}>{optional(row.pcs, 0)}</td>
                                <td className={`${td} text-right font-mono text-[11px]`}>{optional(row.gw)}</td>
                                <td className={td}>{row.cargoType}</td>
                                <td className="min-w-[160px] px-2 py-1.5 text-gray-600 dark:text-gray-400">{row.note}</td>
                            </tr>
                        ))}
                    </tbody>
                    )}
                </table>
            </div>

            {!loading && rows.length > 0 && <Pagination page={current} lastPage={lastPage} total={rows.length} perPage={perPage} onPage={setPage} onPerPage={onPerPage} />}
        </>
    )
}

interface EvaluationDriver {
    id: number
    name: string
    trips: number
    flagged: number
    completion: number | null
    completionDiff: number | null
    completionDiffPercent: number | null
    completionCompared: number
    wait: number | null
    loading: number | null
    unloading: number | null
    waitDiff: number | null
    waitDiffPercent: number | null
    waitCompared: number
}

interface EvaluationTrip {
    id: number
    driverId: number
    day: string | null
    time: string | null
    codes: string[]
    isEmpty: boolean
    route: string
    completion: number | null
    routeCompletion: number | null
    wait: number | null
    loading: number | null
    unloading: number | null
    routeWait: number | null
    routeSamples: number
    flag: string | null
}

interface Evaluation {
    drivers: EvaluationDriver[]
    trips: EvaluationTrip[]
}

/** Dữ liệu của tab chỉ tải khi mở tab, tải lại khi đổi kỳ; dữ liệu kỳ cũ vẫn hiện (mờ) trong lúc chờ. */
const usePeriodData = <T,>(enabled: boolean, periodKey: string, load: () => Promise<T>) => {
    const [state, setState] = useState<{ key: string; data: T } | null>(null)
    const [error, setError] = useState<string | null>(null)
    const isStale = state?.key !== periodKey

    useEffect(() => {
        if (!enabled || !isStale) {
            return
        }
        let cancelled = false
        setError(null)
        load()
            .then((data) => !cancelled && setState({ key: periodKey, data }))
            .catch(() => !cancelled && setError('Không tải được dữ liệu, vui lòng thử lại.'))

        return () => {
            cancelled = true
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- load đổi mỗi lần render, periodKey đã đại diện cho nó
    }, [enabled, isStale, periodKey])

    return { data: state?.data ?? null, loading: isStale && error === null, error }
}

/** Ngưỡng tô màu độ lệch so với chuẩn tuyến (%). */
const DIFF_WARN = 10
const DIFF_BAD = 30

const minutes = (value: number | null): string => {
    if (value === null) {
        return '—'
    }
    const hours = Math.floor(value / 60)
    const rest = value % 60

    return hours > 0 ? (rest > 0 ? `${hours}h ${rest}p` : `${hours}h`) : `${rest}p`
}

const percentOf = (actual: number | null, expected: number | null): number | null =>
    actual === null || expected === null || expected <= 0 ? null : Math.round(((actual - expected) / expected) * 100)

/** Độ lệch so với chuẩn tuyến hiện bằng thời gian (+1h 20p); màu theo %, % nằm trong tooltip. */
const Deviation = ({ diff, percent, note }: { diff: number | null; percent: number | null; note?: string }) => {
    if (diff === null || percent === null) {
        return (
            <span className="text-gray-400" title={`Chưa có chuẩn để so: tuyến cần ít nhất 3 chuyến hợp lệ trong kỳ${note ? ` · ${note}` : ''}`}>
                —
            </span>
        )
    }
    const tone =
        percent >= DIFF_BAD
            ? 'text-rose-700 dark:text-rose-400 font-bold'
            : percent >= DIFF_WARN
              ? 'text-amber-700 dark:text-amber-400 font-semibold'
              : percent <= -DIFF_WARN
                ? 'text-emerald-700 dark:text-emerald-400 font-semibold'
                : 'text-gray-500 dark:text-gray-400'
    const label = Math.abs(percent) < DIFF_WARN ? '≈ chuẩn' : `${diff > 0 ? '+' : '−'}${minutes(Math.abs(diff))}`

    return (
        <span className={`font-mono text-[11px] ${tone}`} title={`${percent > 0 ? '+' : ''}${percent}% so với chuẩn tuyến${note ? ` · ${note}` : ''}`}>
            {label}
        </span>
    )
}

type EvaluationSort = 'name' | 'trips' | 'flagged' | 'completion' | 'completionDiff' | 'wait' | 'waitDiff'

const EvaluationTable = ({ drivers, codes, periodLabel, selectedId, onSelect }: {
    drivers: EvaluationDriver[]
    codes: Map<number, string | null>
    periodLabel: string
    selectedId: number | null
    onSelect: (id: number) => void
}) => {
    const [sort, setSort] = useState<{ key: EvaluationSort; desc: boolean }>({ key: 'completionDiff', desc: true })
    const sorted = useMemo(() => {
        const value = (driver: EvaluationDriver): number | string => (sort.key === 'name' ? driver.name : (driver[sort.key] ?? Number.NEGATIVE_INFINITY))

        return [...drivers].sort((a, b) => {
            const left = value(a)
            const right = value(b)
            const order = typeof left === 'string' ? left.localeCompare(String(right), 'vi') : left - (right as number)

            return sort.desc ? -order : order
        })
    }, [drivers, sort])

    const header = (key: EvaluationSort, label: string, title: string) => (
        <th className={`${th} cursor-pointer select-none hover:bg-white/10`} title={title} onClick={() => setSort((current) => ({ key, desc: current.key === key ? !current.desc : true }))}>
            {label}
            {sort.key === key && <span className="ms-1">{sort.desc ? '▼' : '▲'}</span>}
        </th>
    )

    return (
        <div className={`${card} xl:col-span-3`}>
            <table className="dispatch-table w-full border-collapse font-sans text-xs">
                <thead className="sticky top-0 z-20 shadow-sm">
                    <tr style={NAVY}>
                        <th colSpan={8} className="border-b border-white/10 px-4 py-2.5 text-center text-sm font-bold uppercase tracking-wider text-white sm:text-base">
                            Đánh giá lái xe · {periodLabel}
                        </th>
                    </tr>
                    <tr className="text-[11px] font-bold uppercase tracking-wide text-white" style={NAVY}>
                        <th colSpan={4} className={th}>
                            Lái xe
                        </th>
                        <th colSpan={2} className={th}>
                            Thời gian hoàn thành chuyến
                        </th>
                        <th colSpan={2} className={th}>
                            Thời gian chờ (đóng + hạ)
                        </th>
                    </tr>
                    <tr className="text-[11px] font-bold uppercase tracking-wide text-white" style={NAVY}>
                        {header('name', 'Tên lái xe', 'Sắp theo tên')}
                        <th className={th}>Mã NV</th>
                        {header('trips', 'Chuyến', 'Số chuyến có số liệu hợp lệ')}
                        {header('flagged', 'Cần KT', 'Chuyến số liệu bất thường (dưới 5 phút hoặc quá 12 giờ), không tính vào đánh giá')}
                        {header('completion', 'TB / chuyến', 'Trung bình từ bắt đầu đến kết thúc chuyến')}
                        {header('completionDiff', 'Lệch chuẩn', 'Trung bình mỗi chuyến chậm (+) / nhanh (−) hơn chuẩn tuyến')}
                        {header('wait', 'TB / chuyến', 'Trung bình thời gian đóng + hạ mỗi chuyến')}
                        {header('waitDiff', 'Lệch chuẩn', 'Trung bình mỗi chuyến chờ lâu (+) / ít (−) hơn chuẩn tuyến')}
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-white/5">
                    {sorted.length === 0 && (
                        <tr>
                            <td colSpan={8} className="px-3 py-8 text-center text-gray-500">
                                Không có chuyến hoàn thành nào trong kỳ.
                            </td>
                        </tr>
                    )}
                    {sorted.map((driver, index) => (
                        <tr
                            key={driver.id}
                            onClick={() => onSelect(driver.id)}
                            className={`dispatch-row cursor-pointer ${driver.id === selectedId ? 'bg-primary-50 dark:bg-primary-500/15' : zebra(index)}`}
                        >
                            <td className={`${td} font-semibold text-primary-600 dark:text-primary-400`} title={`Bấm để xem chuyến của ${driver.name}`}>
                                <span className="hover:underline">{driver.name}</span>
                            </td>
                            <td className={`${td} text-center font-mono text-[11px] text-gray-600 dark:text-gray-400`}>{codes.get(driver.id) ?? '—'}</td>
                            <td className={`${td} text-center font-bold text-gray-800 dark:text-gray-200`}>{driver.trips}</td>
                            <td className={`${td} text-center`}>{driver.flagged > 0 ? <Badge tone="warning">{driver.flagged}</Badge> : <span className="text-gray-300 dark:text-gray-600">0</span>}</td>
                            <td className={`${td} text-center font-mono text-[11px]`}>{minutes(driver.completion)}</td>
                            <td className={`${td} text-center`}>
                                <Deviation diff={driver.completionDiff} percent={driver.completionDiffPercent} note={`so được ${driver.completionCompared}/${driver.trips} chuyến`} />
                            </td>
                            <td className={`${td} text-center font-mono text-[11px]`} title={`Đóng ${minutes(driver.loading)} · hạ ${minutes(driver.unloading)}`}>
                                {minutes(driver.wait)}
                                <span className="ms-1 text-[10px] text-gray-400">
                                    ({minutes(driver.loading)} + {minutes(driver.unloading)})
                                </span>
                            </td>
                            <td className="whitespace-nowrap px-2 py-1.5 text-center">
                                <Deviation diff={driver.waitDiff} percent={driver.waitDiffPercent} note={`so được ${driver.waitCompared}/${driver.trips} chuyến`} />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    )
}

const TripMetric = ({ actual, standard, note }: { actual: number | null; standard: number | null; note: string }) => (
    <div className="flex items-baseline justify-end gap-1.5" title={`${note} · chuẩn tuyến ${minutes(standard)}`}>
        <span className="font-mono text-[11px] font-semibold">{minutes(actual)}</span>
        <Deviation diff={actual === null || standard === null ? null : Math.round(actual - standard)} percent={percentOf(actual, standard)} />
    </div>
)

const EvaluationTrips = ({ driver, trips, periodLabel, onClose, onOpenTrip }: {
    driver: EvaluationDriver | null
    trips: EvaluationTrip[]
    periodLabel: string
    onClose: () => void
    onOpenTrip: (tripId: number) => void
}) => {
    const days = useMemo(() => {
        const byDay = new Map<string, EvaluationTrip[]>()
        trips.forEach((trip) => byDay.set(trip.day ?? '', [...(byDay.get(trip.day ?? '') ?? []), trip]))

        return [...byDay.entries()]
    }, [trips])

    if (driver === null) {
        return (
            <div className={`${card} xl:sticky xl:top-20 xl:col-span-2`}>
                <div className="border-b border-white/10 px-3 py-2.5 text-center text-sm font-bold uppercase tracking-wider text-white" style={NAVY}>
                    Chi tiết chuyến
                </div>
                <EmptyState heading="Chọn một lái xe" description="Bấm vào một dòng để xem thời gian hoàn thành và thời gian chờ của từng chuyến so với chuẩn tuyến." />
            </div>
        )
    }

    return (
        <div className={`${card} xl:sticky xl:top-20 xl:col-span-2`}>
            <table className="dispatch-table w-full border-collapse font-sans text-xs">
                <thead className="sticky top-0 z-20 shadow-sm">
                    <tr style={NAVY}>
                        <th colSpan={5} className="border-b border-white/10 px-3 py-2.5 text-left text-white">
                            <div className="flex items-center justify-between gap-2">
                                <div className="min-w-0">
                                    <div className="truncate text-sm font-bold uppercase tracking-wider">{driver.name}</div>
                                    <div className="mt-0.5 text-[11px] font-medium normal-case text-white/70">
                                        {periodLabel} · {driver.trips} chuyến hợp lệ{driver.flagged > 0 ? ` · ${driver.flagged} cần kiểm tra` : ''}
                                    </div>
                                </div>
                                <button type="button" className="shrink-0 rounded p-1 text-white/70 transition hover:bg-white/10 hover:text-white" title="Đóng" onClick={onClose}>
                                    <Icon path={ICONS.close} />
                                </button>
                            </div>
                        </th>
                    </tr>
                    <tr className="text-[11px] font-bold uppercase tracking-wide text-white" style={NAVY}>
                        {['Ngày', 'Mã đơn', 'Tuyến', 'Hoàn thành', 'Chờ (đóng + hạ)'].map((label) => (
                            <th key={label} className={th}>
                                {label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-white/5">
                    {days.map(([day, dayTrips]) =>
                        dayTrips.map((trip, index) => (
                            <tr
                                key={trip.id}
                                onClick={() => onOpenTrip(trip.id)}
                                title="Bấm để xem hành trình chuyến"
                                className={`dispatch-row cursor-pointer ${index === dayTrips.length - 1 ? 'dispatch-group-end' : ''} ${trip.flag ? 'bg-amber-50/60 dark:bg-amber-400/5' : zebra(index)}`}
                            >
                                {index === 0 ? <td className={`${td} bg-gray-50/80 font-bold text-gray-900 dark:bg-white/5 dark:text-gray-100`}>{dayLabel(day || null)}</td> : <td className={td} />}
                                <td className="border-r border-gray-100 px-2 py-1.5 dark:border-white/5">
                                    <div className="flex flex-wrap items-center gap-1">
                                        <span className="font-mono text-[10px] text-gray-500 dark:text-gray-400">{trip.time}</span>
                                        <span className="font-semibold text-gray-900 dark:text-gray-100">{trip.codes.join(', ')}</span>
                                        {trip.isEmpty && <Badge tone="warning">Không hàng</Badge>}
                                    </div>
                                </td>
                                <td className={`${td} text-gray-600 dark:text-gray-400`} title={`${trip.routeSamples} chuyến hợp lệ cùng tuyến trong kỳ`}>
                                    {trip.route}
                                </td>
                                {trip.flag ? (
                                    <td colSpan={2} className="px-2 py-1.5 text-right" title={trip.flag}>
                                        <span className="me-2 font-mono text-[11px] text-gray-500">
                                            {minutes(trip.completion)} · chờ {minutes(trip.wait)}
                                        </span>
                                        <Badge tone="warning">Cần kiểm tra</Badge>
                                    </td>
                                ) : (
                                    <>
                                        <td className={td}>
                                            <TripMetric actual={trip.completion} standard={trip.routeCompletion} note="Bắt đầu → kết thúc chuyến" />
                                        </td>
                                        <td className="whitespace-nowrap px-2 py-1.5">
                                            <TripMetric actual={trip.wait} standard={trip.routeWait} note={`Đóng ${minutes(trip.loading)} + hạ ${minutes(trip.unloading)}`} />
                                        </td>
                                    </>
                                )}
                            </tr>
                        )),
                    )}
                </tbody>
            </table>
        </div>
    )
}

const PERIODS: { key: Period; label: string }[] = [
    { key: 'today', label: 'Hôm nay' },
    { key: 'week', label: 'Tuần này' },
    { key: 'month', label: 'Tháng này' },
]

const PRIMARY_BTN = 'fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950'

const pad = (value: number): string => String(value).padStart(2, '0')
const isoDate = (date: Date): string => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
const today = (): string => isoDate(new Date())

/** Dời mốc ngày theo kỳ: ngày ±1, tuần ±7 ngày, tháng về ngày 1 rồi ±1 tháng (như addMonthsNoOverflow). */
const shiftDate = (period: Period, date: string, step: number): string => {
    const [year, month, day] = date.split('-').map(Number)
    if (period === 'month') {
        return isoDate(new Date(year, month - 1 + step, 1))
    }

    return isoDate(new Date(year, month - 1, day + step * (period === 'week' ? 7 : 1)))
}

/** 24 tháng gần nhất, như ô "Chọn tháng" cũ. */
const recentMonths = (): { value: string; label: string }[] => {
    const now = new Date()

    return Array.from({ length: 24 }, (_, back) => {
        const month = new Date(now.getFullYear(), now.getMonth() - back, 1)

        return { value: isoDate(month), label: `Tháng ${pad(month.getMonth() + 1)}/${month.getFullYear()}` }
    })
}

const MonthPicker = ({ current, onPick }: { current: string; onPick: (date: string) => void }) => {
    const [open, setOpen] = useState(false)
    const ref = useRef<HTMLDivElement>(null)
    const months = useMemo(recentMonths, [])
    const currentMonth = `${current.slice(0, 7)}-01`

    useEffect(() => {
        if (!open) {
            return
        }
        const close = (event: MouseEvent | KeyboardEvent) => {
            if (event instanceof KeyboardEvent ? event.key === 'Escape' : !ref.current?.contains(event.target as Node)) {
                setOpen(false)
            }
        }
        document.addEventListener('mousedown', close)
        document.addEventListener('keydown', close)

        return () => {
            document.removeEventListener('mousedown', close)
            document.removeEventListener('keydown', close)
        }
    }, [open])

    return (
        <div ref={ref} className="relative">
            <button type="button" className="fi-btn fi-size-md" aria-expanded={open} onClick={() => setOpen((value) => !value)}>
                <Icon path={ICONS.calendar} className="fi-icon fi-size-md" />
                Chọn tháng
            </button>
            {open && (
                <ul className="absolute end-0 top-full z-30 mt-2 max-h-72 w-44 overflow-y-auto rounded-lg bg-white p-1 text-sm shadow-lg ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    {months.map((month) => (
                        <li key={month.value}>
                            <button
                                type="button"
                                className={`w-full rounded-md px-3 py-1.5 text-start transition hover:bg-gray-50 dark:hover:bg-white/5 ${
                                    month.value === currentMonth ? 'font-semibold text-primary-600 dark:text-primary-400' : 'text-gray-700 dark:text-gray-200'
                                }`}
                                onClick={() => {
                                    setOpen(false)
                                    onPick(month.value)
                                }}
                            >
                                {month.label}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    )
}

const PeriodBar = ({ report, onChange }: { report: Configuration; onChange: (period: Period, date: string) => void }) => (
    <div className="flex flex-wrap items-center gap-2">
        {PERIODS.map(({ key, label }) => (
            <button key={key} type="button" className={`fi-btn fi-size-md ${report.period === key && report.isCurrent ? PRIMARY_BTN : ''}`} onClick={() => onChange(key, today())}>
                {label}
            </button>
        ))}
        <button type="button" className="fi-icon-btn fi-size-md" title="Kỳ trước" aria-label="Kỳ trước" onClick={() => onChange(report.period, shiftDate(report.period, report.date, -1))}>
            <Icon path={ICONS.chevronLeft} className="fi-icon fi-size-md" />
        </button>
        <MonthPicker current={report.date} onPick={(date) => onChange('month', date)} />
        <button
            type="button"
            className={`fi-icon-btn fi-size-md ${report.hasNext ? '' : 'fi-disabled'}`}
            title="Kỳ sau"
            aria-label="Kỳ sau"
            disabled={!report.hasNext}
            onClick={() => onChange(report.period, shiftDate(report.period, report.date, 1))}
        >
            <Icon path={ICONS.chevronRight} className="fi-icon fi-size-md" />
        </button>
    </div>
)

const DriverReport = ({ configuration, wire }: { configuration: Configuration; wire: Wire }) => {
    const initial = useMemo(readUrlState, [])
    const [report, setReport] = useState<Configuration>(configuration)
    const [pending, setPending] = useState<{ period: Period; date: string } | null>(null)
    const [reportError, setReportError] = useState<string | null>(null)
    const [tab, setTab] = useState<Tab>(initial.tab)
    const [search, setSearch] = useState(initial.search)
    const [driverId, setDriverId] = useState<number | null>(initial.driverId)
    const [perPage, setPerPage] = useState(initial.perPage)
    const deferredSearch = useDeferredValue(search)
    const needle = normalize(deferredSearch.trim())
    const periodKey = `${report.period}|${report.date}`

    useEffect(
        () => writeUrlState({ period: report.period, date: report.date, tab: tab === 'summary' ? null : tab, search, driverId, perPage: perPage === PER_PAGE_OPTIONS[0] ? null : perPage }),
        [report.period, report.date, tab, search, driverId, perPage],
    )

    // Đổi kỳ: chỉ tải số liệu kỳ mới, bảng kỳ cũ vẫn hiện (mờ) đến khi có dữ liệu; bấm liên tiếp thì lấy lần cuối.
    useEffect(() => {
        if (pending === null) {
            return
        }
        let cancelled = false
        setReportError(null)
        wire.loadReport(pending.period, pending.date)
            .then((next) => {
                if (!cancelled) {
                    setReport(next)
                    setPending(null)
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setReportError('Không tải được số liệu kỳ này, vui lòng thử lại.')
                    setPending(null)
                }
            })

        return () => {
            cancelled = true
        }
    }, [pending, wire])

    const changePeriod = (period: Period, date: string) => {
        if (period !== report.period || date !== report.date) {
            setPending({ period, date })
        }
    }

    // Bảng kê + đánh giá tải chung một lần, ngầm ngay khi có số liệu tổng hợp (không đợi bấm tab).
    const details = usePeriodData(pending === null, periodKey, () => wire.loadDetails(report.period, report.date))
    const statement = { data: details.data?.statement ?? null, loading: details.loading, error: details.error }
    const evaluation = { data: details.data?.evaluation ?? null, loading: details.loading, error: details.error }

    const drivers = useMemo(
        () => (needle === '' ? report.drivers : report.drivers.filter((driver) => normalize(`${driver.name} ${driver.code ?? ''}`).includes(needle))),
        [report.drivers, needle],
    )
    const selectedDriver = report.drivers.find((driver) => driver.id === driverId) ?? null
    const selectedTrips = useMemo(() => report.trips.filter((trip) => trip.driverId === driverId), [report.trips, driverId])
    const statementRows = useMemo(() => {
        const rows = statement.data ?? []
        if (driverId !== null) {
            return rows.filter((row) => row.driverId === driverId)
        }

        return needle === '' ? rows : rows.filter((row) => normalize(row.driver).includes(needle))
    }, [statement.data, driverId, needle])
    const evaluationDrivers = useMemo(() => {
        const rows = evaluation.data?.drivers ?? []
        const codes = new Map(report.drivers.map((driver) => [driver.id, driver.code ?? '']))

        return needle === '' ? rows : rows.filter((row) => normalize(`${row.name} ${codes.get(row.id) ?? ''}`).includes(needle))
    }, [evaluation.data, report.drivers, needle])
    const driverCodes = useMemo(() => new Map(report.drivers.map((driver) => [driver.id, driver.code])), [report.drivers])
    const selectedEvaluationDriver = evaluation.data?.drivers.find((driver) => driver.id === driverId) ?? null
    const selectedEvaluationTrips = useMemo(() => (evaluation.data?.trips ?? []).filter((trip) => trip.driverId === driverId), [evaluation.data, driverId])

    const toggleDriver = (id: number) => setDriverId((current) => (current === id ? null : id))
    // Mở popup ngay (skeleton trong popup) rồi mới gọi server lấy nội dung hành trình.
    const openTrip = (tripId: number) => {
        window.dispatchEvent(new CustomEvent('open-modal', { detail: { id: 'driver-trip-timeline' } }))
        void wire.$parent.openTripTimeline(tripId)
    }
    const isLoading = pending !== null

    const tabButton = (key: Tab, label: string, icon: string) => (
        <button
            type="button"
            role="tab"
            aria-selected={tab === key}
            onClick={() => setTab(key)}
            className={`flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium transition ${
                tab === key ? 'bg-primary-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5'
            }`}
        >
            <Icon path={icon} />
            {label}
        </button>
    )

    return (
        <div className="w-full space-y-3">
            {isLoading && <div className="fixed inset-x-0 top-0 z-50 h-0.5 animate-pulse bg-primary-500" />}

            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-gray-600 dark:text-gray-400">
                    <span className="font-semibold text-gray-900 dark:text-white">{report.periodLabel}</span> · chỉ tính chuyến đã hoàn thành thuộc ca đã kết thúc.
                    {reportError && <span className="ms-2 font-medium text-rose-600 dark:text-rose-400">{reportError}</span>}
                </p>
                <PeriodBar report={report} onChange={changePeriod} />
            </div>

            <div className="relative w-full max-w-xs">
                <span className="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-gray-400">
                    <Icon path={ICONS.search} />
                </span>
                <input
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Tìm tên hoặc mã NV lái xe..."
                    className="block w-full rounded-lg border-none bg-white py-1.5 pe-3 ps-9 text-sm text-gray-950 shadow-xs ring-1 ring-gray-950/10 placeholder:text-gray-400 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20"
                />
            </div>

            <div role="tablist" className="flex gap-1 rounded-xl bg-white p-1 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                {tabButton('summary', 'Tổng hợp km', ICONS.chart)}
                {tabButton('statement', 'Bảng kê chuyến', ICONS.document)}
                {tabButton('evaluation', 'Đánh giá', ICONS.clock)}
            </div>

            <div className="space-y-3" aria-busy={isLoading || details.loading}>
                {tab === 'evaluation' ? (
                    <>
                        <p className="text-xs text-gray-500 dark:text-gray-400">
                            <span className="font-semibold text-gray-700 dark:text-gray-200">Chuẩn tuyến</span> = thời gian ở giữa (trung vị) của cả đội trên cùng tuyến trong kỳ, cần ít nhất 3
                            chuyến. Lệch <span className="font-semibold text-rose-700 dark:text-rose-400">+</span> là chậm / chờ lâu hơn chuẩn,{' '}
                            <span className="font-semibold text-emerald-700 dark:text-emerald-400">−</span> là nhanh / chờ ít hơn. Chuyến dưới 5 phút hoặc quá 12 giờ (thường do bấm checkpoint sai)
                            không tính, đếm ở cột <span className="font-semibold">Cần KT</span>.
                            {evaluation.error && <span className="ms-2 font-medium text-rose-600 dark:text-rose-400">{evaluation.error}</span>}
                        </p>
                        <div className="grid grid-cols-1 items-start gap-3 xl:grid-cols-5">
                            {evaluation.error && evaluation.data === null ? (
                                <div className={`${card} px-3 py-8 text-center text-rose-600 xl:col-span-5`}>{evaluation.error}</div>
                            ) : isLoading || evaluation.loading || evaluation.data === null ? (
                                <>
                                    <SkeletonCard title={`Đánh giá lái xe · ${report.periodLabel}`} columns={8} className="xl:col-span-3" />
                                    <SkeletonCard title="Chi tiết chuyến" columns={5} rows={6} className="xl:col-span-2" />
                                </>
                            ) : (
                                <>
                                    <EvaluationTable drivers={evaluationDrivers} codes={driverCodes} periodLabel={report.periodLabel} selectedId={driverId} onSelect={toggleDriver} />
                                    <EvaluationTrips driver={selectedEvaluationDriver} trips={selectedEvaluationTrips} periodLabel={report.periodLabel} onClose={() => setDriverId(null)} onOpenTrip={openTrip} />
                                </>
                            )}
                        </div>
                    </>
                ) : tab === 'summary' ? (
                    <div className="grid grid-cols-1 items-start gap-3 xl:grid-cols-5">
                        {isLoading ? (
                            <>
                                <SkeletonCard title="Thống kê chuyến & km lái xe" columns={9} className="xl:col-span-3" />
                                <SkeletonCard title="Chi tiết chuyến" columns={6} rows={6} className="xl:col-span-2" />
                            </>
                        ) : (
                        <>
                        <DriverTable
                            drivers={drivers}
                            totals={report.totals}
                            totalDrivers={report.drivers.length}
                            activeDrivers={report.drivers.filter((driver) => driver.trips > 0).length}
                            periodLabel={report.periodLabel}
                            selectedId={driverId}
                            onSelect={toggleDriver}
                        />
                        <DriverTrips driver={selectedDriver} trips={selectedTrips} periodLabel={report.periodLabel} onClose={() => setDriverId(null)} onOpenTrip={openTrip} />
                        </>
                        )}
                    </div>
                ) : (
                    <>
                        {selectedDriver && (
                            <div className="flex items-center gap-2 text-sm">
                                <span className="text-gray-600 dark:text-gray-400">Đang xem lái xe:</span>
                                <Badge tone="primary">{selectedDriver.name}</Badge>
                                <button type="button" className="text-sm font-semibold text-gray-600 hover:underline dark:text-gray-300" onClick={() => setDriverId(null)}>
                                    Xem tất cả
                                </button>
                            </div>
                        )}
                        <Statement
                            rows={statementRows}
                            loading={isLoading || statement.loading}
                            error={statement.error}
                            periodLabel={report.periodLabel}
                            perPage={perPage}
                            onPerPage={setPerPage}
                            onOpenTrip={openTrip}
                        />
                    </>
                )}
            </div>
        </div>
    )
}

const mountDriverMonthlyKmTable: JsWidgetRenderer<Deferred<Configuration>, WidgetMethods> = ({ host, props: initialProps, utilities }) => {
    const root = createRoot(host)
    const dataRequest = { requested: false }
    let isReportMounted = false

    // Kỳ đổi trong React (loadReport) nên chỉ lấy configuration có dữ liệu lần đầu; sau đó bỏ qua update để không mất state.
    const update = ({ configuration }: JsWidgetProps<Deferred<Configuration>>) => {
        if (isReportMounted) {
            return
        }

        if (! configuration.loaded) {
            requestDataOnce(utilities.$wire, dataRequest)
            root.render(<TableSkeleton columns={configuration.skeletonColumns} />)

            return
        }

        isReportMounted = true
        root.render(<DriverReport configuration={configuration as Configuration} wire={utilities.$wire} />)
    }

    update(initialProps)

    return { update, destroy: () => root.unmount() }
}

export default mountDriverMonthlyKmTable
