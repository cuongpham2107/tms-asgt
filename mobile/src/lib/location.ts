import * as Location from "expo-location";
import { Alert, Linking, Platform } from "react-native";

export interface GpsCoordinates {
    gps_lat: number;
    gps_lng: number;
    gps_accuracy?: number | null;
}

export type GpsFailureReason = "services_disabled" | "permission_denied";

export interface GpsLookupResult {
    ok: boolean;
    coords: GpsCoordinates | null;
    reason?: GpsFailureReason;
}

const MAX_CURRENT_ACCURACY_M = 800;
const MAX_CACHED_AGE_MS = 180000;
const MAX_CACHED_ACCURACY_M = 300;

/**
 * Hiển thị thông báo yêu cầu bật Định vị (GPS) hoặc cấp quyền vị trí kèm nút mở Cài đặt.
 */
export function promptEnableGps(reason?: GpsFailureReason) {
    const title = "Cần bật Định vị (GPS)";
    const message =
        reason === "services_disabled"
            ? "Thiết bị đang tắt dịch vụ Định vị (GPS). Vui lòng bật Định vị trên điện thoại để gửi mốc hành trình."
            : "Ứng dụng chưa được cấp quyền truy cập Vị trí. Vui lòng bật quyền Vị trí trong phần Cài đặt để gửi mốc hành trình.";

    if (Platform.OS === "web") {
        window.alert(`${title}\n\n${message}`);
        return;
    }

    Alert.alert(title, message, [
        { text: "Đóng", style: "cancel" },
        {
            text: "Mở Cài đặt",
            onPress: () => {
                Linking.openSettings().catch(() => { });
            },
        },
    ]);
}

/**
 * Kiểm tra bắt buộc dịch vụ GPS + quyền vị trí và lấy toạ độ nhanh, chính xác:
 * 1. Bắt buộc bật dịch vụ Định vị (Location.hasServicesEnabledAsync) và cấp quyền Foreground.
 *    Nếu tắt hoặc từ chối quyền -> trả về { ok: false, coords: null, reason }.
 * 2. ƯU TIÊN KIỂM TRA CACHE TRƯỚC (Location.getLastKnownPositionAsync, maxAge 120s, requiredAccuracy 300m):
 *    -> Nếu có vị trí mới gần đây, trả về NGAY LẬP TỨC (~10-20ms), không bắt người dùng chờ chip GPS.
 * 3. Nếu chưa có cache, lấy vị trí hiện tại với Accuracy.Balanced (Fused Provider / WiFi / Trạm phát),
 *    timeout tối đa 1500ms thay vì 3500ms để không làm đơ giao diện.
 * 4. Nếu timeout, thử fallback sang vị trí cache gần nhất bất kỳ.
 * 5. Nếu vẫn không có sóng vệ tinh dù đã bật GPS, trả về { ok: true, coords: null } để không làm kẹt thao tác tài xế
 *    (Server sẽ đối chiếu qua thiết bị định vị EUP của xe).
 */
export async function getRequiredGpsCoordinates(
    timeoutMs: number = 1000
): Promise<GpsLookupResult> {
    try {
        const servicesEnabled = await Location.hasServicesEnabledAsync().catch(
            () => true
        );
        if (!servicesEnabled) {
            return { ok: false, coords: null, reason: "services_disabled" };
        }

        let perm = await Location.getForegroundPermissionsAsync().catch(() => null);
        if (!perm || perm.status !== "granted") {
            perm = await Location.requestForegroundPermissionsAsync().catch(() => null);
        }
        if (!perm || perm.status !== "granted") {
            return { ok: false, coords: null, reason: "permission_denied" };
        }

        // 1. Đọc toạ độ cache gần nhất trước (siêu nhanh ~10-20ms)
        try {
            const last = await Location.getLastKnownPositionAsync({
                maxAge: MAX_CACHED_AGE_MS,
                requiredAccuracy: MAX_CACHED_ACCURACY_M,
            });
            if (last?.coords) {
                const acc = last.coords.accuracy;
                const ageMs = Date.now() - (last.timestamp || 0);
                if ((acc == null || acc <= MAX_CACHED_ACCURACY_M) && ageMs <= MAX_CACHED_AGE_MS) {
                    return {
                        ok: true,
                        coords: {
                            gps_lat: last.coords.latitude,
                            gps_lng: last.coords.longitude,
                            gps_accuracy: acc != null ? Math.round(acc) : null,
                        },
                    };
                }
            }
        } catch { }

        // 2. Nếu chưa có cache còn hạn, lấy toạ độ với độ chính xác Balanced (Fused Location)
        const getCurrentPromise = (async (): Promise<GpsCoordinates | null> => {
            try {
                const pos = await Location.getCurrentPositionAsync({
                    accuracy: Location.Accuracy.Balanced,
                });
                const acc = pos.coords.accuracy;
                if (acc != null && acc > MAX_CURRENT_ACCURACY_M) {
                    return null;
                }
                return {
                    gps_lat: pos.coords.latitude,
                    gps_lng: pos.coords.longitude,
                    gps_accuracy: acc != null ? Math.round(acc) : null,
                };
            } catch {
                return null;
            }
        })();

        const timeoutPromise = new Promise<null>((resolve) => {
            setTimeout(() => {
                resolve(null);
            }, timeoutMs);
        });

        const currentResult = await Promise.race([
            getCurrentPromise,
            timeoutPromise,
        ]);
        if (currentResult) {
            return { ok: true, coords: currentResult };
        }

        // 3. Nếu timeout (kho kín/mái tôn), fallback lấy vị trí cache gần nhất khả dĩ
        try {
            const last = await Location.getLastKnownPositionAsync({
                maxAge: MAX_CACHED_AGE_MS * 2,
            });
            if (last?.coords) {
                const acc = last.coords.accuracy;
                return {
                    ok: true,
                    coords: {
                        gps_lat: last.coords.latitude,
                        gps_lng: last.coords.longitude,
                        gps_accuracy: acc != null ? Math.round(acc) : null,
                    },
                };
            }
        } catch { }

        return { ok: true, coords: null };
    } catch {
        return { ok: true, coords: null };
    }
}

/**
 * Hàm lấy nhanh toạ độ cho các luồng không bắt buộc chặn thao tác (như vào ca).
 * Ưu tiên đọc cache trong 5 phút gần nhất (~10ms), hoặc lấy nhanh tối đa 800ms.
 */
export async function getFastGpsCoordinates(
    timeoutMs: number = 500
): Promise<GpsCoordinates | null> {
    try {
        // 1. Đọc toạ độ cache gần nhất trước
        const last = await Location.getLastKnownPositionAsync({
            maxAge: 300000,
        }).catch(() => null);

        if (last?.coords) {
            return {
                gps_lat: last.coords.latitude,
                gps_lng: last.coords.longitude,
                gps_accuracy: last.coords.accuracy != null ? Math.round(last.coords.accuracy) : null,
            };
        }

        // 2. Nếu không có cache, thử lấy nhanh trong tối đa timeoutMs (mặc định 800ms)
        const quickPromise = Location.getCurrentPositionAsync({
            accuracy: Location.Accuracy.Balanced,
        })
            .then((pos) => {
                const acc = pos.coords.accuracy;
                return {
                    gps_lat: pos.coords.latitude,
                    gps_lng: pos.coords.longitude,
                    gps_accuracy: acc != null ? Math.round(acc) : null,
                };
            })
            .catch(() => null);

        const timeoutPromise = new Promise<null>((resolve) =>
            setTimeout(() => resolve(null), timeoutMs)
        );

        return await Promise.race([quickPromise, timeoutPromise]);
    } catch {
        return null;
    }
}
