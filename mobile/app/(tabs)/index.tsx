import { useState, useCallback } from "react";
import { View, Text, StyleSheet, ScrollView, TouchableOpacity, RefreshControl } from "react-native";
import { useRouter, useFocusEffect } from "expo-router";
import { useAuth } from "../../src/lib/auth";
import { api } from "../../src/lib/api";
import { Ionicons } from "@expo/vector-icons";

const shiftLabels: Record<string, string> = { full: "Cả ca (X)", morning_half: "Nửa ca ngày (X/2)", night_half: "Nửa ca đêm (Y/2)" };

const statusColors: Record<string, { bg: string; text: string; label: string }> = {
  pending: { bg: "#F3F4F6", text: "#6B7280", label: "Chờ" },
  started: { bg: "#FEF3C7", text: "#D97706", label: "Đang chạy" },
  arrived_pickup: { bg: "#FEF3C7", text: "#D97706", label: "Đến lấy hàng" },
  delivering: { bg: "#DBEAFE", text: "#2563EB", label: "Đang giao" },
  arrived_delivery: { bg: "#FEF3C7", text: "#D97706", label: "Đến giao" },
  delivered: { bg: "#D1FAE5", text: "#059669", label: "Đã giao" },
  completed: { bg: "#D1FAE5", text: "#059669", label: "Hoàn thành" },
  driver_swap: { bg: "#E0E7FF", text: "#4F46E5", label: "Đảo lái" },
  return_trip: { bg: "#FEE2E2", text: "#DC2626", label: "Quay đầu" },
  cancelled: { bg: "#FEE2E2", text: "#DC2626", label: "Đã huỷ" },
};

export default function DashboardScreen() {
  const { token, shift: authShift, setShift } = useAuth();
  const router = useRouter();
  const [trips, setTrips] = useState<any[]>([]);
  const [refreshing, setRefreshing] = useState(false);
  const [stats, setStats] = useState<any>(null);
  const [shift, setShiftState] = useState<any>(authShift);
  const userId = shift?.driver?.id;

  const load = async () => {
    if (!token) return;
    const [tRes, sRes, shiftRes] = await Promise.all([
      api.trips.active(token).catch(() => ({ data: [] })),
      api.stats(token).catch(() => null),
      api.shifts.current(token).catch(() => null),
    ]);
    setTrips(tRes.data || []);
    if (sRes?.data) setStats(sRes.data);
    if (shiftRes?.shift) { setShiftState(shiftRes.shift); setShift(shiftRes.shift); }
  };

  useFocusEffect(useCallback(() => { load(); }, [token]));
  const onRefresh = async () => { setRefreshing(true); await load(); setRefreshing(false); };

  const activeTrips = trips.filter((t) => t.status !== "completed" && t.status !== "cancelled" && t.status !== "driver_swap");
  // Sort: current/active trips first, then pending
  const isCurrentTrip = (t: any) => t.status !== "pending";
  const sortedTrips = [...activeTrips].sort((a, b) => (isCurrentTrip(b) ? 1 : 0) - (isCurrentTrip(a) ? 1 : 0));
  const completedCount = stats?.completed ?? 0;

  const shiftDuration = shift?.start_time ? (() => {
    const start = new Date(shift.start_time);
    const end = shift.end_time ? new Date(shift.end_time) : new Date();
    const h = Math.floor((end.getTime() - start.getTime()) / 3600000);
    const m = Math.floor(((end.getTime() - start.getTime()) % 3600000) / 60000);
    return h > 0 ? `${h}h ${m}p` : `${m}p`;
  })() : null;

  // Hiển thị km ca: từ DB nếu đã tính, nếu không tổng hợp từ trips
  const fmt = (v: any) => v != null ? parseInt(v).toLocaleString("vi-VN") : "-";
  const tripsInShift: any[] = shift?.trips || [];
  const calcTotal = tripsInShift.reduce((s: number, t: any) => s + (
    parseFloat(t.total_km) || Math.max(0, (parseFloat(t.end_km) || 0) - (parseFloat(t.start_km) || 0))
  ), 0);
  const calcLoaded = tripsInShift.reduce((s: number, t: any) => s + (parseFloat(t.total_km_loaded) || 0), 0);
  const shiftTotalKm = shift?.total_km != null ? parseFloat(shift.total_km) : (calcTotal > 0 ? calcTotal : null);
  const shiftLoaded = shift?.total_km_loaded != null ? parseFloat(shift.total_km_loaded) : (calcLoaded > 0 ? calcLoaded : null);
  const shiftEmpty = shift?.total_km_empty != null ? parseFloat(shift.total_km_empty) : (shiftTotalKm != null && shiftLoaded != null ? shiftTotalKm - shiftLoaded : null);

  return (
    <ScrollView style={st.container} refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor="#4F46E5" />}>
      <View style={st.header}>
        <View>
          <Text style={st.greeting}>Xin chào, {shift?.driver?.name || "Tài xế"} 👋</Text>
          <Text style={st.subtitle}>{shift ? "Ca đang hoạt động" : "Chưa vào ca"}</Text>
        </View>
      </View>

      {shift && (
        <View style={st.shiftCard}>
          <View style={{ flexDirection: "row", justifyContent: "space-between", alignItems: "center" }}>
            <View style={{ flexDirection: "row", alignItems: "center", gap: 10 }}>
              <View style={st.shiftDot} />
              <View>
                <Text style={st.shiftType}>{shiftLabels[shift.shift_type] || shift.shift_type}</Text>
                <Text style={st.shiftTime}>
                  {shift.start_time ? new Date(shift.start_time).toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" }) : "--:--"}
                  {" → "}{shift.end_time ? new Date(shift.end_time).toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" }) : "đang chạy"}
                </Text>
              </View>
            </View>
            <View style={st.shiftDuration}><Text style={st.shiftDurationText}>{shiftDuration}</Text></View>
          </View>
          <View style={st.shiftKmRow}>
            <View style={{ alignItems: "center", flex: 1 }}><Text style={st.shiftKmVal}>{fmt(shiftTotalKm)}</Text><Text style={st.shiftKmLbl}>tổng km</Text></View>
            <View style={st.shiftKmSep} />
            <View style={{ alignItems: "center", flex: 1 }}><Text style={st.shiftKmVal}>{fmt(shiftLoaded)}</Text><Text style={st.shiftKmLbl}>có hàng</Text></View>
            <View style={st.shiftKmSep} />
            <View style={{ alignItems: "center", flex: 1 }}><Text style={st.shiftKmVal}>{fmt(shiftEmpty)}</Text><Text style={st.shiftKmLbl}>rỗng</Text></View>
          </View>
        </View>
      )}

      <View style={st.statRow}>
        <View style={[st.statCard, { backgroundColor: "#F3F4F6", borderColor: "#D1D5DB" }]}>
          <Ionicons name="time-outline" size={22} color="#6B7280" />
          <Text style={[st.statVal, { color: "#6B7280" }]}>{stats?.assigned ?? 0}</Text>
          <Text style={st.statLbl}>Chờ chạy</Text>
        </View>
        <View style={[st.statCard, { backgroundColor: "#EEF2FF", borderColor: "#C7D2FE" }]}>
          <Ionicons name="car-sport" size={22} color="#4F46E5" />
          <Text style={[st.statVal, { color: "#4F46E5" }]}>{stats?.in_progress ?? activeTrips.length}</Text>
          <Text style={st.statLbl}>Đang chạy</Text>
        </View>
        <View style={[st.statCard, { backgroundColor: "#D1FAE5", borderColor: "#A7F3D0" }]}>
          <Ionicons name="checkmark-circle" size={22} color="#059669" />
          <Text style={[st.statVal, { color: "#059669" }]}>{completedCount}</Text>
          <Text style={st.statLbl}>Hoàn thành</Text>
        </View>
      </View>

      <View style={st.sectionHeader}>
        <Text style={st.sectionTitle}>Chuyến đang chạy</Text>
        <TouchableOpacity onPress={() => router.push("/trips")}>
          <Text style={st.seeAll}>Xem tất cả →</Text>
        </TouchableOpacity>
      </View>

      {activeTrips.length === 0 ? (
        <View style={st.emptyState}>
          <Ionicons name="car-outline" size={48} color="#D1D5DB" />
          <Text style={st.emptyText}>Chưa có chuyến nào</Text>
        </View>
      ) : (
        sortedTrips.slice(0, 5).map((t) => {
          const sc = statusColors[t.status] || statusColors["pending"];
          const isCurrent = isCurrentTrip(t);
          const isSwapped = userId && t.driver_id !== userId;
          return (
            <TouchableOpacity key={t.id} style={[st.tripCard, { borderColor: isCurrent ? sc.text + "40" : "#F3F4F6" }]} activeOpacity={0.7}
              onPress={() => router.push({ pathname: "/trip-detail", params: { id: t.id, trip: JSON.stringify(t) } })}>
              <View style={[st.tripIcon, { backgroundColor: sc.bg }]}>
                <Ionicons name="car" size={20} color={sc.text} />
              </View>
              <View style={{ flex: 1 }}>
                {(() => {
                  const codes: string[] = [];
                  (t.orders || []).forEach((o: any) => {
                    if (o.pickup_location?.code) codes.push(o.pickup_location.code);
                    (o.delivery_points || []).forEach((dp: any) => {
                      if (dp.location?.code) codes.push(dp.location.code);
                    });
                  });
                  const deduped = codes.filter((c, i) => i === 0 || c !== codes[i - 1]);
                  if (deduped.length > 0) return (
                    <View style={st.routeWrap}>
                      <Ionicons name="navigate" size={11} color="#4F46E5" />
                      <Text style={st.routeText} numberOfLines={1}>{deduped.join("  →  ")}</Text>
                    </View>
                  );
                  return null;
                })()}
                <View style={{ flexDirection: "row", justifyContent: "space-between", alignItems: "center" }}>
                  <View style={{ flexDirection: "row", alignItems: "center", gap: 6 }}>
                    <Text style={st.tripCode}>{t.vehicle?.plate_number || "Chưa gán xe"}</Text>
                    {isCurrent && <View style={{ backgroundColor: "#10B981", paddingHorizontal: 5, paddingVertical: 1, borderRadius: 4 }}><Text style={{ fontSize: 9, fontWeight: "700", color: "#fff" }}>● HIỆN TẠI</Text></View>}
                    {isSwapped && <View style={{ backgroundColor: "#FEF3C7", paddingHorizontal: 5, paddingVertical: 1, borderRadius: 4 }}><Text style={{ fontSize: 9, fontWeight: "700", color: "#D97706" }}>⤿ ĐÃ BÀN GIAO</Text></View>}
                  </View>
                  <View style={[st.tripBadge, { backgroundColor: sc.bg }]}>
                    <Text style={[st.tripBadgeText, { color: sc.text }]}>{sc.label}</Text>
                  </View>
                </View>
                <Text style={st.tripKm}>
                  📏 {t.total_km ?? "-"} km · {t.start_km != null ? `${t.start_km} → ${t.end_km ?? "?"}` : "Chưa có Km"}
                </Text>
                {(() => {
                  const loadingTimes = (t.orders || []).map((o: any) => o.planned_loading_at).filter(Boolean);
                  if (loadingTimes.length === 0) return null;
                  return <Text style={st.loadingTime}>🕐 Đóng hàng: {new Date(loadingTimes[0]).toLocaleString("vi-VN")}</Text>;
                })()}
              </View>
              <Ionicons name="chevron-forward" size={16} color="#D1D5DB" />
            </TouchableOpacity>
          );
        })
      )}

      <TouchableOpacity style={st.linkBtn} onPress={() => router.push("/completed-trips")}>
        <Ionicons name="checkmark-circle-outline" size={16} color="#4F46E5" />
        <Text style={st.linkText}>Xem chuyến đã hoàn thành</Text>
      </TouchableOpacity>
      <View style={{ height: 32 }} />
    </ScrollView>
  );
}

const st = StyleSheet.create({
  container: { flex: 1, backgroundColor: "#F9FAFB" },
  header: { flexDirection: "row", justifyContent: "space-between", alignItems: "center", padding: 20, paddingTop: 16 },
  greeting: { fontSize: 22, fontWeight: "800", color: "#111827" },
  subtitle: { fontSize: 13, color: "#6B7280", marginTop: 2 },
  logoutBtn: { padding: 8, backgroundColor: "#FEE2E2", borderRadius: 10 },
  shiftCard: { backgroundColor: "#4F46E5", marginHorizontal: 16, marginBottom: 16, padding: 16, borderRadius: 16 },
  shiftDot: { width: 10, height: 10, borderRadius: 5, backgroundColor: "#34D399" },
  shiftType: { fontSize: 16, fontWeight: "700", color: "#fff" },
  shiftTime: { fontSize: 13, color: "rgba(255,255,255,0.7)", marginTop: 2 },
  shiftDuration: { backgroundColor: "rgba(255,255,255,0.2)", paddingHorizontal: 10, paddingVertical: 4, borderRadius: 8 },
  shiftDurationText: { color: "#fff", fontSize: 13, fontWeight: "600" },
  shiftKmRow: { flexDirection: "row", alignItems: "center", marginTop: 14, paddingTop: 12, borderTopWidth: 1, borderTopColor: "rgba(255,255,255,0.15)" },
  shiftKmVal: { fontSize: 16, fontWeight: "700", color: "#fff" },
  shiftKmLbl: { fontSize: 11, color: "rgba(255,255,255,0.6)", marginTop: 2 },
  shiftKmSep: { width: 1, height: 24, backgroundColor: "rgba(255,255,255,0.15)" },
  statRow: { flexDirection: "row", gap: 10, paddingHorizontal: 16, marginBottom: 20 },
  statCard: { flex: 1, padding: 16, borderRadius: 14, alignItems: "center", gap: 6, borderWidth: 1, shadowColor: "#000", shadowOpacity: 0.05, shadowRadius: 8, shadowOffset: { width: 0, height: 2 }, elevation: 2 },
  statVal: { fontSize: 20, fontWeight: "800" },
  statLbl: { fontSize: 11, color: "#6B7280", textAlign: "center" },
  sectionHeader: { flexDirection: "row", justifyContent: "space-between", alignItems: "center", paddingHorizontal: 16, marginBottom: 10 },
  sectionTitle: { fontSize: 16, fontWeight: "700", color: "#111827" },
  seeAll: { fontSize: 13, color: "#4F46E5", fontWeight: "500" },
  emptyState: { alignItems: "center", paddingVertical: 24 },
  emptyText: { color: "#9CA3AF", marginTop: 6, fontSize: 14 },
  tripCard: { flexDirection: "row", alignItems: "center", backgroundColor: "#fff", marginHorizontal: 16, marginBottom: 6, padding: 14, borderRadius: 14, borderWidth: 1, borderColor: "#F3F4F6", shadowColor: "#000", shadowOpacity: 0.04, shadowRadius: 6, shadowOffset: { width: 0, height: 1 }, elevation: 1, gap: 12 },
  tripIcon: { width: 40, height: 40, borderRadius: 12, alignItems: "center", justifyContent: "center" },
  tripCode: { fontSize: 15, fontWeight: "700", color: "#111827" },
  tripBadge: { paddingHorizontal: 7, paddingVertical: 2, borderRadius: 5 },
  tripBadgeText: { fontSize: 10, fontWeight: "700" },
  tripPlate: { fontSize: 13, color: "#6B7280", marginTop: 2 },
  tripKm: { fontSize: 12, color: "#9CA3AF", marginTop: 3 },
  routeWrap: { flexDirection: "row", alignItems: "center", gap: 4, marginBottom: 6 },
  routeText: { fontSize: 11, color: "#4F46E5", fontWeight: "600", flex: 1 },
  loadingTime: { fontSize: 12, color: "#6B7280", marginTop: 2 },
  linkBtn: { flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 6, marginTop: 4, padding: 16 },
  linkText: { color: "#4F46E5", fontWeight: "600", fontSize: 14 },
});
