import { useState } from "react";
import { useRouter } from "expo-router";
import { View, Text, ScrollView, TouchableOpacity, StyleSheet } from "react-native";
import { useAuth } from "../../src/lib/auth";
import { api } from "../../src/lib/api";
import { showAlert, showDestructiveConfirm } from "../../src/lib/alert";
import { Ionicons } from "@expo/vector-icons";

export default function ProfileScreen() {
  const { logout, token, shift, setShift } = useAuth(); const router = useRouter();
  const [ending, setEnding] = useState(false);

  const driver = shift?.driver;
  const vehicleKm = shift?.vehicle?.current_mileage;
  const shiftTrips: any[] = shift?.trips || [];
  const activeTrips = shiftTrips.filter((t: any) => t.status !== "completed" && t.status !== "driver_swap");

  const handleEndShift = () => {
    if (!shift?.id) { showAlert("Không có ca", "Bạn chưa vào ca"); return; }

    if (activeTrips.length > 0) {
      showDestructiveConfirm(
        "Cảnh báo",
        `${activeTrips.length} chuyến chưa kết thúc. Tiếp tục sẽ chuyển sang Đảo lái.`,
        () => doEnd(),
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
      showAlert("Thành công", "Đã kết thúc ca");
      router.replace("/shift");
    } catch (e: any) { showAlert("Lỗi", e.message); }
    finally { setEnding(false); }
  };

  const initials = driver?.name ? driver.name.split(" ").pop()?.charAt(0)?.toUpperCase() || "TX" : "TX";

  return (
    <ScrollView style={s.container}>
      <View style={s.header}>
        <View style={s.avatar}><Text style={s.avatarText}>{initials}</Text></View>
        <Text style={s.name}>{driver?.name || "Tài xế"}</Text>
        <Text style={s.role}>{driver?.roles?.includes("driver") ? "Lái xe" : "Nhân viên"}</Text>
      </View>

      {/* Thông tin cơ bản */}
      <View style={s.infoSection}>
        <Text style={s.sectionTitle}>Thông tin cá nhân</Text>
        {driver?.email ? <InfoRow icon="mail-outline" label="Email" value={driver.email} /> : null}
        {driver?.phone ? <InfoRow icon="call-outline" label="SĐT" value={driver.phone} /> : null}
        {driver?.cccd ? <InfoRow icon="card-outline" label="CCCD" value={driver.cccd} /> : null}
        {driver?.license_number ? <InfoRow icon="id-card-outline" label="GPLX" value={`${driver.license_number}${driver.license_class ? ` (${driver.license_class})` : ""}`} /> : null}
        {driver?.license_expiry_date ? <InfoRow icon="calendar-outline" label="GPLX hết hạn" value={new Date(driver.license_expiry_date).toLocaleDateString("vi-VN")} /> : null}
      </View>

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

function InfoRow({ icon, label, value }: { icon: any; label: string; value: string }) {
  return (
    <View style={s.infoRow}>
      <Ionicons name={icon} size={18} color="#6B7280" />
      <Text style={s.infoLabel}>{label}</Text>
      <Text style={s.infoValue}>{value}</Text>
    </View>
  );
}

const s = StyleSheet.create({
  container: { flex: 1, backgroundColor: "#F9FAFB", padding: 16 },
  header: { alignItems: "center", paddingVertical: 24 },
  avatar: { width: 72, height: 72, borderRadius: 36, backgroundColor: "#4F46E5", alignItems: "center", justifyContent: "center", marginBottom: 12 },
  avatarText: { fontSize: 24, fontWeight: "700", color: "#fff" },
  name: { fontSize: 18, fontWeight: "600", color: "#111827" },
  role: { fontSize: 14, color: "#6B7280", marginTop: 2 },
  infoSection: { backgroundColor: "#fff", borderRadius: 14, padding: 16, marginTop: 12, borderWidth: 1, borderColor: "#E5E7EB" },
  sectionTitle: { fontSize: 14, fontWeight: "700", color: "#374151", marginBottom: 12 },
  infoRow: { flexDirection: "row", alignItems: "center", paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: "#F3F4F6", gap: 10 },
  infoLabel: { fontSize: 13, color: "#9CA3AF", width: 80 },
  infoValue: { fontSize: 13, fontWeight: "600", color: "#111827", flex: 1 },
  endShiftBtn: { flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 10, backgroundColor: "#EF4444", marginTop: 12, padding: 16, borderRadius: 14 },
  endShiftText: { color: "#fff", fontSize: 16, fontWeight: "700" },
  menu: { marginTop: 20 },
  menuItem: { flexDirection: "row", alignItems: "center", backgroundColor: "#fff", padding: 16, borderRadius: 12, borderWidth: 1, borderColor: "#E5E7EB", marginBottom: 8, gap: 12 },
  menuText: { fontSize: 15, color: "#111827", flex: 1 },
});
