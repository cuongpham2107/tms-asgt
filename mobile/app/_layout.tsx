import { Stack, useRouter, useSegments } from "expo-router";
import { StatusBar } from "expo-status-bar";
import { useEffect, useState } from "react";
import { AuthProvider, useAuth } from "../src/lib/auth";
import { LoadingProvider, useLoading } from "../src/lib/loading";
import { usePushNotifications } from "../src/lib/notifications";
import LoadingOverlay from "../src/components/LoadingOverlay";

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

export default function RootLayout() {
  return (
    <AuthProvider>
      <LoadingProvider>
        <StatusBar style="light" />
        <AuthGuard />
        <NotificationManager />
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
        </Stack>
      </LoadingProvider>
    </AuthProvider>
  );
}
