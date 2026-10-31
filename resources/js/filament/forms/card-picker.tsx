import { useEffect, useMemo, useRef, useState } from 'react'
import { createRoot } from 'react-dom/client'
import type { JsonValue, JsFieldProps, JsFieldRenderer } from '@filament/forms/js-field'

type PickerValue = string | number | null

interface Detail {
    icon?: string | null
    label?: string | null
    value?: string | null
    fullWidth?: boolean | null
}

interface Card {
    value: PickerValue
    title?: string | null
    subtitle?: string | null
    leading?: string | null
    statusDot?: string | null
    details?: readonly Detail[]
    badge?: string | null
    badgeClasses?: string | null
    suggestedBadge?: string | null
    suggestedBadgeClasses?: string | null
    isSuggested?: boolean
    suggestionScore?: number | null
    type?: 'company' | 'rent' | null
    meta?: readonly string[]
}

interface CreateAction {
    name: string
    label: string
    schemaComponent: string
}

interface Configuration {
    cards: Card[]
    icons: Record<string, string | null>
    leadingIcon: string | null
    searchPlaceholder: string
    mode: 'generic' | 'vehicle' | 'driver'
    hasSuggestions: boolean
    hasTypes: boolean
    defaultTab: string
    createAction?: CreateAction | null
}

type MountAction = (name: string, args: Record<string, JsonValue>, context: Record<string, JsonValue>) => Promise<unknown>

const BATCH_SIZE = 24

const statusDotColors: Record<string, string> = {
    success: 'bg-emerald-500',
    warning: 'bg-amber-500',
    danger: 'bg-red-500',
    info: 'bg-sky-500',
    primary: 'bg-indigo-500',
}

const defaultBadgeClasses = 'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200'

const tabClass = (active: boolean): string =>
    active
        ? 'flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition bg-primary-500/10 text-primary-600 dark:text-primary-400'
        : 'flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200'

function CardGrid(props: JsFieldProps<PickerValue, Configuration> & { mountAction: MountAction }) {
    const config = props.configuration
    const [activeTab, setActiveTab] = useState(config.defaultTab)
    const [search, setSearch] = useState('')
    const [limit, setLimit] = useState(BATCH_SIZE)
    const [busy, setBusy] = useState(false)

    const cardRefs = useRef(new Map<string, HTMLButtonElement>())
    const sentinelRef = useRef<HTMLDivElement | null>(null)
    const previousSearch = useRef('')

    const selectedKey = props.value === null || props.value === undefined ? '' : String(props.value)
    const isDisabled = props.isDisabled

    const suggestedCards = useMemo(
        () =>
            config.cards
                .filter((card) => card.isSuggested === true)
                .slice()
                .sort((a, b) => (b.suggestionScore ?? 0) - (a.suggestionScore ?? 0)),
        [config.cards],
    )

    const scopedCards = (tab: string, query: string): readonly Card[] => {
        if (config.mode === 'generic' && config.hasSuggestions && tab === 'suggested') {
            return query ? config.cards : suggestedCards
        }

        if (tab === 'company') {
            return config.cards.filter((card) => card.type === 'company')
        }

        if (tab === 'rent') {
            return config.cards.filter((card) => card.type === 'rent')
        }

        return config.cards
    }

    const matches = (card: Card, query: string): boolean => {
        if (!query) {
            return true
        }

        const searchable = [card.title, card.subtitle, ...(card.meta ?? []), ...(card.details ?? []).map((detail) => detail?.value)]

        return searchable
            .filter(Boolean)
            .join(' ')
            .toLowerCase()
            .includes(query.toLowerCase())
    }

    const filtered = useMemo(
        () => scopedCards(activeTab, search).filter((card) => matches(card, search)),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [activeTab, search, config, suggestedCards],
    )

    const visible = filtered.slice(0, limit)
    const hasMore = filtered.length > limit

    const tabs = useMemo(() => {
        if (config.mode === 'driver') {
            return []
        }

        const list: { key: string; label: string; count: number; magic?: boolean }[] = []

        if (config.mode === 'generic' && config.hasSuggestions) {
            list.push({ key: 'suggested', label: 'Gợi ý', count: suggestedCards.length, magic: true })
        }

        if (config.hasTypes) {
            list.push({ key: 'company', label: 'Xe công ty', count: config.cards.filter((card) => card.type === 'company').length })
            list.push({ key: 'rent', label: 'Xe ngoài', count: config.cards.filter((card) => card.type === 'rent').length })
        }

        list.push({ key: 'all', label: 'Tất cả', count: config.cards.length })

        return list
    }, [config, suggestedCards])

    const setTab = (tab: string) => {
        setActiveTab(tab)
        setSearch('')
        setLimit(BATCH_SIZE)
    }

    // Đổi giá trị: nhảy đúng tab như bản Blade (xe công ty / xe ngoài) hoặc về "Tất cả".
    useEffect(() => {
        if (!selectedKey) {
            return
        }

        const card = config.cards.find((candidate) => String(candidate.value) === selectedKey)

        if (config.mode === 'vehicle') {
            if (card && (card.type === 'company' || card.type === 'rent')) {
                setActiveTab(card.type)
            }

            return
        }

        if (config.mode === 'generic') {
            if (!card || !scopedCards(activeTab, search).some((candidate) => String(candidate.value) === selectedKey)) {
                setActiveTab('all')
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedKey])

    // Xoá từ khoá (khi đã gõ) thì quay lại tab "Gợi ý" như bản Blade.
    useEffect(() => {
        if (previousSearch.current !== '' && search === '' && config.mode === 'generic' && config.hasSuggestions) {
            setActiveTab('suggested')
        }

        previousSearch.current = search
    }, [search, config])

    useEffect(() => {
        setLimit(BATCH_SIZE)
    }, [activeTab, search])

    // Giữ thẻ đang chọn trong danh sách đã tải.
    useEffect(() => {
        if (!selectedKey) {
            return
        }

        const index = filtered.findIndex((card) => String(card.value) === selectedKey)

        if (index >= limit) {
            setLimit(Math.ceil((index + 1) / BATCH_SIZE) * BATCH_SIZE)
        }
    }, [filtered, selectedKey, limit])

    useEffect(() => {
        cardRefs.current.get(selectedKey)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    }, [selectedKey])

    // Tải thêm khi cuộn tới cuối danh sách.
    useEffect(() => {
        const sentinel = sentinelRef.current

        if (!hasMore || sentinel === null) {
            return
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries[0]?.isIntersecting) {
                    setLimit((current) => current + BATCH_SIZE)
                }
            },
            { rootMargin: '200px' },
        )

        observer.observe(sentinel)

        return () => observer.disconnect()
    }, [hasMore, filtered.length])

    const select = (card: Card) => {
        if (isDisabled) {
            return
        }

        props.onChange(card.value)
    }

    const runAction = () => {
        if (!config.createAction || isDisabled || busy) {
            return
        }

        setBusy(true)
        void props.mountAction(config.createAction.name, {}, { schemaComponent: config.createAction.schemaComponent }).finally(() => setBusy(false))
    }

    const showSuggested = activeTab === 'suggested' && (search === '' || config.mode === 'generic')

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2.5 sm:flex-nowrap sm:gap-3">
                {tabs.length > 0 && (
                    <div className="inline-flex max-w-full shrink-0 overflow-x-auto rounded-xl border border-gray-200 bg-gray-50 p-1 dark:border-gray-700 dark:bg-gray-900">
                        {tabs.map((tab) => (
                            <button key={tab.key} type="button" className={tabClass(activeTab === tab.key)} onClick={() => setTab(tab.key)}>
                                {tab.magic && (
                                    <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" aria-hidden="true">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                                    </svg>
                                )}
                                {tab.label}
                                <span className="rounded-full bg-gray-200/80 px-1.5 py-0.5 text-[10px] font-bold tabular-nums text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                    {tab.count}
                                </span>
                            </button>
                        ))}
                    </div>
                )}

                <div className="ml-auto flex w-full flex-1 items-center justify-end gap-2 sm:flex-initial">
                    {config.createAction && (
                        <button
                            type="button"
                            className="shrink-0 whitespace-nowrap rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-primary-500 disabled:opacity-60"
                            disabled={isDisabled || busy}
                            onClick={runAction}
                        >
                            {config.createAction.label}
                        </button>
                    )}

                    <div className="w-full max-w-xs sm:w-64">
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
                                    placeholder={config.searchPlaceholder}
                                    value={search}
                                    aria-label={config.searchPlaceholder}
                                    onChange={(event) => setSearch(event.target.value)}
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div className="grid max-h-[60vh] grid-cols-1 gap-2.5 overflow-y-auto p-1.5 custom-scrollbar sm:grid-cols-2 md:grid-cols-3">
                {visible.map((card) => {
                    const selected = String(card.value) === selectedKey
                    const dotClass = statusDotColors[card.statusDot ?? ''] ?? 'bg-gray-400'
                    const showBadge = Boolean(card.badge)
                    const badgeText = showSuggested && card.isSuggested ? (card.suggestedBadge ?? card.badge) : card.badge
                    const badgeClasses =
                        showSuggested && card.isSuggested ? (card.suggestedBadgeClasses ?? card.badgeClasses ?? defaultBadgeClasses) : (card.badgeClasses ?? defaultBadgeClasses)

                    return (
                        <button
                            key={String(card.value)}
                            type="button"
                            ref={(element) => {
                                if (element) {
                                    cardRefs.current.set(String(card.value), element)
                                } else {
                                    cardRefs.current.delete(String(card.value))
                                }
                            }}
                            aria-pressed={selected}
                            disabled={isDisabled}
                            onClick={() => select(card)}
                            className={`group relative flex h-full w-full flex-col rounded-xl border-2 text-left transition-all duration-200 ${
                                selected
                                    ? 'border-primary-500 bg-primary-50/50 shadow-md shadow-primary-500/10 dark:border-primary-400 dark:bg-primary-950/20'
                                    : 'border-gray-200 bg-white hover:border-gray-300 hover:shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:hover:border-gray-600'
                            }`}
                        >
                            {selected && (
                                <span className="absolute -right-1.5 -top-1.5 z-10 flex h-5 w-5 items-center justify-center rounded-full bg-primary-500 text-white shadow-sm">
                                    <svg className="h-3 w-3" fill="none" viewBox="0 0 24 24" strokeWidth={3} stroke="currentColor" aria-hidden="true">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                </span>
                            )}

                            <div className="flex items-center gap-3 border-b border-gray-100 px-3.5 py-3 dark:border-gray-800">
                                <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-sm ${selected ? 'bg-primary-100 dark:bg-primary-900/50' : 'bg-gray-100 dark:bg-gray-800'}`}>
                                    {config.leadingIcon ? (
                                        <span
                                            className={selected ? 'text-primary-600 dark:text-primary-400' : 'text-gray-500 dark:text-gray-400'}
                                            dangerouslySetInnerHTML={{ __html: config.leadingIcon }}
                                        />
                                    ) : (
                                        <span className="text-base">{card.leading ?? '•'}</span>
                                    )}
                                </div>

                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="min-w-0 text-sm font-bold leading-snug text-gray-900 [overflow-wrap:anywhere] dark:text-gray-100">{card.title}</span>
                                        {card.statusDot && (
                                            <span className="relative flex h-2 w-2 shrink-0">
                                                <span className={`absolute inline-flex h-full w-full animate-ping rounded-full opacity-75 ${dotClass}`} />
                                                <span className={`relative inline-flex h-2 w-2 rounded-full ${dotClass}`} />
                                            </span>
                                        )}
                                    </div>
                                    <div className="truncate text-xs text-gray-500 dark:text-gray-400">{card.subtitle}</div>
                                </div>
                            </div>

                            {Array.isArray(card.details) && card.details.length > 0 && (
                                <div className="grid grid-cols-2 gap-x-3 gap-y-2 border-t border-gray-50/50 bg-gray-50/20 px-3.5 py-3 dark:border-gray-800/50 dark:bg-gray-900/10">
                                    {card.details.map((detail, index) =>
                                        detail ? (
                                            <div key={index} className={`flex min-w-0 items-center gap-1.5 ${detail.fullWidth ? 'col-span-2' : ''}`}>
                                                {detail.icon && config.icons[detail.icon] && (
                                                    <div
                                                        className="flex shrink-0 items-center justify-center rounded bg-gray-100/80 p-1 text-gray-500 dark:bg-gray-700 dark:text-gray-400"
                                                        dangerouslySetInnerHTML={{ __html: config.icons[detail.icon] as string }}
                                                    />
                                                )}
                                                <div className="min-w-0 flex-1">
                                                    <span className="block text-[10px] leading-tight text-gray-400 dark:text-gray-500">{detail.label}</span>
                                                    <span className="block truncate text-xs font-semibold leading-tight text-gray-700 dark:text-gray-300" title={detail.value ?? ''}>
                                                        {detail.value}
                                                    </span>
                                                </div>
                                            </div>
                                        ) : null,
                                    )}
                                </div>
                            )}

                            {showBadge && (
                                <div className="mt-auto flex border-t border-gray-100 px-3.5 py-2 dark:border-gray-800">
                                    <span className={`rounded-lg px-2 py-1 text-[10px] font-bold uppercase tracking-wider ${badgeClasses}`}>{badgeText}</span>
                                </div>
                            )}

                            {showSuggested && card.isSuggested && (
                                <div className="mt-auto flex items-center gap-2 rounded-b-[10px] bg-primary-50 px-3.5 py-2 dark:bg-primary-950/30">
                                    <svg className="h-3.5 w-3.5 text-primary-500" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" aria-hidden="true">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                                    </svg>
                                    <span className="text-[11px] font-semibold text-primary-600 dark:text-primary-400">Phù hợp nhất cho đơn hàng này</span>
                                </div>
                            )}
                        </button>
                    )
                })}

                {hasMore && (
                    <div ref={sentinelRef} className="col-span-full flex items-center justify-center py-4">
                        <div className="flex items-center gap-2 text-xs text-gray-400 dark:text-gray-500">
                            <svg className="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                            </svg>
                            <span>
                                Đang tải thêm... ({visible.length}/{filtered.length})
                            </span>
                        </div>
                    </div>
                )}
            </div>

            {filtered.length === 0 && (
                <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-gray-200 bg-gray-50/50 px-4 py-6 sm:px-6 sm:py-8 dark:border-gray-700 dark:bg-gray-900/50">
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                        <svg className="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" aria-hidden="true">
                            <path strokeLinecap="round" strokeLinejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607z" />
                        </svg>
                    </div>
                    <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Không tìm thấy kết quả phù hợp</p>
                    <p className="text-xs text-gray-400 dark:text-gray-500">Thử từ khóa khác hoặc chuyển sang tab "Tất cả"</p>
                </div>
            )}
        </div>
    )
}

const mountCardPicker: JsFieldRenderer<PickerValue, Configuration> = ({ host, props: initialProps, utilities }) => {
    const root = createRoot(host)

    const mountAction: MountAction = (name, args, context) => utilities.$wire.$call('mountAction', name, args, context)

    const update = (props: JsFieldProps<PickerValue, Configuration>) => root.render(<CardGrid {...props} mountAction={mountAction} />)

    update(initialProps)

    return { update, destroy: () => root.unmount() }
}

export default mountCardPicker
