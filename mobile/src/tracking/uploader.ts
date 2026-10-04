import { AppState } from "react-native";
import { api } from "../lib/api";
import { getDeviceId } from "./deviceId";
import { ackUpTo, peekBatch, pendingCount } from "./queue";

const UPLOAD_INTERVAL_MS = 30_000;
const UPLOAD_TRIGGER_COUNT = 50;
const MAX_BATCH = 500;
const BACKOFF_START_MS = 30_000;
const BACKOFF_MAX_MS = 120_000;

let authToken: string | null = null;
let uploading = false;
let lastAttemptAt = 0;
let backoffMs = 0; // 0 = lần gửi trước thành công

export function setUploadToken(token: string | null): void {
    authToken = token;
}

/** Gửi một lô (các điểm liền nhau cùng ca/xe). Trả true nếu còn điểm cần gửi tiếp. */
async function uploadOnce(token: string): Promise<boolean> {
    const rows = await peekBatch(MAX_BATCH);
    if (rows.length === 0) return false;
    const first = rows[0];
    const sameRun = rows.findIndex(
        (r) => r.shift_id !== first.shift_id || r.vehicle_id !== first.vehicle_id,
    );
    const batch = sameRun === -1 ? rows : rows.slice(0, sameRun);
    const res = await api.gpsPoints(
        {
            device_id: await getDeviceId(),
            shift_id: first.shift_id,
            vehicle_id: first.vehicle_id,
            points: batch.map(({ shift_id, vehicle_id, ...p }) => p),
        },
        token,
    );
    await ackUpTo(res.last_seq);
    return sameRun !== -1 || rows.length === MAX_BATCH;
}

/**
 * Gửi hết hàng đợi. Lỗi thì backoff 30s → 60s → 120s (tối đa); `force` bỏ qua backoff.
 * ponytail: lô bị server từ chối (422) sẽ được thử lại mãi, cần bỏ lô nếu thấy kẹt thật.
 */
export async function flush(force = false): Promise<void> {
    const token = authToken;
    if (!token || uploading) return;
    const now = Date.now();
    if (!force && backoffMs > 0 && now - lastAttemptAt < backoffMs) return;
    uploading = true;
    lastAttemptAt = now;
    try {
        while (await uploadOnce(token)) {
            // tiếp tục tới khi hết
        }
        backoffMs = 0;
    } catch {
        backoffMs = backoffMs > 0 ? Math.min(backoffMs * 2, BACKOFF_MAX_MS) : BACKOFF_START_MS;
    } finally {
        uploading = false;
    }
}

/** Gọi từ task nền sau khi ghi điểm: đủ 50 điểm hoặc đã 30 giây thì gửi. */
export async function onPointsQueued(): Promise<void> {
    if (Date.now() - lastAttemptAt >= UPLOAD_INTERVAL_MS || (await pendingCount()) >= UPLOAD_TRIGGER_COUNT) {
        await flush();
    }
}

/** Hẹn giờ 30 giây + flush khi app vào foreground. Trả hàm huỷ. */
export function startUploader(): () => void {
    const timer = setInterval(() => flush(), UPLOAD_INTERVAL_MS);
    const sub = AppState.addEventListener("change", (state) => {
        if (state === "active") flush(true);
    });
    flush(true);
    return () => {
        clearInterval(timer);
        sub.remove();
    };
}
