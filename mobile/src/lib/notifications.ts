import { Platform } from "react-native";
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
    shouldSetBadge: true,
    shouldShowBanner: true,
    shouldShowList: true,
  }),
});

/**
 * Đăng ký quyền và lấy Push Token của thiết bị (hỗ trợ cả máy thật & Simulator).
 */
export async function registerForPushNotificationsAsync(): Promise<string | null> {
  if (Platform.OS === "web") {
    return null;
  }

  // Cấu hình Android Notification Channel
  if (Platform.OS === "android") {
    await Notifications.setNotificationChannelAsync("orders", {
      name: "Đơn hàng & Chuyến đi",
      importance: Notifications.AndroidImportance.MAX,
      vibrationPattern: [0, 250, 250, 250],
      lightColor: "#4F46E5",
      sound: "default",
    });
  }

  // Kiểm tra quyền nhận thông báo
  const { status: existingStatus } = await Notifications.getPermissionsAsync();
  let finalStatus = existingStatus;

  if (existingStatus !== "granted") {
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
    "ae85c2f6-7bd4-4ab1-ac6a-b9efbccae670";

  // Khi chạy trên Simulator: ưu tiên lấy Expo Push Token để nhận notification qua Expo Server
  if (!Device.isDevice) {
    try {
      const expoTokenData = await Notifications.getExpoPushTokenAsync({
        projectId,
      });
      if (expoTokenData?.data) {
        return expoTokenData.data;
      }
    } catch (error) {
      console.log("Error getting Expo push token on simulator:", error);
    }
  }

  // Khi chạy trên máy thật: thử lấy Native Device Token (FCM/APNs)
  try {
    const deviceTokenData = await Notifications.getDevicePushTokenAsync();
    if (deviceTokenData?.data) {
      return deviceTokenData.data;
    }
  } catch (err) {
    console.log("Failed to get native device push token, falling back to expo push token:", err);
  }

  // Fallback sang Expo Push Token
  try {
    const expoTokenData = await Notifications.getExpoPushTokenAsync({
      projectId,
    });
    return expoTokenData.data;
  } catch (error) {
    console.log("Error getting push token fallback:", error);
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

  useEffect(() => {
    if (!authToken || Platform.OS === "web") return;

    // Đăng ký token với máy chủ Laravel
    registerForPushNotificationsAsync()
      .then((fcmToken) => {
        if (fcmToken) {
          console.log("FCM Token obtained:", fcmToken);
          api.updateFcmToken(fcmToken, authToken)
            .then(() => {
              console.log("Successfully synced FCM token to server!");
            })
            .catch((err) => {
              console.log("Failed to sync FCM token to server:", err);
            });
        }
      })
      .catch((err) => {
        console.log("Error during registerForPushNotificationsAsync:", err);
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

    // Lắng nghe khi người dùng bấm vào thông báo
    responseListener.current = Notifications.addNotificationResponseReceivedListener((response) => {
      console.log("Notification response received (tapped):", response);
      const data = response.notification.request.content.data as Record<string, any> | undefined;

      if (!data) return;

      if (data.order_id) {
        router.push({
          pathname: "/order-detail",
          params: { orderId: String(data.order_id), tripId: String(data.trip_id ?? "") },
        });
      } else if (data.trip_id) {
        router.push({
          pathname: "/trip-detail",
          params: { id: String(data.trip_id) },
        });
      } else {
        router.push("/(tabs)/trips");
      }
    });

    return () => {
      if (notificationListener.current) {
        notificationListener.current.remove();
      }
      if (responseListener.current) {
        responseListener.current.remove();
      }
    };
  }, [authToken]);
}
