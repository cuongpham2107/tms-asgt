import { useState, useEffect } from "react";
import { useRouter } from "expo-router";
import { View, Text, ScrollView, TouchableOpacity, StyleSheet, Linking, Modal, TextInput, KeyboardAvoidingView, Platform } from "react-native";
import { useAuth } from "../../src/lib/auth";
import { useLoading } from "../../src/lib/loading";
import { api, PRIVACY_POLICY_URL } from "../../src/lib/api";
import { showAlert, showDestructiveConfirm } from "../../src/lib/alert";
import { Ionicons } from "@expo/vector-icons";
import { flushAndStop } from "../../src/tracking/tracker";

export default function ProfileScreen() {
  const { logout, token, shift, setShift } = useAuth(); const router = useRouter();
  const { showLoading, hideLoading } = useLoading();
  const [ending, setEnding] = useState(false);
  const [localDriver, setLocalDriver] = useState<any>(shift?.driver || null);
  const [showPasswordModal, setShowPasswordModal] = useState(false);
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [passwordError, setPasswordError] = useState("");
  const [changingPassword, setChangingPassword] = useState(false);

  const handleChangePassword = async () => {
    if (!currentPassword) {
      setPasswordError("Vui lòng nhập mật khẩu hiện tại");
      return;
    }
    if (!newPassword || newPassword.length < 6) {
      setPasswordError("Mật khẩu mới phải có ít nhất 6 ký tự");
      return;
    }
    if (newPassword !== confirmPassword) {
      setPasswordError("Xác nhận mật khẩu mới không khớp");
      return;
    }

    setChangingPassword(true);
    setPasswordError("");
    showLoading();
    try {
      if (!token) throw new Error("Chưa đăng nhập");
      await api.auth.changePassword(
        {
          current_password: currentPassword,
          new_password: newPassword,
          new_password_confirmation: confirmPassword,
        },
        token,
      );
      setShowPasswordModal(false);
      setCurrentPassword("");
      setNewPassword("");
      setConfirmPassword("");
      showAlert("Thành công", "Đổi mật khẩu tài khoản thành công!");
    } catch (e: any) {
      setPasswordError(e.message || "Không thể đổi mật khẩu");
    } finally {
      setChangingPassword(false);
      hideLoading();
    }
  };

  useEffect(() => {
    if (shift?.driver) setLocalDriver(shift.driver);
  }, [shift?.driver]);

  const driver = localDriver;
  const shiftTrips: any[] = shift?.trips || [];
  const activeTrips = shiftTrips.filter((t: any) => t.status !== "completed" && t.status !== "driver_swap" && t.status !== "cancelled");

  const handleEndShift = () => {
    if (!shift?.id) { showAlert("Không có ca", "Bạn chưa vào ca"); return; }

    if (activeTrips.length > 0) {
      const codes = activeTrips.map((t: any) => t.trip_code).join(", ");
      showDestructiveConfirm(
        "Kết thúc ca?",
        `Bạn có ${activeTrips.length} chuyến đang hoạt động (${codes}). Các chuyến này sẽ tự chuyển sang đảo lái (bàn giao ca) để điều hành gán lái mới.`,
        doEnd,
      );
      return;
    }

    doEnd();
  };

  const doEnd = async () => {
    setEnding(true); showLoading();
    try {
      const res = await api.shifts.end(token!);
      await flushAndStop();
      setShift(res?.shift || shift);
      setLocalDriver(res?.shift?.driver || localDriver);
      showAlert("Thành công", "Đã kết thúc ca");
      router.replace("/");
    } catch (e: any) { showAlert("Lỗi", e.message); }
    finally { setEnding(false); hideLoading(); }
  };

  // Đăng xuất: gửi hết điểm GPS đang chờ rồi dừng ghi
  const handleLogout = async () => {
    await flushAndStop();
    logout();
  };

  const initials = driver?.name
    ? driver.name.split(" ").pop()?.charAt(0)?.toUpperCase() || "TX"
    : "TX";

  const handleDeleteAccount = () => {
    showDestructiveConfirm(
      "Yêu cầu xóa tài khoản",
      "Bạn có chắc chắn muốn yêu cầu xóa tài khoản? Khi xác nhận, phiên đăng nhập của bạn sẽ bị thu hồi ngay lập tức và ban quản trị sẽ xử lý thông tin tài khoản theo chính sách bảo mật.",
      async () => {
        showLoading();
        try {
          if (token) {
            await api.account.requestDelete(token);
          }
          await handleLogout();
          showAlert("Thành công", "Yêu cầu xóa tài khoản của bạn đã được ghi nhận.");
          router.replace("/login");
        } catch (e: any) {
          showAlert("Lỗi", e.message || "Không thể gửi yêu cầu xóa tài khoản");
        } finally {
          hideLoading();
        }
      },
      undefined,
      "Yêu cầu xóa",
    );
  };

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

      {/* Chuyến đang chạy */}
      {shift && !shift.end_time && activeTrips.length > 0 && (
        <View style={s.section}>
          <Text style={s.sectionTitle}>Chuyến đang chạy ({activeTrips.length})</Text>
          {activeTrips.map((t: any) => (
            <TouchableOpacity key={t.id} style={s.activeTripCard}
              onPress={() => router.push({
                pathname: "/trip-detail",
                params: { id: t.id, trip: JSON.stringify(t) },
              })}>
              <View style={[s.activeTripDot, { backgroundColor: t.status === "pending" ? "#6B7280" : "#F59E0B" }]} />
              <View style={{ flex: 1 }}>
                {/*<Text style={s.activeTripCode}>{t.trip_code}</Text>*/}
                <Text style={s.activeTripPlate}>{t.vehicle?.plate_number || "Chưa gán xe"}</Text>
              </View>
              <Ionicons name="chevron-forward" size={16} color="#D1D5DB" />
            </TouchableOpacity>
          ))}
        </View>
      )}

      {/* Kết thúc ca */}
      {shift && !shift.end_time && (
        <TouchableOpacity style={s.endShiftBtn} onPress={handleEndShift} disabled={ending} activeOpacity={0.8}>
          <Ionicons name="stop-circle" size={24} color="#fff" />
          <Text style={s.endShiftText}>{ending ? "Đang kết thúc..." : "Kết thúc ca làm việc"}</Text>
        </TouchableOpacity>
      )}

      {/* Vào ca */}
      {(!shift || shift.end_time) && (
        <TouchableOpacity style={s.startShiftBtn} onPress={() => router.push("/shift")} activeOpacity={0.8}>
          <Ionicons name="play-circle" size={24} color="#fff" />
          <Text style={s.startShiftText}>Vào ca</Text>
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

        <TouchableOpacity
          style={s.menuItem}
          onPress={() => Linking.openURL(PRIVACY_POLICY_URL).catch(() => showAlert("Lỗi", "Không thể mở trang Chính sách quyền riêng tư"))}
        >
          <View style={[s.menuIcon, { backgroundColor: "#F0FDF4" }]}>
            <Ionicons name="shield-checkmark" size={20} color="#16A34A" />
          </View>
          <Text style={s.menuText}>Chính sách quyền riêng tư</Text>
          <Ionicons name="open-outline" size={18} color="#9CA3AF" />
        </TouchableOpacity>

        <TouchableOpacity style={s.menuItem} onPress={() => { setPasswordError(""); setShowPasswordModal(true); }}>
          <View style={[s.menuIcon, { backgroundColor: "#FFFBEB" }]}>
            <Ionicons name="key-outline" size={20} color="#D97706" />
          </View>
          <Text style={s.menuText}>Đổi mật khẩu</Text>
          <Ionicons name="chevron-forward" size={18} color="#D1D5DB" />
        </TouchableOpacity>

        <TouchableOpacity style={s.menuItem} onPress={handleLogout}>
          <View style={[s.menuIcon, { backgroundColor: "#F3F4F6" }]}>
            <Ionicons name="log-out" size={20} color="#4B5563" />
          </View>
          <Text style={[s.menuText, { color: "#374151" }]}>Đăng xuất</Text>
          <Ionicons name="chevron-forward" size={18} color="#D1D5DB" />
        </TouchableOpacity>

        <TouchableOpacity style={s.menuItem} onPress={handleDeleteAccount}>
          <View style={[s.menuIcon, { backgroundColor: "#FEF2F2" }]}>
            <Ionicons name="trash-outline" size={20} color="#EF4444" />
          </View>
          <Text style={[s.menuText, { color: "#EF4444" }]}>Yêu cầu xóa tài khoản</Text>
          <Ionicons name="chevron-forward" size={18} color="#D1D5DB" />
        </TouchableOpacity>
      </View>

      {/* Modal Đổi mật khẩu */}
      <Modal
        visible={showPasswordModal}
        transparent
        animationType="fade"
        onRequestClose={() => setShowPasswordModal(false)}
      >
        <KeyboardAvoidingView
          behavior={Platform.OS === "ios" ? "padding" : undefined}
          style={s.modalOverlay}
        >
          <View style={s.modalContent}>
            <Text style={s.modalTitle}>Đổi mật khẩu</Text>

            {passwordError ? <Text style={s.modalError}>{passwordError}</Text> : null}

            <TextInput
              style={s.modalInput}
              placeholder="Mật khẩu hiện tại"
              placeholderTextColor="#9CA3AF"
              secureTextEntry
              value={currentPassword}
              onChangeText={setCurrentPassword}
            />

            <TextInput
              style={s.modalInput}
              placeholder="Mật khẩu mới (tối thiểu 6 ký tự)"
              placeholderTextColor="#9CA3AF"
              secureTextEntry
              value={newPassword}
              onChangeText={setNewPassword}
            />

            <TextInput
              style={s.modalInput}
              placeholder="Xác nhận mật khẩu mới"
              placeholderTextColor="#9CA3AF"
              secureTextEntry
              value={confirmPassword}
              onChangeText={setConfirmPassword}
            />

            <View style={s.modalActions}>
              <TouchableOpacity
                style={s.modalCancelBtn}
                onPress={() => setShowPasswordModal(false)}
                disabled={changingPassword}
              >
                <Text style={s.modalCancelText}>Hủy</Text>
              </TouchableOpacity>

              <TouchableOpacity
                style={[s.modalSubmitBtn, changingPassword && { opacity: 0.6 }]}
                onPress={handleChangePassword}
                disabled={changingPassword}
              >
                <Text style={s.modalSubmitText}>{changingPassword ? "Đang lưu..." : "Lưu thay đổi"}</Text>
              </TouchableOpacity>
            </View>
          </View>
        </KeyboardAvoidingView>
      </Modal>

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
  startShiftBtn: { flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 10, backgroundColor: "#10B981", marginHorizontal: 16, marginTop: 20, padding: 16, borderRadius: 14, shadowColor: "#10B981", shadowOpacity: 0.25, shadowRadius: 8, elevation: 3 },
  startShiftText: { color: "#fff", fontSize: 16, fontWeight: "700" },
  activeTripCard: { flexDirection: "row", alignItems: "center", backgroundColor: "#FFF7ED", padding: 14, borderRadius: 12, borderWidth: 1, borderColor: "#FED7AA", marginBottom: 8, gap: 12 },
  activeTripDot: { width: 10, height: 10, borderRadius: 5 },
  activeTripCode: { fontSize: 14, fontWeight: "700", color: "#111827" },
  activeTripPlate: { fontSize: 12, color: "#6B7280", marginTop: 2 },
  menu: { marginTop: 24, marginHorizontal: 16, gap: 10 },
  menuItem: { flexDirection: "row", alignItems: "center", backgroundColor: "#fff", padding: 16, borderRadius: 14, gap: 14 },
  menuIcon: { width: 38, height: 38, borderRadius: 12, alignItems: "center", justifyContent: "center" },
  menuText: { fontSize: 15, fontWeight: "600", color: "#111827", flex: 1 },
  modalOverlay: { flex: 1, backgroundColor: "rgba(0,0,0,0.5)", justifyContent: "center", padding: 20 },
  modalContent: { backgroundColor: "#fff", borderRadius: 20, padding: 20, shadowColor: "#000", shadowOpacity: 0.1, shadowRadius: 10, elevation: 5 },
  modalTitle: { fontSize: 18, fontWeight: "700", color: "#111827", marginBottom: 16, textAlign: "center" },
  modalInput: { backgroundColor: "#F3F4F6", borderRadius: 12, paddingHorizontal: 14, paddingVertical: 12, fontSize: 15, color: "#111827", marginBottom: 12, borderWidth: 1, borderColor: "#E5E7EB" },
  modalError: { color: "#EF4444", fontSize: 13, marginBottom: 12, textAlign: "center" },
  modalActions: { flexDirection: "row", gap: 10, marginTop: 4 },
  modalCancelBtn: { flex: 1, paddingVertical: 14, borderRadius: 12, backgroundColor: "#F3F4F6", alignItems: "center" },
  modalCancelText: { fontSize: 15, fontWeight: "600", color: "#6B7280" },
  modalSubmitBtn: { flex: 1, paddingVertical: 14, borderRadius: 12, backgroundColor: "#4F46E5", alignItems: "center" },
  modalSubmitText: { fontSize: 15, fontWeight: "600", color: "#fff" },
});
