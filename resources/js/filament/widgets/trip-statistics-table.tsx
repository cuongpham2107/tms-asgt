import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { createRoot } from 'react-dom/client'
import type { JsonValue, JsWidgetProps, JsWidgetRenderer, JsWidgetUtilities, Snapshot } from '@filament/widgets/js-widget'
import { requestDataOnce, SkeletonBar, SkeletonRows } from './table-skeleton'

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
    isEdited?: boolean
    updatedAt?: string | null
}

interface Row {
    key: string
    stt: number
    variant: 'single'
    orderId: number | null
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
    charge: Charge | null
}

interface Configuration {
    /** false ở lần render đầu: chỉ có khung (tab, bộ lọc, cột), chưa có số liệu. */
    loaded: boolean
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
    lockCharge(orderId: number): Promise<unknown>
}

interface WidgetMethods {
    readonly $parent: PageApi
}

type Wire = JsWidgetUtilities<WidgetMethods>['$wire']

interface Column {
    key: string
    groupId: number
    label: string
    title?: string
    align?: 'left' | 'center' | 'right'
    width?: number
    minWidth?: number
    sticky?: boolean
    extra?: string
    titleClassName?: string
    render: (row: Row, page: PageApi) => ReactNode
}

const money = (value: number | null | undefined): string => (!value ? '0' : value.toLocaleString('vi-VN'))

const count = (value: number): string => value.toLocaleString('vi-VN')

const weight = (value: number | null): string =>
    !value
        ? '0'
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
    emerald: 'bg-emerald-200 ring-emerald-400 dark:bg-emerald-800 dark:ring-emerald-600',
    amber: 'bg-amber-200 ring-amber-400 dark:bg-amber-800 dark:ring-amber-600',
}

const chipActive: Record<string, string> = {
    emerald: 'bg-emerald-100 ring-emerald-500 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
    amber: 'bg-amber-100 ring-amber-500 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
}

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
    lockOpen: 'M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75A2.25 2.25 0 001.5 12.75v6.75a2.25 2.25 0 002.25 2.25z',
    refresh: 'M16.023 9.348h4.992V4.356M2.985 19.644v-4.992h4.992M4.532 9.348A8.25 8.25 0 0117.94 5.94l1.71 1.71M2.985 14.652a8.25 8.25 0 0013.408 3.408l1.71-1.71',
    chevronLeft: 'M15.75 19.5L8.25 12l7.5-7.5',
    chevronRight: 'M8.25 4.5l7.5 7.5-7.5 7.5',
    viewColumns: 'M9 4.5v15m6-15v15m-10.875 0h15.75c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H4.125C3.504 4.5 3 5.004 3 5.625v12.75c0 .621.504 1.125 1.125 1.125z',
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
            data-no-row-click="true"
            className="cursor-pointer"
            title="Nhấn để sửa nhanh (Enter lưu, Esc huỷ)"
            onClick={(e) => {
                e.stopPropagation()
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
        <div className="inline-flex items-center gap-1" data-no-row-click="true" onClick={(e) => e.stopPropagation()}>
            <button
                type="button"
                className="rounded-md p-1 text-primary-600 transition hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-primary-500/10"
                title={locked ? 'Xem cước (đang khoá)' : 'Sửa cước'}
                onClick={() => void page.mountAction('editCharge', { orderId })}
            >
                <Icon path={ICONS.edit} />
            </button>
        </div>
    )
}

function buildColumns(showChargeableWeight: boolean, showCharges: boolean): Column[] {
    const columns: Column[] = [
        { key: 'stt', groupId: 0, label: 'STT', align: 'center', width: 50, sticky: true, extra: 'px-2.5 text-gray-400 font-mono', render: (row) => row.stt },
        {
            key: 'code',
            groupId: 0,
            label: 'Mã đơn hàng',
            align: 'center',
            width: 140,
            sticky: true,
            extra: 'px-3 font-semibold text-gray-900 dark:text-gray-100 truncate',
            render: (row) => (
                <div className="inline-flex items-center justify-center gap-1.5">
                    <span>{row.code || '—'}</span>
                    {row.charge?.isEdited && (
                        <span
                            className="inline-flex items-center rounded px-1.5 py-0.5 text-[9px] font-semibold bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-300 ring-1 ring-inset ring-sky-600/20"
                            title={`Đã sửa cước${row.charge.updatedAt ? ` lúc ${row.charge.updatedAt}` : ''}`}
                        >
                            Đã sửa
                        </span>
                    )}
                </div>
            ),
        },
        {
            key: 'plate',
            groupId: 0,
            label: 'BSX',
            align: 'center',
            width: 110,
            sticky: true,
            extra: 'px-3 font-mono font-medium text-gray-800 dark:text-gray-200',
            render: (row) => row.plate || '—',
        },
        { key: 'date', groupId: 0, label: 'Ngày', align: 'center', minWidth: 90, extra: 'px-3 text-gray-600 dark:text-gray-400', render: (row) => row.date || '—' },
        { key: 'journey', groupId: 0, label: 'Hành trình', minWidth: 130, extra: 'px-3.5 font-medium text-gray-800 dark:text-gray-200', render: (row) => row.journey || '—' },
        { key: 'timeA', groupId: 1, label: 'TG đóng hàng (A)', align: 'center', minWidth: 115, extra: 'px-3 font-mono text-gray-700 dark:text-gray-300', render: (row) => row.timeA || '—' },
        { key: 'timeB', groupId: 1, label: 'TG xe chạy (B)', align: 'center', minWidth: 115, extra: 'px-3 font-mono text-gray-700 dark:text-gray-300', render: (row) => row.timeB || '—' },
        { key: 'timeC', groupId: 1, label: 'TG đến (C)', align: 'center', minWidth: 115, extra: 'px-3 font-mono text-gray-700 dark:text-gray-300', render: (row) => row.timeC || '—' },
        { key: 'timeD', groupId: 1, label: 'TG hạ hàng (D)', align: 'center', minWidth: 115, extra: 'px-3 font-mono text-gray-700 dark:text-gray-300', render: (row) => row.timeD || '—' },
        { key: 'loading', groupId: 2, label: 'TG đóng (1)', title: 'B - A (giờ & phút)', align: 'center', minWidth: 85, extra: 'px-2.5 font-medium text-gray-700 dark:text-gray-300', render: (row) => <span title={row.loadingTitle}>{row.loading}</span> },
        { key: 'travel', groupId: 2, label: 'TG chạy (2)', title: 'C - B (giờ & phút)', align: 'center', minWidth: 85, extra: 'px-2.5 font-medium text-gray-700 dark:text-gray-300', render: (row) => <span title={row.travelTitle}>{row.travel}</span> },
        { key: 'unloading', groupId: 2, label: 'TG hạ (3)', title: 'D - C (giờ & phút)', align: 'center', minWidth: 85, extra: 'px-2.5 font-medium text-gray-700 dark:text-gray-300', render: (row) => <span title={row.unloadingTitle}>{row.unloading}</span> },
        {
            key: 'waiting22h',
            groupId: 2,
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
            groupId: 2,
            label: 'Tổng TG (1+2+3)',
            title: '(1) + (2) + (3) (giờ & phút)',
            align: 'center',
            minWidth: 105,
            extra: 'px-3 font-bold text-primary-600 dark:text-primary-400',
            titleClassName: 'font-bold text-sky-300',
            render: (row) => <span title={row.totalTimeTitle}>{row.totalTime}</span>,
        },
        { key: 'customer', groupId: 3, label: 'Khách hàng', minWidth: 100, extra: 'px-3 font-semibold text-gray-800 dark:text-gray-200', render: (row) => row.customer || '—' },
        { key: 'warehouse', groupId: 3, label: 'Kho đóng/trả', minWidth: 100, extra: 'px-3 text-gray-700 dark:text-gray-300', render: (row) => row.warehouse || '—' },
        {
            key: 'vehicleOwner',
            groupId: 3,
            label: 'Quản lý xe',
            align: 'center',
            minWidth: 95,
            extra: 'px-3',
            render: (row) =>
                !row.vehicleOwner
                    ? ''
                    : badge(row.vehicleOwner, row.vehicleOwner === 'Xe công ty' ? 'info' : row.vehicleOwner === 'Xe thuê' ? 'warning' : 'gray'),
        },
        { key: 'vehicleType', groupId: 3, label: 'Loại xe', align: 'center', minWidth: 90, extra: 'px-3 text-gray-600 dark:text-gray-400', render: (row) => row.vehicleType ?? '' },
        { key: 'note', groupId: 3, label: 'Ghi chú', minWidth: 100, extra: 'px-3 text-gray-500 max-w-[140px] truncate', render: (row) => <span title={row.note ?? ''}>{row.note || '—'}</span> },
        {
            key: 'isReturnTrip',
            groupId: 3,
            label: 'Hàng quay đầu',
            align: 'center',
            minWidth: 90,
            extra: 'px-2.5',
            render: (row) => (row.isReturnTrip ? badge('Quay đầu', 'success') : <span className="text-gray-300 dark:text-gray-600">—</span>),
        },
        { key: 'driverName', groupId: 3, label: 'Họ tên lái xe', minWidth: 120, extra: 'px-3 font-medium text-gray-800 dark:text-gray-200', render: (row) => row.driverName || '—' },
        { key: 'kmLoaded', groupId: 4, label: 'Km có hàng', align: 'right', minWidth: 85, extra: 'px-3 font-mono', render: (row) => (row.kmLoaded !== null ? row.kmLoaded.toLocaleString('vi-VN', { maximumFractionDigits: 1 }) : '0') },
        {
            key: 'kmEmpty',
            groupId: 4,
            label: 'Km không hàng',
            align: 'right',
            minWidth: 85,
            extra: 'px-3 font-mono text-gray-500',
            render: (row) => (row.kmEmpty !== null ? row.kmEmpty.toLocaleString('vi-VN', { maximumFractionDigits: 1 }) : '0'),
        },
        { key: 'pcs', groupId: 4, label: 'PCS', align: 'right', minWidth: 110, extra: 'px-1.5 font-mono text-xs', render: (row, page) => <MetricCell row={row} field="total_packages" value={row.pcs} page={page} /> },
        { key: 'gw', groupId: 4, label: 'GW (kg)', align: 'right', minWidth: 125, extra: 'px-1.5 font-mono text-xs', render: (row, page) => <MetricCell row={row} field="total_weight" value={row.gw} page={page} /> },
        { key: 'cargoType', groupId: 4, label: 'Loại hàng', align: 'center', minWidth: 75, extra: 'px-3', render: (row) => <span className="font-mono text-[11px] text-gray-600 dark:text-gray-400">{row.cargoType || '—'}</span> },
    ]

    if (showChargeableWeight) {
        columns.push({
            key: 'chargeableWeight',
            groupId: 4,
            label: 'Trọng tải cước',
            align: 'right',
            minWidth: 95,
            extra: 'px-3 font-mono font-semibold text-gray-900 dark:text-gray-100',
            render: (row) => (row.chargeableWeight !== null ? row.chargeableWeight.toLocaleString('vi-VN', { maximumFractionDigits: 1 }) : '0'),
        })
    }

    if (showCharges) {
        columns.push(
            {
                key: 'priceCode',
                groupId: 5,
                label: 'Mã giá',
                minWidth: 160,
                extra: 'px-3 font-mono text-[11px] text-gray-600 dark:text-gray-400 max-w-[200px] truncate',
                render: (row) => <span title={row.charge?.priceCode ?? ''}>{row.charge?.priceCode || '—'}</span>,
            },
            { key: 'rateType', groupId: 5, label: 'Loại hình cước', minWidth: 110, extra: 'px-3 text-gray-700 dark:text-gray-300', render: (row) => row.charge?.rateType ?? '—' },
            {
                key: 'basePrice',
                groupId: 5,
                label: 'Đơn giá gốc',
                align: 'right',
                minWidth: 110,
                extra: 'px-3 font-mono',
                render: (row) =>
                    row.charge === null ? '0' : row.charge.basePrice === null ? <span className="font-sans font-medium text-amber-700 dark:text-amber-400">Chưa có giá</span> : money(row.charge.basePrice),
            },
            { key: 'fuelPercent', groupId: 5, label: '% dầu', align: 'right', minWidth: 70, extra: 'px-3 font-mono', render: (row) => (row.charge ? percent(row.charge.fuelPercent) : '0%') },
            { key: 'adjustedPrice', groupId: 5, label: 'Đơn giá sau ĐC', align: 'right', minWidth: 120, extra: 'px-3 font-mono font-medium text-gray-900 dark:text-gray-100', render: (row) => money(row.charge?.adjustedPrice) },
            {
                key: 'waitingDuration',
                groupId: 5,
                label: 'Giờ chờ',
                title: 'Thời gian chờ tính tiền (đã trừ thời gian miễn phí)',
                align: 'right',
                minWidth: 85,
                extra: 'px-3 font-mono text-gray-700 dark:text-gray-300',
                render: (row) => row.charge?.waitingDuration || '0',
            },
            {
                key: 'waitingFee',
                groupId: 5,
                label: 'Phí chờ',
                title: 'Chi phí giờ chờ',
                align: 'right',
                minWidth: 105,
                extra: 'px-3 font-mono',
                render: (row) => (row.charge && row.charge.waitingFee > 0 ? money(row.charge.waitingFee) : '0'),
            },
            {
                key: 'otherFees',
                groupId: 5,
                label: 'Phí khác',
                align: 'right',
                minWidth: 100,
                extra: 'px-3 font-mono',
                render: (row) => <span title={row.charge?.otherFeesCount ? `${row.charge.otherFeesCount} khoản phí` : ''}>{row.charge && row.charge.otherFees > 0 ? money(row.charge.otherFees) : '0'}</span>,
            },
            {
                key: 'vat',
                groupId: 5,
                label: 'VAT',
                align: 'right',
                minWidth: 90,
                extra: 'px-3 font-mono',
                render: (row) => <span title={row.charge ? `VAT ${row.charge.vatPercent}%` : ''}>{row.charge && row.charge.vatAmount > 0 ? money(row.charge.vatAmount) : '0'}</span>,
            },
            {
                key: 'total',
                groupId: 5,
                label: 'Thanh toán',
                align: 'right',
                minWidth: 120,
                extra: 'px-3 font-mono font-bold text-sky-700 dark:text-sky-300',
                titleClassName: 'font-bold text-sky-300',
                render: (row) => (row.charge && row.charge.total > 0 ? money(row.charge.total) : '0'),
            },
            { key: 'actions', groupId: 5, label: 'Thao tác', align: 'center', minWidth: 60, extra: 'px-2', render: (row, page) => <Actions row={row} page={page} /> },
        )
    }

    return columns
}

const GROUP_DEFS = [
    { id: 0, title: '1. THÔNG TIN CHUYẾN & HÀNH TRÌNH' },
    { id: 1, title: '2. 4 MỐC THỜI GIAN THỰC TẾ' },
    { id: 2, title: '3. THỜI LƯỢNG TÍNH TOÁN (GIỜ & PHÚT)' },
    { id: 3, title: '4. ĐỐI TÁC, XE & TÀI XẾ' },
    { id: 4, title: '5. SẢN LƯỢNG, KM & CƯỚC' },
    { id: 5, title: '6. CƯỚC VẬN CHUYỂN' },
]

interface StickyInfo {
    left: number
    isLast: boolean
}

function calculateStickyMap(visibleColumns: Column[]): Map<string, StickyInfo> {
    const map = new Map<string, StickyInfo>()
    let accumulatedLeft = 0
    let lastStickyKey: string | null = null

    visibleColumns.forEach((col) => {
        if (col.sticky) {
            lastStickyKey = col.key
        }
    })

    visibleColumns.forEach((col) => {
        if (col.sticky) {
            map.set(col.key, {
                left: accumulatedLeft,
                isLast: col.key === lastStickyKey,
            })
            accumulatedLeft += col.width ?? col.minWidth ?? 100
        }
    })

    return map
}

const STORAGE_KEY = 'tms_trip_statistics_hidden_columns_v1'

function loadHiddenColumns(): Set<string> {
    try {
        const raw = localStorage.getItem(STORAGE_KEY)
        if (raw) {
            const parsed = JSON.parse(raw)
            if (Array.isArray(parsed)) {
                return new Set(parsed)
            }
        }
    } catch {
        // ignore
    }
    return new Set()
}

function saveHiddenColumns(hidden: Set<string>): void {
    try {
        if (hidden.size === 0) {
            localStorage.removeItem(STORAGE_KEY)
        } else {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(Array.from(hidden)))
        }
    } catch {
        // ignore
    }
}

const alignmentClass = (align: Column['align']): string => (align === 'center' ? 'text-center' : align === 'right' ? 'text-right' : '')

const columnStyle = (column: Column, sticky?: StickyInfo) => ({
    width: column.width,
    minWidth: column.width ?? column.minWidth,
    maxWidth: column.width,
    ...(sticky ? { left: sticky.left } : {}),
})

function rowTone(row: Row): 'locked' | 'edited' | 'missing' | null {
    if (row.charge?.locked) {
        return 'locked'
    }

    if (row.charge?.isEdited) {
        return 'edited'
    }

    return row.charge?.missing ? 'missing' : null
}

function RowView({
    row,
    columns,
    stickyMap,
    page,
}: {
    row: Row
    columns: Column[]
    stickyMap: Map<string, StickyInfo>
    page: PageApi
}) {
    const tone = rowTone(row)
    const isClickable = Boolean(row.orderId)

    const handleRowClick = (e: React.MouseEvent) => {
        const target = e.target as HTMLElement
        if (target.closest('button, input, textarea, select, a, [data-no-row-click]')) {
            return
        }

        if (row.orderId) {
            void page.mountAction('editCharge', { orderId: row.orderId })
        }
    }

    const rowClass = [
        'dispatch-row whitespace-nowrap group transition-colors duration-150',
        isClickable ? 'cursor-pointer' : '',
        tone === 'locked'
            ? 'bg-emerald-100 hover:bg-emerald-200/80 dark:bg-emerald-950/70 dark:hover:bg-emerald-900/60'
            : tone === 'edited'
              ? 'bg-sky-100 hover:bg-sky-200/80 dark:bg-sky-950/70 dark:hover:bg-sky-900/60'
              : tone === 'missing'
                ? 'bg-amber-100 hover:bg-amber-200/80 dark:bg-amber-950/70 dark:hover:bg-amber-900/60'
                : 'hover:bg-gray-50/80 dark:hover:bg-gray-800/50',
    ]
        .filter(Boolean)
        .join(' ')

    const stickyBg =
        tone === 'locked'
            ? 'bg-emerald-100 dark:bg-emerald-950'
            : tone === 'edited'
              ? 'bg-sky-100 dark:bg-sky-950'
              : tone === 'missing'
                ? 'bg-amber-100 dark:bg-amber-950'
                : 'bg-white dark:bg-gray-900'

    const stickyHoverBg =
        tone === 'locked'
            ? 'group-hover:bg-emerald-200/80 dark:group-hover:bg-emerald-900/60'
            : tone === 'edited'
              ? 'group-hover:bg-sky-200/80 dark:group-hover:bg-sky-900/60'
              : tone === 'missing'
                ? 'group-hover:bg-amber-200/80 dark:group-hover:bg-amber-900/60'
                : 'group-hover:bg-gray-50 dark:group-hover:bg-gray-800'

    return (
        <tr className={rowClass} onClick={handleRowClick}>
            {columns.map((column) => {
                const sticky = stickyMap.get(column.key)

                return (
                    <td
                        key={column.key}
                        className={[
                            'py-2 border-r border-gray-100 dark:border-white/5',
                            sticky ? `sticky z-10 ${stickyBg} ${stickyHoverBg}` : '',
                            sticky?.isLast ? 'shadow-[2px_0_4px_-1px_rgba(0,0,0,0.06)] border-gray-200 dark:border-white/10' : '',
                            alignmentClass(column.align),
                            column.extra ?? 'px-3',
                        ]
                            .filter(Boolean)
                            .join(' ')}
                        style={columnStyle(column, sticky)}
                    >
                        {column.render(row, page)}
                    </td>
                )
            })}
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

    const allColumns = useMemo(
        () => buildColumns(configuration.showChargeableWeight, configuration.showCharges),
        [configuration.showChargeableWeight, configuration.showCharges]
    )

    const [hiddenColumns, setHiddenColumns] = useState<Set<string>>(() => loadHiddenColumns())
    const [columnMenuOpen, setColumnMenuOpen] = useState(false)

    const toggleColumn = (key: string) => {
        setHiddenColumns((prev) => {
            const next = new Set(prev)
            if (next.has(key)) {
                next.delete(key)
            } else {
                next.add(key)
            }
            saveHiddenColumns(next)
            return next
        })
    }

    const resetColumns = () => {
        const empty = new Set<string>()
        setHiddenColumns(empty)
        saveHiddenColumns(empty)
    }

    const visibleColumns = useMemo(
        () => allColumns.filter((col) => !hiddenColumns.has(col.key)),
        [allColumns, hiddenColumns]
    )

    const stickyMap = useMemo(() => calculateStickyMap(visibleColumns), [visibleColumns])

    const groups = useMemo(
        () =>
            GROUP_DEFS.filter((g) => g.id !== 5 || configuration.showCharges)
                .map((g) => ({
                    title: g.title,
                    span: visibleColumns.filter((c) => c.groupId === g.id).length,
                }))
                .filter((g) => g.span > 0),
        [visibleColumns, configuration.showCharges]
    )

    const { metrics, pagination, filters } = configuration

    return (
        <div className={`w-full space-y-3 transition-opacity ${busy ? 'pointer-events-none opacity-60' : ''}`}>
            {/* Hàng 1: Tab theo loại hình + Số liệu tổng hợp */}
            <div className="flex flex-wrap items-center justify-between gap-3">
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
                                <span className={`rounded-full px-1.5 py-0.5 text-[10px] ${active ? 'bg-white/20 text-white' : tabCountTones[tab.tone]}`}>{configuration.loaded ? count(tab.count) : <SkeletonBar className="w-4" />}</span>
                            </button>
                        )
                    })}
                </div>

                {!configuration.loaded ? (
                    <div className="ms-auto flex items-center gap-4" aria-hidden="true">
                        <SkeletonBar className="w-24" />
                        <SkeletonBar className="w-14" />
                        <SkeletonBar className="w-28" />
                    </div>
                ) : (
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
                )}
            </div>

            {/* Hàng 2: Chip lọc trạng thái cước (bên trái) + Bộ lọc tìm kiếm & quản lý cột (cuối dòng bên phải) */}
            <div className="flex flex-wrap items-center justify-between gap-3">
                {configuration.chargeFilters.length > 0 ? (
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
                                    <span className="font-mono text-[11px] opacity-70">{configuration.loaded ? count(filter.count) : <SkeletonBar className="w-4" />}</span>
                                </button>
                            )
                        })}
                    </div>
                ) : (
                    <div />
                )}

                <div className="ms-auto flex flex-wrap items-center gap-3">
                    <div className="fi-input-wrp w-64 max-w-xs">
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

                    <div className="relative">
                        <button
                            type="button"
                            className={`inline-flex h-9 w-9 items-center justify-center rounded-lg border shadow-xs transition cursor-pointer ${
                                columnMenuOpen
                                    ? 'border-primary-500 bg-gray-100 text-primary-600 ring-1 ring-primary-500 dark:border-primary-400 dark:bg-white/10 dark:text-primary-300'
                                    : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 hover:text-gray-900 dark:border-white/10 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-white/5 dark:hover:text-white'
                            }`}
                            title="Quản lý cột"
                            onClick={() => setColumnMenuOpen((prev) => !prev)}
                        >
                            <Icon path={ICONS.viewColumns} className="h-4 w-4" />
                        </button>

                        {columnMenuOpen && (
                            <>
                                <div className="fixed inset-0 z-40" onClick={() => setColumnMenuOpen(false)} />
                                <div className="absolute right-0 top-full z-50 mt-2 w-80 rounded-xl bg-white shadow-xl ring-1 ring-gray-950/10 dark:bg-gray-800 dark:ring-white/10">
                                    <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-white/5">
                                        <span className="text-sm font-semibold text-gray-900 dark:text-white">Cột</span>
                                        <button
                                            type="button"
                                            className="text-xs font-medium text-danger-600 hover:text-danger-500 hover:underline dark:text-danger-400 cursor-pointer"
                                            onClick={resetColumns}
                                        >
                                            Đặt lại
                                        </button>
                                    </div>

                                    <div className="max-h-[30rem] space-y-2 overflow-y-auto p-3">
                                        {GROUP_DEFS.filter((g) => g.id !== 5 || configuration.showCharges).map((g) => {
                                            const groupCols = allColumns.filter((c) => c.groupId === g.id)
                                            if (groupCols.length === 0) {
                                                return null
                                            }

                                            return (
                                                <div key={g.id} className="pt-2.5 first:pt-0">
                                                    <div className="px-2.5 pb-1.5 text-[11px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                                        {g.title}
                                                    </div>
                                                    <div className="space-y-1">
                                                        {groupCols.map((col) => {
                                                            const isVisible = !hiddenColumns.has(col.key)
                                                            const label = col.key === 'code' ? configuration.codeLabel : col.key === 'warehouse' ? configuration.warehouseLabel : col.label
                                                            const isLocked = col.key === 'code'

                                                            return (
                                                                <label
                                                                    key={col.key}
                                                                    className={`flex items-center gap-3 rounded-lg px-2.5 py-2 text-xs font-medium transition select-none ${
                                                                        isLocked ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer hover:bg-gray-50 dark:hover:bg-white/5 text-gray-700 dark:text-gray-200'
                                                                    }`}
                                                                >
                                                                    <input
                                                                        type="checkbox"
                                                                        className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:focus:ring-primary-400"
                                                                        checked={isVisible}
                                                                        disabled={isLocked}
                                                                        onChange={() => toggleColumn(col.key)}
                                                                    />
                                                                    <span className="truncate">{label}</span>
                                                                </label>
                                                            )
                                                        })}
                                                    </div>
                                                </div>
                                            )
                                        })}
                                    </div>

                                    <div className="border-t border-gray-100 p-3 dark:border-white/5">
                                        <button
                                            type="button"
                                            className="w-full rounded-lg bg-primary-600 px-4 py-2 text-center text-xs font-semibold text-white shadow-xs hover:bg-primary-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 transition cursor-pointer"
                                            onClick={() => setColumnMenuOpen(false)}
                                        >
                                            Áp dụng cột
                                        </button>
                                    </div>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            </div>

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
                                {visibleColumns.map((column) => {
                                    const sticky = stickyMap.get(column.key)

                                    return (
                                        <th
                                            key={column.key}
                                            title={column.title}
                                            style={columnStyle(column, sticky)}
                                            className={[
                                                'border-b border-r border-white/25 px-2.5 py-2.5',
                                                sticky ? 'sticky z-30' : '',
                                                sticky?.isLast ? 'shadow-[2px_0_4px_-1px_rgba(0,0,0,0.06)]' : '',
                                                alignmentClass(column.align),
                                                column.titleClassName ?? '',
                                                column.key === 'code' ? 'truncate' : '',
                                            ]
                                                .filter(Boolean)
                                                .join(' ')}
                                        >
                                            {column.key === 'code' ? configuration.codeLabel : column.key === 'warehouse' ? configuration.warehouseLabel : column.label}
                                        </th>
                                    )
                                })}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-white/5">
                            {!configuration.loaded ? (
                                <SkeletonRows columns={visibleColumns.length} />
                            ) : configuration.rows.length === 0 ? (
                                <tr>
                                    <td colSpan={visibleColumns.length} className="py-12 text-center text-gray-500 dark:text-gray-400">
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
                                configuration.rows.map((row) => (
                                    <RowView key={row.key} row={row} columns={visibleColumns} stickyMap={stickyMap} page={page} />
                                ))
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
    const dataRequest = { requested: false }

    const update = ({ configuration }: JsWidgetProps<Configuration>) => {
        if (!configuration.loaded) {
            requestDataOnce(utilities.$wire, dataRequest)
        }

        root.render(<TripStatistics configuration={configuration} wire={utilities.$wire} />)
    }

    update(initialProps)

    return { update, destroy: () => root.unmount() }
}

export default mountTripStatisticsTable
