import type { JsWidgetLivewire } from '@filament/widgets/js-widget'

/** Cấu hình render đầu tiên: chưa có dữ liệu, chỉ có tiêu đề cột để vẽ skeleton. */
export interface PendingConfiguration {
    loaded: false
    skeletonColumns: string[]
}

/** Configuration của widget sau khi server đã trả dữ liệu. */
export type Deferred<Configuration> = PendingConfiguration | (Configuration & { loaded: true })

interface TableSkeletonProps {
    columns: readonly string[]
    rows?: number
}

/** Độ dài vạch so le giống bảng thật (theo dòng và cột, không ngẫu nhiên để không nhảy khi render lại). */
const barWidth = (row: number, column: number): string => `${45 + ((row * 7 + column * 5) % 11) * 5}%`

/** Bảng skeleton cùng kiểu loadingSkeleton của Filament: tiêu đề cột thật + vạch xám nhấp nháy. */
export function TableSkeleton({ columns, rows = 10 }: TableSkeletonProps) {
    return (
        <div role="status" aria-busy="true" className="overflow-x-auto rounded-xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <table className="w-full min-w-max table-auto text-sm">
                <thead>
                    <tr className="bg-[rgb(30_58_95)]">
                        {columns.map((column) => (
                            <th key={column} className="whitespace-nowrap border-l border-white/10 px-3 py-3 text-left font-semibold text-white first:border-l-0">
                                {column}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 dark:divide-white/5">
                    {Array.from({ length: rows }, (_, row) => (
                        <tr key={row}>
                            {columns.map((column, index) => (
                                <td key={column} className="px-3 py-4">
                                    <div
                                        className="h-4 animate-pulse rounded-md bg-gray-200 motion-reduce:animate-none dark:bg-white/10"
                                        style={{ width: barWidth(row, index) }}
                                    />
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
            <span className="sr-only">Đang tải dữ liệu…</span>
        </div>
    )
}

/** Lần đầu (chưa có dữ liệu) báo server bật `isReady`; Livewire render lại và gửi configuration đầy đủ qua `update()`. */
export function requestDataOnce(wire: JsWidgetLivewire, state: { requested: boolean }): void {
    if (state.requested) {
        return
    }

    state.requested = true
    void wire.$set('isReady', true)
}
