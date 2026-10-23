import React from "react";
import { View, Text, Modal, StyleSheet, TouchableOpacity } from "react-native";
import { Ionicons } from "@expo/vector-icons";
import type { GeofenceError } from "../lib/api";

interface Props {
  error: GeofenceError | null;
  onClose: () => void;
  onRetry: () => void;
}

function formatDistance(meters: number | null): string {
  if (meters == null) return "—";
  return meters >= 1000
    ? `${(meters / 1000).toLocaleString("vi-VN", { maximumFractionDigits: 1 })} km`
    : `${meters} m`;
}

export default function GeofenceBlockedModal({ error, onClose, onRetry }: Props) {
  if (!error) return null;

  const isLocation = error.layer === "location";
  const title = isLocation ? "Xe chưa đến điểm" : "Bạn đang không ở cạnh xe";
  const subject = isLocation ? "Xe đang cách" : "Điện thoại đang cách xe";
  const hint = isLocation
    ? "Vui lòng gửi khi xe đã vào khu vực điểm đến."
    : "Vui lòng gửi khi bạn đang ở cạnh xe.";

  return (
    <Modal visible transparent animationType="fade" onRequestClose={onClose}>
      <View style={s.backdrop}>
        <View style={s.card}>
          <View style={s.iconCircle}>
            <Ionicons name={isLocation ? "location" : "car"} size={36} color="#EA580C" />
          </View>

          <Text style={s.title}>{title}</Text>

          <View style={s.distanceBox}>
            <Text style={s.distanceLabel}>
              {subject}
              {isLocation && error.target_code ? (
                <Text style={s.code}> {error.target_code}</Text>
              ) : null}
            </Text>
            <Text style={s.distanceValue}>{formatDistance(error.distance_m)}</Text>
            <Text style={s.allowed}>Cho phép tối đa {formatDistance(error.radius_m)}</Text>
          </View>

          <Text style={s.hint}>{hint}</Text>

          <View style={s.tipBox}>
            <Ionicons name="information-circle-outline" size={16} color="#6B7280" style={{ marginTop: 1 }} />
            <Text style={s.tipText}>
              Nếu đã ở đúng chỗ, vị trí có thể chưa cập nhật kịp. Đợi khoảng 1 phút rồi thử lại, hoặc liên hệ Điều hành.
            </Text>
          </View>

          <View style={s.btnRow}>
            <TouchableOpacity style={s.secondaryBtn} onPress={onClose} activeOpacity={0.7}>
              <Text style={s.secondaryBtnText}>Đóng</Text>
            </TouchableOpacity>
            <TouchableOpacity style={s.primaryBtn} onPress={onRetry} activeOpacity={0.85}>
              <Ionicons name="refresh" size={18} color="#FFFFFF" style={{ marginRight: 6 }} />
              <Text style={s.primaryBtnText}>Thử lại</Text>
            </TouchableOpacity>
          </View>
        </View>
      </View>
    </Modal>
  );
}

const s = StyleSheet.create({
  backdrop: {
    flex: 1,
    backgroundColor: "rgba(17, 24, 39, 0.55)",
    justifyContent: "center",
    alignItems: "center",
    paddingHorizontal: 24,
  },
  card: {
    width: "100%",
    maxWidth: 380,
    backgroundColor: "#FFFFFF",
    borderRadius: 20,
    padding: 24,
    alignItems: "center",
    shadowColor: "#000",
    shadowOpacity: 0.2,
    shadowRadius: 24,
    shadowOffset: { width: 0, height: 8 },
    elevation: 12,
  },
  iconCircle: {
    width: 72,
    height: 72,
    borderRadius: 36,
    backgroundColor: "#FFF7ED",
    alignItems: "center",
    justifyContent: "center",
    marginBottom: 14,
  },
  title: { fontSize: 19, fontWeight: "700", color: "#111827", textAlign: "center" },
  distanceBox: {
    width: "100%",
    marginTop: 16,
    paddingVertical: 14,
    paddingHorizontal: 16,
    borderRadius: 14,
    backgroundColor: "#FFF7ED",
    borderWidth: 1,
    borderColor: "#FED7AA",
    alignItems: "center",
  },
  distanceLabel: { fontSize: 13, color: "#9A3412" },
  code: { fontWeight: "700", color: "#9A3412" },
  distanceValue: { fontSize: 32, fontWeight: "800", color: "#EA580C", marginTop: 2 },
  allowed: { fontSize: 12, color: "#C2410C", marginTop: 2 },
  hint: { fontSize: 14, color: "#374151", textAlign: "center", marginTop: 14, lineHeight: 20 },
  tipBox: {
    flexDirection: "row",
    gap: 6,
    width: "100%",
    marginTop: 12,
    padding: 10,
    borderRadius: 10,
    backgroundColor: "#F9FAFB",
  },
  tipText: { flex: 1, fontSize: 12, color: "#6B7280", lineHeight: 17 },
  btnRow: { flexDirection: "row", gap: 10, width: "100%", marginTop: 20 },
  secondaryBtn: {
    flex: 1,
    height: 48,
    borderRadius: 12,
    backgroundColor: "#F3F4F6",
    alignItems: "center",
    justifyContent: "center",
  },
  secondaryBtnText: { fontSize: 15, fontWeight: "600", color: "#374151" },
  primaryBtn: {
    flex: 1.4,
    height: 48,
    borderRadius: 12,
    backgroundColor: "#EA580C",
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
  },
  primaryBtnText: { fontSize: 15, fontWeight: "700", color: "#FFFFFF" },
});
