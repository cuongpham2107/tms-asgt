import { useEffect, useState } from "react";
import * as Location from "expo-location";

/**
 * Giả lập vị trí khi bấm checkpoint — CHỈ dùng để test (thiết bị đứng một chỗ).
 * Bật khi chạy dev hoặc build có EXPO_PUBLIC_FAKE_LOCATION=1 (profile "preview" trong eas.json).
 * Bản production không có biến này nên luôn dùng GPS thật.
 */
export const FAKE_LOCATION_ENABLED =
    __DEV__ || process.env.EXPO_PUBLIC_FAKE_LOCATION === "1";

export type FakePoint = { label: string; lat: number; lng: number };

/** "auto": lấy toạ độ địa điểm của bước đang bấm; "real": GPS thật; hoặc một điểm cố định. */
export type FakeMode = "auto" | "real" | FakePoint;

let mode: FakeMode = "auto";
const listeners = new Set<(m: FakeMode) => void>();

export function setFakeMode(next: FakeMode) {
    mode = next;
    listeners.forEach((l) => l(next));
}

export function useFakeMode(): FakeMode {
    const [value, setValue] = useState<FakeMode>(mode);
    useEffect(() => {
        listeners.add(setValue);
        return () => {
            listeners.delete(setValue);
        };
    }, []);
    return value;
}

/** Toạ độ địa điểm từ API (pickup_location / delivery_point.location) → FakePoint. */
export function toFakePoint(label: string, loc: any): FakePoint | null {
    const lat = Number(loc?.lat);
    const lng = Number(loc?.lng);
    if (!loc || !Number.isFinite(lat) || !Number.isFinite(lng) || (lat === 0 && lng === 0)) {
        return null;
    }
    return { label, lat, lng };
}

async function realGps(): Promise<{ gps_lat: number; gps_lng: number } | null> {
    try {
        const { status } = await Location.requestForegroundPermissionsAsync();
        if (status !== "granted") return null;
        const pos = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
        return { gps_lat: pos.coords.latitude, gps_lng: pos.coords.longitude };
    } catch {
        return null;
    }
}

/**
 * Toạ độ gửi kèm checkpoint. autoPoint = địa điểm tương ứng với bước đang bấm (điểm lấy / điểm giao).
 */
export async function getCheckpointGps(
    autoPoint: FakePoint | null = null,
): Promise<{ gps_lat: number; gps_lng: number } | null> {
    if (FAKE_LOCATION_ENABLED && mode !== "real") {
        const point = mode === "auto" ? autoPoint : mode;
        if (point) return { gps_lat: point.lat, gps_lng: point.lng };
    }
    return realGps();
}
