import { AppState, Platform } from "react-native";
import * as Notifications from "expo-notifications";
import * as Device from "expo-device";
import Constants from "expo-constants";
import { useEffect, useRef } from "react";
import { useRouter } from "expo-router";
import { api } from "./api";
import { showAlert } from "./alert";

// Cấu hình cách hiển thị thông báo khi app đang mở (Foreground)
Notifications.setNotificationHandler({
    handleNotification: async () => ({
        shouldShowAlert: true,
        shouldPlaySound: true,
        shouldSetBadge: false, // Khi app đang mở thì không cần giữ badge icon ngoài màn hình
        shouldShowBanner: true,
        shouldShowList: true,
    }),
});

/**
 * Xóa số lượng thông báo (badge) trên biểu tượng ứng dụng ngoài màn hình chính.
 */
export async function clearNotificationBadge(): Promise<void> {
    if (Platform.OS === "web") return;
    try {
        await Notifications.setBadgeCountAsync(0);
    } catch (error) {
        console.log("Failed to clear notification badge:", error);
    }
}

/** Thông báo mà chuyến/đơn không còn thuộc về tài xế: bấm vào thì mở danh sách chuyến, không mở chi tiết (sẽ bị chặn 403). */
const NO_DETAIL_NOTIFICATION_TYPES = new Set(["trip_unassigned", "trip_cancelled", "order_cancelled", "order_recalled"]);

/** Gửi lại token khi app quay về foreground, không dày hơn mức này. */
const TOKEN_RESYNC_INTERVAL_MS = 60_000;

/**
 * Đăng ký quyền và lấy Push Token của thiết bị (hỗ trợ cả máy thật & Simulator).
 * requestPermission = false: chỉ lấy token khi đã được cấp quyền, không hiện hộp xin quyền.
 */
export async function registerForPushNotificationsAsync(
    { requestPermission = true }: { requestPermission?: boolean } = {},
): Promise<string | null> {
    if (Platform.OS === "web") {
        return null;
    }

    // Cấu hình Android Notification Channels (Bắt buộc từ Android 8.0+)
    if (Platform.OS === "android") {
        await Notifications.setNotificationChannelAsync("default", {
            name: "Thông báo chung",
            importance: Notifications.AndroidImportance.MAX,
            vibrationPattern: [0, 250, 250, 250],
            lightColor: "#4F46E5",
            sound: "default",
        });

        await Notifications.setNotificationChannelAsync("orders", {
            name: "Đơn hàng & Chuyến đi",
            importance: Notifications.AndroidImportance.MAX,
            vibrationPattern: [0, 250, 250, 250],
            lightColor: "#4F46E5",
            sound: "default",
        });
    }

    // Kiểm tra và xin quyền nhận thông báo
    const { status: existingStatus } = await Notifications.getPermissionsAsync();
    let finalStatus = existingStatus;

    if (existingStatus !== "granted") {
        if (!requestPermission) {
            return null;
        }
        const { status } = await Notifications.requestPermissionsAsync();
        finalStatus = status;
    }

    if (finalStatus !== "granted") {
        console.log("Push notification permission not granted.");
        return null;
    }

    const projectId =
        Constants?.expoConfig?.extra?.eas?.projectId ??
        Constants?.easConfig?.projectId ??
        "4a73c5d5-0135-4373-a36e-8e0b2009c779";

    // 1. Trên iOS: Dùng Expo Push Token vì iOS device token thuần (APNs 64 ký tự)
    // không phải FCM token và bị Firebase Admin SDK từ chối. Expo Push Server tự bridge APNs cho iOS.
    if (Platform.OS === "ios") {
        try {
            const expoTokenData = await Notifications.getExpoPushTokenAsync({
                projectId,
            });
            if (expoTokenData?.data) {
                console.log("[PushNotifications] iOS Expo Push Token obtained:", expoTokenData.data);
                return expoTokenData.data;
            }
        } catch (error) {
            console.log("[PushNotifications] Error getting Expo push token on iOS:", error);
        }
        return null;
    }

    // 2. Trên Android: Ưu tiên lấy Native Device Push Token (FCM token chuẩn của Firebase).
    // Dự án đã có google-services.json và backend có Firebase Admin SDK gửi qua FCM v1 trực tiếp,
    // hoạt động hoàn toàn độc lập, không phụ thuộc vào hạ tầng Expo Push Server hay EAS credentials.
    if (Platform.OS === "android") {
        try {
            const deviceTokenData = await Notifications.getDevicePushTokenAsync();
            if (deviceTokenData?.data) {
                console.log("[PushNotifications] Android Native FCM Token obtained:", deviceTokenData.data);
                return deviceTokenData.data;
            }
        } catch (err) {
            console.log("[PushNotifications] Failed to get native FCM token, falling back to Expo token:", err);
        }

        // Fallback sang Expo Push Token nếu không lấy được native token (ví dụ chạy trên máy ảo hoặc thiết bị thiếu Google Play Services)
        try {
            const expoTokenData = await Notifications.getExpoPushTokenAsync({
                projectId,
            });
            if (expoTokenData?.data) {
                console.log("[PushNotifications] Android Expo Push Token fallback obtained:", expoTokenData.data);
                return expoTokenData.data;
            }
        } catch (error) {
            console.log("[PushNotifications] Error getting fallback Expo push token on Android:", error);
        }
    }

    return null;
}

/**
 * Lấy push token của thiết bị (chỉ khi đã có quyền) nhưng không chờ quá `timeoutMs`, để gửi kèm lúc đăng nhập.
 * Lỗi hoặc quá thời gian thì trả về null; hook usePushNotifications sẽ gửi lại sau khi đăng nhập.
 */
export async function getPushTokenWithTimeout(timeoutMs = 3000): Promise<string | null> {
    const timeout = new Promise<null>((resolve) => setTimeout(() => resolve(null), timeoutMs));

    try {
        return await Promise.race([registerForPushNotificationsAsync({ requestPermission: false }), timeout]);
    } catch (error) {
        console.log("[PushNotifications] Could not get push token before login:", error);
        return null;
    }
}

/**
 * Hook lắng nghe thông báo và xử lý điều hướng khi bấm vào thông báo.
 */
export function usePushNotifications(authToken: string | null) {
    const router = useRouter();
    const notificationListener = useRef<Notifications.Subscription | null>(null);
    const responseListener = useRef<Notifications.Subscription | null>(null);
    const lastSyncedAtRef = useRef(0);

    // Xóa số thông báo (badge) trên icon khi mở app hoặc khi quay lại từ background
    useEffect(() => {
        if (Platform.OS === "web") return;

        clearNotificationBadge();

        const subscription = AppState.addEventListener("change", (nextAppState) => {
            if (nextAppState === "active") {
                clearNotificationBadge();
            }
        });

        return () => {
            subscription.remove();
        };
    }, []);

    useEffect(() => {
        if (!authToken || Platform.OS === "web") return;

        lastSyncedAtRef.current = 0;

        const syncToken = async (requestPermission: boolean) => {
            try {
                const fcmToken = await registerForPushNotificationsAsync({ requestPermission });
                if (fcmToken) {
                    await api.updateFcmToken(fcmToken, authToken);
                    lastSyncedAtRef.current = Date.now();
                    console.log("[PushNotifications] Successfully synced push token to server!");
                }
            } catch (err) {
                console.log("[PushNotifications] Failed to register/sync push token:", err);
            }
        };

        // Đồng bộ ngay khi có token đăng nhập (xin quyền nếu chưa có)
        syncToken(true);

        // Mỗi lần app quay về foreground gửi lại token (cách nhau tối thiểu 1 phút): lần trước lỗi mạng sẽ được bù,
        // và nếu tài khoản khác từng đăng nhập trên máy này thì token được trả về đúng tài khoản đang dùng.
        const appStateSub = AppState.addEventListener("change", (nextAppState) => {
            if (nextAppState === "active" && Date.now() - lastSyncedAtRef.current >= TOKEN_RESYNC_INTERVAL_MS) {
                syncToken(false);
            }
        });

        // Lắng nghe khi thông báo tới lúc đang mở app (Foreground)
        notificationListener.current = Notifications.addNotificationReceivedListener((notification) => {
            console.log("Notification received in foreground:", notification);
            const title = notification.request.content.title;
            const body = notification.request.content.body;
            if (title) {
                showAlert(title, body ?? "");
            }
        });

        // Lắng nghe khi người dùng bấm vào thông báo -> Xóa badge và mở thẳng vào Chi tiết chuyến đi
        responseListener.current = Notifications.addNotificationResponseReceivedListener((response) => {
            console.log("Notification response received (tapped):", response);
            clearNotificationBadge();
            const data = response.notification.request.content.data as Record<string, any> | undefined;

            if (!data) return;

            const tripId = data.trip_id || data.tripId;
            if (NO_DETAIL_NOTIFICATION_TYPES.has(String(data.type ?? ""))) {
                router.push("/(tabs)/trips");
            } else if (tripId) {
                router.push({
                    pathname: "/trip-detail",
                    params: { id: String(tripId) },
                });
            } else {
                router.push("/(tabs)/trips");
            }
        });

        return () => {
            appStateSub.remove();
            if (notificationListener.current) {
                notificationListener.current.remove();
            }
            if (responseListener.current) {
                responseListener.current.remove();
            }
        };
    }, [authToken]);
}

/**
 * Hook tự động gọi hàm refresh khi:
 * 1. Ứng dụng nhận được thông báo đẩy (Push Notification) lúc đang mở (Foreground).
 * 2. Ứng dụng quay lại trạng thái Active từ Background.
 * 
 * Có cơ chế debounce nhẹ để tránh spam API nếu nhiều thông báo tới liên tiếp.
 */
export function useRefreshOnNotification(onRefresh: () => void | Promise<void>, delay = 300) {
    const refreshRef = useRef(onRefresh);
    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        refreshRef.current = onRefresh;
    }, [onRefresh]);

    useEffect(() => {
        if (Platform.OS === "web") return;

        const triggerRefresh = () => {
            if (timerRef.current) {
                clearTimeout(timerRef.current);
            }
            timerRef.current = setTimeout(() => {
                refreshRef.current();
            }, delay);
        };

        // 1. Nhận thông báo khi app đang mở (Foreground)
        const notifSub = Notifications.addNotificationReceivedListener((notification) => {
            console.log("[useRefreshOnNotification] Push notification received, triggering refresh:", notification.request.content.data);
            triggerRefresh();
        });

        // 2. Khi ứng dụng quay lại trạng thái Active từ Background
        const appStateSub = AppState.addEventListener("change", (nextState) => {
            if (nextState === "active") {
                triggerRefresh();
            }
        });

        return () => {
            if (timerRef.current) {
                clearTimeout(timerRef.current);
                timerRef.current = null;
            }
            notifSub.remove();
            appStateSub.remove();
        };
    }, [delay]);
}

