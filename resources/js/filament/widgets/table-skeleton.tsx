import type { JsWidgetLivewire } from '@filament/widgets/js-widget'

interface SkeletonRowsProps {
    columns: number
    rows?: number
    cellClassName?: string
}

/** Độ dài vạch so le như bảng thật (theo dòng và cột, cố định để không nhảy khi render lại). */
const barWidth = (row: number, column: number): string => `${45 + ((row * 7 + column * 5) % 11) * 5}%`

/** Các dòng skeleton đặt trong <tbody> của bảng thật, nên header, cột và độ rộng giữ đúng như lúc có dữ liệu. */
export function SkeletonRows({ columns, rows = 20, cellClassName = 'px-2.5 py-3' }: SkeletonRowsProps) {
    return (
        <>
            {Array.from({ length: rows }, (_, row) => (
                <tr key={row} aria-hidden="true">
                    {Array.from({ length: columns }, (_, column) => (
                        <td key={column} className={cellClassName}>
                            <div className="h-3.5 animate-pulse rounded-md bg-gray-200 motion-reduce:animate-none dark:bg-white/10" style={{ width: barWidth(row, column) }} />
                        </td>
                    ))}
                </tr>
            ))}
        </>
    )
}

/** Vạch nhỏ thay cho con số (đếm tab, tổng hợp) khi chưa có dữ liệu. */
export function SkeletonBar({ className = 'w-6' }: { className?: string }) {
    return <span aria-hidden="true" className={`inline-block h-3 animate-pulse rounded bg-gray-300/70 align-middle motion-reduce:animate-none dark:bg-white/15 ${className}`} />
}

/** Lần đầu (chưa có dữ liệu) báo server bật `isReady`; Livewire render lại và gửi configuration đầy đủ qua `update()`. */
export function requestDataOnce(wire: JsWidgetLivewire, state: { requested: boolean }): void {
    if (state.requested) {
        return
    }

    state.requested = true
    void wire.$set('isReady', true)
}
