import { useState } from "react";
import { useRouter } from "expo-router";
import { View, Text, ScrollView, TouchableOpacity, StyleSheet, Alert } from "react-native";
import { useAuth } from "../../src/lib/auth";
import { api } from "../../src/lib/api";
import { Ionicons } from "@expo/vector-icons";

export default function ProfileScreen() {
  const { logout, token, shift, setShift } = useAuth(); const router = useRouter();
  const [ending, setEnding] = useState(false);

  const vehicleKm = shift?.vehicle?.current_mileage;
  const shiftTrips: any[] = shift?.trips || [];
  const activeTrips = shiftTrips.filter((t: any) => t.status !== "completed" && t.status !== "driver_swap");

  const handleEndShift = () => {
    if (!shift?.id) { Alert.alert("Không có ca", "Bạn chưa vào ca"); return; }

    if (activeTrips.length > 0) {
      Alert.alert(
        "Cảnh báo",
        `${activeTrips.length} chuyến chưa kết thúc. Tiếp tục sẽ chuyển sang Đảo lái.`,
        [
          { text: "Huỷ", style: "cancel" },
          { text: "Vẫn kết thúc", style: "destructive", onPress: () => doEnd() },
        ]
      );
    } else {
      doEnd();
    }
  };

  const doEnd = async () => {
    setEnding(true);
    try {
      // Refresh shift data để lấy km xe mới nhất
      let kmToUse = shift?.vehicle?.current_mileage;
      const fresh = await api.shifts.current(token!).catch(() => null);
      if (fresh?.shift?.vehicle?.current_mileage != null) {
        kmToUse = fresh.shift.vehicle.current_mileage;
      }

      if (kmToUse != null) {
        await api.shifts.endVehicle(String(shift.id), parseInt(kmToUse), token!);
      }
      await api.shifts.end(token!);
      setShift(null);
      Alert.alert("Thành công", "Đã kết thúc ca");
      router.replace("/shift");
    } catch (e: any) { Alert.alert("Lỗi", e.message); }
    finally { setEnding(false); }
  };

  return (
    <ScrollView style={s.container}>
      <View style={s.header}><View style={s.avatar}><Text style={s.avatarText}>TX</Text></View><Text style={s.name}>Tài xế</Text><Text style={s.role}>Lái xe</Text></View>

      {shift && !shift.end_time && (
        <TouchableOpacity style={s.endShiftBtn} onPress={handleEndShift} disabled={ending} activeOpacity={0.8}>
          <Ionicons name="stop-circle" size={24} color="#fff" />
          <Text style={s.endShiftText}>{ending ? "Đang kết thúc..." : "Kết thúc ca làm việc"}</Text>
        </TouchableOpacity>
      )}

      <View style={s.menu}>
        <TouchableOpacity style={s.menuItem} onPress={() => router.push("/completed-trips")}>
          <Ionicons name="checkmark-done-outline" size={20} color="#4F46E5" /><Text style={s.menuText}>Chuyến đã hoàn thành</Text>
        </TouchableOpacity>
        <TouchableOpacity style={s.menuItem} onPress={logout}>
          <Ionicons name="log-out-outline" size={20} color="#EF4444" /><Text style={[s.menuText, { color: "#EF4444" }]}>Đăng xuất</Text>
        </TouchableOpacity>
      </View>
    </ScrollView>
  );
}

const s = StyleSheet.create({
  container: { flex: 1, backgroundColor: "#F9FAFB", padding: 16 },
  header: { alignItems: "center", paddingVertical: 24 },
  avatar: { width: 72, height: 72, borderRadius: 36, backgroundColor: "#4F46E5", alignItems: "center", justifyContent: "center", marginBottom: 12 },
  avatarText: { fontSize: 24, fontWeight: "700", color: "#fff" },
  name: { fontSize: 18, fontWeight: "600", color: "#111827" }, role: { fontSize: 14, color: "#6B7280", marginTop: 2 },
  endShiftBtn: { flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 10, backgroundColor: "#EF4444", marginTop: 12, padding: 16, borderRadius: 14 },
  endShiftText: { color: "#fff", fontSize: 16, fontWeight: "700" },
  menu: { marginTop: 20 },
  menuItem: { flexDirection: "row", alignItems: "center", backgroundColor: "#fff", padding: 16, borderRadius: 12, borderWidth: 1, borderColor: "#E5E7EB", marginBottom: 8, gap: 12 },
  menuText: { fontSize: 15, color: "#111827", flex: 1 },
});
