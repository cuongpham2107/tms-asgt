import { resolveNextAction, type NextAction } from "../../src/lib/tripActions";
import { showAlert, showConfirm, showDestructiveConfirm } from "../../src/lib/alert";
import { useLoading } from "../../src/lib/loading";
import { getCheckpointGps, toFakePoint } from "../../src/lib/fakeLocation";
import { formatKm } from "../../src/lib/format";
import { useState, useEffect, useCallback } from "react";
import {
  View,
  Text,
  StyleSheet,
  ScrollView,
  TouchableOpacity,
  RefreshControl,
  ActivityIndicator,
  Modal,
  Image,
  TextInput,
  KeyboardAvoidingView,
  Pressable,
  Platform,
} from "react-native";
import * as ImagePicker from "expo-image-picker";
import { useRouter, useFocusEffect } from "expo-router";
import { useAuth } from "../../src/lib/auth";
import { api } from "../../src/lib/api";
import { Ionicons } from "@expo/vector-icons";
import { getGpsStatus, flushBeforeCheckpoint, type GpsStatus } from "../../src/tracking/tracker";

const GPS_POLL_MS = 5000;
const gpsPillConfig = {
  ok: { icon: "🟢", text: "GPS đang ghi", bg: "#ECFDF5", color: "#047857" },
  stale: { icon: "🟡", text: "GPS: chưa có điểm mới (60s)", bg: "#FFFBEB", color: "#B45309" },
  off: { icon: "🔴", text: "GPS tắt hoặc thiếu quyền", bg: "#FEF2F2", color: "#B91C1C" },
} as const;

/** Thanh trạng thái ghi GPS khi đang trong ca. Ẩn trên web. */
function GpsStatusPill({ onFix }: { onFix: () => void }) {
  const [status, setStatus] = useState<GpsStatus | null>(null);
  useFocusEffect(useCallback(() => {
    const tick = () => getGpsStatus().then(setStatus).catch(() => {});
    tick();
    const timer = setInterval(tick, GPS_POLL_MS);
    return () => clearInterval(timer);
  }, []));
  if (!status) return null;
  const cfg = gpsPillConfig[status.level];
  return (
    <TouchableOpacity
      style={[st.gpsPill, { backgroundColor: cfg.bg }]}
      onPress={status.level === "off" ? onFix : undefined}
      disabled={status.level !== "off"}
      activeOpacity={0.8}
    >
      <Text style={[st.gpsPillText, { color: cfg.color }]}>{cfg.icon} {cfg.text}</Text>
      <Text style={[st.gpsPillText, { color: cfg.color }]}>Chờ gửi: {status.pending}</Text>
    </TouchableOpacity>
  );
}

const shiftLabels: Record<string, string> = { full: "Cả ca (X)", morning_half: "Nửa ca ngày (X/2)", night_half: "Nửa ca đêm (Y/2)" };

const statusColors: Record<string, { bg: string; text: string }> = {
  pending: { bg: "#F3F4F6", text: "#6B7280" },
  started: { bg: "#FEF3C7", text: "#D97706" },
  arrived_pickup: { bg: "#FEF3C7", text: "#D97706" },
  delivering: { bg: "#DBEAFE", text: "#2563EB" },
  arrived_delivery: { bg: "#FEF3C7", text: "#D97706" },
  delivered: { bg: "#D1FAE5", text: "#059669" },
  completed: { bg: "#D1FAE5", text: "#059669" },
  driver_swap: { bg: "#E0E7FF", text: "#4F46E5" },
  cancelled: { bg: "#FEE2E2", text: "#DC2626" },
};

export default function DashboardScreen() {
  const [quickActionModal, setQuickActionModal] = useState<{
    trip: any;
    action: NextAction;
  } | null>(null);
  const [quickPhotos, setQuickPhotos] = useState<string[]>([]);
  const [quickNote, setQuickNote] = useState("");
  const [submittingQuick, setSubmittingQuick] = useState(false);
  const { showLoading, hideLoading } = useLoading();

  const localISO = () => {
    const d = new Date();
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000)
      .toISOString()
      .slice(0, 19);
  };

  const handleTakeQuickCamera = async () => {
    try {
      const { status } = await ImagePicker.requestCameraPermissionsAsync();
      if (status !== "granted") {
        showAlert("Quyền truy cập", "Cần cấp quyền camera để chụp ảnh");
        return;
      }
      const res = await ImagePicker.launchCameraAsync({
        allowsEditing: false,
        quality: 0.7,
      });
      if (!res.canceled && res.assets?.[0]?.uri) {
        setQuickPhotos((prev) => [...prev, res.assets[0].uri]);
      }
    } catch (e: any) {
      const msg = String(e?.message || "");
      if (
        msg.toLowerCase().includes("simulator") ||
        msg.toLowerCase().includes("not available") ||
        msg.toLowerCase().includes("unavailable")
      ) {
        await handlePickQuickGallery();
        return;
      }
      showAlert("Lỗi chụp ảnh", msg || "Không thể mở camera");
    }
  };

  const handlePickQuickGallery = async () => {
    try {
      const res = await ImagePicker.launchImageLibraryAsync({
        mediaTypes: ["images"],
        allowsEditing: false,
        quality: 0.7,
      });
      if (!res.canceled && res.assets?.[0]?.uri) {
        setQuickPhotos((prev) => [...prev, res.assets[0].uri]);
      }
    } catch (e: any) {
      showAlert("Lỗi chọn ảnh", e?.message || "Không thể mở thư viện ảnh");
    }
  };

  const handleOpenQuickModal = (t: any, act: NextAction) => {
    if (!token) return;

    if (!shift && act.type !== "started") {
      showAlert("Cần vào ca", "Bạn cần vào ca trước khi thực hiện thao tác");
      return;
    }

    if (act.type === "end") {
      showDestructiveConfirm(
        "Kết thúc chuyến",
        `Bạn có chắc chắn muốn kết thúc chuyến xe ${t.vehicle?.plate_number || ""} và hoàn thành toàn bộ lộ trình?`,
        async () => {
          showLoading();
          try {
            await flushBeforeCheckpoint();
            const gps = await getCheckpointGps(null);
            await api.trips.complete(String(t.id), token, gps ?? undefined);
            showAlert("Thành công", "Đã hoàn thành chuyến xe!");
            await refresh();
          } catch (e: any) {
            showAlert("Lỗi kết thúc chuyến", e.message || "Không thể thực hiện");
          } finally {
            hideLoading();
          }
        },
        undefined,
        "Kết thúc xe"
      );
      return;
    }

    setQuickPhotos([]);
    setQuickNote("");
    setQuickActionModal({ trip: t, action: act });
  };

  const handleSubmitQuickAction = async () => {
    if (!quickActionModal || !token) return;
    const { trip: t, action: act } = quickActionModal;

    setSubmittingQuick(true);
    showLoading();
    try {
      await flushBeforeCheckpoint();
      const fakeFallback = act.targetLocation
        ? toFakePoint(act.pointLabel || act.label, act.targetLocation)
        : null;
      const gps = await getCheckpointGps(fakeFallback);

      const body: any = {
        checkpoint_type: act.type,
        occurred_at: localISO(),
      };
      if (gps) {
        body.gps_lat = gps.gps_lat;
        body.gps_lng = gps.gps_lng;
      }
      if (quickPhotos.length > 0) {
        body.photos = quickPhotos;
      }
      if (quickNote.trim().length > 0) {
        body.voice_note = quickNote.trim();
      }
      if (act.orderId) body.order_id = act.orderId;
      if (act.deliveryPointId) {
        body.delivery_point_id = act.deliveryPointId;
      } else if (act.targetLocation?.id) {
        body.new_delivery_location_id = act.targetLocation.id;
      }

      await api.trips.checkpoint(String(t.id), body, token);

      showAlert("Thành công", `Đã cập nhật: ${act.label}`);
      setQuickActionModal(null);
      setQuickPhotos([]);
      setQuickNote("");
      await refresh();
    } catch (e: any) {
      showAlert("Lỗi cập nhật", e.message || "Không thể thực hiện");
    } finally {
      setSubmittingQuick(false);
      hideLoading();
    }
  };
  const { token, shift: authShift, setShift, user } = useAuth();
  const router = useRouter();
  const [trips, setTrips] = useState<any[]>([]);
  const [refreshing, setRefreshing] = useState(false);
  const [shift, setShiftState] = useState<any>(authShift);
  const userId = user?.id || shift?.driver?.id;

  useEffect(() => {
    if (authShift) setShiftState(authShift);
  }, [authShift]);

  useEffect(() => {
    if (!token) return;
    (async () => {
      const shiftRes = await api.shifts.current(token).catch(() => null);
      if (shiftRes?.shift) {
        setShiftState(shiftRes.shift);
        setShift(shiftRes.shift);
      }
    })();
  }, [token]);

  useFocusEffect(useCallback(() => {
    if (!token) return;
    (async () => {
      const [tRes, shiftRes] = await Promise.all([
        api.trips.active(token).catch(() => ({ data: [] })),
        api.shifts.current(token).catch(() => null),
      ]);
      setTrips(tRes.data || []);
      if (shiftRes?.shift) {
        setShiftState(shiftRes.shift);
        setShift(shiftRes.shift);
      }
    })();
  }, [token]));
  const refresh = async () => {
    if (!token) return;
    const [tRes, shiftRes] = await Promise.all([
      api.trips.active(token).catch(() => ({ data: [] })),
      api.shifts.current(token).catch(() => null),
    ]);
    setTrips(tRes.data || []);
    if (shiftRes?.shift) {
      setShiftState(shiftRes.shift);
      setShift(shiftRes.shift);
    }
  };
  const onRefresh = async () => { setRefreshing(true); await refresh(); setRefreshing(false); };

  const activeTrips = trips.filter((t) => t.status !== "completed" && t.status !== "cancelled" && t.status !== "driver_swap" && t.driver_id === userId);
  // Sort: current/active trips first, then pending
  const isCurrentTrip = (t: any) => t.status !== "pending";
  const sortedTrips = [...activeTrips].sort((a, b) => (isCurrentTrip(b) ? 1 : 0) - (isCurrentTrip(a) ? 1 : 0));

  const tripsInShift: any[] = shift?.trips || [];
  const activeInShift = (trips || []).filter((t: any) => (t.shift_id === shift?.id || t.shift_id === null) && t.status !== "completed" && t.status !== "cancelled" && t.status !== "driver_swap");
  const mergedTrips = [...tripsInShift];
  activeInShift.forEach((t: any) => { if (!mergedTrips.find((m: any) => m.id === t.id)) mergedTrips.push(t); });
  const shiftAssigned = mergedTrips.filter((t: any) => t.status === "pending").length;
  const shiftInProgress = activeTrips.filter((t: any) => ["started", "arrived_pickup", "delivering", "arrived_delivery", "delivered"].includes(t.status)).length;
  const shiftCompleted = mergedTrips.filter((t: any) => t.status === "completed").length;
  const shiftTotalKm = shift?.total_km != null ? parseFloat(shift.total_km) : null;
  const shiftLoaded = shift?.total_km_loaded != null ? parseFloat(shift.total_km_loaded) : null;
  const shiftEmpty = shift?.total_km_empty != null ? parseFloat(shift.total_km_empty) : null;

  const shiftDuration = shift?.start_time ? (() => {
    const start = new Date(shift.start_time);
    const end = shift.end_time ? new Date(shift.end_time) : new Date();
    const h = Math.floor((end.getTime() - start.getTime()) / 3600000);
    const m = Math.floor(((end.getTime() - start.getTime()) % 3600000) / 60000);
    return h > 0 ? `${h}h ${m}p` : `${m}p`;
  })() : null;

  // Hiển thị km ca: từ DB nếu đã tính, nếu không tổng hợp từ trips
  const fmt = formatKm;

  return (
    <ScrollView style={st.container} refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor="#4F46E5" />}>
      <View style={st.header}>
        <View>
          <Text style={st.greeting}>Xin chào, {shift?.driver?.name || "Tài xế"} 👋</Text>
          <Text style={st.subtitle}>{shift ? "Ca đang hoạt động" : "Chưa vào ca"}</Text>
        </View>
      </View>

      {shift && !shift.end_time && (
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

      {shift && !shift.end_time && <GpsStatusPill onFix={() => router.push("/gps-permission")} />}

      {!shift && (
        <TouchableOpacity style={st.startShiftBtn} onPress={() => router.replace("/shift")} activeOpacity={0.8}>
          <Ionicons name="play-circle" size={22} color="#fff" />
          <Text style={st.startShiftText}>Vào ca</Text>
        </TouchableOpacity>
      )}

      <TouchableOpacity
        style={st.overtimeBanner}
        onPress={() => router.push("/overtime-registration")}
        activeOpacity={0.8}
      >
        <View style={st.overtimeIconWrap}>
          <Ionicons name="calendar" size={20} color="#4F46E5" />
        </View>
        <View style={{ flex: 1 }}>
          <Text style={st.overtimeTitle}>Đăng ký lịch tăng cường</Text>
          <Text style={st.overtimeSub}>Chọn ca & ngày làm việc tăng cường</Text>
        </View>
        <Ionicons name="chevron-forward" size={18} color="#9CA3AF" />
      </TouchableOpacity>

      <View style={st.statRow}>
        <View style={[st.statCard, { backgroundColor: "#F3F4F6", borderColor: "#D1D5DB" }]}>
          <Ionicons name="time-outline" size={22} color="#6B7280" />
          <Text style={[st.statVal, { color: "#6B7280" }]}>{shiftAssigned}</Text>
          <Text style={st.statLbl}>Chờ chạy</Text>
        </View>
        <View style={[st.statCard, { backgroundColor: "#EEF2FF", borderColor: "#C7D2FE" }]}>
          <Ionicons name="car-sport" size={22} color="#4F46E5" />
          <Text style={[st.statVal, { color: "#4F46E5" }]}>{shiftInProgress}</Text>
          <Text style={st.statLbl}>Đang chạy</Text>
        </View>
        <View style={[st.statCard, { backgroundColor: "#D1FAE5", borderColor: "#A7F3D0" }]}>
          <Ionicons name="checkmark-circle" size={22} color="#059669" />
          <Text style={[st.statVal, { color: "#059669" }]}>{shiftCompleted}</Text>
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
                  if (codes.length === 0 && t.route) {
                    codes.push(...t.route.split(' → '));
                  }
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
                    <Text style={[st.tripBadgeText, { color: sc.text }]}>{t.status_label ?? t.status}</Text>
                  </View>
                </View>
                <Text style={st.tripKm}>
                  📏 {t.total_km ?? "—"} km
                </Text>
                {(() => {
                  const loadingTimes = (t.orders || []).map((o: any) => o.planned_loading_at).filter(Boolean);
                  if (loadingTimes.length === 0) return null;
                  return <Text style={st.loadingTime}>🕐 Đóng hàng: {new Date(loadingTimes[0]).toLocaleString("vi-VN")}</Text>;
                })()}
                {(() => {
                  if (!isCurrent || isSwapped) return null;
                  const nextAct = resolveNextAction(t, userId);
                  if (!nextAct) return null;
                  return (
                    <View style={st.nextActionBanner}>
                      <View style={{ flex: 1, marginRight: 10 }}>
                        <View style={{ flexDirection: "row", alignItems: "center", gap: 5, marginBottom: 3 }}>
                          <View style={[st.nextActionDot, { backgroundColor: nextAct.color }]} />
                          <Text style={[st.nextActionTag, { color: nextAct.color }]}>
                            BƯỚC TIẾP THEO
                          </Text>
                        </View>
                        <Text style={st.nextActionLabel} numberOfLines={1}>
                          {nextAct.label}
                        </Text>
                      </View>
                      <TouchableOpacity
                        style={[
                          st.nextActionBtn,
                          { backgroundColor: nextAct.color },
                        ]}
                        activeOpacity={0.8}
                        onPress={(e) => {
                          e.stopPropagation?.();
                          handleOpenQuickModal(t, nextAct);
                        }}
                      >
                        <Ionicons name={nextAct.icon as any} size={13} color="#fff" />
                        <Text style={st.nextActionBtnText}>
                          Cập nhật
                        </Text>
                        <Ionicons name="camera-outline" size={13} color="rgba(255,255,255,0.9)" />
                      </TouchableOpacity>
                    </View>
                  );
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
      {/* Modal Chụp ảnh & Xác nhận nhanh mốc tại Trang chủ */}
      <Modal
        visible={!!quickActionModal}
        transparent
        animationType="fade"
        onRequestClose={() => setQuickActionModal(null)}
      >
        <KeyboardAvoidingView
          behavior={Platform.OS === "ios" ? "padding" : undefined}
          style={st.modalOverlay}
        >
          <Pressable
            style={st.modalBackdrop}
            onPress={() => setQuickActionModal(null)}
          />
          <View style={st.modalCard}>
            {/* Header */}
            <View style={st.modalHeader}>
              <View style={[st.modalHeaderIcon, { backgroundColor: quickActionModal?.action.bg || "#EEF2FF" }]}>
                <Ionicons
                  name={(quickActionModal?.action.icon as any) || "cube"}
                  size={20}
                  color={quickActionModal?.action.color || "#4F46E5"}
                />
              </View>
              <View style={{ flex: 1 }}>
                <Text style={st.modalTitle}>
                  {quickActionModal?.action.label}
                </Text>
                {quickActionModal?.action.sub ? (
                  <Text style={st.modalSubtitle} numberOfLines={1}>
                    {quickActionModal.action.sub}
                  </Text>
                ) : null}
              </View>
              <TouchableOpacity
                onPress={() => setQuickActionModal(null)}
                hitSlop={12}
                style={st.modalCloseBtn}
              >
                <Ionicons name="close" size={20} color="#64748B" />
              </TouchableOpacity>
            </View>

            {/* Photo Section */}
            <Text style={st.modalSectionLabel}>HÌNH ẢNH XÁC THỰC</Text>
            <View style={st.photoRowWrap}>
              <TouchableOpacity
                style={st.addPhotoBtn}
                activeOpacity={0.7}
                onPress={handleTakeQuickCamera}
              >
                <Ionicons name="camera" size={22} color="#4F46E5" />
                <Text style={st.addPhotoText}>Chụp ảnh</Text>
              </TouchableOpacity>

              <TouchableOpacity
                style={[st.addPhotoBtn, { backgroundColor: "#F8FAFC", borderColor: "#CBD5E1" }]}
                activeOpacity={0.7}
                onPress={handlePickQuickGallery}
              >
                <Ionicons name="images" size={22} color="#64748B" />
                <Text style={[st.addPhotoText, { color: "#64748B" }]}>Thư viện</Text>
              </TouchableOpacity>

              {quickPhotos.map((uri, idx) => (
                <View key={idx} style={st.photoThumbWrap}>
                  <Image source={{ uri }} style={st.photoThumb} />
                  <TouchableOpacity
                    style={st.photoRemoveBtn}
                    onPress={() =>
                      setQuickPhotos((prev) => prev.filter((_, i) => i !== idx))
                    }
                  >
                    <Ionicons name="close" size={12} color="#fff" />
                  </TouchableOpacity>
                </View>
              ))}
            </View>

            {/* Note Section */}
            <Text style={st.modalSectionLabel}>GHI CHÚ (KHÔNG BẮT BUỘC)</Text>
            <TextInput
              style={st.noteInput}
              placeholder="Nhập ghi chú nếu có (VD: Đã bốc xong, bảo vệ ký...)"
              placeholderTextColor="#94A3B8"
              value={quickNote}
              onChangeText={setQuickNote}
              multiline
              numberOfLines={3}
            />

            {/* Action Buttons */}
            <View style={st.modalBtnRow}>
              <TouchableOpacity
                style={st.modalCancelBtn}
                onPress={() => setQuickActionModal(null)}
                disabled={submittingQuick}
              >
                <Text style={st.modalCancelText}>Huỷ</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[
                  st.modalSubmitBtn,
                  { backgroundColor: quickActionModal?.action.color || "#4F46E5" },
                  submittingQuick && { opacity: 0.7 },
                ]}
                activeOpacity={0.85}
                onPress={handleSubmitQuickAction}
                disabled={submittingQuick}
              >
                {submittingQuick ? (
                  <ActivityIndicator size="small" color="#fff" />
                ) : (
                  <Text style={st.modalSubmitText}>
                    Xác nhận gửi
                  </Text>
                )}
              </TouchableOpacity>
            </View>
          </View>
        </KeyboardAvoidingView>
      </Modal>
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
  gpsPill: { flexDirection: "row", justifyContent: "space-between", alignItems: "center", marginHorizontal: 16, marginTop: -8, marginBottom: 16, paddingHorizontal: 14, paddingVertical: 10, borderRadius: 12 },
  gpsPillText: { fontSize: 13, fontWeight: "600" },
  startShiftBtn: { flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 8, backgroundColor: "#4F46E5", paddingVertical: 14, borderRadius: 12, marginHorizontal: 16, marginTop: 8, marginBottom:8 },
  startShiftText: { color: "#fff", fontSize: 16, fontWeight: "700" },
  nextActionBanner: {
    marginTop: 10,
    backgroundColor: "#F8FAFC",
    borderWidth: 1,
    borderColor: "#E2E8F0",
    borderRadius: 12,
    padding: 10,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
  },
  nextActionDot: {
    width: 6,
    height: 6,
    borderRadius: 3,
  },
  nextActionTag: {
    fontSize: 10,
    fontWeight: "800",
    letterSpacing: 0.6,
  },
  nextActionLabel: {
    fontSize: 13,
    fontWeight: "700",
    color: "#0F172A",
  },
  nextActionBtn: {
    flexDirection: "row",
    alignItems: "center",
    gap: 4,
    paddingHorizontal: 12,
    paddingVertical: 7,
    borderRadius: 9,
    shadowColor: "#0F172A",
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.15,
    shadowRadius: 4,
    elevation: 2,
  },
  nextActionBtnText: {
    color: "#fff",
    fontSize: 12,
    fontWeight: "700",
  },
  overtimeBanner: {
    flexDirection: "row",
    alignItems: "center",
    backgroundColor: "#fff",
    marginHorizontal: 16,
    marginBottom: 16,
    padding: 14,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: "#E5E7EB",
    gap: 12,
    shadowColor: "#000",
    shadowOpacity: 0.03,
    shadowRadius: 6,
    shadowOffset: { width: 0, height: 1 },
    elevation: 1,
  },
  overtimeIconWrap: {
    width: 40,
    height: 40,
    borderRadius: 10,
    backgroundColor: "#EEF2FF",
    alignItems: "center",
    justifyContent: "center",
  },
  overtimeTitle: {
    fontSize: 14,
    fontWeight: "700",
    color: "#1F2937",
  },
  overtimeSub: {
    fontSize: 12,
    color: "#6B7280",
    marginTop: 2,
  },
  modalBackdrop: { position: "absolute", top: 0, left: 0, right: 0, bottom: 0 },
  modalOverlay: {
    flex: 1,
    backgroundColor: "rgba(15, 23, 42, 0.5)",
    justifyContent: "center",
    alignItems: "center",
    paddingHorizontal: 16,
  },
  modalCard: {
    backgroundColor: "#FFFFFF",
    borderRadius: 20,
    padding: 20,
    width: "100%",
    maxWidth: 390,
    shadowColor: "#0F172A",
    shadowOffset: { width: 0, height: 10 },
    shadowOpacity: 0.15,
    shadowRadius: 24,
    elevation: 8,
  },
  modalHeader: {
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
    marginBottom: 16,
    paddingBottom: 12,
    borderBottomWidth: 1,
    borderBottomColor: "#F1F5F9",
  },
  modalHeaderIcon: {
    width: 40,
    height: 40,
    borderRadius: 12,
    alignItems: "center",
    justifyContent: "center",
  },
  modalTitle: {
    fontSize: 17,
    fontWeight: "800",
    color: "#0F172A",
    letterSpacing: -0.2,
  },
  modalSubtitle: {
    fontSize: 12,
    color: "#64748B",
    marginTop: 2,
  },
  modalCloseBtn: {
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: "#F1F5F9",
    alignItems: "center",
    justifyContent: "center",
  },
  modalSectionLabel: {
    fontSize: 11,
    fontWeight: "700",
    color: "#64748B",
    marginBottom: 8,
    letterSpacing: 0.5,
  },
  photoRowWrap: {
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    marginBottom: 16,
    flexWrap: "wrap",
  },
  addPhotoBtn: {
    width: 72,
    height: 72,
    borderRadius: 12,
    borderWidth: 1.5,
    borderColor: "#C7D2FE",
    borderStyle: "dashed",
    backgroundColor: "#EEF2FF",
    alignItems: "center",
    justifyContent: "center",
    gap: 4,
  },
  addPhotoText: {
    fontSize: 11,
    fontWeight: "700",
    color: "#4F46E5",
  },
  photoThumbWrap: {
    position: "relative",
  },
  photoThumb: {
    width: 72,
    height: 72,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: "#E2E8F0",
  },
  photoRemoveBtn: {
    position: "absolute",
    top: -5,
    right: -5,
    backgroundColor: "#EF4444",
    width: 20,
    height: 20,
    borderRadius: 10,
    alignItems: "center",
    justifyContent: "center",
  },
  noteInput: {
    borderRadius: 12,
    backgroundColor: "#F8FAFC",
    borderWidth: 1,
    borderColor: "#E2E8F0",
    padding: 12,
    fontSize: 14,
    color: "#0F172A",
    minHeight: 74,
    textAlignVertical: "top",
    marginBottom: 18,
  },
  modalBtnRow: {
    flexDirection: "row",
    gap: 10,
  },
  modalCancelBtn: {
    flex: 1,
    height: 46,
    borderRadius: 12,
    backgroundColor: "#F1F5F9",
    alignItems: "center",
    justifyContent: "center",
  },
  modalCancelText: {
    fontSize: 14,
    fontWeight: "600",
    color: "#475569",
  },
  modalSubmitBtn: {
    flex: 1.6,
    height: 46,
    borderRadius: 12,
    alignItems: "center",
    justifyContent: "center",
    shadowColor: "#0F172A",
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.18,
    shadowRadius: 5,
    elevation: 3,
  },
  modalSubmitText: {
    fontSize: 14,
    fontWeight: "700",
    color: "#FFFFFF",
  },
});
