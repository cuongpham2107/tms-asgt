import { useState, useMemo, useCallback } from "react";
import { View, Text, TextInput, StyleSheet, FlatList, TouchableOpacity, RefreshControl, ScrollView } from "react-native";
import { useRouter, useFocusEffect } from "expo-router";
import { useAuth } from "../../src/lib/auth";
import { api } from "../../src/lib/api";
import { Ionicons } from "@expo/vector-icons";

const statusConfig: Record<string, { icon: string; bg: string; text: string; label: string }> = {
  pending: { icon: "time-outline", bg: "#F3F4F6", text: "#6B7280", label: "Chờ" },
  started: { icon: "play-circle-outline", bg: "#FEF3C7", text: "#D97706", label: "Đang chạy" },
  arrived_pickup: { icon: "cube-outline", bg: "#FEF3C7", text: "#D97706", label: "Đến lấy" },
  delivering: { icon: "car-outline", bg: "#DBEAFE", text: "#2563EB", label: "Đang giao" },
  arrived_delivery: { icon: "location-outline", bg: "#FEF3C7", text: "#D97706", label: "Đến giao" },
  completed: { icon: "checkmark-circle", bg: "#D1FAE5", text: "#059669", label: "Hoàn thành" },
  driver_swap: { icon: "swap-horizontal", bg: "#E0E7FF", text: "#4F46E5", label: "Đảo lái" },
  return_trip: { icon: "arrow-undo", bg: "#FEE2E2", text: "#DC2626", label: "Quay đầu" },
};

const tabs = [
  { key: "all", label: "Tất cả" },
  { key: "started", label: "Đang chạy", color: "#D97706" },
  { key: "pending", label: "Chờ", color: "#6B7280" },
  { key: "completed", label: "Hoàn thành", color: "#059669" },
  { key: "driver_swap", label: "Đảo lái", color: "#4F46E5" },
];

const periods = [
  { key: "all", label: "Tất cả" },
  { key: "today", label: "Hôm nay" },
  { key: "week", label: "Tuần này" },
  { key: "month", label: "Tháng này" },
];

const fmt = (v: any) => v != null ? parseInt(v).toLocaleString("vi-VN") : "-";

export default function TripsScreen() {
  const { token } = useAuth(); const router = useRouter();
  const [trips, setTrips] = useState<any[]>([]); const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false); const [activeTab, setActiveTab] = useState("all");
  const [activePeriod, setActivePeriod] = useState("all");
  const [search, setSearch] = useState("");

  const getPeriodDates = (period: string) => {
    const now = new Date();
    const to = now.toISOString().slice(0, 10);
    if (period === "today") return { from: to, to };
    if (period === "week") {
      const d = new Date(now); d.setDate(d.getDate() - d.getDay() + (d.getDay() === 0 ? -6 : 1));
      return { from: d.toISOString().slice(0, 10), to };
    }
    if (period === "month") {
      const from = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-01`;
      return { from, to };
    }
    return {};
  };

  const load = async () => {
    if (!token) return;
    try {
      const [activeRes, historyRes] = await Promise.all([
        api.trips.active(token).catch(() => ({ data: [] })),
        api.trips.history({ per_page: 50 }, token).catch(() => ({ data: [] })),
      ]);
      // Merge active + history, dedupe by id
      const map = new Map<string, any>();
      (activeRes.data || []).forEach((t: any) => map.set(String(t.id), t));
      (historyRes.data || []).forEach((t: any) => map.set(String(t.id), t));
      setTrips(Array.from(map.values()));
    } finally { setLoading(false); }
  };
  useFocusEffect(useCallback(() => { load(); }, [token]));
  const onRefresh = async () => { setRefreshing(true); await load(); setRefreshing(false); };

  const filtered = useMemo(() => {
    let result = activeTab === "all" ? trips : trips.filter((t) => t.status === activeTab);
    // Period filter
    if (activePeriod !== "all") {
      const { from, to } = getPeriodDates(activePeriod);
      if (from) {
        result = result.filter((t) => {
          const d = t.started_at || t.created_at;
          if (!d) return true;
          const date = new Date(d).toISOString().slice(0, 10);
          return date >= from && date <= to;
        });
      }
    }
    if (search.trim()) {
      const q = search.toLowerCase();
      result = result.filter((t) => (t.trip_code || "").toLowerCase().includes(q) || (t.vehicle?.plate_number || "").toLowerCase().includes(q));
    }
    return result;
  }, [trips, activeTab, activePeriod, search]);

  const counts = useMemo(() => {
    const c: Record<string, number> = { all: trips.length };
    trips.forEach((t) => { const k = t.status || "?"; c[k] = (c[k] || 0) + 1; });
    return c;
  }, [trips]);

  return (
    <View style={s.container}>
      <View style={s.tabsWrap}><ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={s.tabsScroll}>
        {tabs.map((t) => { const active = activeTab === t.key;
          return <TouchableOpacity key={t.key} style={[s.tab, active && { backgroundColor: t.color || "#4F46E5" }]} onPress={() => setActiveTab(t.key)} activeOpacity={0.7}>
            <Text style={[s.tabText, active && { color: "#fff" }]}>{t.label} ({counts[t.key] || 0})</Text></TouchableOpacity>; })}
      </ScrollView></View>
      <View style={s.searchWrap}><Ionicons name="search-outline" size={18} color="#9CA3AF" /><TextInput style={s.searchInput} placeholder="Tìm mã chuyến, biển số..." placeholderTextColor="#9CA3AF" value={search} onChangeText={setSearch} clearButtonMode="while-editing" /></View>
      <FlatList data={filtered} keyExtractor={(t) => String(t.id)}
        ListHeaderComponent={
          <View style={{ paddingBottom: 4 }}>
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 6 }}>
              {periods.map((p) => { const active = activePeriod === p.key;
                return <TouchableOpacity key={p.key} style={[s.periodTab, active && { backgroundColor: "#4F46E5" }]} onPress={() => setActivePeriod(p.key)} activeOpacity={0.7}>
                  <Text style={[s.periodText, active && { color: "#fff" }]}>{p.label}</Text></TouchableOpacity>;
              })}
            </ScrollView>
          </View>
        }
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor="#4F46E5" />}
        contentContainerStyle={{ padding: 16, paddingTop: 8 }}
        ListEmptyComponent={<View style={s.empty}><Ionicons name="car-outline" size={56} color="#E5E7EB" /><Text style={s.emptyText}>{loading ? "Đang tải..." : "Không có chuyến nào"}</Text></View>}
        renderItem={({ item }) => {
          const st = statusConfig[item.status] || statusConfig["pending"];
          return <TouchableOpacity style={s.card} activeOpacity={0.7}
            onPress={() => router.push({ pathname: "/trip-detail", params: { id: item.id, trip: JSON.stringify(item) } })}>
            <View style={s.topRow}>
              <View style={[s.iconBox, { backgroundColor: st.bg }]}><Ionicons name={st.icon as any} size={24} color={st.text} /></View>
              <View style={{ flex: 1 }}><Text style={s.code}>{item.vehicle?.plate_number || "Chưa gán xe"}</Text></View>
              <View style={[s.badge, { backgroundColor: st.bg }]}><Text style={[s.badgeText, { color: st.text }]}>{st.label}</Text></View>
            </View>
            <Text style={s.kmLine}>📏 {fmt(item.total_km)} km · {fmt(item.start_km)} → {fmt(item.end_km)}</Text>
            {item.orders?.length > 0 && (
              <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ marginTop: 8 }} contentContainerStyle={{ gap: 6 }}>
                {item.orders.map((o: any) => {
                  const oColor = o.status === "completed" ? "#059669" : o.status === "in_transit" ? "#D97706" : o.status === "driver_swap" ? "#8B5CF6" : "#6B7280";
                  return (
                    <View key={o.id} style={{ flexDirection: "row", alignItems: "center", backgroundColor: oColor + "10", paddingHorizontal: 8, paddingVertical: 4, borderRadius: 8, gap: 4 }}>
                      <View style={{ width: 5, height: 5, borderRadius: 3, backgroundColor: oColor }} />
                      <Text style={{ fontSize: 11, fontWeight: "600", color: oColor }} numberOfLines={1}>{o.order_code}</Text>
                      {o.loaded_km != null && <Text style={{ fontSize: 10, color: "#9CA3AF" }}>{fmt(o.loaded_km)}km</Text>}
                    </View>
                  );
                })}
              </ScrollView>
            )}
            {item.started_at && <Text style={s.time}>🕐 {new Date(item.started_at).toLocaleString("vi-VN")}</Text>}
          </TouchableOpacity>;
        }} />
    </View>
  );
}

const s = StyleSheet.create({
  container: { flex: 1, backgroundColor: "#F9FAFB" },
  tabsWrap: { paddingVertical: 8, backgroundColor: "#fff", borderBottomWidth: 1, borderBottomColor: "#F3F4F6" },
  tabsScroll: { paddingHorizontal: 12, gap: 6 },
  tab: { paddingHorizontal: 14, paddingVertical: 7, borderRadius: 18, backgroundColor: "#F3F4F6" },
  tabText: { fontSize: 13, fontWeight: "600", color: "#6B7280" },
  card: { backgroundColor: "#fff", padding: 16, borderRadius: 16, borderWidth: 1, borderColor: "#F3F4F6", marginBottom: 10, shadowColor: "#000", shadowOpacity: 0.04, shadowRadius: 6, shadowOffset: { width: 0, height: 1 }, elevation: 1 },
  topRow: { flexDirection: "row", alignItems: "center", gap: 12, marginBottom: 14 },
  iconBox: { width: 44, height: 44, borderRadius: 14, alignItems: "center", justifyContent: "center" },
  code: { fontSize: 16, fontWeight: "700", color: "#111827" }, plate: { fontSize: 13, color: "#6B7280", marginTop: 2 },
  badge: { paddingHorizontal: 10, paddingVertical: 3, borderRadius: 8 }, badgeText: { fontSize: 12, fontWeight: "700" },
  kmLine: { fontSize: 12, color: "#6B7280", marginTop: 8 }, time: { fontSize: 11, color: "#9CA3AF" },
  empty: { alignItems: "center", paddingVertical: 48 }, emptyText: { fontSize: 16, fontWeight: "600", color: "#9CA3AF", marginTop: 12 },
  searchWrap: { flexDirection: "row", alignItems: "center", marginHorizontal: 16, marginTop: 12, backgroundColor: "#fff", borderRadius: 12, paddingHorizontal: 12, borderWidth: 1, borderColor: "#E5E7EB", gap: 8 },
  searchInput: { flex: 1, paddingVertical: 10, fontSize: 15, color: "#111827" },
  periodTab: { paddingHorizontal: 14, paddingVertical: 6, borderRadius: 16, backgroundColor: "#F3F4F6", height: 28, justifyContent: "center" },
  periodText: { fontSize: 12, fontWeight: "600", color: "#6B7280" },
});
