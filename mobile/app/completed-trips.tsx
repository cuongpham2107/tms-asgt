import { useState, useCallback } from "react";
import { View, Text, StyleSheet, FlatList, RefreshControl } from "react-native";
import { useFocusEffect } from "expo-router";
import { api } from "../src/lib/api";
import { useAuth } from "../src/lib/auth";
import { Ionicons } from "@expo/vector-icons";

const fmt = (v: any) => v != null ? parseInt(v).toLocaleString("vi-VN") : "-";

export default function CompletedTripsScreen() {
  const { token } = useAuth();
  const [trips, setTrips] = useState<any[]>([]); const [loading, setLoading] = useState(true); const [refreshing, setRefreshing] = useState(false);
  const load = async () => {
    if (!token) return;
    try {
      const r = await api.trips.history({ per_page: 50, status: "completed" }, token);
      setTrips(r.data || []);
    } finally { setLoading(false); }
  };
  useFocusEffect(useCallback(() => { load(); }, [token]));
  const onRefresh = async () => { setRefreshing(true); await load(); setRefreshing(false); };

  return (
    <FlatList data={trips} keyExtractor={(t) => String(t.id)} style={s.container}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor="#4F46E5" />}
      contentContainerStyle={{ padding: 16, paddingTop: 8 }}
      ListEmptyComponent={<View style={s.empty}><Ionicons name="checkmark-circle-outline" size={48} color="#E5E7EB" /><Text style={s.emptyText}>{loading ? "Đang tải..." : "Chưa có chuyến hoàn thành"}</Text></View>}
      renderItem={({ item }) => (
        <View style={s.card}>
          <View style={s.topRow}>
            <View style={s.iconBox}><Ionicons name="checkmark-circle" size={22} color="#059669" /></View>
            <View style={{ flex: 1 }}><Text style={s.code}>{item.vehicle?.plate_number || "Chưa gán xe"}</Text><Text style={s.plate}>{item.trip_code}</Text></View>
            <View style={s.badge}><Text style={s.badgeText}>Hoàn thành</Text></View>
          </View>
          <View style={s.kmRow}>
            <View style={s.kmItem}><Text style={s.kmVal}>{fmt(item.total_km)}</Text><Text style={s.kmLbl}>tổng km</Text></View>
            <View style={s.kmSep} />
            <View style={s.kmItem}><Text style={s.kmVal}>{fmt(item.total_km_loaded)}</Text><Text style={s.kmLbl}>có hàng</Text></View>
            <View style={s.kmSep} />
            <View style={s.kmItem}><Text style={s.kmVal}>{fmt(item.total_km_empty)}</Text><Text style={s.kmLbl}>rỗng</Text></View>
          </View>
          {item.completed_at && (
            <View style={s.timeRow}><Ionicons name="time-outline" size={13} color="#9CA3AF" /><Text style={s.timeText}>{new Date(item.completed_at).toLocaleString("vi-VN")}</Text></View>
          )}
        </View>
      )} />
  );
}

const s = StyleSheet.create({
  container: { flex: 1, backgroundColor: "#F9FAFB" },
  card: { backgroundColor: "#fff", padding: 16, borderRadius: 16, borderWidth: 1, borderColor: "#F3F4F6", marginBottom: 10 },
  topRow: { flexDirection: "row", alignItems: "center", gap: 12, marginBottom: 12 },
  iconBox: { width: 42, height: 42, borderRadius: 14, backgroundColor: "#D1FAE5", alignItems: "center", justifyContent: "center" },
  code: { fontSize: 16, fontWeight: "700", color: "#111827" }, plate: { fontSize: 13, color: "#6B7280", marginTop: 2 },
  badge: { paddingHorizontal: 10, paddingVertical: 3, borderRadius: 8, backgroundColor: "#D1FAE5" }, badgeText: { fontSize: 12, fontWeight: "700", color: "#059669" },
  kmRow: { flexDirection: "row", alignItems: "center", backgroundColor: "#F9FAFB", borderRadius: 8, paddingVertical: 6, marginBottom: 8 },
  kmItem: { flex: 1, alignItems: "center" }, kmVal: { fontSize: 13, fontWeight: "700", color: "#111827" }, kmLbl: { fontSize: 10, color: "#9CA3AF", marginTop: 1 },
  kmSep: { width: 1, height: 14, backgroundColor: "#E5E7EB" },
  timeRow: { flexDirection: "row", alignItems: "center", gap: 6 }, timeText: { fontSize: 12, color: "#9CA3AF" },
  empty: { alignItems: "center", paddingVertical: 48 }, emptyText: { fontSize: 15, fontWeight: "600", color: "#9CA3AF", marginTop: 10 },
});
