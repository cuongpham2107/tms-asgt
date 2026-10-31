import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { createRoot } from 'react-dom/client'
import type { JsonValue, JsWidgetProps, JsWidgetRenderer, JsWidgetUtilities, Snapshot } from '@filament/widgets/js-widget'

interface Charge {
    priceCode: string | null
    rateType: string
    basePrice: number | null
    fuelPercent: number
    adjustedPrice: number | null
    waitingFee: number
    waitingDuration: string | null
    otherFees: number
    otherFeesCount: number
    vatPercent: number
    vatAmount: number
    total: number | null
    locked: boolean
    missing: boolean
}

interface Row {
    key: string
    stt: number | null
    variant: 'trip' | 'order' | 'single'
    orderId: number | null
    ordersCount: number | null
    code: string | null
    plate: string | null
    date: string | null
    journey: string | null
    timeA: string | null
    timeB: string | null
    timeC: string | null
    timeD: string | null
    loading: string
    loadingTitle: string
    travel: string
    travelTitle: string
    unloading: string
    unloadingTitle: string
    waiting22h: string | null
    waiting22hTitle: string
    totalTime: string
    totalTimeTitle: string
    customer: string | null
    warehouse: string | null
    vehicleOwner: string | null
    vehicleType: string | null
    note: string | null
    isReturnTrip: boolean
    driverName: string | null
    kmLoaded: number | null
    kmEmpty: number | null
    pcs: number | null
    gw: number | null
    cargoType: string | null
    chargeableWeight: number | null
    tripTotal: number | null
    charge: Charge | null
}

interface Configuration {
    rows: Row[]
    showCharges: boolean
    showChargeableWeight: boolean
    codeLabel: string
    warehouseLabel: string
    emptyMessage: string
    filters: {
        activeTab: string
        search: string
        customerId: number | null
        vehicleOwner: string
        chargeStatus: string | null
    }
    tabs: { key: string; label: string; count: number; tone: 'info' | 'warning' | 'gray' }[]
    chargeFilters: { key: string; label: string; count: number; tone: 'emerald' | 'amber' }[]
    customers: Record<string, string>
    metrics: {
        trips: number
        records: number
        km: number
        kmTitle: string
        avgDuration: string
        avgDurationTitle: string
        waiting22h: number
        payment: number
    }
    pagination: { page: number; lastPage: number; total: number; perPage: number; from: number | null; to: number | null }
}

/** Các hàm của page (state bộ lọc nằm ở page để header actions và xuất Excel dùng chung). */
interface PageApi {
    $set(path: string, value: JsonValue, live?: boolean): Promise<unknown>
    setActiveTab(tab: string): Promise<unknown>
    toggleChargeStatus(status: string): Promise<unknown>
    resetFilters(): Promise<unknown>
    gotoPage(page: number): Promise<unknown>
    updateOrderMetric(orderId: number, field: string, value: string | null): Promise<unknown>
    mountAction(name: string, args?: Record<string, unknown>): Promise<unknown>
    recalculateCharge(orderId: number): Promise<unknown>
    unlockCharge(orderId: number): Promise<unknown>
}

interface WidgetMethods {
    readonly $parent: PageApi
}

type Wire = JsWidgetUtilities<WidgetMethods>['$wire']

interface Column {
    key: string
    label: string
    title?: string
    align?: 'left' | 'center' | 'right'
    width?: number
    minWidth?: number
    sticky?: number
    extra?: string
    titleClassName?: string
    render: (row: Row, page: PageApi) => ReactNode
}

const money = (value: number | null | undefined): string => (value === null || value === undefined ? '—' : value.toLocaleString('vi-VN'))

const count = (value: number): string => value.toLocaleString('vi-VN')

const weight = (value: number | null): string =>
    value === null
        ? '—'
        : value.toLocaleString('vi-VN', {
              minimumFractionDigits: Number.isInteger(value) ? 0 : 2,
              maximumFractionDigits: 2,
          })

const percent = (value: number): string => `${(Math.round(value * 100) / 100).toString().replace('.', ',')}%`

const badges: Record<string, string> = {
    info: 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/30',
    warning: 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/30',
    success: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/30',
    gray: 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10',
}

const badge = (label: string, tone: keyof typeof badges = 'gray') => (
    <span className={`inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-medium ring-1 whitespace-nowrap ${badges[tone]}`}>
        {label}
    </span>
)

const tabCountTones: Record<string, string> = {
    info: 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300',
    warning: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
    gray: 'bg-gray-200 text-gray-700 dark:bg-white/10 dark:text-gray-300',
}

const chipSwatches: Record<string, string> = {
    emerald: 'bg-emerald-100 ring-emerald-300 dark:bg-emerald-900 dark:ring-emerald-700',
    amber: 'bg-amber-100 ring-amber-300 dark:bg-amber-900 dark:ring-amber-700',
}

const chipActive: Record<string, string> = {
    emerald: 'bg-emerald-50 ring-emerald-400 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    amber: 'bg-amber-50 ring-amber-400 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
}

const isChild = (row: Row) => row.variant === 'order'

const Icon = ({ path, className = '' }: { path: string; className?: string }) => (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.6} strokeLinecap="round" strokeLinejoin="round" className={`h-4 w-4 ${className}`} aria-hidden="true">
        <path d={path} />
    </svg>
)

const ICONS = {
    search: 'm21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607z',
    reset: 'M16.023 9.348h4.992V4.356M2.985 19.644v-4.992h4.992M4.532 9.348A8.25 8.25 0 0117.94 5.94l1.71 1.71M2.985 14.652a8.25 8.25 0 0013.408 3.408l1.71-1.71',
    edit: 'M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zM19.5 7.125L16.875 4.5',
    lock: 'M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75M5.25 10.5h13.5v10.5H5.25V10.5z',
    refresh: 'M16.023 9.348h4.992V4.356M2.985 19.644v-4.992h4.992M4.532 9.348A8.25 8.25 0 0117.94 5.94l1.71 1.71M2.985 14.652a8.25 8.25 0 0013.408 3.408l1.71-1.71',
    chevronLeft: 'M15.75 19.5L8.25 12l7.5-7.5',
    chevronRight: 'M8.25 4.5l7.5 7.5-7.5 7.5',
}

function MetricCell({ row, field, value, page }: { row: Row; field: 'total_packages' | 'total_weight'; value: number | null; page: PageApi }) {
    const [editing, setEditing] = useState(false)
    const [draft, setDraft] = useState('')
    const [saving, setSaving] = useState(false)

    const display = field === 'total_packages' ? money(value) : weight(value)
    const initial = value === null ? '' : String(value)
    const orderId = row.orderId

    if (!orderId) {
        return <span className="px-1">{display}</span>
    }

    const save = () => {
        if (!editing) {
            return
        }

        setEditing(false)

        if (draft === initial) {
            return
        }

        if (draft === '') {
            void page.updateOrderMetric(orderId, field, null)
            return
        }

        setSaving(true)
        void page.updateOrderMetric(orderId, field, draft).finally(() => setSaving(false))
    }

    return (
        <div
            className="cursor-pointer"
            title="Nhấn để sửa nhanh (Enter lưu, Esc huỷ)"
            onClick={() => {
                if (saving) {
                    return
                }
                setDraft(initial)
                setEditing(true)
            }}
        >
            {editing ? (
                <input
                    type="number"
                    min={0}
                    step={field === 'total_packages' ? 1 : 'any'}
                    className="metric-input"
                    value={draft}
                    autoFocus
                    onFocus={(event) => event.currentTarget.select()}
                    onClick={(event) => event.stopPropagation()}
                    onChange={(event) => setDraft(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault()
                            save()
                        }
                        if (event.key === 'Escape') {
                            event.preventDefault()
                            setEditing(false)
                        }
                    }}
                    onBlur={save}
                />
            ) : (
                <span className={saving ? 'opacity-40' : ''}>{display}</span>
            )}
        </div>
    )
}

function Actions({ row, page }: { row: Row; page: PageApi }) {
    if (!row.orderId) {
        return null
    }

    const orderId = row.orderId
    const locked = row.charge?.locked === true

    return (
        <div className="inline-flex items-center gap-0.5">
            <button
                type="button"
                className="rounded-md p-1 text-primary-600 transition hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-primary-500/10"
                title={locked ? 'Xem cước' : 'Sửa cước'}
                onClick={() => void page.mountAction('editCharge', { orderId })}
            >
                <Icon path={ICONS.edit} />
            </button>
            {locked ? (
                <button
                    type="button"
                    className="rounded-md p-1 text-emerald-700 transition hover:bg-emerald-100 dark:text-emerald-400 dark:hover:bg-emerald-500/10"
                    title="Mở khoá"
                    onClick={() => {
                        if (window.confirm('Mở khoá dòng cước đã xuất bảng kê để sửa lại?')) {
                            void page.unlockCharge(orderId)
                        }
                    }}
                >
                    <Icon path={ICONS.lock} />
                </button>
            ) : (
                <button
                    type="button"
                    className="rounded-md p-1 text-gray-500 transition hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5"
                    title={row.charge ? 'Tính lại cước' : 'Tính cước'}
                    onClick={() => {
                        if (!row.charge || window.confirm('Tra lại đơn giá và giờ chờ theo dữ liệu hiện tại? Đơn giá gốc và giờ chờ đã sửa tay sẽ bị thay.')) {
                            void page.recalculateCharge(orderId)
                        }
                    }}
                >
                    <Icon path={ICONS.refresh} />
                </button>
            )}
        </div>
    )
}

function buildColumns(showChargeableWeight: boolean, showCharges: boolean): Column[] {
    const columns: Column[] = [
        { key: 'stt', label: 'STT', align: 'center', width: 50, sticky: 0, extra: 'px-2.5 text-gray-400 font-mono', render: (row) => row.stt ?? '' },
        {
            key: 'code',
            label: 'Mã đơn hàng',
            align: 'center',
            width: 140,
            sticky: 1,
            extra: 'px-3 font-semibold text-gray-900 dark:text-gray-100 truncate',
            render: (row) =>
                isChild(row) ? (
                    <span className="font-medium text-gray-700 dark:text-gray-300">
                        <span className="text-gray-400">└</span> {row.code}
                    </span>
                ) : (
                    <>
                        {row.code || '—'}
                        {row.variant === 'trip' && <span className="ml-1 text-[10px] font-medium text-gray-500">({row.ordersCount} đơn)</span>}
                    </>
                ),
        },
        {
            key: 'plate',
            label: 'BSX',
            align: 'center',
            width: 110,
            sticky: 2,
            extra: 'px-3 font-mono font-medium text-gray-800 dark:text-gray-200',
            render: (row) => (isChild(row) ? '' : row.plate || '—'),
        },
        { key: 'date', label: 'Ngày', align: 'center', minWidth: 90, extra: 'px-3 text-gray-600 dark:text-gray-400', render: (row) => row.date || '—' },
        { key: 'journey', label: 'Hành trình', minWidth: 130, extra: 'px-3.5 font-medium text-gray-800 dark:text-gray-200', render: (row) => row.journey || '—' },
        { key: 'timeA', label: 'TG đóng hàng (A)', align: 'center', minWidth: 115, extra: 'px-3 font-mono text-gray-700 dark:text-gray-300', render: (row) => row.timeA || '—' },
        { key: 'timeB', label: 'TG xe chạy (B)', align: 'center', minWidth: 115, extra: 'px-3 font-mono text-gray-700 dark:text-gray-300', render: (row) => row.timeB || '—' },
        { key: 'timeC', label: 'TG đến (C)', align: 'center', minWidth: 115, extra: 'px-3 font-mono text-gray-700 dark:text-gray-300', render: (row) => row.timeC || '—' },
        { key: 'timeD', label: 'TG hạ hàng (D)', align: 'center', minWidth: 115, extra: 'px-3 font-mono text-gray-700 dark:text-gray-300', render: (row) => row.timeD || '—' },
        { key: 'loading', label: 'TG đóng (1)', title: 'B - A (giờ & phút)', align: 'center', minWidth: 85, extra: 'px-2.5 font-medium text-gray-700 dark:text-gray-300', render: (row) => <span title={row.loadingTitle}>{row.loading}</span> },
        { key: 'travel', label: 'TG chạy (2)', title: 'C - B (giờ & phút)', align: 'center', minWidth: 85, extra: 'px-2.5 font-medium text-gray-700 dark:text-gray-300', render: (row) => <span title={row.travelTitle}>{row.travel}</span> },
        { key: 'unloading', label: 'TG hạ (3)', title: 'D - C (giờ & phút)', align: 'center', minWidth: 85, extra: 'px-2.5 font-medium text-gray-700 dark:text-gray-300', render: (row) => <span title={row.unloadingTitle}>{row.unloading}</span> },
        {
            key: 'waiting22h',
            label: 'TG chờ 22:00',
            title: 'Chờ hạ hàng sau 22:00 (giờ & phút)',
            align: 'center',
            minWidth: 110,
            extra: 'px-3',
            titleClassName: 'text-amber-300',
            render: (row) =>
                row.waiting22h ? <span title={row.waiting22hTitle}>{badge(row.waiting22h, 'warning')}</span> : <span className="text-gray-400">0</span>,
        },
        {
            key: 'totalTime',
            label: 'Tổng TG (1+2+3)',
            title: '(1) + (2) + (3) (giờ & phút)',
            align: 'center',
            minWidth: 105,
            extra: 'px-3 font-bold text-primary-600 dark:text-primary-400',
            titleClassName: 'font-bold text-sky-300',
            render: (row) => <span title={row.totalTimeTitle}>{row.totalTime}</span>,
        },
        { key: 'customer', label: 'Khách hàng', minWidth: 100, extra: 'px-3 font-semibold text-gray-800 dark:text-gray-200', render: (row) => row.customer || '—' },
        { key: 'warehouse', label: 'Kho đóng/trả', minWidth: 100, extra: 'px-3 text-gray-700 dark:text-gray-300', render: (row) => row.warehouse || '—' },
        {
            key: 'vehicleOwner',
            label: 'Quản lý xe',
            align: 'center',
            minWidth: 95,
            extra: 'px-3',
            render: (row) =>
                isChild(row) || !row.vehicleOwner
                    ? ''
                    : badge(row.vehicleOwner, row.vehicleOwner === 'Xe công ty' ? 'info' : row.vehicleOwner === 'Xe thuê' ? 'warning' : 'gray'),
        },
        { key: 'vehicleType', label: 'Loại xe', align: 'center', minWidth: 90, extra: 'px-3 text-gray-600 dark:text-gray-400', render: (row) => (isChild(row) ? '' : row.vehicleType ?? '') },
        { key: 'note', label: 'Ghi chú', minWidth: 100, extra: 'px-3 text-gray-500 max-w-[140px] truncate', render: (row) => <span title={row.note ?? ''}>{row.note || '—'}</span> },
        {
            key: 'isReturnTrip',
            label: 'Hàng quay đầu',
            align: 'center',
            minWidth: 90,
            extra: 'px-2.5',
            render: (row) => (row.isReturnTrip ? badge('Quay đầu', 'success') : <span className="text-gray-300 dark:text-gray-600">—</span>),
        },
        { key: 'driverName', label: 'Họ tên lái xe', minWidth: 120, extra: 'px-3 font-medium text-gray-800 dark:text-gray-200', render: (row) => (isChild(row) ? '' : row.driverName || '—') },
        { key: 'kmLoaded', label: 'Km có hàng', align: 'right', minWidth: 85, extra: 'px-3 font-mono', render: (row) => (row.kmLoaded !== null ? row.kmLoaded.toLocaleString('vi-VN', { maximumFractionDigits: 1 }) : '—') },
        {
            key: 'kmEmpty',
            label: 'Km không hàng',
            align: 'right',
            minWidth: 85,
            extra: 'px-3 font-mono text-gray-500',
            render: (row) => (isChild(row) ? '' : row.kmEmpty !== null ? row.kmEmpty.toLocaleString('vi-VN', { maximumFractionDigits: 1 }) : '—'),
        },
        { key: 'pcs', label: 'PCS', align: 'right', minWidth: 110, extra: 'px-1.5 font-mono text-xs', render: (row, page) => <MetricCell row={row} field="total_packages" value={row.pcs} page={page} /> },
        { key: 'gw', label: 'GW (kg)', align: 'right', minWidth: 125, extra: 'px-1.5 font-mono text-xs', render: (row, page) => <MetricCell row={row} field="total_weight" value={row.gw} page={page} /> },
        { key: 'cargoType', label: 'Loại hàng', align: 'center', minWidth: 75, extra: 'px-3', render: (row) => <span className="font-mono text-[11px] text-gray-600 dark:text-gray-400">{row.cargoType || '—'}</span> },
    ]

    if (showChargeableWeight) {
        columns.push({
            key: 'chargeableWeight',
            label: 'Trọng tải cước',
            align: 'right',
            minWidth: 95,
            extra: 'px-3 font-mono font-semibold text-gray-900 dark:text-gray-100',
            render: (row) => (row.chargeableWeight !== null ? row.chargeableWeight.toLocaleString('vi-VN', { maximumFractionDigits: 1 }) : '—'),
        })
    }

    if (showCharges) {
        columns.push(
            {
                key: 'priceCode',
                label: 'Mã giá',
                minWidth: 160,
                extra: 'px-3 font-mono text-[11px] text-gray-600 dark:text-gray-400 max-w-[200px] truncate',
                render: (row) => <span title={row.charge?.priceCode ?? ''}>{row.charge?.priceCode || '—'}</span>,
            },
            { key: 'rateType', label: 'Loại hình cước', minWidth: 110, extra: 'px-3 text-gray-700 dark:text-gray-300', render: (row) => row.charge?.rateType ?? '—' },
            {
                key: 'basePrice',
                label: 'Đơn giá gốc',
                align: 'right',
                minWidth: 110,
                extra: 'px-3 font-mono',
                render: (row) =>
                    row.charge === null ? '—' : row.charge.basePrice === null ? <span className="font-sans font-medium text-amber-700 dark:text-amber-400">Chưa có giá</span> : money(row.charge.basePrice),
            },
            { key: 'fuelPercent', label: '% dầu', align: 'right', minWidth: 70, extra: 'px-3 font-mono', render: (row) => (row.charge ? percent(row.charge.fuelPercent) : '—') },
            { key: 'adjustedPrice', label: 'Đơn giá sau ĐC', align: 'right', minWidth: 120, extra: 'px-3 font-mono font-medium text-gray-900 dark:text-gray-100', render: (row) => money(row.charge?.adjustedPrice) },
            {
                key: 'waitingFee',
                label: 'Giờ chờ',
                title: 'Chi phí giờ chờ (thời gian chờ đóng + hạ hàng)',
                align: 'right',
                minWidth: 110,
                extra: 'px-3 font-mono',
                render: (row) =>
                    row.charge === null ? (
                        '—'
                    ) : (
                        <span title={row.charge.waitingDuration ? `Chờ ${row.charge.waitingDuration} (đóng + hạ hàng)` : ''}>
                            {row.charge.waitingFee > 0 ? money(row.charge.waitingFee) : '—'}
                            {row.charge.waitingDuration && <span className="ml-1 text-[10px] text-gray-400">{row.charge.waitingDuration}</span>}
                        </span>
                    ),
            },
            {
                key: 'otherFees',
                label: 'Phí khác',
                align: 'right',
                minWidth: 100,
                extra: 'px-3 font-mono',
                render: (row) => <span title={row.charge?.otherFeesCount ? `${row.charge.otherFeesCount} khoản phí` : ''}>{row.charge && row.charge.otherFees > 0 ? money(row.charge.otherFees) : '—'}</span>,
            },
            {
                key: 'vat',
                label: 'VAT',
                align: 'right',
                minWidth: 90,
                extra: 'px-3 font-mono',
                render: (row) => <span title={row.charge ? `VAT ${row.charge.vatPercent}%` : ''}>{row.charge && row.charge.vatAmount > 0 ? money(row.charge.vatAmount) : '—'}</span>,
            },
            {
                key: 'total',
                label: 'Thanh toán',
                align: 'right',
                minWidth: 120,
                extra: 'px-3 font-mono font-bold text-sky-700 dark:text-sky-300',
                titleClassName: 'font-bold text-sky-300',
                render: (row) => (row.charge ? money(row.charge.total) : row.variant === 'trip' ? money(row.tripTotal) : '—'),
            },
            { key: 'actions', label: 'Thao tác', align: 'center', minWidth: 120, extra: 'px-2', render: (row, page) => <Actions row={row} page={page} /> },
        )
    }

    return columns
}

const STICKY_LEFT = [0, 50, 190]

const alignmentClass = (align: Column['align']): string => (align === 'center' ? 'text-center' : align === 'right' ? 'text-right' : '')

const columnStyle = (column: Column) => ({
    width: column.width,
    minWidth: column.width ?? column.minWidth,
    maxWidth: column.width,
    ...(column.sticky === undefined ? {} : { left: STICKY_LEFT[column.sticky] }),
})

function rowTone(row: Row): 'locked' | 'missing' | null {
    if (row.charge?.locked) {
        return 'locked'
    }

    return row.charge?.missing ? 'missing' : null
}

function RowView({ row, columns, page }: { row: Row; columns: Column[]; page: PageApi }) {
    const tone = rowTone(row)
    const rowClass = [
        'dispatch-row whitespace-nowrap group',
        row.variant === 'trip' ? 'bg-slate-100 dark:bg-gray-800 font-semibold' : '',
        tone === 'locked' ? 'bg-emerald-50 dark:bg-emerald-950' : '',
        tone === 'missing' ? 'bg-amber-50 dark:bg-amber-950' : '',
    ]
        .filter(Boolean)
        .join(' ')

    const stickyBg =
        tone === 'locked'
            ? 'bg-emerald-50 dark:bg-emerald-950'
            : tone === 'missing'
              ? 'bg-amber-50 dark:bg-amber-950'
              : row.variant === 'trip'
                ? 'bg-slate-100 dark:bg-gray-800'
                : 'bg-white dark:bg-gray-900'

    return (
        <tr className={rowClass}>
            {columns.map((column) => (
                <td
                    key={column.key}
                    className={[
                        'py-2 border-r border-gray-100 dark:border-white/5',
                        column.sticky === undefined ? '' : `sticky z-10 ${stickyBg} group-hover:bg-gray-50 dark:group-hover:bg-gray-800/90`,
                        column.sticky === 2 ? 'shadow-[2px_0_4px_-1px_rgba(0,0,0,0.06)] border-gray-200 dark:border-white/10' : '',
                        alignmentClass(column.align),
                        column.extra ?? 'px-3',
                    ]
                        .filter(Boolean)
                        .join(' ')}
                    style={columnStyle(column)}
                >
                    {column.render(row, page)}
                </td>
            ))}
        </tr>
    )
}

function pageNumbers(current: number, last: number): (number | null)[] {
    if (last <= 7) {
        return Array.from({ length: last }, (_, index) => index + 1)
    }

    const pages = [...new Set([1, last, current - 1, current, current + 1].filter((page) => page >= 1 && page <= last))].sort((a, b) => a - b)
    const result: (number | null)[] = []

    pages.forEach((page, index) => {
        if (index > 0 && page - pages[index - 1] > 1) {
            result.push(null)
        }
        result.push(page)
    })

    return result
}

function TripStatistics({ configuration, wire }: { configuration: Snapshot<Configuration>; wire: Wire }) {
    const page = wire.$parent
    const [busy, setBusy] = useState(false)
    const [search, setSearch] = useState(configuration.filters.search)

    const run = (action: () => Promise<unknown>) => {
        setBusy(true)
        void action().finally(() => setBusy(false))
    }

    useEffect(() => {
        setSearch(configuration.filters.search)
    }, [configuration.filters.search])

    useEffect(() => {
        if (search === configuration.filters.search) {
            return
        }

        const timer = setTimeout(() => run(() => page.$set('search', search, true)), 400)

        return () => clearTimeout(timer)
    }, [search])

    const columns = buildColumns(configuration.showChargeableWeight, configuration.showCharges)
    const groups: { title: string; span: number }[] = [
        { title: '1. THÔNG TIN CHUYẾN & HÀNH TRÌNH', span: 5 },
        { title: '2. 4 MỐC THỜI GIAN THỰC TẾ', span: 4 },
        { title: '3. THỜI LƯỢNG TÍNH TOÁN (GIỜ & PHÚT)', span: 5 },
        { title: '4. ĐỐI TÁC, XE & TÀI XẾ', span: 7 },
        { title: '5. SẢN LƯỢNG, KM & CƯỚC', span: configuration.showChargeableWeight ? 6 : 5 },
        ...(configuration.showCharges ? [{ title: '6. CƯỚC VẬN CHUYỂN', span: 10 }] : []),
    ]

    const { metrics, pagination, filters } = configuration

    return (
        <div className={`w-full space-y-3 transition-opacity ${busy ? 'pointer-events-none opacity-60' : ''}`}>
            {/* Bộ lọc + số liệu tổng hợp */}
            <div className="flex flex-wrap items-center gap-3">
                <div className="fi-input-wrp w-full max-w-xs">
                    <div className="fi-input-wrp-prefix fi-input-wrp-prefix-has-content">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" className="fi-icon fi-size-md fi-color-gray" aria-hidden="true">
                            <path d={ICONS.search} />
                        </svg>
                    </div>
                    <div className="fi-input-wrp-content-ctn">
                        <input
                            type="search"
                            className="fi-input"
                            placeholder="Mã đơn, BSX, lái xe..."
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </div>
                </div>

                <div className="fi-input-wrp w-48">
                    <div className="fi-input-wrp-content-ctn">
                        <select
                            className="fi-select-input"
                            aria-label="Khách hàng"
                            value={filters.customerId ?? ''}
                            onChange={(event) => run(() => page.$set('customerId', event.target.value === '' ? null : Number(event.target.value), true))}
                        >
                            <option value="">Tất cả khách hàng</option>
                            {Object.entries(configuration.customers).map(([id, code]) => (
                                <option key={id} value={id}>
                                    {code}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                <div className="fi-input-wrp w-40">
                    <div className="fi-input-wrp-content-ctn">
                        <select
                            className="fi-select-input"
                            aria-label="Quản lý xe"
                            value={filters.vehicleOwner}
                            onChange={(event) => run(() => page.$set('vehicleOwner', event.target.value, true))}
                        >
                            <option value="all">Tất cả xe</option>
                            <option value="company">Xe công ty</option>
                            <option value="rent">Xe thuê</option>
                        </select>
                    </div>
                </div>

                <button type="button" className="fi-icon-btn fi-color-gray fi-size-md" title="Đặt lại bộ lọc" onClick={() => run(() => page.resetFilters())}>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" className="fi-icon fi-size-md" aria-hidden="true">
                        <path d={ICONS.reset} />
                    </svg>
                </button>

                <div className="ms-auto flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                    <span>
                        <b className="font-semibold text-gray-900 dark:text-white">{count(metrics.trips)}</b> chuyến · {count(metrics.records)} đơn
                    </span>
                    <span title={metrics.kmTitle}>
                        <b className="font-semibold text-gray-900 dark:text-white">{metrics.km.toLocaleString('vi-VN', { maximumFractionDigits: 1 })}</b> km
                    </span>
                    <span title={metrics.avgDurationTitle}>
                        TB <b className="font-semibold text-gray-900 dark:text-white">{metrics.avgDuration}</b> / chuyến
                    </span>
                    {metrics.waiting22h > 0 && (
                        <span className="text-amber-600 dark:text-amber-400">
                            <b className="font-semibold">{count(metrics.waiting22h)}</b> chờ sau 22h
                        </span>
                    )}
                    {configuration.showCharges && (
                        <span>
                            Cước <b className="font-semibold text-sky-700 dark:text-sky-300">{count(metrics.payment)} đ</b>
                        </span>
                    )}
                </div>
            </div>

            {/* Tab theo loại hình */}
            <div className="inline-flex flex-wrap items-center gap-1 rounded-xl bg-gray-100 p-1 dark:bg-white/5">
                {configuration.tabs.map((tab) => {
                    const active = filters.activeTab === tab.key

                    return (
                        <button
                            key={tab.key}
                            type="button"
                            aria-pressed={active}
                            className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                                active ? 'bg-primary-600 text-white shadow-sm' : 'text-gray-600 hover:bg-white dark:text-gray-300 dark:hover:bg-white/10'
                            }`}
                            onClick={() => run(() => page.setActiveTab(tab.key))}
                        >
                            {tab.label}
                            <span className={`rounded-full px-1.5 py-0.5 text-[10px] ${active ? 'bg-white/20 text-white' : tabCountTones[tab.tone]}`}>{count(tab.count)}</span>
                        </button>
                    )
                })}
            </div>

            {/* Chip lọc trạng thái cước */}
            {configuration.chargeFilters.length > 0 && (
                <div className="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    <span>Lọc:</span>
                    {configuration.chargeFilters.map((filter) => {
                        const active = filters.chargeStatus === filter.key

                        return (
                            <button
                                key={filter.key}
                                type="button"
                                aria-pressed={active}
                                title={active ? 'Bỏ lọc' : `Chỉ hiện các đơn ${filter.label.toLowerCase()}`}
                                className={`inline-flex cursor-pointer items-center gap-1.5 rounded-full px-2.5 py-1 ring-1 transition ${
                                    active
                                        ? `${chipActive[filter.tone]} font-semibold`
                                        : 'ring-gray-950/10 hover:bg-gray-50 dark:ring-white/10 dark:hover:bg-white/5'
                                }`}
                                onClick={() => run(() => page.toggleChargeStatus(filter.key))}
                            >
                                <span className={`h-3 w-3 rounded-sm ring-1 ${chipSwatches[filter.tone]}`} />
                                {filter.label}
                                <span className="font-mono text-[11px] opacity-70">{count(filter.count)}</span>
                            </button>
                        )
                    })}
                </div>
            )}

            {/* Bảng */}
            <div className="relative overflow-hidden rounded-xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div className="dispatch-table-container relative overflow-auto">
                    <table className="dispatch-table w-full border-collapse text-left font-sans text-xs">
                        <thead>
                            <tr className="text-center text-[10px] font-bold uppercase tracking-wider text-white">
                                {groups.map((group) => (
                                    <th key={group.title} colSpan={group.span} className="border-b border-r border-white/25 px-3 py-2">
                                        {group.title}
                                    </th>
                                ))}
                            </tr>
                            <tr className="whitespace-nowrap text-[11px] font-bold uppercase tracking-wide text-white">
                                {columns.map((column) => (
                                    <th
                                        key={column.key}
                                        title={column.title}
                                        style={columnStyle(column)}
                                        className={[
                                            'border-b border-r border-white/25 px-2.5 py-2.5',
                                            column.sticky === undefined ? '' : `sticky z-30 ${column.sticky === 2 ? 'shadow-[2px_0_4px_-1px_rgba(0,0,0,0.06)]' : ''}`,
                                            alignmentClass(column.align),
                                            column.titleClassName ?? '',
                                            column.key === 'code' ? 'truncate' : '',
                                        ]
                                            .filter(Boolean)
                                            .join(' ')}
                                    >
                                        {column.key === 'code' ? configuration.codeLabel : column.key === 'warehouse' ? configuration.warehouseLabel : column.label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-white/5">
                            {configuration.rows.length === 0 ? (
                                <tr>
                                    <td colSpan={columns.length} className="py-12 text-center text-gray-500 dark:text-gray-400">
                                        <div className="flex flex-col items-center justify-center gap-2">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} className="h-8 w-8 text-gray-400" aria-hidden="true">
                                                <path d="M2.25 13.5h3.86a2.25 2.25 0 012.012 1.244l.256.512a2.25 2.25 0 002.013 1.244h3.218a2.25 2.25 0 002.013-1.244l.256-.512a2.25 2.25 0 012.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 00-2.15-1.588H6.911a2.25 2.25 0 00-2.15 1.588L2.35 13.177a2.25 2.25 0 00-.1.661z" />
                                            </svg>
                                            <span className="text-sm font-medium">{configuration.emptyMessage}</span>
                                            <button type="button" className="mt-1 cursor-pointer text-xs text-primary-600 hover:underline dark:text-primary-400" onClick={() => run(() => page.resetFilters())}>
                                                Đặt lại bộ lọc
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                configuration.rows.map((row) => <RowView key={row.key} row={row} columns={columns} page={page} />)
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Chân bảng: số dòng + phân trang */}
                {pagination.total > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-white/5 dark:text-gray-400">
                        <span>
                            Hiển thị {pagination.from ?? 0}–{pagination.to ?? 0} / <b className="font-semibold text-gray-900 dark:text-white">{count(pagination.total)}</b> chuyến
                        </span>
                        <div className="flex items-center gap-2">
                            <div className="fi-input-wrp">
                                <div className="fi-input-wrp-content-ctn">
                                    <select
                                        className="fi-select-input"
                                        aria-label="Số dòng mỗi trang"
                                        value={pagination.perPage}
                                        onChange={(event) => run(() => page.$set('perPage', Number(event.target.value), true))}
                                    >
                                        {[25, 50, 100].map((option) => (
                                            <option key={option} value={option}>
                                                {option} / trang
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            </div>
                            <button
                                type="button"
                                className="rounded-md p-1 transition hover:bg-gray-100 disabled:opacity-40 dark:hover:bg-white/5"
                                disabled={pagination.page <= 1}
                                onClick={() => run(() => page.gotoPage(pagination.page - 1))}
                            >
                                <Icon path={ICONS.chevronLeft} />
                            </button>
                            {pageNumbers(pagination.page, pagination.lastPage).map((number, index) =>
                                number === null ? (
                                    <span key={`gap-${index}`} className="px-1">
                                        …
                                    </span>
                                ) : (
                                    <button
                                        key={number}
                                        type="button"
                                        className={`min-w-7 rounded-md px-1.5 py-1 font-mono transition ${
                                            number === pagination.page
                                                ? 'bg-primary-600 font-semibold text-white'
                                                : 'hover:bg-gray-100 dark:hover:bg-white/5'
                                        }`}
                                        onClick={() => run(() => page.gotoPage(number as number))}
                                    >
                                        {number}
                                    </button>
                                ),
                            )}
                            <button
                                type="button"
                                className="rounded-md p-1 transition hover:bg-gray-100 disabled:opacity-40 dark:hover:bg-white/5"
                                disabled={pagination.page >= pagination.lastPage}
                                onClick={() => run(() => page.gotoPage(pagination.page + 1))}
                            >
                                <Icon path={ICONS.chevronRight} />
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    )
}

const mountTripStatisticsTable: JsWidgetRenderer<Configuration, WidgetMethods> = ({ host, props: initialProps, utilities }) => {
    const root = createRoot(host)

    const update = (props: JsWidgetProps<Configuration>) => root.render(<TripStatistics configuration={props.configuration} wire={utilities.$wire} />)

    update(initialProps)

    return { update, destroy: () => root.unmount() }
}

export default mountTripStatisticsTable
