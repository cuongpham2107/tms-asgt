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
const MAX_CACHED_AGE_MS = 120000;
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
                Linking.openSettings().catch(() => {});
            },
        },
    ]);
}

/**
 * Kiểm tra bắt buộc dịch vụ GPS + quyền vị trí và lấy toạ độ độ chính xác cao:
 * 1. Bắt buộc bật dịch vụ Định vị (Location.hasServicesEnabledAsync) và cấp quyền Foreground.
 *    Nếu tắt hoặc từ chối quyền -> trả về { ok: false, coords: null, reason }.
 * 2. Lấy vị trí hiện tại với Accuracy.High (timeout mặc định 3500ms), bỏ qua toạ độ nhảy trạm phát (> 800m).
 * 3. Nếu timeout (trong kho kín/mái tôn), fallback sang getLastKnownPositionAsync với maxAge 120s & requiredAccuracy 300m.
 * 4. Nếu vẫn không có sóng vệ tinh dù đã bật GPS, trả về { ok: true, coords: null } để không làm kẹt thao tác tài xế
 *    (Server sẽ đối chiếu qua thiết bị định vị EUP của xe).
 */
export async function getRequiredGpsCoordinates(
    timeoutMs: number = 3500
): Promise<GpsLookupResult> {
    try {
        const servicesEnabled = await Location.hasServicesEnabledAsync().catch(
            () => true
        );
        if (!servicesEnabled) {
            return { ok: false, coords: null, reason: "services_disabled" };
        }

        const { status } = await Location.requestForegroundPermissionsAsync();
        if (status !== "granted") {
            return { ok: false, coords: null, reason: "permission_denied" };
        }

        const getCurrentPromise = (async (): Promise<GpsCoordinates | null> => {
            try {
                const pos = await Location.getCurrentPositionAsync({
                    accuracy: Location.Accuracy.High,
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

        try {
            const last = await Location.getLastKnownPositionAsync({
                maxAge: MAX_CACHED_AGE_MS,
                requiredAccuracy: MAX_CACHED_ACCURACY_M,
            });
            if (last) {
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
        } catch {}

        return { ok: true, coords: null };
    } catch {
        return { ok: true, coords: null };
    }
}

/**
 * Hàm tương thích ngược cho các luồng không chặn thao tác (như vào ca).
 */
export async function getFastGpsCoordinates(
    timeoutMs: number = 3500
): Promise<GpsCoordinates | null> {
    const res = await getRequiredGpsCoordinates(timeoutMs);
    return res.coords;
}
