import { memo, useCallback, useEffect, useMemo, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import type {
    JsWidgetProps,
    JsWidgetRenderer,
    JsWidgetUtilities,
    Snapshot,
} from "@filament/widgets/js-widget";
import { requestDataOnce, SkeletonBar, SkeletonRows } from "./table-skeleton";
import { ChipCount, FilterChip, SearchInput } from "./filter-controls";

interface Area {
    id: number;
    code: string;
    name: string;
}

interface PlanRow {
    id: number;
    orderCode: string;
    type: string | null;
    isExternal: boolean;
    areaId: number | null;
    areaCode: string | null;
    customerCode: string | null;
    customerName: string | null;
    cargoName: string | null;
    pickup: string | null;
    pickupTitle: string | null;
    destinations: string[];
    packages: number | null;
    weight: number | null;
    chargeableWeight: number | null;
    notes: string | null;
    isReturnTrip: boolean;
    createdBy: string | null;
    createdById: number | null;
    /** false: giờ đóng là giờ form tự điền lúc tạo đơn (không sau giờ tạo), ô Giờ đóng để trống. */
    hasPlannedTime: boolean;
    time: string | null;
    minutesLeft: number | null;
    /** Giờ đóng hàng thuộc ngày trước hôm nay mà vẫn chưa gán: tô vàng cảnh báo. */
    isPastDay: boolean;
}

interface Configuration {
    /** false ở lần render đầu: chỉ có khung, chưa có đơn. */
    loaded: boolean;
    areas: Area[];
    rows: PlanRow[];
    soonMinutes: number;
    currentUserId: number | null;
}

interface PageApi {
    openAssignTransport(orderId: number): Promise<unknown>;
}

interface WidgetMethods {
    readonly $parent: PageApi;
    $refresh(): Promise<unknown>;
}

type Wire = JsWidgetUtilities<WidgetMethods>["$wire"];

/** Tự tải lại để "còn X phút" và đơn mới tạo luôn đúng khi điều hành để màn hình mở. */
const REFRESH_MS = 60_000;
const AREA_STORAGE_KEY = "tms.dispatchPlan.areas";
const MINE_STORAGE_KEY = "tms.dispatchPlan.mineOnly";
const COLUMN_COUNT = 9;
const MINUTES_PER_DAY = 1440;
/** Cùng màu tiêu đề bảng ở tab Kiểm soát xe. */
const HEADER_BG = "rgb(30 58 95)";
/** Safari không vẽ border của ô sticky trong bảng border-collapse, nên kẻ ô header bằng box-shadow (giống bảng Kiểm soát xe). */
const HEADER_LINE = "shadow-[inset_-1px_-1px_0_rgb(255_255_255/0.25)]";
const HEADER_CELL = `px-2 py-2 text-center whitespace-nowrap ${HEADER_LINE}`;
const CELL = "border-r border-gray-100 px-2 py-1.5 align-middle dark:border-white/5";

const numberFormat = new Intl.NumberFormat("vi-VN", { maximumFractionDigits: 2 });

function readStoredAreas(): number[] {
    try {
        const value = JSON.parse(window.localStorage.getItem(AREA_STORAGE_KEY) ?? "[]");
        return Array.isArray(value) ? value.filter((id): id is number => typeof id === "number") : [];
    } catch {
        return [];
    }
}

function storeAreas(ids: number[]): void {
    try {
        window.localStorage.setItem(AREA_STORAGE_KEY, JSON.stringify(ids));
    } catch {
        // Trình duyệt chặn localStorage: bộ lọc chỉ mất khi tải lại trang.
    }
}

function readStoredMineOnly(): boolean {
    try {
        return window.localStorage.getItem(MINE_STORAGE_KEY) === "1";
    } catch {
        return false;
    }
}

function storeMineOnly(value: boolean): void {
    try {
        window.localStorage.setItem(MINE_STORAGE_KEY, value ? "1" : "0");
    } catch {
        // Trình duyệt chặn localStorage: bộ lọc chỉ mất khi tải lại trang.
    }
}

function normalize(text: string): string {
    return text.normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/đ/g, "d").replace(/Đ/g, "D").toLowerCase();
}

function formatCountdown(minutes: number): string {
    const abs = Math.abs(minutes);
    if (abs >= MINUTES_PER_DAY) {
        const days = Math.floor(abs / MINUTES_PER_DAY);
        return minutes < 0 ? `Quá ${days} ngày` : `Còn ${days} ngày`;
    }

    const hours = Math.floor(abs / 60);
    const rest = abs % 60;
    const text = hours > 0 ? `${hours}g${rest > 0 ? String(rest).padStart(2, "0") : ""}` : `${rest}p`;

    return minutes < 0 ? `Quá ${text}` : `Còn ${text}`;
}

/** Giờ đóng hàng dự kiến còn ở tương lai: đơn đang là kế hoạch. */
function isPlanned(row: Snapshot<PlanRow>): boolean {
    return row.minutesLeft !== null && row.minutesLeft > 0;
}

function isOverdue(row: Snapshot<PlanRow>): boolean {
    return row.minutesLeft !== null && row.minutesLeft <= 0;
}

type SortKey = "time" | "order" | "status" | "area" | "customer" | "route" | "load";
type SortDirection = "asc" | "desc";

const COLUMNS: { label: string; sortKey?: SortKey }[] = [
    { label: "Thao tác" },
    { label: "Giờ đóng", sortKey: "time" },
    { label: "Đơn hàng", sortKey: "order" },
    { label: "Trạng thái", sortKey: "status" },
    { label: "KV", sortKey: "area" },
    { label: "Khách hàng", sortKey: "customer" },
    { label: "Hành trình", sortKey: "route" },
    { label: "Kiện / Tải", sortKey: "load" },
    { label: "Ghi chú" },
];

const collator = new Intl.Collator("vi", { numeric: true, sensitivity: "base" });

function sortValue(row: Snapshot<PlanRow>, key: SortKey): string | number {
    switch (key) {
        case "time":
            return row.minutesLeft ?? Number.MAX_SAFE_INTEGER;
        case "order":
            return row.orderCode;
        case "status":
            return isOverdue(row) ? 0 : isPlanned(row) ? 2 : 1;
        case "area":
            return row.areaCode ?? "";
        case "customer":
            return row.customerCode ?? "";
        case "route":
            return [row.pickup ?? "", ...row.destinations].join(" ");
        case "load":
            return row.packages ?? -1;
    }
}

function compareRows(a: Snapshot<PlanRow>, b: Snapshot<PlanRow>, key: SortKey, direction: SortDirection): number {
    const left = sortValue(a, key);
    const right = sortValue(b, key);
    const result = typeof left === "number" && typeof right === "number" ? left - right : collator.compare(String(left), String(right));

    return direction === "asc" ? result : -result;
}

type Urgency = "overdue" | "soon" | "normal";

function urgencyOf(row: Snapshot<PlanRow>, soonMinutes: number): Urgency {
    if (row.minutesLeft === null) {
        return "normal";
    }

    if (row.minutesLeft < 0) {
        return "overdue";
    }

    return row.minutesLeft <= soonMinutes ? "soon" : "normal";
}

const URGENCY_STYLES: Record<Urgency, { rail: string; time: string; chip: string }> = {
    overdue: {
        rail: "bg-rose-500",
        time: "text-rose-600 dark:text-rose-400",
        chip: "bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/30",
    },
    soon: {
        rail: "bg-amber-400",
        time: "text-amber-600 dark:text-amber-400",
        chip: "bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/30",
    },
    normal: {
        rail: "bg-transparent",
        time: "text-gray-950 dark:text-white",
        chip: "bg-gray-50 text-gray-600 ring-gray-950/10 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10",
    },
};

const PlanRowView = memo(function PlanRowView({
    row,
    soonMinutes,
    isLoading,
    isBusy,
    onAssign,
}: {
    row: Snapshot<PlanRow>;
    soonMinutes: number;
    isLoading: boolean;
    isBusy: boolean;
    onAssign: (id: number) => void;
}) {
    const urgency = urgencyOf(row, soonMinutes);
    const styles = URGENCY_STYLES[urgency];
    const destinations = row.destinations.length > 0 ? row.destinations.join(", ") : "Lái xe chọn";

    return (
        <tr
            className={`group transition-colors ${
                row.isPastDay
                    ? "bg-amber-50 hover:bg-amber-100/70 dark:bg-amber-500/10 dark:hover:bg-amber-500/15"
                    : "bg-white hover:bg-primary-50/40 dark:bg-gray-900 dark:hover:bg-white/[0.03]"
            }`}
        >
            <td className={`${CELL} text-center`}>
                <button
                    type="button"
                    disabled={isBusy}
                    onClick={() => onAssign(row.id)}
                    className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white shadow-xs transition hover:bg-primary-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 active:translate-y-px disabled:cursor-wait disabled:opacity-60"
                >
                    {isLoading ? (
                        <svg aria-hidden="true" className="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z" />
                        </svg>
                    ) : (
                        <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" className="h-3.5 w-3.5">
                            <path d="M6.5 3c-1.051 0-2.093.04-3.125.117A1.49 1.49 0 0 0 2 4.607V10.5h9V4.606c0-.771-.59-1.43-1.375-1.489A41.568 41.568 0 0 0 6.5 3ZM2 12v2.5A1.5 1.5 0 0 0 3.5 16h.041a3 3 0 0 1 5.918 0h.791a.75.75 0 0 0 .75-.75V12H2Z" />
                            <path d="M6.5 18a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM13.25 5a.75.75 0 0 0-.75.75v8.514a3.001 3.001 0 0 1 4.893 1.44c.37-.275.607-.714.607-1.204V9.5a.75.75 0 0 0-.164-.468L15.36 5.282A.75.75 0 0 0 14.775 5H13.25ZM14.5 18a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" />
                        </svg>
                    )}
                    Gán lái, xe
                </button>
            </td>

            <td className={`relative w-24 whitespace-nowrap ${CELL} ps-3 text-center`}>
                <span aria-hidden="true" className={`absolute inset-y-2 start-0 w-1 rounded-e-full ${styles.rail}`} />
                {row.minutesLeft !== null && (
                    <>
                        <div className={`font-mono text-sm font-semibold leading-none tabular-nums ${styles.time}`}>{row.time}</div>
                        <span className={`mt-1 inline-flex whitespace-nowrap rounded-md px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset ${styles.chip}`}>
                            {formatCountdown(row.minutesLeft)}
                        </span>
                    </>
                )}
            </td>

            <td className={CELL}>
                <div className="font-semibold text-gray-950 dark:text-white">{row.orderCode}</div>
                <div className="mt-1 flex flex-wrap items-center gap-1">
                    {row.type && (
                        <span
                            className={`rounded px-1.5 py-0.5 text-[10px] font-semibold ${
                                row.isExternal
                                    ? "bg-violet-100 text-violet-800 dark:bg-violet-500/15 dark:text-violet-300"
                                    : "bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300"
                            }`}
                        >
                            {row.type}
                        </span>
                    )}
                    {row.isReturnTrip && (
                        <span className="rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] font-semibold text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300">
                            Quay đầu
                        </span>
                    )}
                </div>
            </td>

            <td className={`${CELL} text-center`}>
                {isPlanned(row) && (
                    <span className="inline-flex rounded-md bg-sky-100 px-1.5 py-0.5 text-[11px] font-semibold text-sky-800 ring-1 ring-inset ring-sky-600/20 dark:bg-sky-500/15 dark:text-sky-300">
                        Kế hoạch
                    </span>
                )}
                {isOverdue(row) && (
                    <span className="inline-flex whitespace-nowrap rounded-md bg-rose-50 px-1.5 py-0.5 text-[11px] font-semibold text-rose-700 ring-1 ring-inset ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300">
                        Quá giờ cấp xe
                    </span>
                )}
            </td>

            <td className={CELL}>
                {row.areaCode ? (
                    <span className="inline-flex rounded-md bg-gray-900 px-1.5 py-0.5 text-[11px] font-bold tracking-wide text-white dark:bg-white dark:text-gray-900">
                        {row.areaCode}
                    </span>
                ) : (
                    <span className="text-gray-400">—</span>
                )}
            </td>

            <td className={`max-w-56 ${CELL}`}>
                <div className="font-semibold text-gray-950 dark:text-white" title={row.customerName ?? undefined}>
                    {row.customerCode ?? "—"}
                </div>
                {row.cargoName && <div className="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">{row.cargoName}</div>}
            </td>

            <td className={CELL}>
                <div className="flex items-center gap-1.5">
                    <span className="font-semibold text-gray-950 dark:text-white" title={row.pickupTitle ?? undefined}>
                        {row.pickup ?? "—"}
                    </span>
                    <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" className="h-4 w-4 shrink-0 text-gray-400">
                        <path fillRule="evenodd" d="M3 10a.75.75 0 0 1 .75-.75h10.638l-3.96-3.71a.75.75 0 1 1 1.026-1.095l5.25 4.92a.75.75 0 0 1 0 1.095l-5.25 4.92a.75.75 0 0 1-1.026-1.095l3.96-3.71H3.75A.75.75 0 0 1 3 10Z" clipRule="evenodd" />
                    </svg>
                    <span className={row.destinations.length > 0 ? "font-semibold text-gray-950 dark:text-white" : "italic text-gray-400"}>
                        {destinations}
                    </span>
                </div>
            </td>

            <td className={`whitespace-nowrap ${CELL} text-end tabular-nums`}>
                <div className="font-semibold text-gray-950 dark:text-white">
                    {row.packages !== null ? `${numberFormat.format(row.packages)} kiện` : "—"}
                </div>
                <div className="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                    {row.weight !== null ? `${numberFormat.format(row.weight)} tấn` : ""}
                    {row.isExternal && (
                        <span className={row.chargeableWeight === null ? "ms-1 font-semibold text-rose-600 dark:text-rose-400" : "ms-1"}>
                            {row.chargeableWeight === null ? "· chưa có TT tính cước" : `· TT cước ${numberFormat.format(row.chargeableWeight)}`}
                        </span>
                    )}
                </div>
            </td>

            <td className={`max-w-64 ${CELL} text-gray-600 dark:text-gray-400`}>
                {row.notes ? <p className="line-clamp-2" title={row.notes}>{row.notes}</p> : <span className="text-gray-300 dark:text-gray-600">—</span>}
                {row.createdBy && <div className="mt-1 text-[11px] text-gray-400">Tạo bởi {row.createdBy}</div>}
            </td>

        </tr>
    );
});

function DispatchPlan({ configuration, wire }: { configuration: Snapshot<Configuration>; wire: Wire }) {
    const page = wire.$parent;
    const [selectedAreas, setSelectedAreas] = useState<number[]>(readStoredAreas);
    const [search, setSearch] = useState("");
    const [mineOnly, setMineOnly] = useState<boolean>(readStoredMineOnly);
    const [plannedOnly, setPlannedOnly] = useState(false);
    const [sort, setSort] = useState<{ key: SortKey; direction: SortDirection } | null>(null);

    /** Bấm cột: tăng → giảm → bỏ sắp xếp (về thứ tự giờ đóng). */
    const toggleSort = useCallback((key: SortKey) => {
        setSort((current) => {
            if (current?.key !== key) {
                return { key, direction: "asc" };
            }

            return current.direction === "asc" ? { key, direction: "desc" } : null;
        });
    }, []);

    const toggleMineOnly = useCallback(() => {
        setMineOnly((current) => {
            storeMineOnly(!current);
            return !current;
        });
    }, []);
    const [loadingId, setLoadingId] = useState<number | null>(null);
    const loadingRef = useRef<number | null>(null);

    useEffect(() => {
        const timer = window.setInterval(() => void wire.$refresh(), REFRESH_MS);
        return () => window.clearInterval(timer);
    }, [wire]);

    const toggleArea = useCallback((id: number) => {
        setSelectedAreas((current) => {
            const next = current.includes(id) ? current.filter((value) => value !== id) : [...current, id];
            storeAreas(next);
            return next;
        });
    }, []);

    const clearAreas = useCallback(() => {
        storeAreas([]);
        setSelectedAreas([]);
    }, []);

    const assign = useCallback(
        async (orderId: number) => {
            if (loadingRef.current !== null) {
                return;
            }

            loadingRef.current = orderId;
            setLoadingId(orderId);
            try {
                await page.openAssignTransport(orderId);
            } finally {
                loadingRef.current = null;
                setLoadingId(null);
            }
        },
        [page],
    );

    const mineCount = useMemo(
        () => configuration.rows.filter((row) => row.createdById !== null && row.createdById === configuration.currentUserId).length,
        [configuration.rows, configuration.currentUserId],
    );

    const scopedRows = useMemo(
        () => (mineOnly ? configuration.rows.filter((row) => row.createdById !== null && row.createdById === configuration.currentUserId) : configuration.rows),
        [configuration.rows, configuration.currentUserId, mineOnly],
    );

    const plannedCount = useMemo(() => scopedRows.filter(isPlanned).length, [scopedRows]);

    const countsByArea = useMemo(() => {
        const counts = new Map<number, number>();
        scopedRows.forEach((row) => {
            if (row.areaId !== null) {
                counts.set(row.areaId, (counts.get(row.areaId) ?? 0) + 1);
            }
        });
        return counts;
    }, [scopedRows]);

    const visibleRows = useMemo(() => {
        const keyword = normalize(search.trim());

        return scopedRows.filter((row) => {
            if (plannedOnly && !isPlanned(row)) {
                return false;
            }

            if (selectedAreas.length > 0 && (row.areaId === null || !selectedAreas.includes(row.areaId))) {
                return false;
            }

            if (keyword === "") {
                return true;
            }

            const haystack = normalize(
                [row.orderCode, row.customerCode, row.customerName, row.cargoName, row.pickup, ...row.destinations, row.notes]
                    .filter(Boolean)
                    .join(" "),
            );

            return haystack.includes(keyword);
        });
    }, [scopedRows, selectedAreas, search, plannedOnly]);

    const sortedRows = useMemo(
        () => (sort === null ? visibleRows : [...visibleRows].sort((a, b) => compareRows(a, b, sort.key, sort.direction))),
        [visibleRows, sort],
    );

    const overdueCount = useMemo(() => visibleRows.filter(isOverdue).length, [visibleRows]);
    const soonCount = useMemo(
        () => visibleRows.filter((row) => row.minutesLeft !== null && row.minutesLeft > 0 && row.minutesLeft <= configuration.soonMinutes).length,
        [visibleRows, configuration.soonMinutes],
    );

    return (
        <div className="w-full space-y-3">
            <div className="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <SearchInput value={search} onChange={setSearch} placeholder="Tìm mã đơn, KH, điểm..." className="w-72 max-w-xs" />

                <FilterChip active={mineOnly} onClick={toggleMineOnly} title="Chỉ hiện đơn do bạn tạo">
                    <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" className="h-3.5 w-3.5">
                        <path d="M10 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM3.465 14.493a1.23 1.23 0 0 0 .41 1.412A9.957 9.957 0 0 0 10 18c2.31 0 4.438-.784 6.131-2.1.43-.333.604-.903.408-1.41a7.002 7.002 0 0 0-13.074.003Z" />
                    </svg>
                    Đơn của tôi
                    <ChipCount>{configuration.loaded ? mineCount : <SkeletonBar className="w-4" />}</ChipCount>
                </FilterChip>

                <span aria-hidden="true" className="mx-1 h-5 w-px bg-gray-200 dark:bg-white/10" />

                <FilterChip active={selectedAreas.length === 0} onClick={clearAreas}>
                    Tất cả KV
                    <ChipCount>{configuration.loaded ? scopedRows.length : <SkeletonBar className="w-4" />}</ChipCount>
                </FilterChip>
                <FilterChip
                    active={plannedOnly}
                    onClick={() => setPlannedOnly((current) => !current)}
                    title="Chỉ hiện đơn có giờ đóng hàng dự kiến sau thời điểm hiện tại"
                    activeClassName="bg-sky-100 ring-sky-500 text-sky-900 dark:bg-sky-950 dark:text-sky-200"
                >
                    Kế hoạch
                    <ChipCount>{configuration.loaded ? plannedCount : <SkeletonBar className="w-4" />}</ChipCount>
                </FilterChip>
                {configuration.areas.map((area) => (
                    <FilterChip key={area.id} active={selectedAreas.includes(area.id)} title={area.name} onClick={() => toggleArea(area.id)}>
                        {area.code}
                        <ChipCount>{configuration.loaded ? countsByArea.get(area.id) ?? 0 : <SkeletonBar className="w-4" />}</ChipCount>
                    </FilterChip>
                ))}

                <div className="ms-auto flex items-center gap-1.5">
                    {[
                        { label: "Chờ gán", value: visibleRows.length, tone: "bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200" },
                        { label: "Sắp đóng ≤2g", value: soonCount, tone: "bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300" },
                        { label: "Quá giờ", value: overdueCount, tone: "bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300" },
                    ].map((stat) => (
                        <span key={stat.label} className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-medium ${stat.tone}`}>
                            {stat.label}
                            <b className="tabular-nums">{configuration.loaded ? stat.value : "…"}</b>
                        </span>
                    ))}
                </div>
            </div>

            <div className="overflow-auto rounded-xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 max-h-[calc(100vh-9rem)]">
                <table className="w-full min-w-[960px] border-collapse text-xs">
                    <thead className="sticky top-0 z-20 shadow-sm">
                        <tr className="dark:bg-slate-950" style={{ backgroundColor: HEADER_BG }}>
                            <th
                                colSpan={COLUMN_COUNT}
                                className={`px-4 py-2.5 text-center text-sm font-bold uppercase tracking-wider text-white sm:text-base dark:bg-slate-950 ${HEADER_LINE}`}
                                style={{ backgroundColor: HEADER_BG }}
                            >
                                Kế hoạch đơn nháp chờ gán lái, xe
                            </th>
                        </tr>
                        <tr className="text-[11px] font-bold uppercase tracking-wide text-white dark:bg-slate-950" style={{ backgroundColor: HEADER_BG }}>
                            {COLUMNS.map(({ label, sortKey }) =>
                                sortKey === undefined ? (
                                    <th key={label} scope="col" className={HEADER_CELL}>
                                        {label}
                                    </th>
                                ) : (
                                    <th
                                        key={label}
                                        scope="col"
                                        aria-sort={sort?.key === sortKey ? (sort.direction === "asc" ? "ascending" : "descending") : "none"}
                                        className={`${HEADER_CELL} cursor-pointer select-none transition hover:bg-white/10`}
                                        title={`Bấm để sắp xếp theo ${label} (tăng → giảm → mặc định)`}
                                        onClick={() => toggleSort(sortKey)}
                                    >
                                        <span className="inline-flex items-center justify-center gap-1">
                                            {label}
                                            <span className={`text-[10px] ${sort?.key === sortKey ? "text-white" : "text-white/40"}`}>
                                                {sort?.key === sortKey ? (sort.direction === "asc" ? "▲" : "▼") : "⇅"}
                                            </span>
                                        </span>
                                    </th>
                                ),
                            )}
                        </tr>
                    </thead>

                    {!configuration.loaded && (
                        <tbody>
                            <SkeletonRows columns={COLUMN_COUNT} rows={6} cellClassName="border-r border-gray-100 px-2 py-2.5 align-middle dark:border-white/5" />
                        </tbody>
                    )}

                    {configuration.loaded && sortedRows.length === 0 && (
                        <tbody>
                            <tr>
                                <td colSpan={COLUMN_COUNT} className="px-5 py-12 text-center">
                                    <p className="text-sm font-semibold text-gray-950 dark:text-white">
                                        {configuration.rows.length === 0 ? "Không còn đơn nháp nào chờ gán" : "Không có đơn khớp bộ lọc"}
                                    </p>
                                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {configuration.rows.length === 0
                                            ? "Đơn nháp có giờ đóng hàng dự kiến sẽ hiện ở đây."
                                            : "Thử bỏ lọc Đơn của tôi, Kế hoạch, bớt khu vực hoặc đổi từ khoá tìm kiếm."}
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    )}

                    {sortedRows.length > 0 && (
                        <tbody className="divide-y divide-gray-100 dark:divide-white/5">
                            {sortedRows.map((row) => (
                                <PlanRowView
                                    key={row.id}
                                    row={row}
                                    soonMinutes={configuration.soonMinutes}
                                    isLoading={loadingId === row.id}
                                    isBusy={loadingId !== null}
                                    onAssign={assign}
                                />
                            ))}
                        </tbody>
                    )}
                </table>
            </div>
        </div>
    );
}

const mountDispatchPlanTable: JsWidgetRenderer<Configuration, WidgetMethods> = ({ host, props: initialProps, utilities }) => {
    const root = createRoot(host);
    const dataRequest = { requested: false };

    const update = ({ configuration }: JsWidgetProps<Configuration>) => {
        if (!configuration.loaded) {
            requestDataOnce(utilities.$wire, dataRequest);
        }

        root.render(<DispatchPlan configuration={configuration} wire={utilities.$wire} />);
    };

    update(initialProps);

    return { update, destroy: () => root.unmount() };
};

export default mountDispatchPlanTable;
