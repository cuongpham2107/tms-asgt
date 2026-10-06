import * as Location from "expo-location";

/**
 * Chế độ GPS cho ứng dụng.
 * Trên production: FAKE_LOCATION_ENABLED luôn là false và luôn sử dụng GPS thực tế của thiết bị.
 */
export const FAKE_LOCATION_ENABLED = false;

export type FakePoint = { label: string; lat: number; lng: number };
export type FakeMode = "real";

export function setFakeMode(_next: any) {}
export function useFakeMode(): FakeMode {
    return "real";
}
export function toFakePoint(_label: string, _loc: any): FakePoint | null {
    return null;
}

/**
 * Lấy toạ độ GPS thực tế từ thiết bị khi gửi checkpoint.
 */
export async function getCheckpointGps(
    _fallbackPoint: FakePoint | null = null,
): Promise<{ gps_lat: number; gps_lng: number } | null> {
    try {
        const { status } = await Location.requestForegroundPermissionsAsync();
        if (status !== "granted") return null;
        const pos = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
        return { gps_lat: pos.coords.latitude, gps_lng: pos.coords.longitude };
    } catch {
        return null;
    }
}
