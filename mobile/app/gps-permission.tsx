import { useCallback, useEffect, useState } from "react";
import { View, Text, ScrollView, TouchableOpacity, StyleSheet, Linking, AppState, Platform } from "react-native";
import { router } from "expo-router";
import { Ionicons } from "@expo/vector-icons";
import * as Location from "expo-location";
import { hasAlwaysPermission } from "../src/tracking/tracker";

const BATTERY_GUIDES: { brand: string; steps: string }[] = [
  {
    brand: "Xiaomi / Redmi / POCO",
    steps: "Cài đặt → Ứng dụng → Quản lý ứng dụng → ASGT Vận Tải → Tiết kiệm pin → chọn \"Không hạn chế\". Bật thêm \"Tự khởi động\". Trong đa nhiệm, giữ ứng dụng và bấm biểu tượng ổ khoá.",
  },
  {
    brand: "Oppo / Realme",
    steps: "Cài đặt → Pin → Quản lý tiêu thụ điện ứng dụng → ASGT Vận Tải → bật \"Cho phép hoạt động nền\" và \"Cho phép tự khởi động\". Khoá ứng dụng trong đa nhiệm.",
  },
  {
    brand: "Vivo",
    steps: "Cài đặt → Pin → Tiêu thụ điện nền cao → bật cho ASGT Vận Tải. Cài đặt → Ứng dụng → Tự khởi động → bật ASGT Vận Tải.",
  },
  {
    brand: "Samsung",
    steps: "Cài đặt → Ứng dụng → ASGT Vận Tải → Pin → chọn \"Không giới hạn\". Cài đặt → Pin → Giới hạn sử dụng nền → bỏ ASGT Vận Tải khỏi \"Ứng dụng ngủ\" / \"Ứng dụng ngủ sâu\".",
  },
];

/** Hướng dẫn cấp quyền vị trí "Luôn luôn" + tắt tối ưu pin. Vào ca bị chặn tới khi có quyền. */
export default function GpsPermissionScreen() {
  const [granted, setGranted] = useState(false);
  const [askedOnce, setAskedOnce] = useState(false);

  const recheck = useCallback(() => {
    hasAlwaysPermission().then(setGranted).catch(() => setGranted(false));
  }, []);

  useEffect(() => {
    recheck();
    const sub = AppState.addEventListener("change", (state) => {
      if (state === "active") recheck();
    });
    return () => sub.remove();
  }, [recheck]);

  const requestPermission = async () => {
    setAskedOnce(true);
    const fg = await Location.requestForegroundPermissionsAsync();
    if (fg.granted) {
      // Android 11+ sẽ mở trang cài đặt; iOS hỏi nâng cấp lên "Luôn luôn"
      await Location.requestBackgroundPermissionsAsync().catch(() => null);
    }
    recheck();
  };

  return (
    <ScrollView style={s.container} contentContainerStyle={{ padding: 20, paddingBottom: 40 }}>
      <View style={[s.statusCard, granted ? s.statusOk : s.statusBad]}>
        <Ionicons name={granted ? "checkmark-circle" : "location-outline"} size={28} color={granted ? "#059669" : "#DC2626"} />
        <View style={{ flex: 1 }}>
          <Text style={s.statusTitle}>{granted ? "Đã cấp quyền vị trí \"Luôn luôn\"" : "Chưa cấp quyền vị trí \"Luôn luôn\""}</Text>
          <Text style={s.statusDesc}>
            Ứng dụng ghi hành trình suốt ca (kể cả khi tắt màn hình) để tính km chuyến. Bạn cần cấp quyền trước khi vào ca.
          </Text>
        </View>
      </View>

      <Text style={s.sectionTitle}>1. Cấp quyền vị trí</Text>
      <View style={s.card}>
        {Platform.OS === "ios" ? (
          <Text style={s.text}>Bấm nút bên dưới → chọn "Cho phép khi dùng ứng dụng" → khi được hỏi tiếp, chọn "Đổi thành Luôn cho phép". Nếu không thấy hỏi: Cài đặt → ASGT Vận Tải → Vị trí → chọn "Luôn luôn" và bật "Vị trí chính xác".</Text>
        ) : (
          <Text style={s.text}>Bấm nút bên dưới → cho phép vị trí → ở trang cài đặt mở ra, chọn "Luôn cho phép" (Allow all the time) và bật "Dùng vị trí chính xác".</Text>
        )}
        {!granted && (
          <TouchableOpacity style={s.primaryBtn} onPress={requestPermission} activeOpacity={0.8}>
            <Ionicons name="location" size={18} color="#fff" />
            <Text style={s.primaryBtnText}>Cấp quyền vị trí</Text>
          </TouchableOpacity>
        )}
        {!granted && askedOnce && (
          <TouchableOpacity style={s.secondaryBtn} onPress={() => Linking.openSettings()} activeOpacity={0.8}>
            <Ionicons name="settings-outline" size={18} color="#4F46E5" />
            <Text style={s.secondaryBtnText}>Mở cài đặt ứng dụng</Text>
          </TouchableOpacity>
        )}
      </View>

      {Platform.OS === "android" && (
        <>
          <Text style={s.sectionTitle}>2. Tắt tối ưu pin</Text>
          <View style={s.card}>
            <Text style={s.text}>Một số dòng máy tự tắt ứng dụng chạy nền làm mất hành trình. Làm theo hướng dẫn cho máy của bạn:</Text>
            {BATTERY_GUIDES.map((g) => (
              <View key={g.brand} style={s.guide}>
                <Text style={s.guideBrand}>{g.brand}</Text>
                <Text style={s.text}>{g.steps}</Text>
              </View>
            ))}
            <TouchableOpacity style={s.secondaryBtn} onPress={() => Linking.openSettings()} activeOpacity={0.8}>
              <Ionicons name="battery-charging-outline" size={18} color="#4F46E5" />
              <Text style={s.secondaryBtnText}>Mở cài đặt ứng dụng</Text>
            </TouchableOpacity>
          </View>
        </>
      )}

      <TouchableOpacity
        style={[s.primaryBtn, { marginTop: 8 }, !granted && s.btnDisabled]}
        onPress={() => router.back()}
        disabled={!granted}
        activeOpacity={0.8}
      >
        <Text style={s.primaryBtnText}>{granted ? "Tiếp tục vào ca" : "Cần cấp quyền để tiếp tục"}</Text>
      </TouchableOpacity>
    </ScrollView>
  );
}

const s = StyleSheet.create({
  container: { flex: 1, backgroundColor: "#F9FAFB" },
  statusCard: { flexDirection: "row", gap: 12, padding: 16, borderRadius: 14, borderWidth: 1, marginBottom: 20 },
  statusOk: { backgroundColor: "#ECFDF5", borderColor: "#A7F3D0" },
  statusBad: { backgroundColor: "#FEF2F2", borderColor: "#FECACA" },
  statusTitle: { fontSize: 15, fontWeight: "700", color: "#111827" },
  statusDesc: { fontSize: 13, color: "#4B5563", marginTop: 4, lineHeight: 19 },
  sectionTitle: { fontSize: 16, fontWeight: "700", color: "#111827", marginBottom: 8 },
  card: { backgroundColor: "#fff", padding: 16, borderRadius: 14, borderWidth: 1, borderColor: "#E5E7EB", marginBottom: 20, gap: 10 },
  text: { fontSize: 13, color: "#374151", lineHeight: 20 },
  guide: { borderTopWidth: 1, borderTopColor: "#F3F4F6", paddingTop: 10 },
  guideBrand: { fontSize: 14, fontWeight: "700", color: "#4F46E5", marginBottom: 2 },
  primaryBtn: { flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 8, backgroundColor: "#4F46E5", paddingVertical: 14, borderRadius: 12 },
  primaryBtnText: { color: "#fff", fontSize: 15, fontWeight: "700" },
  secondaryBtn: { flexDirection: "row", alignItems: "center", justifyContent: "center", gap: 8, backgroundColor: "#EEF2FF", paddingVertical: 12, borderRadius: 12, borderWidth: 1, borderColor: "#C7D2FE" },
  secondaryBtnText: { color: "#4F46E5", fontSize: 14, fontWeight: "600" },
  btnDisabled: { opacity: 0.5 },
});
