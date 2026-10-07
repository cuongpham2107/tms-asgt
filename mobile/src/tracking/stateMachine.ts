import { api } from "../lib/api";
import {
    startTracking,
    flushAndStop,
    isTracking,
    getTrackingContext,
} from "./tracker";

/** Các trạng thái chuyến được coi là xe đang lăn bánh thực tế trên đường */
export const ACTIVE_DRIVING_STATUSES = [
    "started",
    "arrived_pickup",
    "delivering",
    "arrived_delivery",
    "delivered",
] as const;

export type ActiveDrivingStatus = (typeof ACTIVE_DRIVING_STATUSES)[number];

export type TrackingState =
    | "OFF" // Chưa vào ca / đã kết ca / đăng xuất / thiếu quyền
    | "WAITING_TRIP" // Đang trong ca nhưng chưa chạy chuyến (chờ chuyến, pending, đã hoàn thành, hoặc vừa đảo lái)
    | "ACTIVE_DRIVING"; // Đang trực tiếp lái chuyến (đã bắt đầu chuyến, chưa hoàn thành, chưa đảo lái) -> GPS BẬT

let currentState: TrackingState = "OFF";
let currentTripId: number | null = null;
let currentVehicleId: number | null = null;
let listeners: Array<(state: TrackingState, tripId: number | null) => void> = [];

export function getTrackingMachineState(): TrackingState {
    return currentState;
}

export function getActiveTripId(): number | null {
    return currentTripId;
}

export function subscribeTrackingState(
    fn: (state: TrackingState, tripId: number | null) => void,
): () => void {
    listeners.push(fn);
    return () => {
        listeners = listeners.filter((l) => l !== fn);
    };
}

function updateState(
    next: TrackingState,
    tripId: number | null,
    vehicleId: number | null,
) {
    currentState = next;
    currentTripId = tripId;
    currentVehicleId = vehicleId;
    listeners.forEach((fn) => {
        try {
            fn(next, tripId);
        } catch (e) {
            console.error("Tracking listener error:", e);
        }
    });
}

/**
 * Kiểm tra xem một chuyến có đang trong trạng thái chạy VÀ do tài xế này trực tiếp lái hay không.
 */
export function isTripActiveForDriver(
    trip: any,
    userId: number | string | null | undefined,
): boolean {
    if (!trip || !userId) return false;
    const isDriver = Number(trip.driver_id) === Number(userId);
    const isActive = ACTIVE_DRIVING_STATUSES.includes(trip.status);
    return isDriver && isActive;
}

export interface ReconcileContext {
    token: string | null;
    shift: any;
    user: any;
    explicitTrip?: any;
}

let reconciling = false;

/**
 * Đồng bộ trạng thái tracking với backend và context hiện tại.
 * Đảm bảo:
 * - CHỈ BẬT GPS khi đang trong ca VÀ đang trực tiếp lái một chuyến active (started -> delivered).
 * - TỰ ĐỘNG TẮT GPS khi:
 *   + Vừa đảo lái (lái xe yêu cầu hoặc điều hành đổi trên CMS).
 *   + Đã kết thúc chuyến hoặc huỷ chuyến.
 *   + Chưa bắt đầu chuyến (pending).
 *   + Hết ca / Đăng xuất.
 */
export async function reconcileTrackingState(
    ctx: ReconcileContext,
): Promise<TrackingState> {
    const { token, shift, user, explicitTrip } = ctx;
    const shiftOpen = !!shift?.id && !shift?.end_time;
    const userId = user?.id ?? shift?.driver?.id;

    if (!token || !shiftOpen || !userId) {
        if (currentState !== "OFF" || (await isTracking())) {
            await flushAndStop();
            updateState("OFF", null, null);
        }
        return "OFF";
    }

    if (reconciling) return currentState;
    reconciling = true;

    try {
        let targetTrip: any = null;

        if (explicitTrip !== undefined) {
            targetTrip = explicitTrip;
        } else {
            // Gọi endpoint current của driver
            const res = await api.trips.current(token);
            targetTrip = res?.data?.trip ?? null;
        }

        const shouldDrive = isTripActiveForDriver(targetTrip, userId);

        if (shouldDrive) {
            const tripId = Number(targetTrip.id);
            const vehicleId =
                targetTrip.vehicle_id ??
                targetTrip.vehicle?.id ??
                shift?.vehicle_id ??
                shift?.vehicle?.id ??
                null;

            await startTracking({
                shiftId: Number(shift.id),
                vehicleId,
                tripId,
            });
            updateState("ACTIVE_DRIVING", tripId, vehicleId);
            return "ACTIVE_DRIVING";
        } else {
            // Không có chuyến nào đang trực tiếp lái -> TẮT GPS ngay
            const wasRunning = await isTracking();
            if (wasRunning || currentState === "ACTIVE_DRIVING") {
                await flushAndStop();
            }
            updateState("WAITING_TRIP", null, null);
            return "WAITING_TRIP";
        }
    } catch (netErr) {
        // Nếu lỗi mạng (ví dụ mất sóng trên đường cao tốc), giữ nguyên trạng thái đang ghi
        // để không bị gián đoạn GPS cục bộ trong hầm/khu vực sóng yếu.
        console.log("reconcileTrackingState network error (retaining state):", netErr);
        return currentState;
    } finally {
        reconciling = false;
    }
}

/** Gọi khi tài xế bấm "Bắt đầu chuyến" trên mobile */
export async function onTripStarted(
    trip: any,
    shiftId: number,
    vehicleId: number | null,
): Promise<void> {
    const tripId = Number(trip?.id);
    await startTracking({
        shiftId,
        vehicleId,
        tripId,
    });
    updateState("ACTIVE_DRIVING", tripId, vehicleId);
}

/** Gọi khi tài xế bấm "Đảo lái" trên mobile: ngay lập tức flush và dừng GPS */
export async function onTripSwapped(): Promise<void> {
    await flushAndStop();
    updateState("WAITING_TRIP", null, null);
}

/** Gọi khi tài xế bấm "Kết thúc chuyến" trên mobile */
export async function onTripCompleted(): Promise<void> {
    await flushAndStop();
    updateState("WAITING_TRIP", null, null);
}
