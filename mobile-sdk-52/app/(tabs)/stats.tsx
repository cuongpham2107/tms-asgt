import { useState, useMemo, useCallback } from "react";
import { View, Text, StyleSheet, ScrollView, RefreshControl, TouchableOpacity, ActivityIndicator } from "react-native";
import { useFocusEffect } from "expo-router";
import { useAuth } from "../../src/lib/auth";
import { api } from "../../src/lib/api";
import { Ionicons } from "@expo/vector-icons";

const fmt = (v: any) => v != null ? parseInt(v).toLocaleString("vi-VN") : "-";

const statusBadge: Record<string, { label: string; bg: string; color: string; border: string }> = {
  completed: { label: "Hoàn thành", bg: "#ECFDF5", color: "#059669", border: "#A7F3D0" },
  cancelled: { label: "Đã huỷ", bg: "#FEF2F2", color: "#DC2626", border: "#FECACA" },
  driver_swap: { label: "Đảo lái", bg: "#FFFBEB", color: "#D97706", border: "#FDE68A" },
};

const periods = [
  { key: "all", label: "Tất cả" },
  { key: "today", label: "Hôm nay" },
  { key: "week", label: "Tuần này" },
  { key: "month", label: "Tháng này" },
];

export default function StatsScreen() {
  const { token } = useAuth();
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [history, setHistory] = useState<any[]>([]);
  const [activePeriod, setActivePeriod] = useState("all");
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);

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
      const d = t.completed_at || t.started_at || t.created_at;
      if (!d) return true;
      const date = new Date(d).toISOString().slice(0, 10);
      return date >= from! && date <= to!;
    });
  }, [history, activePeriod]);

  const load = async () => {
    if (!token) return;
    const { from, to } = getPeriodDates(activePeriod);
    setPage(1);
    const [statsRes, histRes] = await Promise.all([
      api.stats(activePeriod, token).catch(() => null),
      api.trips.history({
        page: 1,
        per_page: 15,
        from_date: from,
        to_date: to,
      }, token).catch(() => ({ data: [], meta: {} })),
    ]);
    if (statsRes?.data) setData(statsRes.data);
    const list = histRes.data || [];
    setHistory(list);
    const currentPage = histRes.meta?.current_page ?? 1;
    const lastPage = histRes.meta?.last_page ?? 1;
    setHasMore(currentPage < lastPage);
    setLoading(false);
  };
  useFocusEffect(useCallback(() => { load(); }, [token, activePeriod]));
  const onRefresh = async () => { setRefreshing(true); await load(); setRefreshing(false); };

  const loadMore = async () => {
    if (loading || loadingMore || !hasMore || !token) return;
    setLoadingMore(true);
    const nextPage = page + 1;
    const { from, to } = getPeriodDates(activePeriod);
    const histRes = await api.trips.history({
      page: nextPage,
      per_page: 15,
      from_date: from,
      to_date: to,
    }, token).catch(() => null);

    if (histRes?.data && histRes.data.length > 0) {
      setHistory((prev) => {
        const existingIds = new Set(prev.map((t) => t.id));
        const newItems = histRes.data.filter((t: any) => !existingIds.has(t.id));
        return [...prev, ...newItems];
      });
      setPage(nextPage);
      const currentPage = histRes.meta?.current_page ?? nextPage;
      const lastPage = histRes.meta?.last_page ?? nextPage;
      setHasMore(currentPage < lastPage);
    } else {
      setHasMore(false);
    }
    setLoadingMore(false);
  };

  const handleScroll = ({ nativeEvent }: any) => {
    const { layoutMeasurement, contentOffset, contentSize } = nativeEvent;
    const isCloseToBottom = layoutMeasurement.height + contentOffset.y >= contentSize.height - 60;
    if (isCloseToBottom && hasMore && !loadingMore && !loading) {
      loadMore();
    }
  };

  const histTotalKm = data?.total_km !== undefined ? data.total_km : filteredHistory.reduce((s: number, t: any) => s + (parseFloat(t.total_km) || 0), 0);
  const histLoadedKm = data?.total_km_loaded !== undefined ? data.total_km_loaded : filteredHistory.reduce((s: number, t: any) => s + (parseFloat(t.total_km_loaded) || 0), 0);
  const histEmptyKm = data?.total_km_empty !== undefined ? data.total_km_empty : (histTotalKm != null && histLoadedKm != null ? histTotalKm - histLoadedKm : null);

  return (
    <ScrollView
      style={s.container}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor="#4F46E5" />}
      onScroll={handleScroll}
      scrollEventThrottle={200}
    >
      {loading ? <Text style={s.loading}>Đang tải...</Text> : data ? (<>
        {/* Trip counts */}
        <Text style={s.sectionTitle}>📊 Tổng quan chuyến</Text>
        <View style={s.summaryRow}>
          <View style={[s.sumCard, { backgroundColor: "#EEF2FF", borderColor: "#C7D2FE" }]}><Ionicons name="today-outline" size={22} color="#4F46E5" /><Text style={[s.sumVal, { color: "#4F46E5" }]}>{data.in_progress ?? 0}</Text><Text style={s.sumLbl}>đang chạy</Text></View>
          <View style={[s.sumCard, { backgroundColor: "#ECFDF5", borderColor: "#A7F3D0" }]}><Ionicons name="checkmark-circle" size={22} color="#059669" /><Text style={[s.sumVal, { color: "#059669" }]}>{data.completed ?? 0}</Text><Text style={s.sumLbl}>hoàn thành</Text></View>
          <View style={[s.sumCard, { backgroundColor: "#FFFBEB", borderColor: "#FDE68A" }]}><Ionicons name="trophy-outline" size={22} color="#F59E0B" /><Text style={[s.sumVal, { color: "#F59E0B" }]}>{data.assigned ?? 0}</Text><Text style={s.sumLbl}>đã gán</Text></View>
        </View>

        {/* KM đã thực hiện (theo period) */}
        {(histTotalKm > 0 || filteredHistory.length > 0) && (
          <>
            <Text style={s.sectionTitle}>🚛 KM đã thực hiện</Text>
            <View style={s.kmCard}>
              <View style={{ alignItems: "center", flex: 1 }}>
                <Text style={s.kmVal}>{fmt(histTotalKm)}</Text>
                <Text style={s.kmLbl}>tổng km</Text>
              </View>
              <View style={s.kmSep} />
              <View style={{ alignItems: "center", flex: 1 }}>
                <Text style={s.kmVal}>{fmt(histLoadedKm)}</Text>
                <Text style={s.kmLbl}>có hàng</Text>
              </View>
              <View style={s.kmSep} />
              <View style={{ alignItems: "center", flex: 1 }}>
                <Text style={s.kmVal}>{fmt(histEmptyKm)}</Text>
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

        {/* Trips history */}
        <Text style={s.sectionTitle}>📋 Lịch sử chuyến đi ({filteredHistory.length})</Text>
        {filteredHistory.length === 0 ? (
          <View style={s.empty}><Text style={s.emptyText}>Chưa có chuyến đi</Text></View>
        ) : (
          <>
            {filteredHistory.map((t: any) => {
              const statusInfo = statusBadge[t.status] || statusBadge.completed;
              return (
                <View key={t.id} style={[s.tripCard, { borderColor: statusInfo.border }]}>
                  <View style={{ flex: 1 }}>
                    {(() => {
                      const codes: string[] = [];
                      (t.orders || []).forEach((o: any) => {
                        if (o.pickup_location?.code) codes.push(o.pickup_location.code);
                        (o.delivery_points || []).forEach((dp: any) => {
                          if (dp.location?.code) codes.push(dp.location.code);
                        });
                      });
                      if (codes.length === 0 && t.route) {
                        codes.push(...t.route.split(' → '));
                      }
                      const deduped = codes.filter((c, i) => i === 0 || c !== codes[i - 1]);
                      if (deduped.length > 0) return (
                        <View style={s.routeWrap}>
                          <Ionicons name="navigate" size={11} color="#4F46E5" />
                          <Text style={s.routeText} numberOfLines={1}>{deduped.join("  →  ")}</Text>
                        </View>
                      );
                      return null;
                    })()}
                    <View style={{ flexDirection: "row", alignItems: "center", gap: 6 }}>
                      <Text style={s.tripCode}>{t.vehicle?.plate_number || "-"}</Text>
                      <View style={[s.badge, { backgroundColor: statusInfo.bg }]}>
                        <Text style={[s.badgeText, { color: statusInfo.color }]}>{statusInfo.label}</Text>
                      </View>
                    </View>
                    {(() => {
                      const loadingTimes = (t.orders || []).map((o: any) => o.planned_loading_at).filter(Boolean);
                      if (loadingTimes.length === 0) return null;
                      return <Text style={s.loadingTime}>🕐 Đóng hàng: {new Date(loadingTimes[0]).toLocaleString("vi-VN")}</Text>;
                    })()}
                  </View>
                  <View style={{ alignItems: "flex-end" }}>
                    <Text style={s.tripKm}>{fmt(t.total_km)} km</Text>
                    <Text style={s.tripDate}>
                      {t.completed_at ? new Date(t.completed_at).toLocaleDateString("vi-VN") : (t.started_at ? new Date(t.started_at).toLocaleDateString("vi-VN") : "-")}
                    </Text>
                  </View>
                </View>
              );
            })}

            {loadingMore && (
              <View style={{ paddingVertical: 14, alignItems: "center" }}>
                <ActivityIndicator size="small" color="#4F46E5" />
                <Text style={{ color: "#6B7280", fontSize: 12, marginTop: 4 }}>Đang tải thêm...</Text>
              </View>
            )}

            {!hasMore && filteredHistory.length > 0 && (
              <View style={{ paddingVertical: 12, alignItems: "center" }}>
                <Text style={{ color: "#9CA3AF", fontSize: 12 }}>Đã hiển thị tất cả ({filteredHistory.length}) chuyến</Text>
              </View>
            )}
          </>
        )}

        {histTotalKm > 0 && (
          <View style={[s.totalCard, { borderColor: "#C7D2FE" }]}>
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
  sumCard: { flex: 1, padding: 16, borderRadius: 14, alignItems: "center", gap: 6, borderWidth: 1, shadowColor: "#000", shadowOpacity: 0.05, shadowRadius: 8, shadowOffset: { width: 0, height: 2 }, elevation: 2 },
  sumVal: { fontSize: 24, fontWeight: "800" }, sumLbl: { fontSize: 11, color: "#6B7280" },
  // Km card
  kmCard: { flexDirection: "row", alignItems: "center", backgroundColor: "#4F46E5", marginHorizontal: 16, padding: 18, borderRadius: 14 },
  kmVal: { fontSize: 20, fontWeight: "800", color: "#fff" }, kmLbl: { fontSize: 11, color: "rgba(255,255,255,0.7)", marginTop: 2 },
  kmSep: { width: 1, height: 28, backgroundColor: "rgba(255,255,255,0.2)" },
  // Trips
  tripCard: { flexDirection: "row", alignItems: "center", backgroundColor: "#fff", marginHorizontal: 16, marginBottom: 6, padding: 14, borderRadius: 12, borderWidth: 1, borderColor: "#F3F4F6", shadowColor: "#000", shadowOpacity: 0.04, shadowRadius: 6, shadowOffset: { width: 0, height: 1 }, elevation: 1 },
  tripCode: { fontSize: 14, fontWeight: "700", color: "#111827" }, tripPlate: { fontSize: 12, color: "#6B7280", marginTop: 2 },
  tripKm: { fontSize: 15, fontWeight: "700", color: "#4F46E5" }, tripDate: { fontSize: 11, color: "#9CA3AF", marginTop: 2 },
  routeWrap: { flexDirection: "row", alignItems: "center", gap: 4, marginBottom: 4 },
  routeText: { fontSize: 11, color: "#4F46E5", fontWeight: "600", flex: 1 },
  loadingTime: { fontSize: 12, color: "#6B7280", marginTop: 2 },
  badge: { paddingHorizontal: 6, paddingVertical: 2, borderRadius: 6 },
  badgeText: { fontSize: 10, fontWeight: "700" },
  totalCard: { backgroundColor: "#EEF2FF", marginHorizontal: 16, marginTop: 12, padding: 14, borderRadius: 12, alignItems: "center", borderWidth: 1, shadowColor: "#000", shadowOpacity: 0.05, shadowRadius: 8, shadowOffset: { width: 0, height: 2 }, elevation: 2 },
  totalText: { fontSize: 15, fontWeight: "700", color: "#4F46E5" },
  // Period filter
  periodTab: { paddingHorizontal: 14, paddingVertical: 6, borderRadius: 16, backgroundColor: "#F3F4F6" },
  periodText: { fontSize: 12, fontWeight: "600", color: "#6B7280" },
  empty: { alignItems: "center", padding: 20 }, emptyText: { color: "#9CA3AF", fontSize: 13 },
});
