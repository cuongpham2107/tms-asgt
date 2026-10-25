import { useState, useCallback } from "react";
import { View, Text, TouchableOpacity, TextInput, StyleSheet, Alert, ScrollView } from "react-native";
import { router, useFocusEffect } from "expo-router";
import { useAuth } from "../src/lib/auth";
import { useLoading } from "../src/lib/loading";
import { api } from "../src/lib/api";
import { showAlert } from "../src/lib/alert";
import { Ionicons } from "@expo/vector-icons";
import * as Location from "expo-location";
import { getFastGpsCoordinates } from "../src/lib/location";

const shiftOptions = [
  { key: "full", label: "Cả ca (X)", desc: "Làm việc toàn thời gian" },
  { key: "morning_half", label: "Nửa ca ngày (X/2)", desc: "Buổi sáng" },
  { key: "night_half", label: "Nửa ca đêm (Y/2)", desc: "Buổi tối" },
];

const INCOMPLETE_ORDER_STATUSES = ["sent", "in_transit", "assigned"];

export default function ShiftScreen() {
  const { token, setAuth, shift, setShift } = useAuth();
  const { showLoading, hideLoading } = useLoading();
  const [loading, setLoading] = useState(false);
  const [showEnd, setShowEnd] = useState(false);
  const [endKm, setEndKm] = useState("");
  const [ending, setEnding] = useState(false);
  const [activeWarning, setActiveWarning] = useState<string | null>(null);
  const [activeWarningTripId, setActiveWarningTripId] = useState<string | number | null>(null);

  useFocusEffect(useCallback(() => {
    if (!token || !shift) return;
    api.shifts.current(token).then((res) => {
      const nextShift = res?.shift ?? null;
      // Ca đã kết thúc (kể cả bị auto-end 08:00/20:00) → clear state cũ của app
      if (!nextShift || nextShift.end_time) {
        setShift(null);
        setShowEnd(false);
        return;
      }
      setShift(nextShift);
      setShowEnd(true);
      const vKm = nextShift.vehicle?.current_mileage;
      if (vKm != null && !endKm) setEndKm(String(Math.round(Number(vKm))));
    }).catch(() => {});
  }, [token]));

  useFocusEffect(useCallback(() => {
    if (!token) return;
    api.trips.active(token).then((res) => {
      const trips = res?.data;
      if (!trips || !Array.isArray(trips) || trips.length === 0) {
        setActiveWarning(null);
        setActiveWarningTripId(null);
        return;
      }
      const incompleteOrders: { tripId: number | string; tripCode: string; count: number }[] = [];
      trips.forEach((trip: any) => {
        const cnt = (trip.orders || []).filter((o: any) =>
          INCOMPLETE_ORDER_STATUSES.includes(o.status)
        ).length;
        if (cnt > 0) incompleteOrders.push({ tripId: trip.id, tripCode: trip.trip_code, count: cnt });
      });
      if (incompleteOrders.length > 0) {
        setActiveWarningTripId(incompleteOrders[0].tripId);
        const lines = incompleteOrders
          .map((item) => `${item.tripCode}: ${item.count} đơn chưa xong`)
          .join(", ");
        setActiveWarning(`${trips.length} chuyến đang hoạt động — ${lines}`);
      } else {
        setActiveWarning(null);
        setActiveWarningTripId(null);
      }
    }).catch(() => {
      setActiveWarning(null);
      setActiveWarningTripId(null);
    });
  }, [token]));

  async function startShift(type: string) {
    setLoading(true); showLoading();
    try {
      let gps = null;
      try {
        const coords = await getFastGpsCoordinates();
        if (coords) {
          gps = { start_gps_lat: coords.gps_lat, start_gps_lng: coords.gps_lng };
        }
      } catch (e) {
        // Không lấy được GPS: vẫn cho vào ca, chỉ ghi log để debug
        console.warn("[shift] Không lấy được GPS khi vào ca:", e);
      }
      const res = await api.shifts.start({ shift_type: type, ...(gps || {}) }, token!);
      if (res?.shift?.id) {
        setAuth(token!, String(res.shift.id), res.shift);
        router.replace("/");
      }
    } catch (e: any) {
      Alert.alert("Lỗi", e.message);
    } finally {
      setLoading(false);
      hideLoading();
    }
  }

  async function handleEndShift() {
    const km = parseFloat(endKm);
    if (!km || km <= 0) { Alert.alert("Thiếu", "Nhập số Km kết thúc"); return; }
    if (!shift?.id) return;

    setEnding(true); showLoading();
    try {
      await api.shifts.endVehicle(String(shift.id), km, token!);
      const res = await api.shifts.end(token!);
      setShift(res?.shift || null);
      setShowEnd(false);
      Alert.alert("Thành công", "Đã kết thúc ca");
    } catch (e: any) {
      const msg = e.message || "Không thể kết thúc ca";
      const title = msg.includes("chưa hoàn thiện chuyến") ? "Không thể kết thúc ca" : "Lỗi";
      let targetTripId: string | number | undefined = e.active_trip_id || activeWarningTripId || undefined;
      if (!targetTripId && msg.includes("chưa hoàn thiện chuyến")) {
        try {
          const activeRes = await api.trips.active(token!);
          const data: any = activeRes?.data;
          targetTripId = Array.isArray(data) ? data[0]?.id : data?.id;
        } catch (e) {
          // Không tìm được chuyến đang chạy để điều hướng: chỉ ghi log
          console.warn("[shift] Không lấy được chuyến đang chạy:", e);
        }
      }
      showAlert(title, msg, () => {
        if (targetTripId) {
          router.push({
            pathname: "/trip-detail",
            params: { id: String(targetTripId) },
          });
        }
      });
    } finally {
      setEnding(false);
      hideLoading();
    }
  }

  return (
    <View style={s.container}>
      {!showEnd ? (
        <>
          <Text style={s.title}>Bắt đầu ca làm việc</Text>
          <Text style={s.subtitle}>Chọn loại ca để bắt đầu</Text>
          {shiftOptions.map((opt) => (
            <TouchableOpacity key={opt.key} style={s.card} onPress={() => startShift(opt.key)} disabled={loading}>
              <Text style={s.cardTitle}>{opt.label}</Text>
              <Text style={s.cardDesc}>{opt.desc}</Text>
            </TouchableOpacity>
          ))}
          {loading && <Text style={s.loading}>Đang tạo ca...</Text>}
        </>
      ) : (
        <>
          <Text style={s.title}>Kết thúc ca làm việc</Text>
          <Text style={s.subtitle}>Ca đang hoạt động — nhập Km đồng hồ để kết thúc</Text>

          {activeWarning && (
            <TouchableOpacity
              style={s.warnBox}
              activeOpacity={0.8}
              onPress={() => {
                if (activeWarningTripId) {
                  router.push({
                    pathname: "/trip-detail",
                    params: { id: String(activeWarningTripId) },
                  });
                }
              }}
            >
              <View style={s.warnHeader}>
                <Ionicons name="information-circle" size={18} color="#2563EB" />
                <Text style={s.warnTitle}>Chú ý</Text>
                <Ionicons name="chevron-forward" size={16} color="#2563EB" style={{ marginLeft: "auto" }} />
              </View>
              <Text style={s.warnText}>{activeWarning}</Text>
              <Text style={s.warnHint}>Nhấn vào đây để xem chi tiết chuyến dở dang</Text>
            </TouchableOpacity>
          )}

          <View style={s.endCard}>
            <Text style={s.endLabel}>Km đồng hồ hiện tại</Text>
            <TextInput
              style={s.endInput}
              placeholder="Nhập số Km đồng hồ"
              placeholderTextColor="#D1D5DB"
              keyboardType="numeric"
              value={endKm}
              onChangeText={setEndKm}
            />
          </View>
          <TouchableOpacity style={[s.endBtn, ending && s.btnDisabled]} onPress={handleEndShift} disabled={ending}>
            <Text style={s.endBtnText}>{ending ? "Đang xử lý..." : "Kết thúc ca"}</Text>
          </TouchableOpacity>
        </>
      )}
    </View>
  );
}

const s = StyleSheet.create({
  container: { flex: 1, padding: 24, backgroundColor: "#F9FAFB" },
  title: { fontSize: 22, fontWeight: "700", color: "#111827", marginBottom: 4 },
  subtitle: { fontSize: 14, color: "#6B7280", marginBottom: 24 },
  card: { backgroundColor: "#fff", padding: 20, borderRadius: 12, borderWidth: 1, borderColor: "#E5E7EB", marginBottom: 12 },
  cardTitle: { fontSize: 18, fontWeight: "600", color: "#4F46E5" },
  cardDesc: { fontSize: 14, color: "#6B7280", marginTop: 4 },
  loading: { textAlign: "center", color: "#6B7280", marginTop: 12 },
  endCard: { backgroundColor: "#fff", padding: 20, borderRadius: 12, borderWidth: 1, borderColor: "#E5E7EB", marginBottom: 16 },
  endLabel: { fontSize: 14, fontWeight: "600", color: "#111827", marginBottom: 10 },
  endInput: { backgroundColor: "#F9FAFB", padding: 14, borderRadius: 10, borderWidth: 1, borderColor: "#E5E7EB", fontSize: 18, color: "#111827" },
  endBtn: { backgroundColor: "#EF4444", padding: 16, borderRadius: 12, alignItems: "center" },
  endBtnText: { color: "#fff", fontSize: 16, fontWeight: "600" },
  btnDisabled: { opacity: 0.6 },
  warnBox: { backgroundColor: "#EFF6FF", borderWidth: 1, borderColor: "#93C5FD", borderRadius: 12, padding: 14, marginBottom: 16 },
  warnHeader: { flexDirection: "row", alignItems: "center", gap: 8, marginBottom: 6 },
  warnTitle: { fontSize: 15, fontWeight: "700", color: "#1E40AF" },
  warnText: { fontSize: 13, color: "#1E3A8A", lineHeight: 20 },
  warnHint: { fontSize: 12, color: "#2563EB", fontWeight: "600", marginTop: 6 },
});
