import { Modal, View, ActivityIndicator, StyleSheet } from "react-native";

interface LoadingOverlayProps {
  visible: boolean;
}

export default function LoadingOverlay({ visible }: LoadingOverlayProps) {
  return (
    <Modal visible={visible} transparent animationType="fade">
      <View style={s.overlay}>
        <View style={s.spinnerWrap}>
          <ActivityIndicator size="large" color="#4F46E5" />
        </View>
      </View>
    </Modal>
  );
}

const s = StyleSheet.create({
  overlay: {
    flex: 1,
    backgroundColor: "rgba(0,0,0,0.35)",
    alignItems: "center",
    justifyContent: "center",
  },
  spinnerWrap: {
    backgroundColor: "#fff",
    padding: 28,
    borderRadius: 16,
    shadowColor: "#000",
    shadowOpacity: 0.1,
    shadowRadius: 16,
    elevation: 8,
  },
});
