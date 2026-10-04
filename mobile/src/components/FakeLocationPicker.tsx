import { View, Text, TouchableOpacity, ScrollView } from "react-native";
import { FAKE_LOCATION_ENABLED, FakePoint, setFakeMode, useFakeMode } from "../lib/fakeLocation";

/**
 * Thanh chọn vị trí giả lập cho checkpoint (chỉ hiện ở bản dev / test).
 */
export default function FakeLocationPicker({ points }: { points: FakePoint[] }) {
    const mode = useFakeMode();
    if (!FAKE_LOCATION_ENABLED) return null;

    const isSelected = (m: "auto" | "real" | FakePoint) =>
        typeof m === "string" ? mode === m : typeof mode === "object" && mode.lat === m.lat && mode.lng === m.lng;

    const chip = (label: string, value: "auto" | "real" | FakePoint, key: string) => (
        <TouchableOpacity
            key={key}
            onPress={() => setFakeMode(value)}
            style={{
                paddingHorizontal: 10,
                paddingVertical: 6,
                borderRadius: 14,
                marginRight: 6,
                borderWidth: 1,
                borderColor: isSelected(value) ? "#D97706" : "#FCD34D",
                backgroundColor: isSelected(value) ? "#F59E0B" : "#FFFBEB",
            }}
        >
            <Text style={{ fontSize: 12, fontWeight: "600", color: isSelected(value) ? "#fff" : "#92400E" }}>{label}</Text>
        </TouchableOpacity>
    );

    return (
        <View style={{ backgroundColor: "#FEF3C7", borderRadius: 10, padding: 10, marginVertical: 8, borderWidth: 1, borderColor: "#FCD34D" }}>
            <Text style={{ fontSize: 12, fontWeight: "700", color: "#92400E", marginBottom: 6 }}>
                🧪 Giả lập vị trí checkpoint (bản test)
            </Text>
            <ScrollView horizontal showsHorizontalScrollIndicator={false}>
                {chip("Tự động theo bước", "auto", "auto")}
                {chip("GPS thật", "real", "real")}
                {points.map((p, i) => chip(p.label, p, `p-${i}`))}
            </ScrollView>
        </View>
    );
}
