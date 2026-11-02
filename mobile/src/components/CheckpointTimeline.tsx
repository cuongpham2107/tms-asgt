import { View, Text, StyleSheet, ScrollView, Image } from "react-native";
import { Ionicons } from "@expo/vector-icons";

export const cpInfo: Record<
    string,
    { icon: string; color: string; label: string }
> = {
    started: { icon: "play", color: "#4F46E5", label: "Bắt đầu" },
    arrived_pickup: { icon: "cube", color: "#F59E0B", label: "Đến lấy hàng" },
    left_pickup: {
        icon: "arrow-forward",
        color: "#8B5CF6",
        label: "Bắt đầu đi giao hàng",
    },
    arrived_delivery: {
        icon: "location",
        color: "#3B82F6",
        label: "Đến giao hàng",
    },
    completed: { icon: "flag", color: "#10B981", label: "Giao hàng xong" },
    driver_swap: {
        icon: "swap-horizontal",
        color: "#8B5CF6",
        label: "Đảo lái",
    },
    end: { icon: "close-circle", color: "#EF4444", label: "Kết thúc đơn hàng" },
    cancelled: { icon: "close-circle", color: "#DC2626", label: "Huỷ chuyến" },
};

interface Props {
    checkpoints: any[];
    /** id điểm giao → mã điểm, để ghi kèm khi đơn có nhiều điểm giao */
    dpCodeMap?: Record<string, string>;
    showDpCode?: boolean;
    myDriverId?: number | string | null;
}

/** Lịch sử chốt chặng của một đơn (dùng ở chi tiết đơn và chi tiết chuyến đã hoàn thành). */
export default function CheckpointTimeline({
    checkpoints,
    dpCodeMap = {},
    showDpCode = false,
    myDriverId,
}: Props) {
    return (
        <>
            {checkpoints.length > 0 && (
                <>
                    <Text style={s.sectionTitle}>📜 Lịch sử chốt chặng</Text>
                    {checkpoints.map((cp: any, i: number) => {
                        const ci = cpInfo[cp.checkpoint_type] || {
                            icon: "ellipse",
                            color: "#9CA3AF",
                            label: cp.checkpoint_type,
                        };
                        return (
                            <View key={cp.id || i} style={s.timelineItem}>
                                <View style={s.timelineLine}>
                                    <View
                                        style={[
                                            s.timelineDot,
                                            { backgroundColor: ci.color },
                                        ]}
                                    />
                                    {i < checkpoints.length - 1 && (
                                        <View style={s.timelineBar} />
                                    )}
                                </View>
                                <View style={s.timelineContent}>
                                    <View
                                        style={{
                                            flexDirection: "row",
                                            alignItems: "center",
                                            gap: 6,
                                        }}
                                    >
                                        <Ionicons
                                            name={ci.icon as any}
                                            size={14}
                                            color={ci.color}
                                        />
                                        <Text
                                            style={[
                                                s.tlLabel,
                                                { color: ci.color },
                                            ]}
                                        >
                                            {ci.label}
                                            {cp.delivery_point_id &&
                                            dpCodeMap[cp.delivery_point_id] &&
                                            showDpCode
                                                ? ` (${dpCodeMap[cp.delivery_point_id]})`
                                                : ""}
                                        </Text>
                                    </View>
                                    <Text style={s.tlInfo}>
                                        Km:{" "}
                                        {cp.km_reading != null
                                            ? parseInt(
                                                  cp.km_reading,
                                              ).toLocaleString("vi-VN")
                                            : "-"}{" "}
                                        •{" "}
                                        {new Date(
                                            cp.occurred_at,
                                        ).toLocaleString("vi-VN")}
                                        {cp.driver_name
                                            ? ` • ${cp.driver_id === myDriverId ? "Tôi" : cp.driver_name}`
                                            : ""}
                                    </Text>
                                    {cp.voice_note ? (
                                        <Text style={s.tlNote}>
                                            💬 {cp.voice_note}
                                        </Text>
                                    ) : null}
                                    {cp.photos?.length > 0 && (
                                        <ScrollView
                                            horizontal
                                            style={{ marginTop: 6 }}
                                            showsHorizontalScrollIndicator={
                                                false
                                            }
                                        >
                                            {cp.photos.map(
                                                (p: any, pi: number) => {
                                                    const uri =
                                                        p.photo_url ||
                                                        p.photo_path ||
                                                        (typeof p === "string"
                                                            ? p
                                                            : null);
                                                    if (!uri) return null;
                                                    return (
                                                        <Image
                                                            key={pi}
                                                            source={{ uri }}
                                                            style={{
                                                                width: 48,
                                                                height: 48,
                                                                borderRadius: 8,
                                                                marginRight: 4,
                                                            }}
                                                        />
                                                    );
                                                },
                                            )}
                                        </ScrollView>
                                    )}
                                </View>
                            </View>
                        );
                    })}
                </>
            )}
        </>
    );
}

const s = StyleSheet.create({
    sectionTitle: {
        fontSize: 16,
        fontWeight: "700",
        color: "#111827",
        paddingHorizontal: 12,
        marginBottom: 8,
        marginTop: 4,
    },
    // Timeline
    timelineItem: { flexDirection: "row", paddingHorizontal: 12 },
    timelineLine: { alignItems: "center", width: 24, marginRight: 12 },
    timelineDot: { width: 12, height: 12, borderRadius: 6 },
    timelineBar: {
        width: 2,
        flex: 1,
        backgroundColor: "#E5E7EB",
        marginTop: 4,
    },
    timelineContent: {
        flex: 1,
        backgroundColor: "#fff",
        padding: 12,
        borderRadius: 10,
        borderWidth: 1,
        borderColor: "#F3F4F6",
        marginBottom: 8,
    },
    tlLabel: { fontSize: 13, fontWeight: "700" },
    tlInfo: { fontSize: 12, color: "#9CA3AF", marginTop: 3 },
    tlNote: {
        fontSize: 12,
        color: "#374151",
        marginTop: 3,
        fontStyle: "italic",
    },
});
