import { Platform } from "react-native";
import * as Location from "expo-location";
import AsyncStorage from "@react-native-async-storage/async-storage";
import { flush } from "./uploader";
import { pendingCount } from "./queue";

export const LOCATION_TASK = "tms-gps-tracking";
export const LAST_POINT_KEY = "@tms_gps_last_point_at";
const CONTEXT_KEY = "@tms_gps_context";
const STALE_AFTER_MS = 60_000;
const isWeb = Platform.OS === "web";

export interface TrackingContext {
    shiftId: number;
    vehicleId: number | null;
}

let context: TrackingContext | null = null;

/** Ca/xe đang ghi; task nền đọc lại từ AsyncStorage nếu app bị khởi động lại. */
export async function getTrackingContext(): Promise<TrackingContext | null> {
    if (context) return context;
    const raw = await AsyncStorage.getItem(CONTEXT_KEY);
    context = raw ? JSON.parse(raw) : null;
    return context;
}

/** Đã có quyền vị trí "Luôn luôn" chưa. Web không ghi nền nên coi như có. */
export async function hasAlwaysPermission(): Promise<boolean> {
    if (isWeb) return true;
    const fg = await Location.getForegroundPermissionsAsync();
    if (!fg.granted) return false;
    const bg = await Location.getBackgroundPermissionsAsync();
    return bg.granted;
}

export async function isTracking(): Promise<boolean> {
    if (isWeb) return false;
    return Location.hasStartedLocationUpdatesAsync(LOCATION_TASK).catch(() => false);
}

/** Bắt đầu (hoặc cập nhật ca/xe cho) việc ghi GPS nền. Idempotent. Trả false nếu thiếu quyền. */
export async function startTracking(next: TrackingContext): Promise<boolean> {
    if (isWeb) return false;
    context = next;
    await AsyncStorage.setItem(CONTEXT_KEY, JSON.stringify(next));
    if (!(await hasAlwaysPermission())) return false;
    if (await isTracking()) return true;
    await Location.startLocationUpdatesAsync(LOCATION_TASK, {
        accuracy: Location.Accuracy.High,
        timeInterval: 5000,
        distanceInterval: 25,
        foregroundService: {
            notificationTitle: "Đang ghi hành trình",
            notificationBody: "ASGT Vận Tải",
        },
        activityType: Location.ActivityType.AutomotiveNavigation,
        pausesUpdatesAutomatically: false,
        showsBackgroundLocationIndicator: true,
    });
    return true;
}

export async function stopTracking(): Promise<void> {
    if (isWeb) return;
    if (await isTracking()) {
        await Location.stopLocationUpdatesAsync(LOCATION_TASK);
    }
    context = null;
    await AsyncStorage.removeItem(CONTEXT_KEY);
}

/** Kết ca / đăng xuất: gửi hết điểm đang chờ rồi dừng ghi. Lỗi mạng thì điểm vẫn nằm trong hàng đợi để gửi sau. */
export async function flushAndStop(): Promise<void> {
    await flush(true).catch(() => {});
    await stopTracking().catch(() => {});
}

export type GpsLevel = "ok" | "stale" | "off";

export interface GpsStatus {
    level: GpsLevel;
    pending: number;
}

/** 🟢 ok / 🟡 không có điểm mới trong 60s / 🔴 thiếu quyền hoặc không ghi. Web trả null. */
export async function getGpsStatus(): Promise<GpsStatus | null> {
    if (isWeb) return null;
    const [permitted, running, lastRaw, pending] = await Promise.all([
        hasAlwaysPermission(),
        isTracking(),
        AsyncStorage.getItem(LAST_POINT_KEY),
        pendingCount().catch(() => 0),
    ]);
    if (!permitted || !running) return { level: "off", pending };
    const lastAt = lastRaw ? Number(lastRaw) : 0;
    return { level: Date.now() - lastAt > STALE_AFTER_MS ? "stale" : "ok", pending };
}
