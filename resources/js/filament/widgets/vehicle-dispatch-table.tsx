import { memo, useCallback, useEffect, useMemo, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import type {
    JsonValue,
    JsWidgetProps,
    JsWidgetRenderer,
    JsWidgetUtilities,
    Snapshot,
} from "@filament/widgets/js-widget";
import { requestDataOnce, SkeletonRows } from "./table-skeleton";
import { SearchInput, SelectInput } from "./filter-controls";

interface TripOrderItem {
    id: number;
    orderCode: string;
    statusLabel: string;
    statusColor: string;
    customerCode: string | null;
    pickup: string;
    destination: string;
}

interface ShiftTrip {
    id: number;
    tripCode: string;
    isEmptyRun: boolean;
    orderType: string | null;
    orderTypeColor: string | null;
    isActive: boolean;
    isPending: boolean;
    isCompleted: boolean;
    statusLabel: string;
    statusColor: string;
    driverName: string;
    startedAt: string;
    startKm: string | null;
    completedAt: string;
    endKm: string | null;
    totalKm: string | null;
    totalKmValue: number;
    pickup: string | null;
    destination: string | null;
    plannedLoadingAt: string;
    ordersCount: number;
    orders: TripOrderItem[];
    timelineUrl: string | null;
}

interface ShiftTrip {
    id: number;
    tripCode: string;
    isEmptyRun: boolean;
    orderType: string | null;
    orderTypeColor: string | null;
    isActive: boolean;
    isPending: boolean;
    isCompleted: boolean;
    statusLabel: string;
    statusColor: string;
    driverName: string;
    startedAt: string;
    startKm: string | null;
    completedAt: string;
    endKm: string | null;
    totalKm: string | null;
    totalKmValue: number;
    pickup: string | null;
    destination: string | null;
    plannedLoadingAt: string;
    ordersCount: number;
    orders: TripOrderItem[];
    timelineUrl: string | null;
}

interface Row {
    id: number;
    bsx: string;
    plateNumber: string;
    loadCapacity?: string | null;
    ownerCategory: string;
    orderType: string | null;
    orderTypeColor: string | null;
    orderTypePriority: number;
    statusLabel: string;
    statusPriority: number;
    statusClass: string;
    statusAction: { name: string; tooltip: string } | null;
    pickup: string;
    pickupTitle: string | null;
    destination: string;
    destinationTitle: string | null;
    plannedLoadingAt: string;
    plannedLoadingAtTitle: string | null;
    plannedLoadingTs: number | null;
    xeCho: string;
    xeChoMinutes: number | null;
    xeChoTone: "danger" | "warning" | "success" | "muted" | "normal";
    xeChoTooltip: string;
    customer: string;
    customerTitle: string | null;
    gps: string;
    speed: string;
    speedValue: number | null;
    speedClass: string;
    movement: string;
    movementClass: string;
    movementTooltip: string | null;
    tripCount: number;
    tripId: number | null;
    waitingOrders: number;
    kmOver: string;
    kmOverValue: number | null;
    kmOverClass: string;
    kmOverTooltip: string;
    kmOverFill: number;
    driverName: string;
    station: string;
    shift: string;
    shiftTrips?: ShiftTrip[];
}

interface Group {
    code: string;
    title: string;
    count: number;
    rows: Row[];
}

interface Configuration {
    /** false ở lần render đầu: chỉ có khung, chưa có xe. */
    loaded: boolean;
    ownerType?: "company" | "rent";
    title: string;
    columns: string[];
    groups: Group[];
    filters: { search: string };
    sort: { by: string; direction: string };
    refreshSeconds: number;
    shiftDate?: string;
    totalVehicles: number;
}

interface PageApi {
    $set(path: string, value: JsonValue, live?: boolean): Promise<unknown>;
    $refresh(): Promise<unknown>;
    openTripModal(vehicleId: number): Promise<unknown>;
    openGpsModal(vehicleId: number): Promise<unknown>;
    openTimelineModal(tripId: number): Promise<unknown>;
    openEditTripModal?(tripId: number): Promise<unknown>;
    openDriverSwapModal(vehicleId: number): Promise<unknown>;
    openStatusAction?(name: string, vehicleId: number): Promise<unknown>;
    mountTableAction(name: string, record: number | string): Promise<unknown>;
    sortTable(column: string): Promise<unknown>;
}

interface WidgetMethods {
    readonly $parent: PageApi;
    $refresh(): Promise<unknown>;
}

type Wire = JsWidgetUtilities<WidgetMethods>["$wire"];

const badgeTones: Record<string, string> = {
    info: "bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300",
    warning:
        "bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300",
    danger: "bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-300",
    success:
        "bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300",
    primary:
        "bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300",
    purple: "bg-purple-100 text-purple-700 dark:bg-purple-500/20 dark:text-purple-300",
    gray: "bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300",
};

const badge = (label: string, tone: string | null | undefined) => (
    <span
        className={`inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-semibold ring-1 ring-inset ring-black/5 dark:ring-white/10 ${badgeTones[tone ?? "gray"] ?? badgeTones.gray}`}
    >
        {label}
    </span>
);

const xeChoTones: Record<string, string> = {
    danger: "font-bold text-rose-600 dark:text-rose-400",
    warning: "font-semibold text-amber-600 dark:text-amber-400",
    success: "font-semibold text-emerald-600 dark:text-emerald-400",
    muted: "text-gray-400",
    normal: "text-gray-600 dark:text-gray-300",
};

const stationTones: Record<string, string> = {
    BN: "warning",
    NB: "success",
    NBA: "success",
    TN: "info",
    Province: "primary",
    OTHER: "gray",
};

const cellClass =
    "border-r border-gray-100 dark:border-white/5 py-1.5 align-middle whitespace-nowrap";

type SortDirection = "asc" | "desc";

interface ClientSort {
    column: string;
    direction: SortDirection;
}

const isBlankCell = (value: string | number | null | undefined): boolean =>
    value === null || value === undefined || value === "" || value === "—";

const compareNumbers = (
    a: number | null | undefined,
    b: number | null | undefined,
    dir: SortDirection,
): number => {
    const aEmpty = a === null || a === undefined || Number.isNaN(a);
    const bEmpty = b === null || b === undefined || Number.isNaN(b);

    if (aEmpty && bEmpty) {
        return 0;
    }
    if (aEmpty) {
        return 1;
    }
    if (bEmpty) {
        return -1;
    }

    return dir === "asc" ? a - b : b - a;
};

const compareStrings = (
    a: string | null | undefined,
    b: string | null | undefined,
    dir: SortDirection,
): number => {
    const aEmpty = isBlankCell(a);
    const bEmpty = isBlankCell(b);

    if (aEmpty && bEmpty) {
        return 0;
    }
    if (aEmpty) {
        return 1;
    }
    if (bEmpty) {
        return -1;
    }

    const cmp = String(a).localeCompare(String(b), "vi", {
        numeric: true,
        sensitivity: "base",
    });

    return dir === "asc" ? cmp : -cmp;
};

const compareRowsByColumn = (
    a: Snapshot<Row>,
    b: Snapshot<Row>,
    column: string,
    direction: SortDirection,
): number => {
    switch (column) {
        case "HT":
            return compareStrings(
                a.tripId ? "Có" : "",
                b.tripId ? "Có" : "",
                direction,
            );
        case "BSX":
            return compareStrings(a.plateNumber, b.plateNumber, direction);
        case "Phân loại":
            return compareStrings(a.ownerCategory, b.ownerCategory, direction);
        case "Loại đơn":
            return compareNumbers(
                a.orderType ? a.orderTypePriority : null,
                b.orderType ? b.orderTypePriority : null,
                direction,
            );
        case "Trạng thái":
            return compareNumbers(
                a.statusPriority,
                b.statusPriority,
                direction,
            );
        case "Điểm đi":
            return compareStrings(a.pickup, b.pickup, direction);
        case "Điểm đến":
            return compareStrings(a.destination, b.destination, direction);
        case "Thời gian dự kiến đóng":
            return compareNumbers(
                a.plannedLoadingTs,
                b.plannedLoadingTs,
                direction,
            );
        case "Xe chờ":
            return compareNumbers(a.xeChoMinutes, b.xeChoMinutes, direction);
        case "Khách hàng":
            return compareStrings(a.customer, b.customer, direction);
        case "Vị trí thực tế trên GPS":
            return compareStrings(a.gps, b.gps, direction);
        case "Tốc độ":
            return compareNumbers(a.speedValue, b.speedValue, direction);
        case "Status":
            return compareStrings(a.movement, b.movement, direction);
        case "Chuyến":
            return compareNumbers(a.tripCount, b.tripCount, direction);
        case "Đơn chờ":
            return compareNumbers(a.waitingOrders, b.waitingOrders, direction);
        case "KM over":
            return compareNumbers(a.kmOverValue, b.kmOverValue, direction);
        case "Tên lái xe":
            return compareStrings(a.driverName, b.driverName, direction);
        case "Trực":
            return compareStrings(a.station, b.station, direction);
        case "Ca":
            return compareStrings(a.shift, b.shift, direction);
        default:
            return 0;
    }
};

const extractDatePart = (plannedLoadingAt: string): string | null => {
    if (!plannedLoadingAt || plannedLoadingAt === "—") {
        return null;
    }
    const parts = plannedLoadingAt.trim().split(/\s+/);

    return parts.length >= 2 ? parts[parts.length - 1] : null;
};

/** Tách nút đếm ngược ra component riêng để tick mỗi giây không render lại toàn bộ 1.350+ ô của bảng. */
function RefreshCountdownButton({
    refreshSeconds,
    onRefresh,
}: {
    refreshSeconds: number;
    onRefresh: () => Promise<unknown>;
}) {
    const [secondsLeft, setSecondsLeft] = useState(refreshSeconds);
    const [refreshing, setRefreshing] = useState(false);
    const refreshingRef = useRef(false);
    const onRefreshRef = useRef(onRefresh);
    onRefreshRef.current = onRefresh;

    const triggerRefresh = useCallback(async () => {
        if (refreshingRef.current) {
            return;
        }

        refreshingRef.current = true;
        setRefreshing(true);
        try {
            await onRefreshRef.current();
        } finally {
            refreshingRef.current = false;
            setRefreshing(false);
            setSecondsLeft(refreshSeconds);
        }
    }, [refreshSeconds]);

    useEffect(() => {
        setSecondsLeft(refreshSeconds);
    }, [refreshSeconds]);

    useEffect(() => {
        const timer = setInterval(() => {
            if (document.hidden || refreshingRef.current) {
                return;
            }

            setSecondsLeft((current) => {
                if (current <= 1) {
                    void triggerRefresh();

                    return refreshSeconds;
                }

                return current - 1;
            });
        }, 1000);

        return () => clearInterval(timer);
    }, [refreshSeconds, triggerRefresh]);

    return (
        <button
            type="button"
            title="Bấm để làm mới ngay"
            disabled={refreshing}
            onClick={() => void triggerRefresh()}
            className="ms-auto inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1.5 text-xs font-medium text-gray-600 ring-1 ring-gray-950/10 transition hover:bg-gray-50 hover:text-primary-600 disabled:cursor-wait dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-white/5"
        >
            <svg
                viewBox="0 0 20 20"
                fill="currentColor"
                className={`h-4 w-4 shrink-0 ${refreshing ? "animate-spin" : ""}`}
                aria-hidden="true"
            >
                <path
                    fillRule="evenodd"
                    d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H3.989a.75.75 0 0 0-.75.75v4.242a.75.75 0 0 0 1.5 0v-2.43l.31.31a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm1.23-3.723a.75.75 0 0 0 .219-.53V2.929a.75.75 0 0 0-1.5 0V5.36l-.31-.31A7 7 0 0 0 3.239 8.188a.75.75 0 1 0 1.448.389A5.5 5.5 0 0 1 13.89 6.11l.311.31h-2.432a.75.75 0 0 0 0 1.5h4.243a.75.75 0 0 0 .53-.219Z"
                    clipRule="evenodd"
                />
            </svg>
            <span>
                {refreshing ? "Đang làm mới…" : `Làm mới sau ${secondsLeft}s`}
            </span>
        </button>
    );
}

interface DispatchRowProps {
    row: Snapshot<Row>;
    isFirstInGroup: boolean;
    isLastInGroup: boolean;
    isEvenRow: boolean;
    isCollapsed: boolean;
    isExpanded: boolean;
    colCount: number;
    shiftDate: string;
    groupCode: string;
    groupTitle: string;
    groupCount: number;
    isRent: boolean;
    isActionLoading: boolean;
    loadingTripAction: { tripId: number; type: "edit" | "timeline" } | null;
    onToggleGroup: (code: string) => void;
    onToggleExpand: (vehicleId: number) => void;
    onOpenTimeline: (tripId: number) => void;
    onEditTrip: (tripId: number) => void;
    onOpenGps: (vehicleId: number) => void;
    onCallStatusAction: (row: Snapshot<Row>) => void;
}

function SpinnerIcon({ className = "h-3.5 w-3.5" }: { className?: string }) {
    return (
        <svg
            className={`animate-spin text-current ${className}`}
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            aria-hidden="true"
        >
            <circle
                className="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                strokeWidth="4"
            />
            <path
                className="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            />
        </svg>
    );
}

const DispatchRow = memo(function DispatchRow({
    row,
    isFirstInGroup,
    isLastInGroup,
    isEvenRow,
    isCollapsed,
    isExpanded,
    colCount,
    shiftDate,
    groupCode,
    groupTitle,
    groupCount,
    isRent,
    isActionLoading,
    loadingTripAction,
    onToggleGroup,
    onToggleExpand,
    onOpenTimeline,
    onEditTrip,
    onOpenGps,
    onCallStatusAction,
}: DispatchRowProps) {
    const shiftTrips = row.shiftTrips ?? [];
    const showGroupEndOnParent = isLastInGroup && !isExpanded;

    return (
        <>
            <tr
                className={`dispatch-row group/row cursor-default ${showGroupEndOnParent ? "dispatch-group-end" : ""} ${isEvenRow ? "bg-white dark:bg-gray-900" : "bg-gray-50/40 dark:bg-white/[0.015]"}`}
            >
            {isFirstInGroup ? (
                <td
                    className={`${cellClass} bg-gray-50/80 dark:bg-white/5 px-2 text-left font-bold text-gray-900 dark:text-gray-100 select-none`}
                >
                    <button
                        type="button"
                        className="inline-flex items-center gap-1.5 font-bold transition hover:opacity-80"
                        title={`Bấm để đóng/mở nhóm ${groupTitle}`}
                        onClick={() => onToggleGroup(groupCode)}
                    >
                        <span
                            className="inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded font-mono text-[10px] font-bold leading-none text-white"
                            style={{ backgroundColor: "rgb(30 58 95)" }}
                        >
                            {isCollapsed ? "+" : "−"}
                        </span>
                        <span className="text-xs">{groupTitle}</span>
                        {isCollapsed && (
                            <span className="text-[10px] font-semibold text-primary-600 dark:text-primary-400">
                                ({groupCount} xe)
                            </span>
                        )}
                    </button>
                </td>
            ) : (
                <td className={`${cellClass} px-2`} />
            )}

            <td className={`${cellClass} px-1 text-center`}>
                {row.tripId ? (
                    <button
                        type="button"
                        disabled={
                            loadingTripAction?.tripId === row.tripId &&
                            loadingTripAction?.type === "timeline"
                        }
                        className="cursor-pointer rounded-md p-1 text-primary-600 transition hover:bg-primary-50 disabled:cursor-wait dark:text-primary-400 dark:hover:bg-primary-500/10"
                        title="Xem hành trình chuyến hiện tại"
                        onClick={() => onOpenTimeline(row.tripId as number)}
                    >
                        {loadingTripAction?.tripId === row.tripId &&
                        loadingTripAction?.type === "timeline" ? (
                            <SpinnerIcon className="mx-auto h-4 w-4" />
                        ) : (
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth={1.6}
                                className="mx-auto h-4 w-4"
                                aria-hidden="true"
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"
                                />
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"
                                />
                            </svg>
                        )}
                    </button>
                ) : (
                    <span className="text-gray-400">—</span>
                )}
            </td>

            <td
                className={`${cellClass} px-2 font-medium text-gray-900 dark:text-gray-100`}
            >
                <button
                    type="button"
                    className="cursor-pointer text-left font-semibold text-primary-600 hover:text-primary-500 hover:underline dark:text-primary-400"
                    title={
                        isExpanded
                            ? "Thu gọn danh sách chuyến trong ca"
                            : "Mở rộng xem danh sách chuyến trong ca"
                    }
                    onClick={() => onToggleExpand(row.id)}
                >
                    <span dangerouslySetInnerHTML={{ __html: row.bsx }} />
                </button>
            </td>

            <td className={`${cellClass} px-2 text-center`}>
                {badge(
                    row.ownerCategory,
                    row.ownerCategory.startsWith("Thuê+") ? "warning" : "info",
                )}
            </td>

            <td className={`${cellClass} px-1 text-center`}>
                {row.orderType ? (
                    badge(row.orderType, row.orderTypeColor)
                ) : (
                    <span className="text-gray-400">—</span>
                )}
            </td>

            <td
                className={`status-cell ${cellClass} px-2 text-center text-[11px] select-none ${row.statusClass} ${row.statusAction ? (isActionLoading ? "cursor-wait opacity-75" : "cursor-pointer hover:opacity-85") : ""}`}
                title={
                    isActionLoading
                        ? "Đang tải thao tác..."
                        : row.statusAction?.tooltip
                }
                onClick={() => onCallStatusAction(row)}
            >
                {isActionLoading ? (
                    <span className="inline-flex items-center justify-center gap-1 font-semibold">
                        <svg
                            className="h-3 w-3 animate-spin text-current"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <circle
                                className="opacity-25"
                                cx="12"
                                cy="12"
                                r="10"
                                stroke="currentColor"
                                strokeWidth="4"
                            />
                            <path
                                className="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                            />
                        </svg>
                        <span>Đang tải...</span>
                    </span>
                ) : (
                    row.statusLabel
                )}
            </td>

            <td
                className={`${cellClass} px-1.5 text-center font-medium text-gray-800 dark:text-gray-200`}
            >
                <span
                    className="font-bold"
                    title={row.pickupTitle ?? undefined}
                >
                    {row.pickup}
                </span>
            </td>
            <td
                className={`${cellClass} px-2 font-medium text-gray-800 dark:text-gray-200`}
                title={row.destinationTitle ?? undefined}
            >
                {row.destination}
            </td>

            {isRent && (
                <td
                    className={`${cellClass} px-2 text-center font-mono text-[11px] font-medium text-gray-800 dark:text-gray-200`}
                    title={row.plannedLoadingAtTitle ?? undefined}
                >
                    {row.plannedLoadingAt === "—" ? (
                        <span className="font-sans text-gray-400">—</span>
                    ) : (
                        row.plannedLoadingAt
                    )}
                </td>
            )}

            <td
                className={`${cellClass} px-1.5 text-center font-medium`}
                title={row.xeChoTooltip}
            >
                <span className={xeChoTones[row.xeChoTone]}>{row.xeCho}</span>
            </td>

            <td
                className={`${cellClass} px-2 text-center font-semibold text-gray-800 dark:text-gray-200`}
                title={row.customerTitle ?? undefined}
            >
                {row.customer === "—" ? (
                    <span className="text-gray-400 font-normal">—</span>
                ) : (
                    row.customer
                )}
            </td>

            {!isRent && (
                <>
                    <td
                        className={`${cellClass} px-2 text-gray-700 dark:text-gray-300`}
                    >
                        <span
                            className="cursor-pointer font-medium text-primary-600 hover:text-primary-500 hover:underline dark:text-primary-400"
                            title="Bấm để xem trên bản đồ"
                            onClick={() => onOpenGps(row.id)}
                        >
                            {row.gps}
                        </span>
                    </td>

                    <td
                        className={`${cellClass} px-1 text-center font-bold text-gray-900 dark:text-white`}
                    >
                        <span
                            className={`inline-block min-w-[46px] rounded px-1 py-0.5 ${row.speedClass}`}
                        >
                            {row.speed}
                        </span>
                    </td>

                    <td
                        className={`status-cell ${cellClass} px-2 text-center text-[11px] select-none ${row.movementClass}`}
                        title={row.movementTooltip ?? undefined}
                    >
                        {row.movement}
                    </td>
                </>
            )}

            <td
                className={`${cellClass} px-1 text-center font-bold text-gray-800 dark:text-gray-200`}
            >
                <span
                    className="cursor-pointer font-bold text-primary-600 hover:text-primary-500 hover:underline dark:text-primary-400"
                    title="Bấm để mở rộng danh sách chuyến trong ca"
                    onClick={() => onToggleExpand(row.id)}
                >
                    {row.tripCount}
                </span>
            </td>

            <td className={`${cellClass} px-1 text-center font-bold`}>
                <span
                    title="Bấm để mở rộng danh sách chuyến và đơn chờ"
                    className={`cursor-pointer hover:underline ${row.waitingOrders > 0 ? "text-primary-600 dark:text-primary-400" : "text-gray-400"}`}
                    onClick={() => onToggleExpand(row.id)}
                >
                    {row.waitingOrders}
                </span>
            </td>

            {!isRent && (
                <td
                    className={`${cellClass} px-1.5 text-center font-mono text-[11px] ${row.kmOverClass}`}
                    title={row.kmOverTooltip}
                    style={
                        row.kmOverClass.includes("km-over-cell")
                            ? undefined
                            : row.kmOverFill > 0
                              ? {
                                    background: `linear-gradient(to right, rgb(59 130 246 / 0.22) ${row.kmOverFill}%, transparent ${row.kmOverFill}%)`,
                                }
                              : undefined
                    }
                >
                    <span>{row.kmOver}</span>
                </td>
            )}

            <td
                className={`${isRent ? "py-1.5 align-middle whitespace-nowrap" : cellClass} px-2 font-medium text-gray-800 dark:text-gray-200`}
            >
                {row.driverName}
            </td>

            {!isRent && (
                <>
                    <td
                        className={`${cellClass} px-1 text-center font-bold text-[11px]`}
                    >
                        {row.station &&
                        row.station !== "—" &&
                        row.station !== "OTHER" ? (
                            badge(
                                row.station,
                                stationTones[row.station] ?? "gray",
                            )
                        ) : (
                            <span className="text-gray-400">—</span>
                        )}
                    </td>

                    <td
                        className={`px-1 py-1.5 text-center font-medium text-[11px] text-gray-700 dark:text-gray-300`}
                    >
                        <span className="font-bold">{row.shift}</span>
                    </td>
                </>
            )}
        </tr>

        {isExpanded &&
            (shiftTrips.length === 0 ? (
                <tr
                    className={`dispatch-row bg-sky-100/60 text-[11px] text-gray-600 italic dark:bg-sky-950/40 dark:text-gray-300 ${isLastInGroup ? "dispatch-group-end" : ""}`}
                >
                    <td className={`${cellClass} px-2 shadow-[inset_3px_0_0_rgb(14,165,233)]`} />
                    <td colSpan={colCount - 1} className="px-3 py-2">
                        <span className="inline-flex items-center gap-1.5 pl-3">
                            <span className="font-mono text-gray-400">└─</span>
                            <span>
                                Xe {row.plateNumber} chưa thực hiện chuyến xe nào vào ngày {shiftDate}.
                            </span>
                        </span>
                    </td>
                </tr>
            ) : (
                shiftTrips.map((trip, tripIdx) => {
                    const isLastChild =
                        isLastInGroup && tripIdx === shiftTrips.length - 1;
                    const orderSummary = trip.orders
                        .map((order) => order.orderCode)
                        .filter(Boolean)
                        .join(", ");
                    const customerSummary = Array.from(
                        new Set(
                            trip.orders
                                .map((order) => order.customerCode)
                                .filter((c): c is string => Boolean(c)),
                        ),
                    ).join(", ");
                    const timeSummary = `${trip.startedAt} → ${trip.completedAt}`;
                    const isEditingTrip =
                        loadingTripAction?.tripId === trip.id &&
                        loadingTripAction?.type === "edit";
                    const isOpeningTimeline =
                        loadingTripAction?.tripId === trip.id &&
                        loadingTripAction?.type === "timeline";
                    const isTripBusy = isEditingTrip || isOpeningTimeline;

                    return (
                        <tr
                            key={`${groupCode}-${row.id}-trip-${trip.id}`}
                            className={`dispatch-row dispatch-child-row ${isTripBusy ? "cursor-wait opacity-75" : "cursor-pointer"} bg-sky-100/60 text-[11px] dark:bg-sky-950/40 ${isLastChild ? "dispatch-group-end" : ""}`}
                            title={
                                isEditingTrip
                                    ? "Đang mở sửa chuyến..."
                                    : `Bấm để sửa chuyến — ${trip.tripCode}`
                            }
                            onClick={() => onEditTrip(trip.id)}
                        >
                            <td className={`${cellClass} px-2 shadow-[inset_3px_0_0_rgb(14,165,233)]`} />

                            <td className={`${cellClass} px-1 text-center`}>
                                <button
                                    type="button"
                                    disabled={isTripBusy}
                                    className="cursor-pointer rounded-md p-1 text-primary-600 transition hover:bg-primary-50 disabled:cursor-wait dark:text-primary-400 dark:hover:bg-primary-500/10"
                                    title={`Xem hành trình ${trip.tripCode}`}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        onOpenTimeline(trip.id);
                                    }}
                                >
                                    {isOpeningTimeline ? (
                                        <SpinnerIcon className="mx-auto h-3.5 w-3.5" />
                                    ) : (
                                        <svg
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth={1.6}
                                            className="mx-auto h-3.5 w-3.5"
                                            aria-hidden="true"
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"
                                            />
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"
                                            />
                                        </svg>
                                    )}
                                </button>
                            </td>

                            <td
                                className={`${cellClass} px-2 text-gray-800 dark:text-gray-200`}
                            >
                                <span className="inline-flex items-center gap-1.5 pl-2">
                                    <span className="font-mono text-gray-400">
                                        └─
                                    </span>
                                    {badge(
                                        `Chuyến ${tripIdx + 1}`,
                                        trip.isActive
                                            ? "warning"
                                            : trip.isPending
                                              ? "info"
                                              : trip.isCompleted
                                                ? "success"
                                                : "gray",
                                    )}
                                    {trip.isEmptyRun &&
                                        badge("Chạy rỗng", "info")}
                                    {isEditingTrip && (
                                        <span className="inline-flex items-center gap-1 text-[10px] font-semibold text-primary-600 dark:text-primary-400">
                                            <SpinnerIcon className="h-3 w-3" />
                                            <span>Đang tải...</span>
                                        </span>
                                    )}
                                </span>
                            </td>

                            <td
                                className={`${cellClass} px-2 text-center font-mono text-[11px] text-gray-600 dark:text-gray-300`}
                            >
                                {timeSummary}
                            </td>

                            <td className={`${cellClass} px-1 text-center`}>
                                {trip.orderType ? (
                                    badge(trip.orderType, trip.orderTypeColor)
                                ) : (
                                    <span className="text-gray-400">—</span>
                                )}
                            </td>

                            <td className={`${cellClass} px-2 text-center`}>
                                <span
                                    className={`inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-semibold ring-1 ring-inset ring-black/5 dark:ring-white/10 ${badgeTones[trip.statusColor] ?? badgeTones.gray}`}
                                >
                                    {trip.isActive && !trip.isCompleted && (
                                        <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-current" />
                                    )}
                                    <span>{trip.statusLabel}</span>
                                </span>
                            </td>

                            <td
                                className={`${cellClass} px-1.5 text-center font-medium text-gray-700 dark:text-gray-300`}
                            >
                                {trip.pickup ?? "—"}
                            </td>

                            <td
                                className={`${cellClass} px-2 font-medium text-gray-700 dark:text-gray-300`}
                            >
                                {trip.destination ?? "—"}
                            </td>

                            {isRent && (
                                <td
                                    className={`${cellClass} px-2 text-center font-mono text-[11px] text-gray-700 dark:text-gray-300`}
                                >
                                    {trip.plannedLoadingAt}
                                </td>
                            )}

                            <td
                                className={`${cellClass} px-1.5 text-center font-mono text-[11px] text-gray-700 dark:text-gray-300`}
                                title={
                                    orderSummary
                                        ? `Đơn hàng: ${orderSummary}`
                                        : undefined
                                }
                            >
                                {orderSummary || (trip.isEmptyRun ? "Chạy rỗng" : "—")}
                            </td>

                            <td
                                className={`${cellClass} px-2 text-center font-medium text-gray-700 dark:text-gray-300`}
                            >
                                {customerSummary || "—"}
                            </td>

                            {!isRent && (
                                <>
                                    <td
                                        className={`${cellClass} px-2 text-left font-mono text-[11px] text-gray-600 dark:text-gray-300`}
                                    >
                                        {trip.startKm ? `KM đầu: ${trip.startKm}` : "—"}
                                    </td>
                                    <td
                                        className={`${cellClass} px-1 text-center font-mono text-[11px] text-gray-600 dark:text-gray-300`}
                                    >
                                        {trip.endKm ? `KM cuối: ${trip.endKm}` : "—"}
                                    </td>
                                    <td
                                        className={`${cellClass} px-2 text-center text-gray-400`}
                                    >
                                        —
                                    </td>
                                </>
                            )}

                            <td
                                className={`${cellClass} px-1 text-center text-gray-700 dark:text-gray-300`}
                            >
                                {trip.ordersCount} đơn
                            </td>

                            <td
                                className={`${cellClass} px-1 text-center font-mono text-[11px] text-gray-600 dark:text-gray-300`}
                            >
                                {isRent ? (trip.totalKm ?? "—") : "—"}
                            </td>

                            {!isRent && (
                                <td
                                    className={`${cellClass} px-1.5 text-center font-mono text-[11px] text-gray-700 dark:text-gray-300`}
                                >
                                    {trip.totalKm ?? "—"}
                                </td>
                            )}

                            <td
                                className={`${isRent ? "py-1.5 align-middle whitespace-nowrap" : cellClass} px-2 text-gray-700 dark:text-gray-300`}
                            >
                                <span className="inline-flex items-center gap-2">
                                    <span>{trip.driverName}</span>
                                    {isRent && (
                                        <button
                                            type="button"
                                            disabled={isTripBusy}
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                onOpenTimeline(trip.id);
                                            }}
                                            className="inline-flex cursor-pointer items-center gap-1 font-medium text-primary-600 hover:underline disabled:cursor-wait dark:text-primary-400"
                                            title={`Xem hành trình ${trip.tripCode}`}
                                        >
                                            {isOpeningTimeline ? (
                                                <>
                                                    <SpinnerIcon className="h-3 w-3" />
                                                    <span>Đang tải...</span>
                                                </>
                                            ) : (
                                                "Hành trình ↗"
                                            )}
                                        </button>
                                    )}
                                </span>
                            </td>

                            {!isRent && (
                                <td
                                    colSpan={2}
                                    className="px-2 py-1.5 text-center align-middle whitespace-nowrap"
                                >
                                    <button
                                        type="button"
                                        disabled={isTripBusy}
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            onOpenTimeline(trip.id);
                                        }}
                                        className="inline-flex cursor-pointer items-center justify-center gap-1 font-medium text-primary-600 hover:underline disabled:cursor-wait dark:text-primary-400"
                                        title={`Xem hành trình ${trip.tripCode}`}
                                    >
                                        {isOpeningTimeline ? (
                                            <>
                                                <SpinnerIcon className="h-3 w-3" />
                                                <span>Đang tải...</span>
                                            </>
                                        ) : (
                                            "Hành trình ↗"
                                        )}
                                    </button>
                                </td>
                            )}
                        </tr>
                    );
                })
            ))}
        </>
    );
});

function DispatchTable({
    configuration,
    wire,
}: {
    configuration: Snapshot<Configuration>;
    wire: Wire;
}) {
    const page = wire.$parent;
    const [search, setSearch] = useState(configuration.filters.search);
    const [loadingActionRowId, setLoadingActionRowId] = useState<number | null>(
        null,
    );
    const loadingActionRowIdRef = useRef<number | null>(null);
    const [loadingTripAction, setLoadingTripAction] = useState<{
        tripId: number;
        type: "edit" | "timeline";
    } | null>(null);
    const loadingTripActionRef = useRef<{
        tripId: number;
        type: "edit" | "timeline";
    } | null>(null);
    const [collapsed, setCollapsed] = useState<Record<string, boolean>>({});
    const [expandedVehicles, setExpandedVehicles] = useState<
        Record<number, boolean>
    >({});
    const [clientSort, setClientSort] = useState<ClientSort | null>(null);
    const [filterGroup, setFilterGroup] = useState<string>("");
    const [filterOrderType, setFilterOrderType] = useState<string>("");
    const [filterStatus, setFilterStatus] = useState<string>("");
    const [filterCustomer, setFilterCustomer] = useState<string>("");
    const [filterPlannedDate, setFilterPlannedDate] = useState<string>("");
    const [filterStation, setFilterStation] = useState<string>("");

    const refresh = useCallback(() => wire.$refresh(), [wire]);

    useEffect(() => {
        setExpandedVehicles({});
        setClientSort(null);
        setFilterGroup("");
        setFilterOrderType("");
        setFilterStatus("");
        setFilterCustomer("");
        setFilterPlannedDate("");
        setFilterStation("");
    }, [configuration.ownerType]);

    // Tìm kiếm lọc trực tiếp toàn bộ dữ liệu trên client để tránh race-condition xoá chữ khi Livewire/Echo làm mới bảng.

    const toggleGroup = useCallback(
        (code: string) =>
            setCollapsed((current) => ({ ...current, [code]: !current[code] })),
        [],
    );

    const toggleVehicleExpand = useCallback(
        (vehicleId: number) =>
            setExpandedVehicles((current) => ({
                ...current,
                [vehicleId]: !current[vehicleId],
            })),
        [],
    );

    const openTimelineModal = useCallback(
        async (tripId: number) => {
            if (loadingTripActionRef.current !== null) {
                return;
            }

            const next = { tripId, type: "timeline" as const };
            loadingTripActionRef.current = next;
            setLoadingTripAction(next);
            try {
                await page.openTimelineModal(tripId);
            } catch (error: any) {
                console.error(
                    "[VehicleDispatchTable] openTimelineModal failed:",
                    error,
                );
                if (error?.status === 419) {
                    window.location.reload();
                }
            } finally {
                loadingTripActionRef.current = null;
                setLoadingTripAction(null);
            }
        },
        [page],
    );

    const openEditTripModal = useCallback(
        async (tripId: number) => {
            if (loadingTripActionRef.current !== null) {
                return;
            }

            const next = { tripId, type: "edit" as const };
            loadingTripActionRef.current = next;
            setLoadingTripAction(next);
            try {
                await page.openEditTripModal?.(tripId);
            } catch (error: any) {
                console.error(
                    "[VehicleDispatchTable] openEditTripModal failed:",
                    error,
                );
                if (error?.status === 419) {
                    window.location.reload();
                }
            } finally {
                loadingTripActionRef.current = null;
                setLoadingTripAction(null);
            }
        },
        [page],
    );

    const openGpsModal = useCallback(
        (vehicleId: number) => {
            void page.openGpsModal(vehicleId);
        },
        [page],
    );

    const callStatusAction = useCallback(
        async (row: Snapshot<Row>) => {
            if (!row.statusAction || loadingActionRowIdRef.current !== null) {
                return;
            }

            loadingActionRowIdRef.current = row.id;
            setLoadingActionRowId(row.id);
            try {
                if (row.statusAction.name === "reassign_driver") {
                    if (typeof page.openDriverSwapModal === "function") {
                        await page.openDriverSwapModal(row.id);
                    } else if (typeof page.openStatusAction === "function") {
                        await page.openStatusAction(
                            row.statusAction.name,
                            row.id,
                        );
                    }
                } else if (typeof page.openStatusAction === "function") {
                    await page.openStatusAction(row.statusAction.name, row.id);
                } else {
                    await page.mountTableAction(
                        row.statusAction.name,
                        String(row.id),
                    );
                }
            } catch (error: any) {
                console.error(
                    "[VehicleDispatchTable] callStatusAction failed:",
                    error,
                );
                if (error?.status === 419) {
                    window.location.reload();
                }
            } finally {
                loadingActionRowIdRef.current = null;
                setLoadingActionRowId(null);
            }
        },
        [page],
    );

    const isRent = configuration.ownerType === "rent";
    const colCount = configuration.columns.length || (isRent ? 14 : 19);

    const filterOptions = useMemo(() => {
        const orderTypes = new Set<string>();
        const statuses = new Set<string>();
        const customers = new Set<string>();
        const plannedDates = new Set<string>();
        const stations = new Set<string>();
        const groups = configuration.groups.map((g) => ({
            code: g.code,
            title: g.title,
        }));

        configuration.groups.forEach((group) => {
            group.rows.forEach((row) => {
                if (row.orderType) {
                    orderTypes.add(row.orderType);
                }
                if (row.statusLabel && row.statusLabel !== "—") {
                    statuses.add(row.statusLabel);
                }
                if (row.customer && row.customer !== "—") {
                    row.customer
                        .split(",")
                        .map((item) => item.trim())
                        .filter(Boolean)
                        .forEach((code) => customers.add(code));
                }
                const datePart = extractDatePart(row.plannedLoadingAt);
                if (datePart) {
                    plannedDates.add(datePart);
                }
                if (
                    row.station &&
                    row.station !== "—" &&
                    row.station !== "OTHER"
                ) {
                    stations.add(row.station);
                }
            });
        });

        return {
            groups,
            orderTypes: Array.from(orderTypes).sort((a, b) =>
                a.localeCompare(b, "vi"),
            ),
            statuses: Array.from(statuses).sort((a, b) =>
                a.localeCompare(b, "vi"),
            ),
            customers: Array.from(customers).sort((a, b) =>
                a.localeCompare(b, "vi"),
            ),
            plannedDates: Array.from(plannedDates).sort((a, b) => {
                const [da, ma, ya] = a.split("/").map(Number);
                const [db, mb, yb] = b.split("/").map(Number);

                return (
                    new Date(ya, ma - 1, da).getTime() -
                    new Date(yb, mb - 1, db).getTime()
                );
            }),
            stations: Array.from(stations).sort((a, b) =>
                a.localeCompare(b, "vi"),
            ),
        };
    }, [configuration.groups]);

    const displayedGroups = useMemo(() => {
        const term = search.trim().toLocaleLowerCase("vi");

        return configuration.groups
            .filter((group) => !filterGroup || group.code === filterGroup)
            .map((group) => {
                const filteredRows = group.rows.filter((row) => {
                    if (term !== "") {
                        const haystack = [
                            row.plateNumber,
                            row.driverName,
                            row.customer,
                            row.customerTitle ?? "",
                            row.pickup,
                            row.destination,
                            row.statusLabel,
                            row.plannedLoadingAt,
                            row.gps,
                            row.station,
                        ]
                            .join(" ")
                            .toLocaleLowerCase("vi");

                        if (!haystack.includes(term)) {
                            return false;
                        }
                    }

                    if (filterOrderType && row.orderType !== filterOrderType) {
                        return false;
                    }

                    if (filterStatus && row.statusLabel !== filterStatus) {
                        return false;
                    }

                    if (filterCustomer) {
                        const rowCustomers = row.customer
                            .split(",")
                            .map((item) => item.trim());
                        if (!rowCustomers.includes(filterCustomer)) {
                            return false;
                        }
                    }

                    if (
                        filterPlannedDate &&
                        extractDatePart(row.plannedLoadingAt) !==
                            filterPlannedDate
                    ) {
                        return false;
                    }

                    if (filterStation && row.station !== filterStation) {
                        return false;
                    }

                    return true;
                });

                const sortedRows = clientSort
                    ? [...filteredRows].sort((a, b) =>
                          compareRowsByColumn(
                              a,
                              b,
                              clientSort.column,
                              clientSort.direction,
                          ),
                      )
                    : filteredRows;

                return {
                    ...group,
                    rows: sortedRows,
                    count: sortedRows.length,
                };
            })
            .filter((group) => group.rows.length > 0);
    }, [
        configuration.groups,
        search,
        filterGroup,
        filterOrderType,
        filterStatus,
        filterCustomer,
        filterPlannedDate,
        filterStation,
        clientSort,
    ]);

    const hasActiveClientControls =
        search !== "" ||
        filterGroup !== "" ||
        filterOrderType !== "" ||
        filterStatus !== "" ||
        filterCustomer !== "" ||
        filterPlannedDate !== "" ||
        filterStation !== "" ||
        clientSort !== null;

    const resetClientControls = () => {
        setSearch("");
        if (configuration.filters.search !== "") {
            void page.$set("searchQuery", null, true);
        }
        setFilterGroup("");
        setFilterOrderType("");
        setFilterStatus("");
        setFilterCustomer("");
        setFilterPlannedDate("");
        setFilterStation("");
        setClientSort(null);
    };

    const toggleColumnSort = (column: string) => {
        setClientSort((current) => {
            if (current?.column !== column) {
                return { column, direction: "asc" };
            }
            if (current.direction === "asc") {
                return { column, direction: "desc" };
            }

            return null;
        });
    };

    const renderSortIndicator = (column: string) => {
        if (clientSort?.column === column) {
            return (
                <span className="text-[10px] font-bold text-amber-300">
                    {clientSort.direction === "asc" ? "▲" : "▼"}
                </span>
            );
        }

        return (
            <span className="text-[10px] text-white/40 group-hover/th:text-white/80">
                ⇅
            </span>
        );
    };

    const headerClass =
        "border-r border-b border-white/10 py-2 px-2 text-center whitespace-nowrap";

    return (
        <div className="w-full space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <SearchInput
                    value={search}
                    onChange={setSearch}
                    placeholder="Tìm BSX, lái xe, KH, điểm..."
                    className="w-72 max-w-xs"
                />

                {filterOptions.groups.length > 1 && (
                    <SelectInput
                        label="Địa điểm"
                        className="w-40"
                        value={filterGroup}
                        onChange={setFilterGroup}
                    >
                        <option value="">Tất cả địa điểm</option>
                        {filterOptions.groups.map((g) => (
                            <option key={g.code} value={g.code}>
                                {g.title}
                            </option>
                        ))}
                    </SelectInput>
                )}

                {filterOptions.orderTypes.length > 0 && (
                    <SelectInput
                        label="Loại đơn"
                        className="w-36"
                        value={filterOrderType}
                        onChange={setFilterOrderType}
                    >
                        <option value="">Tất cả loại đơn</option>
                        {filterOptions.orderTypes.map((type) => (
                            <option key={type} value={type}>
                                {type}
                            </option>
                        ))}
                    </SelectInput>
                )}

                {filterOptions.statuses.length > 0 && (
                    <SelectInput
                        label="Trạng thái"
                        className="w-40"
                        value={filterStatus}
                        onChange={setFilterStatus}
                    >
                        <option value="">Tất cả trạng thái</option>
                        {filterOptions.statuses.map((status) => (
                            <option key={status} value={status}>
                                {status}
                            </option>
                        ))}
                    </SelectInput>
                )}

                {filterOptions.customers.length > 0 && (
                    <SelectInput
                        label="Khách hàng"
                        className="w-40"
                        value={filterCustomer}
                        onChange={setFilterCustomer}
                    >
                        <option value="">Tất cả khách hàng</option>
                        {filterOptions.customers.map((customer) => (
                            <option key={customer} value={customer}>
                                {customer}
                            </option>
                        ))}
                    </SelectInput>
                )}

                {isRent && filterOptions.plannedDates.length > 0 && (
                    <SelectInput
                        label="Ngày dự kiến đóng"
                        className="w-36"
                        value={filterPlannedDate}
                        onChange={setFilterPlannedDate}
                    >
                        <option value="">Tất cả ngày</option>
                        {filterOptions.plannedDates.map((date) => (
                            <option key={date} value={date}>
                                {date}
                            </option>
                        ))}
                    </SelectInput>
                )}

                {!isRent && filterOptions.stations.length > 0 && (
                    <SelectInput
                        label="Trực"
                        className="w-32"
                        value={filterStation}
                        onChange={setFilterStation}
                    >
                        <option value="">Tất cả trực</option>
                        {filterOptions.stations.map((station) => (
                            <option key={station} value={station}>
                                Trực {station}
                            </option>
                        ))}
                    </SelectInput>
                )}

                {hasActiveClientControls && (
                    <button
                        type="button"
                        className="inline-flex h-9 items-center gap-1 rounded-lg border border-gray-300 bg-white px-2.5 text-xs font-medium text-gray-700 shadow-xs transition hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-white/5 cursor-pointer"
                        title="Xoá bộ lọc và sắp xếp về mặc định"
                        onClick={resetClientControls}
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth={1.6}
                            className="h-3.5 w-3.5"
                            aria-hidden="true"
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M6 18 18 6M6 6l12 12"
                            />
                        </svg>
                        <span>Xoá lọc</span>
                    </button>
                )}

                <RefreshCountdownButton
                    refreshSeconds={configuration.refreshSeconds}
                    onRefresh={refresh}
                />
            </div>

            <div className="dispatch-table-container overflow-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 bg-white dark:bg-gray-900 shadow-xs max-h-[calc(100vh-9rem)]">
                <table className="dispatch-table w-full text-xs border-collapse font-sans">
                    <thead className="sticky top-0 z-20 shadow-sm">
                        <tr
                            className="dark:bg-slate-950"
                            style={{ backgroundColor: "rgb(30 58 95)" }}
                        >
                            <th
                                colSpan={colCount}
                                className="text-white py-2.5 px-4 text-center font-bold tracking-wider text-sm sm:text-base border-b border-white/10 uppercase dark:bg-slate-950"
                                style={{ backgroundColor: "rgb(30 58 95)" }}
                            >
                                {configuration.title}
                            </th>
                        </tr>
                        <tr
                            className="text-white text-[11px] font-bold tracking-wide uppercase dark:bg-slate-950"
                            style={{ backgroundColor: "rgb(30 58 95)" }}
                        >
                            {configuration.columns.map((label) => {
                                if (label === "Địa điểm") {
                                    return (
                                        <th key={label} className={headerClass}>
                                            {label}
                                        </th>
                                    );
                                }

                                return (
                                    <th
                                        key={label}
                                        className={`group/th ${headerClass} cursor-pointer hover:bg-white/10 select-none transition`}
                                        title={`Bấm để sắp xếp theo ${label} (tăng → giảm → mặc định)`}
                                        onClick={() => toggleColumnSort(label)}
                                    >
                                        <span className="inline-flex items-center justify-center gap-1">
                                            <span>{label}</span>
                                            {renderSortIndicator(label)}
                                        </span>
                                    </th>
                                );
                            })}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-white/5">
                        {!configuration.loaded && (
                            <SkeletonRows
                                columns={colCount}
                                cellClassName="border-r border-gray-100 px-2 py-2.5 align-middle dark:border-white/5"
                            />
                        )}

                        {configuration.loaded &&
                            displayedGroups.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={colCount}
                                        className="py-8 text-center text-gray-500 italic"
                                    >
                                        {isRent && !hasActiveClientControls
                                            ? "Không có xe thuê ngoài nào đang chờ chạy."
                                            : "Không tìm thấy xe nào phù hợp."}
                                    </td>
                                </tr>
                            )}

                        {displayedGroups.map((group) => {
                            const isCollapsed = collapsed[group.code] === true;

                            return group.rows.map((row, index) => {
                                if (isCollapsed && index > 0) {
                                    return null;
                                }

                                return (
                                    <DispatchRow
                                        key={`${group.code}-${row.id}`}
                                        row={row}
                                        isFirstInGroup={index === 0}
                                        isLastInGroup={
                                            index === group.rows.length - 1
                                        }
                                        isEvenRow={index % 2 === 0}
                                        isCollapsed={isCollapsed}
                                        isExpanded={
                                            expandedVehicles[row.id] === true &&
                                            !isCollapsed
                                        }
                                        colCount={colCount}
                                        shiftDate={configuration.shiftDate ?? ""}
                                        groupCode={group.code}
                                        groupTitle={group.title}
                                        groupCount={group.count}
                                        isRent={isRent}
                                        isActionLoading={
                                            loadingActionRowId === row.id
                                        }
                                        loadingTripAction={
                                            loadingTripAction &&
                                            (row.tripId ===
                                                loadingTripAction.tripId ||
                                                row.shiftTrips?.some(
                                                    (t) =>
                                                        t.id ===
                                                        loadingTripAction.tripId,
                                                ))
                                                ? loadingTripAction
                                                : null
                                        }
                                        onToggleGroup={toggleGroup}
                                        onToggleExpand={toggleVehicleExpand}
                                        onOpenTimeline={openTimelineModal}
                                        onEditTrip={openEditTripModal}
                                        onOpenGps={openGpsModal}
                                        onCallStatusAction={callStatusAction}
                                    />
                                );
                            });
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

const mountVehicleDispatchTable: JsWidgetRenderer<
    Configuration,
    WidgetMethods
> = ({ host, props: initialProps, utilities }) => {
    const root = createRoot(host);
    const dataRequest = { requested: false };

    const update = ({ configuration }: JsWidgetProps<Configuration>) => {
        if (!configuration.loaded) {
            requestDataOnce(utilities.$wire, dataRequest);
        }

        root.render(
            <DispatchTable
                configuration={configuration}
                wire={utilities.$wire}
            />,
        );
    };

    update(initialProps);

    return { update, destroy: () => root.unmount() };
};

export default mountVehicleDispatchTable;
