import { useState, useCallback } from "react";
import { View, Text, TouchableOpacity, TextInput, StyleSheet, Alert, ScrollView } from "react-native";
import { router, useFocusEffect } from "expo-router";
import { useAuth } from "../src/lib/auth";
import { api } from "../src/lib/api";
import { Ionicons } from "@expo/vector-icons";
import * as Location from "expo-location";

const shiftOptions = [
  { key: "full", label: "Cả ca (X)", desc: "Làm việc toàn thời gian" },
  { key: "morning_half", label: "Nửa ca ngày (X/2)", desc: "Buổi sáng" },
  { key: "night_half", label: "Nửa ca đêm (Y/2)", desc: "Buổi tối" },
];

const INCOMPLETE_ORDER_STATUSES = ["sent", "in_transit", "assigned"];

export default function ShiftScreen() {
  const { token, setAuth, shift, setShift } = useAuth();
  const [loading, setLoading] = useState(false);
  const [showEnd, setShowEnd] = useState(false);
  const [endKm, setEndKm] = useState("");
  const [ending, setEnding] = useState(false);
  const [activeWarning, setActiveWarning] = useState<string | null>(null);

  useFocusEffect(useCallback(() => {
    if (!token || !shift) return;
    api.shifts.current(token).then((res) => {
      if (res?.shift && !res.shift.end_time) {
        setShift(res.shift);
        setShowEnd(true);
        const vKm = res.shift.vehicle?.current_mileage;
        if (vKm != null && !endKm) setEndKm(String(parseInt(vKm)));
      }
    }).catch(() => {});
  }, [token]));

  useFocusEffect(useCallback(() => {
    if (!token) return;
    api.trips.active(token).then((res) => {
      const trips = res?.data;
      if (!trips || !Array.isArray(trips) || trips.length === 0) {
        setActiveWarning(null);
        return;
      }
      const incompleteOrders: { tripCode: string; count: number }[] = [];
      trips.forEach((trip: any) => {
        const cnt = (trip.orders || []).filter((o: any) =>
          INCOMPLETE_ORDER_STATUSES.includes(o.status)
        ).length;
        if (cnt > 0) incompleteOrders.push({ tripCode: trip.trip_code, count: cnt });
      });
      if (incompleteOrders.length > 0) {
        const lines = incompleteOrders.map(
          (item) => `  • ${item.tripCode}: ${item.count} đơn chưa xong`
        ).join("\n");
        setActiveWarning(
          `Bạn có ${trips.length} chuyến đang hoạt động với đơn hàng chưa hoàn thành:\n${lines}\n\nVui lòng hoàn thành tất cả đơn hàng trước khi kết thúc ca.`
        );
      } else {
        setActiveWarning(null);
      }
    }).catch(() => setActiveWarning(null));
  }, [token]));

  async function startShift(type: string) {
    setLoading(true);
    try {
      let gps = null;
      try {
        const { status } = await Location.requestForegroundPermissionsAsync();
        if (status === "granted") {
          const pos = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
          gps = { start_gps_lat: pos.coords.latitude, start_gps_lng: pos.coords.longitude };
        }
      } catch {}
      const res = await api.shifts.start({ shift_type: type, ...(gps || {}) }, token!);
      if (res?.shift?.id) {
        setAuth(token!, String(res.shift.id), res.shift);
        router.replace("/");
      }
    } catch (e: any) {
      Alert.alert("Lỗi", e.message);
    } finally {
      setLoading(false);
    }
  }

  async function handleEndShift() {
    const km = parseFloat(endKm);
    if (!km || km <= 0) { Alert.alert("Thiếu", "Nhập số Km kết thúc"); return; }
    if (!shift?.id) return;

    // Check for active trips first
    if (activeWarning) {
      Alert.alert("Chưa thể kết thúc ca", activeWarning, [{ text: "OK" }]);
      return;
    }

    setEnding(true);
    try {
      await api.shifts.endVehicle(String(shift.id), km, token!);
      const res = await api.shifts.end(token!);
      setShift(res?.shift || null);
      setShowEnd(false);
      Alert.alert("Thành công", "Đã kết thúc ca");
    } catch (e: any) {
      Alert.alert("Lỗi", e.message);
    } finally {
      setEnding(false);
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
            <View style={s.warnBox}>
              <View style={s.warnHeader}>
                <Ionicons name="warning" size={18} color="#D97706" />
                <Text style={s.warnTitle}>Có chuyến đang hoạt động</Text>
              </View>
              <Text style={s.warnText}>{activeWarning}</Text>
            </View>
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
  warnBox: { backgroundColor: "#FFFBEB", borderWidth: 1, borderColor: "#FCD34D", borderRadius: 12, padding: 14, marginBottom: 16 },
  warnHeader: { flexDirection: "row", alignItems: "center", gap: 8, marginBottom: 8 },
  warnTitle: { fontSize: 15, fontWeight: "700", color: "#92400E" },
  warnText: { fontSize: 13, color: "#78350F", lineHeight: 20 },
});
