import { View, Text, StyleSheet } from "react-native";
import { Ionicons } from "@expo/vector-icons";

export default function MapScreen() {
  return (
    <View style={s.center}>
      <Ionicons name="map-outline" size={56} color="#D1D5DB" />
      <Text style={s.emptyText}>Bản đồ - Coming soon</Text>
    </View>
  );
}

const s = StyleSheet.create({
  center: { flex: 1, alignItems: "center", justifyContent: "center", backgroundColor: "#F9FAFB", gap: 12 },
  emptyText: { color: "#9CA3AF", fontSize: 14 },
});
