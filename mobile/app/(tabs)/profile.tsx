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

  const initials = driver?.name
    ? driver.name.split(" ").pop()?.charAt(0)?.toUpperCase() || "TX"
    : "TX";

  return (
    <ScrollView style={s.container}>
      {/* Header với background + avatar */}
      <View style={s.headerBg}>
        <View style={s.header}>
          <View style={s.avatar}>
            <Text style={s.avatarText}>{initials}</Text>
          </View>
          <Text style={s.name}>{driver?.name || "Tài xế"}</Text>
          <View style={s.roleBadge}>
            <Ionicons name="car-sport" size={12} color="#4F46E5" />
            <Text style={s.roleText}>Lái xe</Text>
          </View>
          {shift && !shift.end_time && (
            <View style={s.statusBar}>
              <View style={s.statusDot} />
              <Text style={s.statusText}>Đang trong ca</Text>
            </View>
          )}
        </View>
      </View>

      {/* Thông tin cá nhân */}
      <View style={s.section}>
        <Text style={s.sectionTitle}>Thông tin cá nhân</Text>
        <View style={s.infoGrid}>
          {driver?.email && (
            <InfoCard icon="mail" color="#3B82F6" label="Email" value={driver.email} />
          )}
          {driver?.phone && (
            <InfoCard icon="call" color="#10B981" label="Số điện thoại" value={driver.phone} />
          )}
          {driver?.cccd && (
            <InfoCard icon="card" color="#8B5CF6" label="CCCD/CMND" value={driver.cccd} />
          )}
          {driver?.license_number && (
            <InfoCard icon="ribbon" color="#F59E0B" label="GPLX" value={`${driver.license_number}${driver.license_class ? ` · Hạng ${driver.license_class}` : ""}`} />
          )}
          {driver?.license_expiry_date && (
            <InfoCard icon="calendar" color="#EC4899" label="Hết hạn GPLX" value={new Date(driver.license_expiry_date).toLocaleDateString("vi-VN")} />
          )}
        </View>
      </View>

      {/* Kết thúc ca */}
      {shift && !shift.end_time && (
        <TouchableOpacity style={s.endShiftBtn} onPress={handleEndShift} disabled={ending} activeOpacity={0.8}>
          <Ionicons name="stop-circle" size={24} color="#fff" />
          <Text style={s.endShiftText}>{ending ? "Đang kết thúc..." : "Kết thúc ca làm việc"}</Text>
        </TouchableOpacity>
      )}

      {/* Menu */}
      <View style={s.menu}>
        <TouchableOpacity style={s.menuItem} onPress={() => router.push("/completed-trips")}>
          <View style={[s.menuIcon, { backgroundColor: "#EEF2FF" }]}>
            <Ionicons name="checkmark-done" size={20} color="#4F46E5" />
          </View>
          <Text style={s.menuText}>Chuyến đã hoàn thành</Text>
          <Ionicons name="chevron-forward" size={18} color="#D1D5DB" />
        </TouchableOpacity>
        <TouchableOpacity style={s.menuItem} onPress={logout}>
          <View style={[s.menuIcon, { backgroundColor: "#FEF2F2" }]}>
            <Ionicons name="log-out" size={20} color="#EF4444" />
          </View>
          <Text style={[s.menuText, { color: "#EF4444" }]}>Đăng xuất</Text>
          <Ionicons name="chevron-forward" size={18} color="#D1D5DB" />
        </TouchableOpacity>
      </View>

      <View style={{ height: 60 }} />
    </ScrollView>
  );
}

function InfoCard({ icon, color, label, value }: { icon: any; color: string; label: string; value: string }) {
  return (
    <View style={s.infoCard}>
      <View style={[s.infoIcon, { backgroundColor: color + "14" }]}>
        <Ionicons name={icon} size={16} color={color} />
      </View>
      <Text style={s.infoLabel}>{label}</Text>
      <Text style={s.infoValue} numberOfLines={1}>{value}</Text>
    </View>
  );
}

const s = StyleSheet.create({
  container: { flex: 1, backgroundColor: "#F3F4F6" },
  headerBg: { backgroundColor: "#fff", paddingBottom: 24, borderBottomLeftRadius: 24, borderBottomRightRadius: 24, shadowColor: "#000", shadowOpacity: 0.04, shadowRadius: 12, elevation: 2 },
  header: { alignItems: "center", paddingTop: 32, paddingHorizontal: 16 },
  avatar: { width: 80, height: 80, borderRadius: 40, backgroundColor: "#4F46E5", alignItems: "center", justifyContent: "center", shadowColor: "#4F46E5", shadowOpacity: 0.3, shadowRadius: 8, elevation: 4 },
  avatarText: { fontSize: 30, fontWeight: "700", color: "#fff" },
  name: { fontSize: 20, fontWeight: "700", color: "#111827", marginTop: 14 },
  roleBadge: { flexDirection: "row", alignItems: "center", gap: 4, backgroundColor: "#EEF2FF", paddingHorizontal: 10, paddingVertical: 4, borderRadius: 20, marginTop: 8 },
  roleText: { fontSize: 12, fontWeight: "600", color: "#4F46E5" },
  statusBar: { flexDirection: "row", alignItems: "center", gap: 6, marginTop: 10, backgroundColor: "#ECFDF5", paddingHorizontal: 12, paddingVertical: 6, borderRadius: 20 },
  statusDot: { width: 7, height: 7, borderRadius: 4, backgroundColor: "#10B981" },
  statusText: { fontSize: 12, fontWeight: "600", color: "#059669" },
  section: { marginTop: 20, paddingHorizontal: 16 },
  sectionTitle: { fontSize: 14, fontWeight: "700", color: "#6B7280", marginBottom: 12, textTransform: "uppercase", letterSpacing: 0.5 },
  infoGrid: { gap: 10 },
  infoCard: { backgroundColor: "#fff", borderRadius: 14, padding: 14, flexDirection: "row", alignItems: "center", gap: 12 },
  infoIcon: { width: 36, height: 36, borderRadius: 10, alignItems: "center", justifyContent: "center" },
  infoLabel: { fontSize: 12, color: "#9CA3AF", position: "absolute", top: 14, left: 62 },
  infoValue: { fontSize: 14, fontWeight: "600", color: "#111827", flex: 1, marginLeft: 0, marginTop: 14 },
  endShiftBtn: { flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 10, backgroundColor: "#EF4444", marginHorizontal: 16, marginTop: 20, padding: 16, borderRadius: 14, shadowColor: "#EF4444", shadowOpacity: 0.25, shadowRadius: 8, elevation: 3 },
  endShiftText: { color: "#fff", fontSize: 16, fontWeight: "700" },
  menu: { marginTop: 24, marginHorizontal: 16, gap: 10 },
  menuItem: { flexDirection: "row", alignItems: "center", backgroundColor: "#fff", padding: 16, borderRadius: 14, gap: 14 },
  menuIcon: { width: 38, height: 38, borderRadius: 12, alignItems: "center", justifyContent: "center" },
  menuText: { fontSize: 15, fontWeight: "600", color: "#111827", flex: 1 },
});
