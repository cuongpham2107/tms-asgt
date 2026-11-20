import type { ReactNode } from 'react'

/**
 * Bộ lọc dùng chung cho các trang tuỳ chỉnh vẽ bằng React (Bảng kê, Báo cáo tài xế, Kiểm soát xe, Kế hoạch),
 * để ô tìm kiếm, ô chọn, tab, chip và nút kỳ đang chọn trông giống nhau và giống nút/ô của Filament.
 */

/** Nút đang chọn (kỳ báo cáo…): cùng class Filament gắn cho action màu primary ở header trang Bảng kê. */
export const ACTIVE_BUTTON_CLASS =
    'fi-color fi-color-primary fi-bg-color-600 hover:fi-bg-color-500 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-0 hover:fi-text-color-0 dark:fi-text-color-0 dark:hover:fi-text-color-0'

const SEARCH_ICON = 'm21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z'

interface SearchInputProps {
    value: string
    onChange: (value: string) => void
    placeholder: string
    /** Độ rộng ô; mặc định giống nhau ở mọi trang. */
    className?: string
    onFocus?: () => void
    onBlur?: () => void
}

export function SearchInput({ value, onChange, placeholder, className = 'w-64 max-w-xs', onFocus, onBlur }: SearchInputProps) {
    return (
        <div className={`fi-input-wrp ${className}`}>
            <div className="fi-input-wrp-prefix fi-input-wrp-prefix-has-content">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" className="fi-icon fi-size-md fi-color-gray" aria-hidden="true">
                    <path d={SEARCH_ICON} />
                </svg>
            </div>
            <div className="fi-input-wrp-content-ctn">
                <input
                    type="search"
                    className="fi-input"
                    aria-label={placeholder}
                    placeholder={placeholder}
                    value={value}
                    onFocus={onFocus}
                    onBlur={onBlur}
                    onChange={(event) => onChange(event.target.value)}
                />
            </div>
        </div>
    )
}

interface SelectInputProps {
    label: string
    value: string | number
    onChange: (value: string) => void
    children: ReactNode
    className?: string
    /** Chữ đứng trước ô chọn (vd. "Hiển thị"). */
    prefix?: string
}

export function SelectInput({ label, value, onChange, children, className = 'w-40', prefix }: SelectInputProps) {
    return (
        <div className={`fi-input-wrp ${className}`}>
            {prefix && (
                <div className="fi-input-wrp-prefix fi-input-wrp-prefix-has-content fi-input-wrp-prefix-has-label">
                    <span className="fi-input-wrp-label">{prefix}</span>
                </div>
            )}
            <div className="fi-input-wrp-content-ctn">
                <select className="fi-select-input" aria-label={label} value={value} onChange={(event) => onChange(event.target.value)}>
                    {children}
                </select>
            </div>
        </div>
    )
}

export interface SegmentedTabItem<T extends string> {
    key: T
    label: string
    icon?: ReactNode
    count?: ReactNode
    /** Màu ô đếm khi tab chưa chọn. */
    countClassName?: string
}

interface SegmentedTabsProps<T extends string> {
    items: SegmentedTabItem<T>[]
    active: T
    onChange: (key: T) => void
    label?: string
}

/** Nhóm tab nền xám, tab đang chọn tô primary: giống thanh tab ở đầu trang Kiểm soát xe. */
export function SegmentedTabs<T extends string>({ items, active, onChange, label }: SegmentedTabsProps<T>) {
    return (
        <div role="tablist" aria-label={label} className="inline-flex w-fit flex-wrap items-center gap-1 rounded-xl bg-gray-100 p-1 ring-1 ring-gray-950/5 dark:bg-gray-800 dark:ring-white/10">
            {items.map((item) => {
                const isActive = item.key === active

                return (
                    <button
                        key={item.key}
                        type="button"
                        role="tab"
                        aria-selected={isActive}
                        onClick={() => onChange(item.key)}
                        className={`inline-flex cursor-pointer items-center gap-1.5 rounded-lg px-3.5 py-1.5 text-xs font-semibold transition ${
                            isActive ? 'bg-primary-600 text-white shadow-xs' : 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white'
                        }`}
                    >
                        {item.icon}
                        <span>{item.label}</span>
                        {item.count !== undefined && (
                            <span className={`rounded-full px-1.5 py-0.5 text-[10px] ${isActive ? 'bg-white/20 text-white' : item.countClassName ?? 'bg-gray-200 text-gray-700 dark:bg-white/10 dark:text-gray-300'}`}>
                                {item.count}
                            </span>
                        )}
                    </button>
                )
            })}
        </div>
    )
}

interface FilterChipProps {
    active: boolean
    onClick: () => void
    children: ReactNode
    title?: string
    /** Lớp màu khi chip đang bật; mặc định xanh như chip khu vực ở Bảng kê. */
    activeClassName?: string
}

export function FilterChip({ active, onClick, children, title, activeClassName = 'bg-blue-100 ring-blue-500 text-blue-900 dark:bg-blue-950 dark:text-blue-200' }: FilterChipProps) {
    return (
        <button
            type="button"
            aria-pressed={active}
            title={title}
            onClick={onClick}
            className={`inline-flex cursor-pointer items-center gap-1.5 rounded-full px-2.5 py-1 ring-1 transition ${
                active ? `${activeClassName} font-semibold` : 'ring-gray-950/10 hover:bg-gray-50 dark:ring-white/10 dark:hover:bg-white/5'
            }`}
        >
            {children}
        </button>
    )
}

/** Số đếm nhỏ trong chip. */
export function ChipCount({ children }: { children: ReactNode }) {
    return <span className="font-mono text-[11px] opacity-70">{children}</span>
}
