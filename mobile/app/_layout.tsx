import { Stack, useRouter, useSegments } from "expo-router";
import { StatusBar } from "expo-status-bar";
import { useEffect, useState } from "react";
import { AuthProvider, useAuth } from "../src/lib/auth";
import { LoadingProvider, useLoading } from "../src/lib/loading";
import { usePushNotifications } from "../src/lib/notifications";
import LoadingOverlay from "../src/components/LoadingOverlay";
import VersionUpdateModal from "../src/components/VersionUpdateModal";
import { api } from "../src/lib/api";
import {
  CURRENT_APP_VERSION,
  compareSemver,
  shouldShowSoftUpdate,
  snoozeSoftUpdate,
  type AppVersionInfo,
} from "../src/lib/version";

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

function LoadingLayer() {
  const { visible } = useLoading();
  return <LoadingOverlay visible={visible} />;
}

function VersionGate() {
  const [versionInfo, setVersionInfo] = useState<AppVersionInfo | null>(null);
  const [isForce, setIsForce] = useState(false);
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    let mounted = true;
    api
      .version()
      .then(async (info) => {
        if (!mounted || !info) return;
        setVersionInfo(info);

        // 1. Kiểm tra Force Update: phiên bản hiện tại < min_version
        if (compareSemver(CURRENT_APP_VERSION, info.min_version) < 0) {
          setIsForce(true);
          setVisible(true);
          return;
        }

        // 2. Kiểm tra Soft Update: phiên bản hiện tại < latest_version
        if (compareSemver(CURRENT_APP_VERSION, info.latest_version) < 0) {
          const shouldShow = await shouldShowSoftUpdate(info.latest_version);
          if (shouldShow && mounted) {
            setIsForce(false);
            setVisible(true);
          }
        }
      })
      .catch((err) => {
        // Lỗi kết nối -> không block app để tài xế vẫn thao tác được
        console.warn("[VersionCheck] Failed to check app version:", err);
      });

    return () => {
      mounted = false;
    };
  }, []);

  const handleDismiss = async () => {
    if (versionInfo?.latest_version) {
      await snoozeSoftUpdate(versionInfo.latest_version);
    }
    setVisible(false);
  };

  return (
    <VersionUpdateModal
      visible={visible}
      isForce={isForce}
      versionInfo={versionInfo}
      onDismiss={handleDismiss}
    />
  );
}

export default function RootLayout() {
  return (
    <AuthProvider>
      <LoadingProvider>
        <StatusBar style="light" />
        <AuthGuard />
        <NotificationManager />
        <LoadingLayer />
        <VersionGate />
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
        </Stack>
      </LoadingProvider>
    </AuthProvider>
  );
}
