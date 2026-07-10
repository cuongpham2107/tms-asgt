import { Tabs } from "expo-router";
import { Ionicons } from "@expo/vector-icons";

const icons: Record<string, keyof typeof Ionicons.glyphMap> = {
  index: "grid",
  trips: "car",
  map: "map",
  stats: "bar-chart",
  profile: "person",
};

export default function TabLayout() {
  return (
    <Tabs
      screenOptions={({ route }) => ({
        tabBarIcon: ({ color, size }) => (
          <Ionicons
            name={icons[route.name] ?? "ellipse"}
            size={size}
            color={color}
          />
        ),
        tabBarActiveTintColor: "#4F46E5",
        tabBarInactiveTintColor: "#9CA3AF",
        headerStyle: { backgroundColor: "#4F46E5" },
        headerTintColor: "#fff",
      })}
    >
      <Tabs.Screen name="index" options={{ title: "Tổng quan" }} />
      <Tabs.Screen name="trips" options={{ title: "Chuyến đi" }} />
      <Tabs.Screen name="map" options={{ title: "Bản đồ" }} />
      <Tabs.Screen name="stats" options={{ title: "Thống kê" }} />
      <Tabs.Screen name="profile" options={{ title: "Cá nhân" }} />
    </Tabs>
  );
}
