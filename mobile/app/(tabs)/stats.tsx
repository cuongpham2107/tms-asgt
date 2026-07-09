import { useState, useMemo, useCallback } from "react";
import { View, Text, StyleSheet, ScrollView, RefreshControl, TouchableOpacity } from "react-native";
import { useFocusEffect } from "expo-router";
import { useAuth } from "../../src/lib/auth";
import { api } from "../../src/lib/api";
import { Ionicons } from "@expo/vector-icons";

const fmt = (v: any) => v != null ? parseInt(v).toLocaleString("vi-VN") : "-";

const periods = [
  { key: "all", label: "Tất cả" },
  { key: "today", label: "Hôm nay" },
  { key: "week", label: "Tuần này" },
  { key: "month", label: "Tháng này" },
];

export default function StatsScreen() {
  const { token } = useAuth();
  const [data, setData] = useState<any>(null); const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [shiftData, setShiftData] = useState<any>(null);
  const [history, setHistory] = useState<any[]>([]);
  const [activePeriod, setActivePeriod] = useState("all");

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

  const filteredHistory = useMemo(() => {
    if (activePeriod === "all" || !history.length) return history;
    const { from, to } = getPeriodDates(activePeriod);
    return history.filter((t: any) => {
      const d = t.completed_at || t.started_at;
      if (!d) return true;
      const date = new Date(d).toISOString().slice(0, 10);
      return date >= from! && date <= to!;
    });
  }, [history, activePeriod]);

  const load = async () => {
    if (!token) return;
    const [statsRes, shiftRes, histRes] = await Promise.all([
      api.stats(token).catch(() => null),
      api.shifts.current(token).catch(() => null),
      api.trips.history({ per_page: 20, status: "completed" }, token).catch(() => ({ data: [] })),
    ]);
    if (statsRes?.data) setData(statsRes.data);
    if (shiftRes?.shift) setShiftData(shiftRes.shift);
    setHistory(histRes.data || []);
    setLoading(false);
  };
  useFocusEffect(useCallback(() => { load(); }, [token]));
  const onRefresh = async () => { setRefreshing(true); await load(); setRefreshing(false); };

  // Tính tổng km từ shift trips
  const tripsInShift: any[] = shiftData?.trips || [];
  const totalKm = shiftData?.total_km != null ? parseFloat(shiftData.total_km) : tripsInShift.reduce((s: number, t: any) => s + Math.max(0, (parseFloat(t.end_km) || 0) - (parseFloat(t.start_km) || 0)), 0);
  const loadedKm = shiftData?.total_km_loaded != null ? parseFloat(shiftData.total_km_loaded) : tripsInShift.reduce((s: number, t: any) => s + (parseFloat(t.total_km_loaded) || 0), 0);
  const emptyKm = totalKm != null && loadedKm != null ? totalKm - loadedKm : null;

  // Tổng từ lịch sử
  const histTotalKm = filteredHistory.reduce((s: number, t: any) => s + (parseFloat(t.total_km) || 0), 0);

  return (
    <ScrollView style={s.container} refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor="#4F46E5" />}>
      {loading ? <Text style={s.loading}>Đang tải...</Text> : data ? (<>
        {/* Trip counts */}
        <Text style={s.sectionTitle}>📊 Tổng quan chuyến</Text>
        <View style={s.summaryRow}>
          <View style={[s.sumCard, { backgroundColor: "#EEF2FF" }]}><Ionicons name="today-outline" size={22} color="#4F46E5" /><Text style={[s.sumVal, { color: "#4F46E5" }]}>{data.in_progress ?? 0}</Text><Text style={s.sumLbl}>đang chạy</Text></View>
          <View style={[s.sumCard, { backgroundColor: "#ECFDF5" }]}><Ionicons name="checkmark-circle" size={22} color="#059669" /><Text style={[s.sumVal, { color: "#059669" }]}>{data.completed ?? 0}</Text><Text style={s.sumLbl}>hoàn thành</Text></View>
          <View style={[s.sumCard, { backgroundColor: "#FFFBEB" }]}><Ionicons name="trophy-outline" size={22} color="#F59E0B" /><Text style={[s.sumVal, { color: "#F59E0B" }]}>{data.assigned ?? 0}</Text><Text style={s.sumLbl}>đã gán</Text></View>
        </View>

        {/* Shift KM stats */}
        {shiftData && (
          <>
            <Text style={s.sectionTitle}>🚛 Km ca hiện tại</Text>
            <View style={s.kmCard}>
              <View style={{ alignItems: "center", flex: 1 }}>
                <Text style={s.kmVal}>{fmt(totalKm)}</Text>
                <Text style={s.kmLbl}>tổng km</Text>
              </View>
              <View style={s.kmSep} />
              <View style={{ alignItems: "center", flex: 1 }}>
                <Text style={s.kmVal}>{fmt(loadedKm)}</Text>
                <Text style={s.kmLbl}>có hàng</Text>
              </View>
              <View style={s.kmSep} />
              <View style={{ alignItems: "center", flex: 1 }}>
                <Text style={s.kmVal}>{fmt(emptyKm)}</Text>
                <Text style={s.kmLbl}>rỗng</Text>
              </View>
            </View>
          </>
        )}

        {/* Period filter */}
        <View style={{ flexDirection: "row", gap: 6, paddingHorizontal: 16, marginTop: 12 }}>
          {periods.map((p) => { const active = activePeriod === p.key;
            return <TouchableOpacity key={p.key} style={[s.periodTab, active && { backgroundColor: "#4F46E5" }]} onPress={() => setActivePeriod(p.key)} activeOpacity={0.7}>
              <Text style={[s.periodText, active && { color: "#fff" }]}>{p.label}</Text></TouchableOpacity>;
          })}
        </View>

        {/* Completed trips history */}
        <Text style={s.sectionTitle}>📋 Chuyến đã hoàn thành ({filteredHistory.length})</Text>
        {filteredHistory.length === 0 ? (
          <View style={s.empty}><Text style={s.emptyText}>Chưa có chuyến hoàn thành</Text></View>
        ) : filteredHistory.slice(0, 10).map((t: any) => (
          <View key={t.id} style={s.tripCard}>
            <View style={{ flex: 1 }}>
              <Text style={s.tripCode}>{t.trip_code}</Text>
              <Text style={s.tripPlate}>{t.vehicle?.plate_number || "-"}</Text>
            </View>
            <View style={{ alignItems: "flex-end" }}>
              <Text style={s.tripKm}>{fmt(t.total_km)} km</Text>
              <Text style={s.tripDate}>{t.completed_at ? new Date(t.completed_at).toLocaleDateString("vi-VN") : "-"}</Text>
            </View>
          </View>
        ))}

        {histTotalKm > 0 && (
          <View style={s.totalCard}>
            <Text style={s.totalText}>Tổng km đã chạy: {fmt(histTotalKm)} km</Text>
          </View>
        )}
      </>) : null}
      <View style={{ height: 40 }} />
    </ScrollView>
  );
}

const s = StyleSheet.create({
  container: { flex: 1, backgroundColor: "#F9FAFB" }, loading: { textAlign: "center", color: "#9CA3AF", marginTop: 40 },
  sectionTitle: { fontSize: 15, fontWeight: "700", color: "#111827", paddingHorizontal: 16, marginTop: 20, marginBottom: 10 },
  summaryRow: { flexDirection: "row", gap: 8, paddingHorizontal: 12 },
  sumCard: { flex: 1, padding: 16, borderRadius: 14, alignItems: "center", gap: 6 },
  sumVal: { fontSize: 24, fontWeight: "800" }, sumLbl: { fontSize: 11, color: "#6B7280" },
  // Km card
  kmCard: { flexDirection: "row", alignItems: "center", backgroundColor: "#4F46E5", marginHorizontal: 16, padding: 18, borderRadius: 14 },
  kmVal: { fontSize: 20, fontWeight: "800", color: "#fff" }, kmLbl: { fontSize: 11, color: "rgba(255,255,255,0.7)", marginTop: 2 },
  kmSep: { width: 1, height: 28, backgroundColor: "rgba(255,255,255,0.2)" },
  // Trips
  tripCard: { flexDirection: "row", alignItems: "center", backgroundColor: "#fff", marginHorizontal: 16, marginBottom: 6, padding: 14, borderRadius: 12, borderWidth: 1, borderColor: "#F3F4F6" },
  tripCode: { fontSize: 14, fontWeight: "700", color: "#111827" }, tripPlate: { fontSize: 12, color: "#6B7280", marginTop: 2 },
  tripKm: { fontSize: 15, fontWeight: "700", color: "#4F46E5" }, tripDate: { fontSize: 11, color: "#9CA3AF", marginTop: 2 },
  totalCard: { backgroundColor: "#EEF2FF", marginHorizontal: 16, marginTop: 12, padding: 14, borderRadius: 12, alignItems: "center" },
  totalText: { fontSize: 15, fontWeight: "700", color: "#4F46E5" },
  // Period filter
  periodTab: { paddingHorizontal: 14, paddingVertical: 6, borderRadius: 16, backgroundColor: "#F3F4F6" },
  periodText: { fontSize: 12, fontWeight: "600", color: "#6B7280" },
  empty: { alignItems: "center", padding: 20 }, emptyText: { color: "#9CA3AF", fontSize: 13 },
});
