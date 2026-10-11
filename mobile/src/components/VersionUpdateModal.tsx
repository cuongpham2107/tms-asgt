import React from "react";
import {
  View,
  Text,
  Modal,
  StyleSheet,
  TouchableOpacity,
  Linking,
  ScrollView,
  Platform,
} from "react-native";
import { Ionicons } from "@expo/vector-icons";
import {
  CURRENT_APP_VERSION,
  getStoreUrl,
  type AppVersionInfo,
} from "../lib/version";

interface Props {
  visible: boolean;
  isForce: boolean;
  versionInfo: AppVersionInfo | null;
  onDismiss: () => void;
}

export default function VersionUpdateModal({
  visible,
  isForce,
  versionInfo,
  onDismiss,
}: Props) {
  if (!visible || !versionInfo) return null;

  const storeUrl = getStoreUrl(versionInfo.store_urls);

  const handleUpdatePress = async () => {
    try {
      const canOpen = await Linking.canOpenURL(storeUrl);
      if (canOpen) {
        await Linking.openURL(storeUrl);
      } else {
        await Linking.openURL(
          Platform.OS === "ios"
            ? "https://apps.apple.com"
            : "https://play.google.com"
        );
      }
    } catch {
      // Fallback
      Linking.openURL(storeUrl).catch(() => {});
    }
  };

  if (isForce) {
    // ─── Force Update: Toàn màn hình, không thể thoát ───
    return (
      <View style={s.forceContainer}>
        <View style={s.card}>
          <View style={[s.iconCircle, { backgroundColor: "#EEF2FF" }]}>
            <Ionicons name="cloud-download" size={48} color="#4F46E5" />
          </View>

          <Text style={s.forceTitle}>Yêu cầu cập nhật</Text>
          <Text style={s.badge}>
            Phiên bản hiện tại: v{CURRENT_APP_VERSION} → Mới nhất: v{versionInfo.latest_version}
          </Text>

          <Text style={s.desc}>
            Phiên bản ứng dụng đang dùng đã quá cũ và không còn tương thích với hệ thống máy chủ. Vui lòng cập nhật để tiếp tục làm việc.
          </Text>

          {versionInfo.release_notes ? (
            <View style={s.notesBox}>
              <Text style={s.notesTitle}>Nội dung cập nhật:</Text>
              <ScrollView style={{ maxHeight: 120 }}>
                <Text style={s.notesText}>{versionInfo.release_notes}</Text>
              </ScrollView>
            </View>
          ) : null}

          <TouchableOpacity
            style={s.primaryBtn}
            onPress={handleUpdatePress}
            activeOpacity={0.85}
          >
            <Ionicons name="arrow-up-circle-outline" size={20} color="#FFFFFF" style={{ marginRight: 8 }} />
            <Text style={s.primaryBtnText}>Cập nhật ngay</Text>
          </TouchableOpacity>
        </View>
      </View>
    );
  }

  // ─── Soft Update: Modal tùy chọn có nút "Để sau" ───
  return (
    <Modal
      visible={visible}
      transparent
      animationType="fade"
      onRequestClose={onDismiss}
    >
      <View style={s.backdrop}>
        <View style={s.modalCard}>
          <View style={[s.iconCircle, { backgroundColor: "#ECFDF5" }]}>
            <Ionicons name="sparkles" size={40} color="#10B981" />
          </View>

          <Text style={s.softTitle}>Đã có phiên bản mới!</Text>
          <Text style={s.softSubtitle}>
            Phiên bản v{versionInfo.latest_version} đã sẵn sàng
          </Text>

          {versionInfo.release_notes ? (
            <View style={s.notesBox}>
              <Text style={s.notesTitle}>Có gì mới?</Text>
              <ScrollView style={{ maxHeight: 100 }}>
                <Text style={s.notesText}>{versionInfo.release_notes}</Text>
              </ScrollView>
            </View>
          ) : (
            <Text style={s.desc}>
              Bản cập nhật mới giúp tăng cường độ ổn định và cải thiện trải nghiệm lái xe.
            </Text>
          )}

          <View style={s.btnRow}>
            <TouchableOpacity
              style={s.secondaryBtn}
              onPress={onDismiss}
              activeOpacity={0.7}
            >
              <Text style={s.secondaryBtnText}>Để sau</Text>
            </TouchableOpacity>

            <TouchableOpacity
              style={s.softPrimaryBtn}
              onPress={handleUpdatePress}
              activeOpacity={0.85}
            >
              <Text style={s.primaryBtnText}>Cập nhật</Text>
            </TouchableOpacity>
          </View>
        </View>
      </View>
    </Modal>
  );
}

const s = StyleSheet.create({
  // ─── Force Update styles ───
  forceContainer: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: "#F3F4F6",
    zIndex: 99999,
    justifyContent: "center",
    alignItems: "center",
    padding: 24,
  },
  card: {
    backgroundColor: "#FFFFFF",
    borderRadius: 24,
    padding: 28,
    width: "100%",
    maxWidth: 420,
    alignItems: "center",
    shadowColor: "#000",
    shadowOffset: { width: 0, height: 10 },
    shadowOpacity: 0.1,
    shadowRadius: 20,
    elevation: 8,
  },
  iconCircle: {
    width: 88,
    height: 88,
    borderRadius: 44,
    justifyContent: "center",
    alignItems: "center",
    marginBottom: 20,
  },
  forceTitle: {
    fontSize: 22,
    fontWeight: "800",
    color: "#111827",
    marginBottom: 8,
    textAlign: "center",
  },
  badge: {
    fontSize: 13,
    fontWeight: "600",
    color: "#4F46E5",
    backgroundColor: "#EEF2FF",
    paddingHorizontal: 12,
    paddingVertical: 5,
    borderRadius: 20,
    overflow: "hidden",
    marginBottom: 14,
    textAlign: "center",
  },
  desc: {
    fontSize: 14,
    color: "#4B5563",
    textAlign: "center",
    lineHeight: 21,
    marginBottom: 16,
  },
  notesBox: {
    backgroundColor: "#F9FAFB",
    borderRadius: 14,
    borderWidth: 1,
    borderColor: "#E5E7EB",
    padding: 14,
    width: "100%",
    marginBottom: 20,
  },
  notesTitle: {
    fontSize: 13,
    fontWeight: "700",
    color: "#374151",
    marginBottom: 6,
  },
  notesText: {
    fontSize: 13,
    color: "#6B7280",
    lineHeight: 19,
  },
  primaryBtn: {
    backgroundColor: "#4F46E5",
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    paddingVertical: 14,
    paddingHorizontal: 28,
    borderRadius: 14,
    width: "100%",
    shadowColor: "#4F46E5",
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.3,
    shadowRadius: 8,
    elevation: 4,
  },
  primaryBtnText: {
    color: "#FFFFFF",
    fontSize: 16,
    fontWeight: "700",
  },

  // ─── Soft Update Modal styles ───
  backdrop: {
    flex: 1,
    backgroundColor: "rgba(0, 0, 0, 0.55)",
    justifyContent: "center",
    alignItems: "center",
    padding: 24,
  },
  modalCard: {
    backgroundColor: "#FFFFFF",
    borderRadius: 24,
    padding: 24,
    width: "100%",
    maxWidth: 380,
    alignItems: "center",
    shadowColor: "#000",
    shadowOffset: { width: 0, height: 8 },
    shadowOpacity: 0.15,
    shadowRadius: 16,
    elevation: 10,
  },
  softTitle: {
    fontSize: 20,
    fontWeight: "800",
    color: "#111827",
    marginBottom: 4,
    textAlign: "center",
  },
  softSubtitle: {
    fontSize: 13,
    fontWeight: "600",
    color: "#10B981",
    marginBottom: 14,
  },
  btnRow: {
    flexDirection: "row",
    gap: 12,
    width: "100%",
    marginTop: 6,
  },
  secondaryBtn: {
    flex: 1,
    backgroundColor: "#F3F4F6",
    paddingVertical: 13,
    borderRadius: 12,
    alignItems: "center",
    justifyContent: "center",
  },
  secondaryBtnText: {
    color: "#4B5563",
    fontSize: 15,
    fontWeight: "600",
  },
  softPrimaryBtn: {
    flex: 1.2,
    backgroundColor: "#4F46E5",
    paddingVertical: 13,
    borderRadius: 12,
    alignItems: "center",
    justifyContent: "center",
    shadowColor: "#4F46E5",
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.25,
    shadowRadius: 4,
    elevation: 3,
  },
});
