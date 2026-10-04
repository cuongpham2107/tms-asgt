import { View, Text, TouchableOpacity, StyleSheet, Linking, Platform } from "react-native";
import Constants from "expo-constants";
import { Ionicons } from "@expo/vector-icons";

const STORE_URL = Platform.select({
  ios: "https://apps.apple.com/app/id6810122889",
  default: "https://play.google.com/store/apps/details?id=vn.asgt.tmsdriver",
});

/** true nếu `current` < `min` (so sánh semver major.minor.patch, bỏ phần -pre). */
export function isVersionOlder(current: string, min: string): boolean {
  const parse = (v: string) => v.split("-")[0].split(".").map((n) => parseInt(n, 10) || 0);
  const a = parse(current);
  const b = parse(min);
  for (let i = 0; i < 3; i++) {
    if ((a[i] ?? 0) !== (b[i] ?? 0)) return (a[i] ?? 0) < (b[i] ?? 0);
  }
  return false;
}

export const APP_VERSION = Constants.expoConfig?.version ?? "0.0.0";

/** Màn chặn toàn màn hình khi phiên bản app thấp hơn min_app_version của server. */
export default function UpdateRequired() {
  return (
    <View style={s.container}>
      <Ionicons name="cloud-download-outline" size={64} color="#4F46E5" />
      <Text style={s.title}>Vui lòng cập nhật ứng dụng</Text>
      <Text style={s.desc}>
        Phiên bản {APP_VERSION} đã cũ. Hãy cập nhật lên phiên bản mới nhất để tiếp tục sử dụng.
      </Text>
      <TouchableOpacity style={s.btn} onPress={() => Linking.openURL(STORE_URL)} activeOpacity={0.8}>
        <Text style={s.btnText}>Cập nhật ngay</Text>
      </TouchableOpacity>
    </View>
  );
}

const s = StyleSheet.create({
  container: { position: "absolute", top: 0, left: 0, right: 0, bottom: 0, zIndex: 1000, backgroundColor: "#F9FAFB", alignItems: "center", justifyContent: "center", padding: 32 },
  title: { fontSize: 20, fontWeight: "800", color: "#111827", marginTop: 16, textAlign: "center" },
  desc: { fontSize: 14, color: "#6B7280", marginTop: 8, textAlign: "center", lineHeight: 20 },
  btn: { backgroundColor: "#4F46E5", paddingVertical: 14, paddingHorizontal: 32, borderRadius: 12, marginTop: 24 },
  btnText: { color: "#fff", fontSize: 16, fontWeight: "700" },
});
