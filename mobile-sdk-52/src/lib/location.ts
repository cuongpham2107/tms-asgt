import * as Location from "expo-location";

export interface GpsCoordinates {
    gps_lat: number;
    gps_lng: number;
}

/**
 * Lấy toạ độ GPS nhanh và an toàn, giải quyết triệt để vấn đề bị đơ/chậm trên iOS:
 * 1. Kiểm tra quyền vị trí (Foreground permission).
 * 2. Lấy vị trí với độ chính xác Balanced (trên iOS trả về trong 200-800ms thay vì chờ GPS vệ tinh 10-15s như High).
 * 3. Đặt giới hạn timeout nghiêm ngặt (mặc định 2500ms).
 * 4. Nếu timeout, tự động fallback lấy vị trí gần nhất từ cache hệ thống (getLastKnownPositionAsync).
 * 5. Luôn trả về toạ độ hoặc null một cách an toàn mà không làm nghẽn/treo luồng xử lý của tài xế.
 */
export async function getFastGpsCoordinates(
    timeoutMs: number = 2500
): Promise<GpsCoordinates | null> {
    try {
        const { status } = await Location.requestForegroundPermissionsAsync();
        if (status !== "granted") return null;

        // Bắt đầu lấy toạ độ hiện tại với Accuracy.Balanced
        const getCurrentPromise = (async (): Promise<GpsCoordinates | null> => {
            try {
                const pos = await Location.getCurrentPositionAsync({
                    accuracy: Location.Accuracy.Balanced,
                });
                return {
                    gps_lat: pos.coords.latitude,
                    gps_lng: pos.coords.longitude,
                };
            } catch {
                return null;
            }
        })();

        // Promise timeout
        let isTimedOut = false;
        const timeoutPromise = new Promise<null>((resolve) => {
            setTimeout(() => {
                isTimedOut = true;
                resolve(null);
            }, timeoutMs);
        });

        // Chạy đua giữa lấy toạ độ và timeout
        const currentResult = await Promise.race([getCurrentPromise, timeoutPromise]);
        if (currentResult) {
            return currentResult;
        }

        // Nếu bị timeout hoặc getCurrentPositionAsync trả về null, lấy vị trí gần nhất từ bộ nhớ tạm
        try {
            const last = await Location.getLastKnownPositionAsync();
            if (last) {
                return {
                    gps_lat: last.coords.latitude,
                    gps_lng: last.coords.longitude,
                };
            }
        } catch {}

        return null;
    } catch {
        return null;
    }
}
