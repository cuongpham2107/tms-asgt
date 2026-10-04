import "../src/tracking/task"; // TaskManager.defineTask phải chạy ở top-level
import { Stack, useRouter, useSegments } from "expo-router";
import { StatusBar } from "expo-status-bar";
import { useEffect, useState } from "react";
import { AuthProvider, useAuth } from "../src/lib/auth";
import { LoadingProvider, useLoading } from "../src/lib/loading";
import { usePushNotifications } from "../src/lib/notifications";
import LoadingOverlay from "../src/components/LoadingOverlay";
import UpdateRequired, { APP_VERSION, isVersionOlder } from "../src/components/UpdateRequired";
import { api } from "../src/lib/api";
import { startTracking } from "../src/tracking/tracker";
import { setUploadToken, startUploader } from "../src/tracking/uploader";

function AuthGuard() {
  const { token, shiftId } = useAuth();
  const segments = useSegments();
  const router = useRouter();
  const [isReady, setIsReady] = useState(false);

  useEffect(() => {
    setIsReady(true);
  }, []);

  useEffect(() => {
    if (!isReady) return;
    if (!token && segments[0] !== "login") {
      router.replace("/login");
    }
  }, [isReady, token, segments[0]]);

  return null;
}

function NotificationManager() {
  const { token } = useAuth();
  usePushNotifications(token);
  return null;
}

/** Ca đang mở → đảm bảo đang ghi GPS (tự chạy lại khi mở app), và gửi điểm khi đã đăng nhập. */
function TrackingManager() {
  const { token, shift } = useAuth();
  const shiftOpen = !!shift?.id && !shift?.end_time;
  const vehicleId = shift?.vehicle_id ?? shift?.vehicle?.id ?? null;

  useEffect(() => {
    setUploadToken(token);
    if (!token) return;
    return startUploader();
  }, [token]);

  useEffect(() => {
    if (!token || !shiftOpen) return;
    startTracking({ shiftId: Number(shift.id), vehicleId }).catch((e) =>
      console.log("startTracking failed:", e),
    );
  }, [token, shiftOpen, shift?.id, vehicleId]);

  return null;
}

/** Chặn app khi phiên bản thấp hơn min_app_version (từ /login hoặc /shifts/active). */
function VersionGate() {
  const { token, minAppVersion, setMinAppVersion } = useAuth();

  useEffect(() => {
    if (!token) return;
    api.shifts.active(token)
      .then((res) => setMinAppVersion(res?.min_app_version))
      .catch(() => {});
  }, [token]);

  if (!minAppVersion || !isVersionOlder(APP_VERSION, minAppVersion)) return null;
  return <UpdateRequired />;
}

function LoadingLayer() {
  const { visible } = useLoading();
  return <LoadingOverlay visible={visible} />;
}

export default function RootLayout() {
  return (
    <AuthProvider>
      <LoadingProvider>
        <StatusBar style="light" />
        <AuthGuard />
        <NotificationManager />
        <TrackingManager />
        <LoadingLayer />
        <Stack screenOptions={{ headerShown: false }}>
          <Stack.Screen name="login" />
          <Stack.Screen
            name="shift"
            options={{
              headerShown: true,
              title: "Chọn ca",
              headerStyle: { backgroundColor: "#4F46E5" },
              headerTintColor: "#fff",
            }}
          />
          <Stack.Screen name="(tabs)" options={{ title: "Trở lại" }} />
          <Stack.Screen
            name="trip-detail"
            options={{
              headerShown: true,
              title: "Chi tiết chuyến",
              headerStyle: { backgroundColor: "#4F46E5" },
              headerTintColor: "#fff",
            }}
          />
          <Stack.Screen
            name="order-detail"
            options={{
              headerShown: true,
              title: "Chi tiết đơn hàng",
              headerStyle: { backgroundColor: "#4F46E5" },
              headerTintColor: "#fff",
            }}
          />
          <Stack.Screen
            name="completed-trips"
            options={{
              headerShown: true,
              title: "Chuyến đã hoàn thành",
              headerStyle: { backgroundColor: "#4F46E5" },
              headerTintColor: "#fff",
            }}
          />
          <Stack.Screen
            name="overtime-registration"
            options={{
              headerShown: true,
              title: "Đăng ký tăng cường",
              headerStyle: { backgroundColor: "#4F46E5" },
              headerTintColor: "#fff",
            }}
          />
          <Stack.Screen
            name="gps-permission"
            options={{
              headerShown: true,
              title: "Quyền vị trí",
              headerStyle: { backgroundColor: "#4F46E5" },
              headerTintColor: "#fff",
            }}
          />
        </Stack>
        <VersionGate />
      </LoadingProvider>
    </AuthProvider>
  );
}
