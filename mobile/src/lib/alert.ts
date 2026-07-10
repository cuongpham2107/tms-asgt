import { Alert, Platform } from "react-native";

export function showAlert(title: string, message: string, onDismiss?: () => void) {
  if (Platform.OS === "web") {
    window.alert(`${title}\n\n${message}`);
    onDismiss?.();
  } else {
    Alert.alert(title, message, [{ text: "OK", onPress: onDismiss }]);
  }
}

export function showConfirm(
  title: string,
  message: string,
  onConfirm: () => void,
  onCancel?: () => void,
) {
  if (Platform.OS === "web") {
    if (window.confirm(`${title}\n\n${message}`)) {
      onConfirm();
    } else {
      onCancel?.();
    }
  } else {
    Alert.alert(title, message, [
      { text: "Huỷ", style: "cancel", onPress: onCancel },
      { text: "Xác nhận", onPress: onConfirm },
    ]);
  }
}

export function showDestructiveConfirm(
  title: string,
  message: string,
  onConfirm: () => void,
  onCancel?: () => void,
) {
  if (Platform.OS === "web") {
    if (window.confirm(`${title}\n\n${message}`)) {
      onConfirm();
    } else {
      onCancel?.();
    }
  } else {
    Alert.alert(title, message, [
      { text: "Huỷ", style: "cancel", onPress: onCancel },
      { text: "Vẫn kết thúc", style: "destructive", onPress: onConfirm },
    ]);
  }
}
