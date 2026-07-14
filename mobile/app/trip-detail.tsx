import { useState, useCallback, useEffect } from "react";
import { View, Text, StyleSheet, ScrollView, TouchableOpacity, RefreshControl, TextInput, Modal } from "react-native";
import { useLocalSearchParams, useRouter, useFocusEffect } from "expo-router";
import { useAuth } from "../src/lib/auth";
import { api } from "../src/lib/api";
import { showAlert, showDestructiveConfirm } from "../src/lib/alert";
import { Ionicons } from "@expo/vector-icons";
import * as Location from "expo-location";

const statusConfig: Record<string, { icon: string; bg: string; text: string; label: string }> = {
  pending: { icon: "time-outline", bg: "#F3F4F6", text: "#6B7280", label: "Chờ" },
  started: { icon: "play-circle-outline", bg: "#FEF3C7", text: "#D97706", label: "Đang chạy" },
  arrived_pickup: { icon: "cube-outline", bg: "#FEF3C7", text: "#D97706", label: "Đến lấy" },
  delivering: { icon: "car-outline", bg: "#DBEAFE", text: "#2563EB", label: "Đang giao" },
  arrived_delivery: { icon: "location-outline", bg: "#FEF3C7", text: "#D97706", label: "Đến giao" },
  delivered: { icon: "checkmark-done", bg: "#D1FAE5", text: "#059669", label: "Đã giao" },
  completed: { icon: "checkmark-circle", bg: "#D1FAE5", text: "#059669", label: "Hoàn thành" },
  driver_swap: { icon: "swap-horizontal", bg: "#E0E7FF", text: "#4F46E5", label: "Đảo lái" },
  return_trip: { icon: "arrow-undo", bg: "#FEE2E2", text: "#DC2626", label: "Quay đầu" },
};

const orderStatusLabel: Record<string, string> = {
  assigned: "Đã gán", sent: "Chờ lấy", in_transit: "Đang giao", completed: "Xong", driver_swap: "Đảo lái",
};

const localISO = (d: Date = new Date()) => {
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
};

export default function TripDetailScreen() {
  const { token, shift } = useAuth(); const router = useRouter();
  const params = useLocalSearchParams<{ id: string; trip: string }>();
  const trip = params.trip ? JSON.parse(params.trip) : null;
  const [detail, setDetail] = useState<any>(null); const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [completeKm, setCompleteKm] = useState("");
  const [completing, setCompleting] = useState(false);
  const [starting, setStarting] = useState(false);
  const [startKmInput, setStartKmInput] = useState("");
  const [returnStarted, setReturnStarted] = useState(false);
  const [showCompleteModal, setShowCompleteModal] = useState(false);
  const userId = shift?.driver?.id;

  // Format: bỏ .0, hiển thị số nguyên
  const fmt = (v: any) => v != null ? parseInt(v).toLocaleString("vi-VN") : "-";

  const getGps = async () => {
    try {
      await Location.requestForegroundPermissionsAsync();
      const pos = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
      return { gps_lat: pos.coords.latitude, gps_lng: pos.coords.longitude };
    } catch { return null; }
  };

  const load = async () => {
    const tripId = trip?.id || params.id;
    if (!token || !tripId) return;
    try { const r = await api.trips.detail(String(tripId), token); setDetail(r.data || r); } finally { setLoading(false); }
  };
  useFocusEffect(useCallback(() => { load(); }, [token, trip?.id, params.id]));
  const onRefresh = async () => { setRefreshing(true); await load(); setRefreshing(false); };

  const currentStatus = detail?.status || trip?.status || "pending";
  const isReturnTrip = currentStatus === "return_trip";
  const isSwapped = detail && userId && detail.driver_id !== userId;
  const canStart = currentStatus === "pending" && !isReturnTrip && !isSwapped;
  const canComplete = (!["pending", "completed", "driver_swap", "cancelled"].includes(currentStatus) || (isReturnTrip && currentStatus !== "completed")) && !isSwapped;
  const orders: any[] = detail?.orders || trip?.orders || [];

  // Auto lấy km hiện tại của xe
  const vehicleKm = detail?.vehicle?.km_reading ?? trip?.vehicle?.km_reading;

  // Auto-fill KM input for return trip with vehicle's current mileage
  useEffect(() => {
    if (vehicleKm != null) {
      if (!startKmInput) setStartKmInput(String(Math.round(vehicleKm)));
      if (!completeKm) setCompleteKm(String(Math.round(vehicleKm)));
    }
  }, [vehicleKm]);

  const handleStart = async () => {
    if (!trip?.id || !token) return;
    setStarting(true);
    try {
      const gps = await getGps();
      const body: any = { checkpoint_type: "started", occurred_at: localISO() };
      if (gps) { body.gps_lat = gps.gps_lat; body.gps_lng = gps.gps_lng; }
      await api.trips.checkpoint(String(trip.id), body, token);
      showAlert("Thành công", "Đã bắt đầu chuyến");
      await load();
    } catch (e: any) {
      const msg = e.message || "";
      const match = msg.match(/#(\d+)/);
      showAlert("Không thể bắt đầu", msg, () => {
        if (match) router.push({ pathname: "/trip-detail", params: { id: match[1] } });
      });
    }
    finally { setStarting(false); }
  };

  const handleStartReturn = async () => {
    const sKm = parseFloat(startKmInput);
    if (!sKm) { showAlert("Thiếu", "Nhập Km bắt đầu"); return; }
    if (!trip?.id || !token) return;
    setStarting(true);
    try {
      const cps = detail?.checkpoints || [];
      const startedCp = cps.find((cp: any) => cp.checkpoint_type === "started");
      if (startedCp) {
        await api.trips.checkpoint(String(trip.id), {
          checkpoint_type: "started",
          km_reading: sKm,
          occurred_at: startedCp.occurred_at,
        }, token);
      }
      setReturnStarted(true);
      showAlert("Thành công", "Đã ghi nhận Km bắt đầu");
      await load();
    } catch (e: any) { showAlert("Lỗi", e.message); }
    finally { setStarting(false); }
  };

  const handleCompleteReturn = async () => {
    const eKm = parseFloat(completeKm);
    if (!eKm) { showAlert("Thiếu", "Nhập Km kết thúc"); return; }
    if (!trip?.id || !token) return;
    showDestructiveConfirm("Kết thúc chuyến quay đầu", "Bạn có chắc chắn muốn kết thúc chuyến quay đầu?", async () => {
      setCompleting(true);
      try {
        const cps = detail?.checkpoints || [];
        const endCp = cps.find((cp: any) => cp.checkpoint_type === "end");
        if (endCp) {
          await api.trips.checkpoint(String(trip.id), {
            checkpoint_type: "end",
            km_reading: eKm,
            occurred_at: endCp.occurred_at,
          }, token);
        }
        await api.trips.complete(String(trip.id), eKm, token);
        showAlert("Thành công", "Đã kết thúc chuyến quay đầu");
        await load();
      } catch (e: any) { showAlert("Lỗi", e.message); }
      finally { setCompleting(false); }
    });
  };

  const doComplete = async (km: number) => {
    if (!trip?.id || !token) return;
    setCompleting(true);
    try {
      const gps = await getGps();
      await api.trips.complete(String(trip.id), km, token, gps ?? undefined);
      const hasIncomplete = orders.some((o: any) => o.status !== "completed");
      showAlert("Thành công", hasIncomplete ? "Chuyến → Đảo lái (đơn chưa xong)" : "Đã kết thúc chuyến");
      setShowCompleteModal(false);
      await load();
    } catch (e: any) { showAlert("Lỗi", e.message); }
    finally { setCompleting(false); }
  };

  // Show loading while fetching trip data
  if (!trip && !detail) {
    return (
      <View style={s.container}>
        <View style={{ flex: 1, alignItems: "center", justifyContent: "center" }}>
          <Text style={{ color: "#9CA3AF", fontSize: 14 }}>Đang tải...</Text>
        </View>
      </View>
    );
  }

  // Use detail (from API) or trip (from params)
  const effectiveTrip = detail || trip;
  const st = statusConfig[effectiveTrip?.status || currentStatus] || statusConfig["pending"];

  return (
    <View style={s.container}>
      <ScrollView
        style={{ flex: 1 }}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor="#4F46E5" />}
      >
        {/* Bàn giao banner */}
        {isSwapped && (
          <View style={s.swappedBanner}>
            <Ionicons name="swap-horizontal" size={18} color="#D97706" />
            <Text style={s.swappedBannerText}>Chuyến đã bàn giao — chỉ xem</Text>
          </View>
        )}

        {/* Hero */}
        <View style={[s.heroCard, { borderLeftColor: st.text, borderLeftWidth: 4 }]}>
          <View style={s.heroRow}>
            <View style={{ flex: 1 }}>
              <Text style={s.tripCode}>{detail?.vehicle?.plate_number || trip?.vehicle?.plate_number || "Chưa gán xe"}</Text>
            </View>
            <View style={[s.statusPill, { backgroundColor: st.bg }]}>
              <Ionicons name={st.icon as any} size={14} color={st.text} />
              <Text style={[s.statusPillText, { color: st.text }]}>{st.label}</Text>
            </View>
          </View>
          {canStart && (
            <TouchableOpacity style={[s.actionBtn, { backgroundColor: "#10B981", marginTop: 12 }]} onPress={handleStart} disabled={starting}>
              <Ionicons name="play-circle" size={20} color="#fff" />
              <Text style={s.actionBtnText}>{starting ? "Đang xử lý..." : "Bắt đầu chuyến"}</Text>
            </TouchableOpacity>
          )}
        </View>

        {/* Km stats — ẩn với chuyến quay đầu */}
        {!isReturnTrip && (
          <View style={s.statsGrid}>
            {[
              { icon: "speedometer-outline", label: "Tổng Km", value: fmt(detail?.total_km ?? trip?.total_km), color: "#4F46E5", bg: "#EEF2FF" },
              { icon: "cube-outline", label: "Km có hàng", value: fmt(detail?.total_km_loaded ?? trip?.total_km_loaded), color: "#3B82F6", bg: "#EFF6FF" },
              { icon: "arrow-down-circle-outline", label: "Km bắt đầu", value: fmt(detail?.start_km ?? trip?.start_km), color: "#10B981", bg: "#ECFDF5" },
              { icon: "arrow-up-circle-outline", label: "Km xe", value: fmt(vehicleKm), color: "#F59E0B", bg: "#FFFBEB" },
            ].map((st2, i) => (
              <View key={i} style={s.statCard}>
                <View style={[s.statIcon, { backgroundColor: st2.bg }]}><Ionicons name={st2.icon as any} size={20} color={st2.color} /></View>
                <View style={{ flex: 1 }}><Text style={s.statLabel}>{st2.label}</Text><Text style={s.statValue}>{st2.value}</Text></View>
              </View>
            ))}
          </View>
        )}

        {/* Thời gian */}
        {detail?.started_at && (
          <View style={s.timeCard}>
            <View style={s.timeRow}><Ionicons name="play-circle" size={16} color="#10B981" /><Text style={s.timeText}>Bắt đầu: {new Date(detail.started_at).toLocaleString("vi-VN")}</Text></View>
            {detail.completed_at && <View style={s.timeRow}><Ionicons name="flag" size={16} color="#4F46E5" /><Text style={s.timeText}>Hoàn thành: {new Date(detail.completed_at).toLocaleString("vi-VN")}</Text></View>}
          </View>
        )}

        {/* Orders hoặc Return Trip Info */}
        {isReturnTrip ? (
          <View style={s.infoCard}>
            {currentStatus === "completed" ? (
              <>
                <Ionicons name="checkmark-circle" size={48} color="#059669" style={{ alignSelf: "center", marginBottom: 8 }} />
                <Text style={[s.infoTitle, { color: "#059669" }]}>Chuyến quay đầu đã hoàn thành</Text>
                <View style={{ flexDirection: "row", gap: 8, marginTop: 12 }}>
                  <View style={{ flex: 1, backgroundColor: "#ECFDF5", padding: 12, borderRadius: 10 }}>
                    <Text style={{ fontSize: 11, color: "#6B7280" }}>Km bắt đầu</Text>
                    <Text style={{ fontSize: 18, fontWeight: "800", color: "#059669" }}>{fmt(detail?.start_km ?? trip?.start_km)}</Text>
                  </View>
                  <View style={{ flex: 1, backgroundColor: "#ECFDF5", padding: 12, borderRadius: 10 }}>
                    <Text style={{ fontSize: 11, color: "#6B7280" }}>Km kết thúc</Text>
                    <Text style={{ fontSize: 18, fontWeight: "800", color: "#059669" }}>{fmt(detail?.end_km ?? trip?.end_km)}</Text>
                  </View>
                </View>
                <Text style={{ fontSize: 13, color: "#6B7280", textAlign: "center", marginTop: 8 }}>
                  Tổng: <Text style={{ fontWeight: "700", color: "#111827" }}>{fmt(detail?.total_km ?? trip?.total_km)} km</Text>
                </Text>
              </>
            ) : (
              <>
                <Ionicons name="car-outline" size={40} color="#DC2626" style={{ alignSelf: "center", marginBottom: 12 }} />
                <Text style={s.infoTitle}>Chuyến quay đầu — không hàng</Text>
                <Text style={{ fontSize: 13, color: "#6B7280", textAlign: "center", marginTop: 4 }}>
                  Km xe hiện tại: <Text style={{ fontWeight: "700", color: "#4F46E5" }}>{fmt(vehicleKm)}</Text>
                </Text>
                {!returnStarted ? (
                  <>
                    <Text style={s.infoDesc}>Bước 1: Nhập Km bắt đầu</Text>
                    <TextInput style={[s.kmInput, { marginTop: 12, marginBottom: 12 }]} placeholder="Km bắt đầu" placeholderTextColor="#D1D5DB" keyboardType="numeric" value={startKmInput} onChangeText={setStartKmInput} />
                    <TouchableOpacity style={[s.actionBtn, { backgroundColor: "#10B981", marginTop: 4 }]} onPress={handleStartReturn} disabled={starting}>
                      <Ionicons name="play-circle" size={20} color="#fff" />
                      <Text style={s.actionBtnText}>{starting ? "Đang xử lý..." : "Bắt đầu chuyến"}</Text>
                    </TouchableOpacity>
                  </>
                ) : (
                  <>
                    <Text style={s.infoDesc}>Bước 2: Nhập Km kết thúc</Text>
                    <View style={{ flexDirection: "row", alignItems: "center", gap: 8, marginTop: 8, backgroundColor: "#ECFDF5", padding: 10, borderRadius: 10 }}>
                      <Ionicons name="checkmark-circle" size={16} color="#059669" />
                      <Text style={{ fontSize: 13, color: "#059669", fontWeight: "600" }}>Km bắt đầu: {fmt(startKmInput)}</Text>
                    </View>
                    <TextInput style={[s.kmInput, { marginTop: 12 }]} placeholder="Km kết thúc" placeholderTextColor="#D1D5DB" keyboardType="numeric" value={completeKm} onChangeText={setCompleteKm} />
                  </>
                )}
              </>
            )}
          </View>
        ) : (
          <>
            <View style={s.sectionHeader}>
              <Text style={s.sectionTitle}>📦 Đơn hàng ({orders.length})</Text>
            </View>
            {orders.length === 0 ? (
              <View style={s.empty}><Ionicons name="cube-outline" size={40} color="#E5E7EB" /><Text style={s.emptyText}>Chưa có đơn hàng</Text></View>
            ) : orders.map((o: any, i: number) => {
              const osText = orderStatusLabel[o.status] || o.status;
              const osColor = o.status === "completed" ? "#059669" : o.status === "in_transit" ? "#D97706" : o.status === "driver_swap" ? "#8B5CF6" : "#6B7280";
              const hasEndCk = (o.trip_checkpoints || []).some((cp: any) => cp.checkpoint_type === "end");
              return (
                <TouchableOpacity key={o.id} style={[s.orderCard, { borderColor: osColor + "20" }]} activeOpacity={0.7}
                  onPress={() => {
                    router.push({ pathname: "/order-detail", params: { id: o.id, order: JSON.stringify({ ...o, trip_id: trip?.id || params.id, vehicle: detail?.vehicle || trip?.vehicle, is_swapped: isSwapped }) } });
                  }}>
                  <View style={{ flex: 1 }}>
                    {(() => {
                      const codes: string[] = [];
                      if (o.pickup_location?.code) codes.push(o.pickup_location.code);
                      (o.delivery_points || []).forEach((dp: any) => {
                        if (dp.location?.code) codes.push(dp.location.code);
                      });
                      if (codes.length > 0) return (
                        <View style={s.routeWrap}>
                          <Ionicons name="navigate" size={12} color="#4F46E5" />
                          <Text style={s.orderRoute}>{codes.join("  →  ")}</Text>
                        </View>
                      );
                      return null;
                    })()}
                    <View style={{ flexDirection: "row", justifyContent: "space-between", alignItems: "center" }}>
                      <View style={{ flexDirection: "row", alignItems: "center", flex: 1 }}>
                        <Text style={s.orderCode}>{o.order_code}</Text>
                        <View style={{ paddingHorizontal: 4, paddingVertical: 1, borderRadius: 4, backgroundColor: o.type === 'HHHK' ? '#E0F2FE' : '#FEF3C7', marginLeft: 6 }}>
                          <Text style={{ fontSize: 10, fontWeight: "600", color: o.type === 'HHHK' ? '#0369A1' : '#B45309' }}>{o.type_label || o.type}</Text>
                        </View>
                      </View>
                      <View style={[s.orderBadge, { backgroundColor: osColor + "20" }]}>
                        <Text style={[s.orderBadgeText, { color: osColor }]}>{osText}</Text>
                      </View>
                    </View>
                    <Text style={s.orderCargo} numberOfLines={1}>{o.cargo_name || "Chưa có tên"}</Text>
                    {o.customer?.name && <Text style={s.orderCustomer}>{o.customer.name}</Text>}
                    {o.planned_loading_at && <Text style={s.orderLoading}>🕐 Đóng hàng: {new Date(o.planned_loading_at).toLocaleString("vi-VN")}</Text>}
                    <Text style={s.orderKm}>📏 loaded: {fmt(o.loaded_km)} km</Text>
                  </View>
                  <Ionicons name="chevron-forward" size={16} color="#D1D5DB" />
                </TouchableOpacity>
              );
            })}
          </>
        )}

        <View style={{ height: 100 }} />
      </ScrollView>

      {/* Sticky bottom — Kết thúc chuyến */}
      {canComplete && (
        <View style={s.stickyBar}>
          {isReturnTrip && returnStarted ? (
            <TouchableOpacity style={[{ backgroundColor: "#DC2626", flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 8, paddingVertical: 14, borderRadius: 12 }]} onPress={handleCompleteReturn} disabled={completing} activeOpacity={0.8}>
              <Ionicons name="flag" size={20} color="#fff" />
              <Text style={{ color: "#fff", fontSize: 17, fontWeight: "800" }}>{completing ? "Đang xử lý..." : "Kết thúc chuyến quay đầu"}</Text>
            </TouchableOpacity>
          ) : isReturnTrip ? null : (
            <TouchableOpacity style={[{ backgroundColor: "#DC2626", flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 8, paddingVertical: 14, borderRadius: 12 }]} onPress={() => {
              setCompleteKm(String(vehicleKm != null ? Math.round(vehicleKm) : ""));
              setShowCompleteModal(true);
            }} activeOpacity={0.8}>
              <Ionicons name="flag" size={20} color="#fff" />
              <Text style={{ color: "#fff", fontSize: 17, fontWeight: "800" }}>Kết thúc chuyến</Text>
            </TouchableOpacity>
          )}
        </View>
      )}
      {/* Modal kết thúc chuyến */}
      <Modal visible={showCompleteModal} animationType="fade" transparent onRequestClose={() => setShowCompleteModal(false)}>
        <View style={s.modalOverlay}>
          <View style={s.modalCard}>
            <Text style={s.modalTitle}>Kết thúc chuyến</Text>

            <Text style={s.modalSectionLabel}>Trạng thái đơn hàng ({orders.length})</Text>
            {orders.map((o: any, i: number) => {
              const hasEnd = (o.trip_checkpoints || []).some((cp: any) => cp.checkpoint_type === "end");
              return (
                <View key={i} style={{ flexDirection: "row", justifyContent: "space-between", alignItems: "center", paddingVertical: 6, borderBottomWidth: 1, borderBottomColor: "#F3F4F6" }}>
                  <View style={{ flex: 1 }}>
                    <Text style={{ fontSize: 14, fontWeight: "600", color: "#111827" }}>{o.order_code || `#${o.id}`}</Text>
                  </View>
                  <Text style={{ fontSize: 13, color: o.status === "completed" ? "#059669" : "#D97706", fontWeight: "600", marginRight: 6 }}>{orderStatusLabel[o.status] || o.status}</Text>
                  {o.status === "completed" && (
                    <Text style={{ fontSize: 11, fontWeight: "700", color: hasEnd ? "#059669" : "#F59E0B" }}>
                      {hasEnd ? "✓ End" : "✗ End"}
                    </Text>
                  )}
                </View>
              );
            })}

            <Text style={[s.modalSectionLabel, { marginTop: 16 }]}>Km đồng hồ</Text>
            <TextInput style={s.modalKmInput} placeholder="Km hiện tại" placeholderTextColor="#D1D5DB" keyboardType="numeric" value={completeKm} onChangeText={setCompleteKm} autoFocus />

            {orders.some((o: any) => o.status !== "completed") && (
              <View style={{ backgroundColor: "#FEF3C7", padding: 10, borderRadius: 8, marginTop: 12 }}>
                <Text style={{ fontSize: 12, color: "#D97706", textAlign: "center" }}>⚠️ Kết thúc chuyến sẽ tạo Đảo lái cho đơn chưa xong</Text>
              </View>
            )}

            {orders.some((o: any) => o.status === "completed" && !(o.trip_checkpoints || []).some((cp: any) => cp.checkpoint_type === "end")) && (
              <View style={{ backgroundColor: "#FEF3C7", padding: 10, borderRadius: 8, marginTop: 12 }}>
                <Text style={{ fontSize: 12, color: "#D97706", textAlign: "center" }}>⚠️ Một số đơn chưa có chốt "Kết thúc đơn hàng" — sẽ được tự động tạo</Text>
              </View>
            )}

            <View style={{ flexDirection: "row", gap: 10, marginTop: 20 }}>
              <TouchableOpacity style={[s.modalBtn, { backgroundColor: "#F3F4F6" }]} onPress={() => setShowCompleteModal(false)}>
                <Text style={[s.modalBtnText, { color: "#6B7280" }]}>Huỷ</Text>
              </TouchableOpacity>
              <TouchableOpacity style={[s.modalBtn, { backgroundColor: "#DC2626" }]} onPress={() => {
                const km = parseFloat(completeKm);
                if (!km) { showAlert("Thiếu", "Vui lòng nhập Km"); return; }
                doComplete(km);
              }} disabled={completing}>
                <Text style={[s.modalBtnText, { color: "#fff" }]}>{completing ? "Đang xử lý..." : "Kết thúc"}</Text>
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>
    </View>
  );
}

const s = StyleSheet.create({
  container: { flex: 1, backgroundColor: "#F9FAFB" },
  swappedBanner: { flexDirection: "row", alignItems: "center", gap: 8, backgroundColor: "#FFFBEB", marginHorizontal: 16, marginTop: 12, padding: 12, borderRadius: 10, borderWidth: 1, borderColor: "#FDE68A" },
  swappedBannerText: { flex: 1, fontSize: 13, fontWeight: "600", color: "#92400E" },
  heroCard: { backgroundColor: "#fff", margin: 16, marginBottom: 4, padding: 16, borderRadius: 14, borderWidth: 1, borderColor: "#F3F4F6" },
  heroRow: { flexDirection: "row", alignItems: "center", gap: 12 },
  tripCode: { fontSize: 20, fontWeight: "800", color: "#111827" }, plate: { fontSize: 14, color: "#6B7280", marginTop: 3 },
  statusPill: { flexDirection: "row", alignItems: "center", gap: 4, paddingHorizontal: 10, paddingVertical: 5, borderRadius: 20 },
  statusPillText: { fontSize: 12, fontWeight: "700" },
  actionBtn: { flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 8, paddingVertical: 12, borderRadius: 12 },
  actionBtnText: { color: "#fff", fontSize: 15, fontWeight: "700" },
  statsGrid: { flexDirection: "row", flexWrap: "wrap", paddingHorizontal: 12, gap: 8, marginBottom: 8 },
  statCard: { width: "47.5%", backgroundColor: "#fff", padding: 14, borderRadius: 14, borderWidth: 1, borderColor: "#F3F4F6", flexDirection: "row", alignItems: "center", gap: 12 },
  statIcon: { width: 42, height: 42, borderRadius: 12, alignItems: "center", justifyContent: "center" },
  statValue: { fontSize: 20, fontWeight: "800", color: "#111827" }, statLabel: { fontSize: 11, color: "#9CA3AF", marginBottom: 2 },
  timeCard: { backgroundColor: "#fff", marginHorizontal: 16, padding: 14, borderRadius: 12, borderWidth: 1, borderColor: "#F3F4F6", marginBottom: 16, gap: 8 },
  timeRow: { flexDirection: "row", alignItems: "center", gap: 8 }, timeText: { fontSize: 13, color: "#374151" },
  sectionHeader: { flexDirection: "row", justifyContent: "space-between", alignItems: "center", paddingHorizontal: 16, marginBottom: 10, marginTop: 4 },
  sectionTitle: { fontSize: 16, fontWeight: "700", color: "#111827" },
  orderCard: { flexDirection: "row", alignItems: "center", backgroundColor: "#fff", marginHorizontal: 16, marginBottom: 8, padding: 12, borderRadius: 12, borderWidth: 1, borderColor: "#F3F4F6", gap: 12, shadowColor: "#000", shadowOpacity: 0.04, shadowRadius: 6, shadowOffset: { width: 0, height: 1 }, elevation: 1 },
  orderSeq: { width: 28, height: 28, borderRadius: 14, backgroundColor: "#4F46E5", alignItems: "center", justifyContent: "center" },
  seqText: { color: "#fff", fontSize: 12, fontWeight: "700" }, orderCode: { fontSize: 14, fontWeight: "700", color: "#111827" },
  orderBadge: { paddingHorizontal: 7, paddingVertical: 2, borderRadius: 5 }, orderBadgeText: { fontSize: 10, fontWeight: "700" },
  orderCargo: { fontSize: 13, color: "#6B7280", marginTop: 2 }, orderCustomer: { fontSize: 12, color: "#9CA3AF", marginTop: 1 },
  routeWrap: { flexDirection: "row", alignItems: "center", gap: 6, marginBottom: 10, paddingBottom: 10, borderBottomWidth: 1, borderBottomColor: "#F3F4F6" },
  orderRoute: { fontSize: 13, fontWeight: "700", color: "#4F46E5", flex: 1 },
  orderKm: { fontSize: 11, color: "#9CA3AF", marginTop: 2 },
  orderLoading: { fontSize: 12, color: "#6B7280", marginTop: 2 },
  empty: { alignItems: "center", paddingVertical: 32 }, emptyText: { color: "#9CA3AF", marginTop: 6, fontSize: 13 },
  // Sticky bottom bar
  stickyBar: { backgroundColor: "#fff", padding: 12, paddingBottom: 32, borderTopWidth: 1, borderTopColor: "#F3F4F6" },
  stickyBtn: { flex: 1, flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 8, paddingVertical: 14, borderRadius: 12 },
  stickyBtnText: { color: "#fff", fontSize: 16, fontWeight: "700" },
  stickyInput: { flex: 1, backgroundColor: "#F9FAFB", padding: 12, borderRadius: 12, borderWidth: 1, borderColor: "#E5E7EB", fontSize: 16, color: "#111827" },
  // Return trip info
  infoCard: { backgroundColor: "#fff", marginHorizontal: 16, padding: 20, borderRadius: 14, borderWidth: 1, borderColor: "#F3F4F6", marginBottom: 12 },
  infoTitle: { fontSize: 16, fontWeight: "700", color: "#DC2626", textAlign: "center" },
  infoDesc: { fontSize: 13, color: "#6B7280", textAlign: "center", marginTop: 6, lineHeight: 18 },
  kmInput: { backgroundColor: "#F9FAFB", padding: 12, borderRadius: 10, borderWidth: 1, borderColor: "#E5E7EB", fontSize: 18, fontWeight: "700", color: "#111827", textAlign: "center" },
  // Complete modal
  modalOverlay: { flex: 1, backgroundColor: "rgba(0,0,0,0.5)", justifyContent: "center", alignItems: "center", paddingHorizontal: 20 },
  modalCard: { backgroundColor: "#fff", borderRadius: 16, padding: 20, width: "100%", maxWidth: 360 },
  modalTitle: { fontSize: 18, fontWeight: "800", color: "#111827", marginBottom: 16, textAlign: "center" },
  modalSectionLabel: { fontSize: 12, fontWeight: "700", color: "#6B7280", marginBottom: 8, textTransform: "uppercase", letterSpacing: 0.5 },
  modalKmInput: { backgroundColor: "#F9FAFB", padding: 14, borderRadius: 12, borderWidth: 1, borderColor: "#E5E7EB", fontSize: 20, fontWeight: "700", color: "#111827", textAlign: "center" },
  modalBtn: { flex: 1, paddingVertical: 12, borderRadius: 12, alignItems: "center", justifyContent: "center" },
  modalBtnText: { fontSize: 15, fontWeight: "700" },
});
