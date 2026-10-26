import { useEffect, useState } from "react";
import {
  ScrollView,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from "react-native";
import {
  clearUiDebugLog,
  formatUiDebugLog,
  getUiDebugEvents,
  hydrateUiDebugLog,
  logUiEvent,
} from "../lib/ui-debug";

interface Props {
  visible: boolean;
  onClose: () => void;
}

/**
 * Panel chẩn đoán ẩn: hiện nhật ký focus/blur và sự kiện bàn phím để tài xế
 * chụp màn hình gửi lại khi không thể mô tả lỗi qua lời.
 * Mở bằng cách bấm logo màn đăng nhập 5 lần.
 */
export default function UiDebugPanel({ visible, onClose }: Props) {
  const [log, setLog] = useState("");

  useEffect(() => {
    if (!visible) return;
    let mounted = true;
    (async () => {
      await hydrateUiDebugLog();
      if (mounted) setLog(formatUiDebugLog(getUiDebugEvents()));
    })();
    return () => {
      mounted = false;
    };
  }, [visible]);

  if (!visible) return null;

  const handleClear = async () => {
    await clearUiDebugLog();
    logUiEvent("debug:cleared");
    setLog(formatUiDebugLog(getUiDebugEvents()));
  };

  return (
    <View style={s.overlay}>
      <Text style={s.title}>Nhật ký bàn phím</Text>
      <Text style={s.hint}>Chụp màn hình này gửi cho bộ phận hỗ trợ</Text>

      <ScrollView style={s.box} contentContainerStyle={s.boxContent}>
        <Text style={s.mono} selectable>
          {log || "(chưa có sự kiện nào)"}
        </Text>
      </ScrollView>

      <View style={s.actions}>
        <TouchableOpacity style={[s.btn, s.btnGhost]} onPress={handleClear}>
          <Text style={s.btnGhostText}>Xoá log</Text>
        </TouchableOpacity>
        <TouchableOpacity style={s.btn} onPress={onClose}>
          <Text style={s.btnText}>Đóng</Text>
        </TouchableOpacity>
      </View>
    </View>
  );
}

const s = StyleSheet.create({
  overlay: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: "#0B1220",
    paddingTop: 48,
    paddingHorizontal: 16,
    paddingBottom: 24,
    zIndex: 999,
  },
  title: { color: "#F9FAFB", fontSize: 16, fontWeight: "700" },
  hint: { color: "#9CA3AF", fontSize: 12, marginTop: 4, marginBottom: 12 },
  box: {
    flex: 1,
    backgroundColor: "#111827",
    borderRadius: 10,
    borderWidth: 1,
    borderColor: "#1F2937",
  },
  boxContent: { padding: 10 },
  mono: {
    color: "#E5E7EB",
    fontSize: 11,
    lineHeight: 16,
    fontFamily: "monospace",
  },
  actions: { flexDirection: "row", gap: 10, marginTop: 12 },
  btn: {
    flex: 1,
    backgroundColor: "#4F46E5",
    paddingVertical: 12,
    borderRadius: 10,
    alignItems: "center",
  },
  btnText: { color: "#fff", fontSize: 14, fontWeight: "600" },
  btnGhost: {
    backgroundColor: "transparent",
    borderWidth: 1,
    borderColor: "#374151",
  },
  btnGhostText: { color: "#D1D5DB", fontSize: 14, fontWeight: "600" },
});
