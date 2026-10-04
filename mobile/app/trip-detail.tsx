import { useState, useCallback } from "react";
import {
    View,
    Text,
    StyleSheet,
    ScrollView,
    TouchableOpacity,
    RefreshControl,
    TextInput,
    Modal,
    KeyboardAvoidingView,
    Platform,
    Keyboard,
    Pressable,
} from "react-native";
import { useLocalSearchParams, useRouter, useFocusEffect } from "expo-router";
import { useAuth } from "../src/lib/auth";
import { useLoading } from "../src/lib/loading";
import { api, SWAP_REASONS, type SwapReason } from "../src/lib/api";
import { showAlert, showDestructiveConfirm } from "../src/lib/alert";
import { clearNotificationBadge } from "../src/lib/notifications";
import { Ionicons } from "@expo/vector-icons";
import { getCheckpointGps, toFakePoint, FakePoint } from "../src/lib/fakeLocation";
import FakeLocationPicker from "../src/components/FakeLocationPicker";

const statusConfig: Record<
    string,
    { icon: string; bg: string; text: string }
> = {
    pending: {
        icon: "time-outline",
        bg: "#F3F4F6",
        text: "#6B7280",
    },
    started: {
        icon: "play-circle-outline",
        bg: "#FEF3C7",
        text: "#D97706",
    },
    arrived_pickup: {
        icon: "cube-outline",
        bg: "#FEF3C7",
        text: "#D97706",
    },
    delivering: {
        icon: "car-outline",
        bg: "#DBEAFE",
        text: "#2563EB",
    },
    arrived_delivery: {
        icon: "location-outline",
        bg: "#FEF3C7",
        text: "#D97706",
    },
    delivered: {
        icon: "checkmark-done",
        bg: "#D1FAE5",
        text: "#059669",
    },
    completed: {
        icon: "checkmark-circle",
        bg: "#D1FAE5",
        text: "#059669",
    },
    driver_swap: {
        icon: "swap-horizontal",
        bg: "#E0E7FF",
        text: "#4F46E5",
    },
    cancelled: {
        icon: "close-circle",
        bg: "#FEE2E2",
        text: "#DC2626",
    },
};

const localISO = (d: Date = new Date()) => {
    const pad = (n: number) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
};

export default function TripDetailScreen() {
    const { token, shift } = useAuth();
    const router = useRouter();
    const { showLoading, hideLoading } = useLoading();
    const params = useLocalSearchParams<{ id: string; trip: string }>();
    const trip = params.trip ? JSON.parse(params.trip) : null;
    const tripId = trip?.id || params.id;
    const [detail, setDetail] = useState<any>(null);
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [completing, setCompleting] = useState(false);
    const [starting, setStarting] = useState(false);
    const [showSwapModal, setShowSwapModal] = useState(false);
    const [swapReason, setSwapReason] = useState<SwapReason>("shift_handover");
    const [swapNote, setSwapNote] = useState("");
    const [swapping, setSwapping] = useState(false);
    const userId = shift?.driver?.id;

    // Format: bỏ .0, hiển thị số nguyên
    const fmt = (v: any) =>
        v != null ? parseInt(v).toLocaleString("vi-VN") : "—";


    const load = async () => {
        if (!token || !tripId) return;
        try {
            const r = await api.trips.detail(String(tripId), token);
            const data = r.data || r;
            setDetail(data);
            return data;
        } finally {
            setLoading(false);
        }
    };
    useFocusEffect(
        useCallback(() => {
            clearNotificationBadge();
            load();
        }, [token, trip?.id, params.id]),
    );
    const onRefresh = async () => {
        setRefreshing(true);
        await load();
        setRefreshing(false);
    };


    const currentStatus = detail?.status || trip?.status || "pending";
    const isSwapped = detail && userId && detail.driver_id !== userId;
    // Nút chỉ hiển thị theo available_actions do server tính
    const actions: string[] =
        detail?.available_actions ?? trip?.available_actions ?? [];
    const canStart = actions.includes("started");
    const canEnd = actions.includes("end");
    const canSwap = actions.includes("request_swap");
    const isEmptyRun =
        (detail?.is_empty_run ?? trip?.is_empty_run ?? false) === true;
    const orders: any[] = detail?.orders || trip?.orders || [];

    // Giả lập vị trí khi test: bắt đầu = điểm lấy của đơn đầu, kết thúc = điểm giao cuối của đơn cuối
    const startFakePoint = toFakePoint(
        `Lấy: ${orders[0]?.pickup_location?.code || "điểm lấy"}`,
        orders[0]?.pickup_location,
    );
    const lastDeliveryPoints: any[] = orders[orders.length - 1]?.delivery_points || [];
    const endFakePoint = toFakePoint(
        "Điểm giao cuối",
        lastDeliveryPoints[lastDeliveryPoints.length - 1]?.location,
    );
    const fakePoints = [startFakePoint, endFakePoint].filter(
        (p): p is FakePoint => p !== null,
    );

    const handleStart = async () => {
        if (!tripId || !token) return;
        setStarting(true);
        showLoading();
        try {
            const gps = await getCheckpointGps(startFakePoint);
            const body: any = {
                checkpoint_type: "started",
                occurred_at: localISO(),
            };
            if (gps) {
                body.gps_lat = gps.gps_lat;
                body.gps_lng = gps.gps_lng;
            }
            await api.trips.checkpoint(String(tripId), body, token);
            const updated = await load();
            const currentOrders: any[] = updated?.orders || detail?.orders || trip?.orders || [];
            const targetOrder = currentOrders.find((o: any) => o.status !== "completed") || currentOrders[0];
            if (targetOrder) {
                router.push({
                    pathname: "/order-detail",
                    params: {
                        id: targetOrder.id,
                        order: JSON.stringify({
                            ...targetOrder,
                            trip_id: tripId,
                            vehicle: updated?.vehicle || detail?.vehicle || trip?.vehicle,
                            is_swapped: isSwapped,
                        }),
                    },
                });
            } else {
                showAlert("Thành công", "Đã bắt đầu chuyến");
            }
        } catch (e: any) {
            const msg = e.message || "";
            const match = msg.match(/#(\d+)/);
            showAlert("Không thể bắt đầu", msg, () => {
                if (match)
                    router.push({
                        pathname: "/trip-detail",
                        params: { id: match[1] },
                    });
            });
        } finally {
            setStarting(false);
            hideLoading();
        }
    };

    const handleEnd = () => {
        if (!tripId || !token) return;
        showDestructiveConfirm(
            "Kết thúc chuyến",
            "Bạn có chắc chắn muốn kết thúc chuyến?",
            async () => {
                setCompleting(true);
                showLoading();
                try {
                    const gps = await getCheckpointGps(endFakePoint);
                    await api.trips.complete(
                        String(tripId),
                        token,
                        gps ?? undefined,
                    );
                    showAlert("Thành công", "Đã kết thúc chuyến");
                    await load();
                } catch (e: any) {
                    showAlert("Lỗi", e.message);
                } finally {
                    setCompleting(false);
                    hideLoading();
                }
            },
            undefined,
            "Kết thúc",
        );
    };

    const handleSwap = async () => {
        if (!tripId || !token) return;
        if (swapReason === "other" && !swapNote.trim()) {
            showAlert("Thiếu thông tin", "Vui lòng nhập lý do cụ thể");
            return;
        }
        setSwapping(true);
        showLoading();
        try {
            await api.trips.swap(
                String(tripId),
                {
                    reason: swapReason,
                    note: swapNote.trim() || undefined,
                },
                token,
            );
            setShowSwapModal(false);
            setSwapNote("");
            showAlert(
                "Đã gửi yêu cầu đảo lái",
                "Điều hành sẽ gán lái mới cho chuyến này.",
                () => router.replace("/(tabs)/trips"),
            );
        } catch (e: any) {
            showAlert("Lỗi", e.message);
        } finally {
            setSwapping(false);
            hideLoading();
        }
    };


    // Show loading while fetching trip data
    if (!trip && !detail) {
        return (
            <View style={s.container}>
                <View
                    style={{
                        flex: 1,
                        alignItems: "center",
                        justifyContent: "center",
                    }}
                >
                    <Text style={{ color: "#9CA3AF", fontSize: 14 }}>
                        Đang tải...
                    </Text>
                </View>
            </View>
        );
    }

    // Use detail (from API) or trip (from params)
    const effectiveTrip = detail || trip;
    const st =
        statusConfig[effectiveTrip?.status || currentStatus] ||
        statusConfig["pending"];

    return (
        <KeyboardAvoidingView
            style={{ flex: 1 }}
            behavior={Platform.OS === "android" ? "padding" : undefined}
            keyboardVerticalOffset={Platform.OS === "android" ? 88 : 0}
        >
            <ScrollView
                style={s.container}
                refreshControl={
                    <RefreshControl
                        refreshing={refreshing}
                        onRefresh={onRefresh}
                        tintColor="#4F46E5"
                    />
                }
            >
                {/* Bàn giao banner */}
                {isSwapped && (
                    <View style={s.swappedBanner}>
                        <Ionicons
                            name="swap-horizontal"
                            size={18}
                            color="#D97706"
                        />
                        <Text style={s.swappedBannerText}>
                            Chuyến đã bàn giao — chỉ xem
                        </Text>
                    </View>
                )}

                {/* Hero */}
                <View
                    style={[
                        s.heroCard,
                        { borderLeftColor: st.text, borderLeftWidth: 4 },
                    ]}
                >
                    <View style={s.heroRow}>
                        <View style={{ flex: 1 }}>
                            <Text style={s.tripCode}>
                                {detail?.vehicle?.plate_number ||
                                    trip?.vehicle?.plate_number ||
                                    "Chưa gán xe"}
                            </Text>
                        </View>
                        <View
                            style={[s.statusPill, { backgroundColor: st.bg }]}
                        >
                            <Ionicons
                                name={st.icon as any}
                                size={14}
                                color={st.text}
                            />
                            <Text
                                style={[s.statusPillText, { color: st.text }]}
                            >
                                {effectiveTrip?.status_label ?? currentStatus}
                            </Text>
                        </View>
                    </View>
                    {isEmptyRun && (
                        <View style={s.badgeEmptyRow}>
                            <View style={s.badgeEmpty}>
                                <Text style={s.badgeEmptyText}>
                                    Chuyến không hàng
                                </Text>
                            </View>
                        </View>
                    )}
                    {(detail?.route || trip?.route) && (
                        <View style={s.routeWrap}>
                            <Ionicons
                                name="navigate"
                                size={12}
                                color="#4F46E5"
                            />
                            <Text style={s.orderRoute}>
                                {detail?.route || trip?.route}
                            </Text>
                        </View>
                    )}
                    {(canStart || canEnd) && (
                        <FakeLocationPicker points={fakePoints} />
                    )}
                    {canStart && (
                        <TouchableOpacity
                            style={[
                                s.actionBtn,
                                { backgroundColor: "#10B981", marginTop: 12 },
                            ]}
                            onPress={handleStart}
                            disabled={starting}
                        >
                            <Ionicons
                                name="play-circle"
                                size={20}
                                color="#fff"
                            />
                            <Text style={s.actionBtnText}>
                                {starting ? "Đang xử lý..." : "Bắt đầu chuyến"}
                            </Text>
                        </TouchableOpacity>
                    )}
                    {!canStart && !shift && (
                        <View style={s.warnBox}>
                            <Text style={s.warnText}>
                                Bạn cần vào ca trước khi bắt đầu chuyến.
                            </Text>
                        </View>
                    )}
                </View>


                {/* Km stats (tính từ GPS ở server, null → —) */}
                <View style={s.statsGrid}>
                    {[
                        {
                            icon: "speedometer-outline",
                            label: "Tổng Km",
                            value: fmt(detail?.total_km ?? trip?.total_km),
                            color: "#4F46E5",
                            bg: "#EEF2FF",
                        },
                        {
                            icon: "cube-outline",
                            label: "Km có hàng",
                            value: fmt(
                                detail?.total_km_loaded ??
                                    trip?.total_km_loaded,
                            ),
                            color: "#3B82F6",
                            bg: "#EFF6FF",
                        },
                        {
                            icon: "arrow-undo-outline",
                            label: "Km rỗng",
                            value: fmt(
                                detail?.total_km_empty ??
                                    trip?.total_km_empty,
                            ),
                            color: "#F59E0B",
                            bg: "#FFFBEB",
                        },
                    ].map((st2, i) => (
                            <View key={i} style={s.statCard}>
                                <View
                                    style={[
                                        s.statIcon,
                                        { backgroundColor: st2.bg },
                                    ]}
                                >
                                    <Ionicons
                                        name={st2.icon as any}
                                        size={20}
                                        color={st2.color}
                                    />
                                </View>
                                <View style={{ flex: 1 }}>
                                    <Text style={s.statLabel}>{st2.label}</Text>
                                    <Text style={s.statValue}>{st2.value}</Text>
                                </View>
                            </View>
                        ))}
                    </View>


                {/* Thời gian */}
                {detail?.started_at && (
                    <View style={s.timeCard}>
                        <View style={s.timeRow}>
                            <Ionicons
                                name="play-circle"
                                size={16}
                                color="#10B981"
                            />
                            <Text style={s.timeText}>
                                Bắt đầu:{" "}
                                {new Date(detail.started_at).toLocaleString(
                                    "vi-VN",
                                )}
                            </Text>
                        </View>
                        {detail.completed_at && (
                            <View style={s.timeRow}>
                                <Ionicons
                                    name="flag"
                                    size={16}
                                    color="#4F46E5"
                                />
                                <Text style={s.timeText}>
                                    Hoàn thành:{" "}
                                    {new Date(
                                        detail.completed_at,
                                    ).toLocaleString("vi-VN")}
                                </Text>
                            </View>
                        )}
                    </View>
                )}


                        <View style={s.sectionHeader}>
                            <Text style={s.sectionTitle}>
                                📦 Đơn hàng ({orders.length})
                            </Text>
                        </View>
                        {orders.length === 0 ? (
                            <View style={s.empty}>
                                <Ionicons
                                    name="cube-outline"
                                    size={40}
                                    color="#E5E7EB"
                                />
                                <Text style={s.emptyText}>
                                    Chưa có đơn hàng
                                </Text>
                            </View>
                        ) : (
                            orders.map((o: any, i: number) => {
                                const osText = o.status_label ?? o.status;
                                const osColor =
                                    o.status === "completed"
                                        ? "#059669"
                                        : o.status === "in_transit"
                                          ? "#D97706"
                                          : o.status === "driver_swap"
                                            ? "#8B5CF6"
                                            : "#6B7280";
                                return (
                                    <TouchableOpacity
                                        key={o.id}
                                        style={[
                                            s.orderCard,
                                            { borderColor: osColor + "20" },
                                        ]}
                                        activeOpacity={0.7}
                                        onPress={() => {
                                            router.push({
                                                pathname: "/order-detail",
                                                params: {
                                                    id: o.id,
                                                    order: JSON.stringify({
                                                        ...o,
                                                        trip_id: tripId,
                                                        vehicle:
                                                            detail?.vehicle ||
                                                            trip?.vehicle,
                                                        is_swapped: isSwapped,
                                                    }),
                                                },
                                            });
                                        }}
                                    >
                                        <View style={{ flex: 1 }}>
                                            {(() => {
                                                const codes: string[] = [];
                                                if (o.pickup_location?.code)
                                                    codes.push(
                                                        o.pickup_location.code,
                                                    );
                                                (
                                                    o.delivery_points || []
                                                ).forEach((dp: any) => {
                                                    if (dp.location?.code)
                                                        codes.push(
                                                            dp.location.code,
                                                        );
                                                });
                                                if (codes.length > 0)
                                                    return (
                                                        <View
                                                            style={s.routeWrap}
                                                        >
                                                            <Ionicons
                                                                name="navigate"
                                                                size={12}
                                                                color="#4F46E5"
                                                            />
                                                            <Text
                                                                style={
                                                                    s.orderRoute
                                                                }
                                                            >
                                                                {codes.join(
                                                                    "  →  ",
                                                                )}
                                                            </Text>
                                                        </View>
                                                    );
                                                return null;
                                            })()}
                                            <View
                                                style={{
                                                    flexDirection: "row",
                                                    justifyContent:
                                                        "space-between",
                                                    alignItems: "center",
                                                }}
                                            >
                                                <View
                                                    style={{
                                                        flexDirection: "row",
                                                        alignItems: "center",
                                                        flex: 1,
                                                    }}
                                                >
                                                    <Text style={s.orderCode}>
                                                        {o.order_code}
                                                    </Text>
                                                    <View
                                                        style={{
                                                            paddingHorizontal: 4,
                                                            paddingVertical: 1,
                                                            borderRadius: 4,
                                                            backgroundColor:
                                                                o.type ===
                                                                "HHHK"
                                                                    ? "#E0F2FE"
                                                                    : "#FEF3C7",
                                                            marginLeft: 6,
                                                        }}
                                                    >
                                                        <Text
                                                            style={{
                                                                fontSize: 10,
                                                                fontWeight:
                                                                    "600",
                                                                color:
                                                                    o.type ===
                                                                    "HHHK"
                                                                        ? "#0369A1"
                                                                        : "#B45309",
                                                            }}
                                                        >
                                                            {o.type_label ||
                                                                o.type}
                                                        </Text>
                                                    </View>
                                                </View>
                                                <View
                                                    style={[
                                                        s.orderBadge,
                                                        {
                                                            backgroundColor:
                                                                osColor + "20",
                                                        },
                                                    ]}
                                                >
                                                    <Text
                                                        style={[
                                                            s.orderBadgeText,
                                                            { color: osColor },
                                                        ]}
                                                    >
                                                        {osText}
                                                    </Text>
                                                </View>
                                            </View>
                                            <Text
                                                style={s.orderCargo}
                                                numberOfLines={1}
                                            >
                                                {o.cargo_name || "Chưa có tên"}
                                            </Text>
                                            {o.customer?.name && (
                                                <Text style={s.orderCustomer}>
                                                    {o.customer.name}
                                                </Text>
                                            )}
                                            {o.planned_loading_at && (
                                                <Text style={s.orderLoading}>
                                                    🕐 Đóng hàng:{" "}
                                                    {new Date(
                                                        o.planned_loading_at,
                                                    ).toLocaleString("vi-VN")}
                                                </Text>
                                            )}
                                            <Text style={s.orderKm}>
                                                📏 Km có hàng: {fmt(o.loaded_km)} km
                                            </Text>
                                        </View>
                                        <Ionicons
                                            name="chevron-forward"
                                            size={16}
                                            color="#D1D5DB"
                                        />
                                    </TouchableOpacity>
                                );
                            })
                        )}

                <View style={{ height: 100 }} />
            </ScrollView>

            {/* Sticky bottom — Đảo lái / Kết thúc chuyến (theo available_actions) */}
            {(canSwap || canEnd) && (
                <View style={[s.stickyBar, { flexDirection: "row", gap: 10 }]}>
                    {canSwap && (
                        <TouchableOpacity
                            style={[s.stickyBtn, { backgroundColor: "#4F46E5" }]}
                            onPress={() => setShowSwapModal(true)}
                            disabled={swapping}
                            activeOpacity={0.8}
                        >
                            <Ionicons
                                name="swap-horizontal"
                                size={20}
                                color="#fff"
                            />
                            <Text style={s.stickyBtnText}>Đảo lái</Text>
                        </TouchableOpacity>
                    )}
                    {canEnd && (
                        <TouchableOpacity
                            style={[s.stickyBtn, { backgroundColor: "#DC2626" }]}
                            onPress={handleEnd}
                            disabled={completing}
                            activeOpacity={0.8}
                        >
                            <Ionicons name="flag" size={20} color="#fff" />
                            <Text style={s.stickyBtnText}>
                                {completing ? "Đang xử lý..." : "Kết thúc chuyến"}
                            </Text>
                        </TouchableOpacity>
                    )}
                </View>
            )}

            {/* Modal Đảo lái */}
            <Modal
                visible={showSwapModal}
                transparent
                animationType="fade"
                onRequestClose={() => setShowSwapModal(false)}
            >
                <Pressable style={s.modalOverlay} onPress={Keyboard.dismiss}>
                    <View style={[s.modalCard, { maxWidth: 420 }]}>
                        <Text style={s.modalTitle}>Xin đảo lái</Text>
                        <Text style={s.modalSectionLabel}>Lý do *</Text>
                        <View
                            style={{
                                flexDirection: "row",
                                flexWrap: "wrap",
                                gap: 8,
                                marginBottom: 12,
                            }}
                        >
                            {SWAP_REASONS.map((r) => {
                                const isSelected = swapReason === r.key;
                                return (
                                    <TouchableOpacity
                                        key={r.key}
                                        onPress={() => setSwapReason(r.key)}
                                        style={[
                                            s.reasonChip,
                                            isSelected && s.reasonChipActive,
                                        ]}
                                    >
                                        {isSelected && (
                                            <Ionicons
                                                name="checkmark-circle"
                                                size={16}
                                                color="#4F46E5"
                                            />
                                        )}
                                        <Text
                                            style={[
                                                s.reasonChipText,
                                                isSelected &&
                                                    s.reasonChipTextActive,
                                            ]}
                                        >
                                            {r.label}
                                        </Text>
                                    </TouchableOpacity>
                                );
                            })}
                        </View>
                        <Text style={s.modalSectionLabel}>
                            Ghi chú{swapReason === "other" ? " *" : ""}
                        </Text>
                        <TextInput
                            style={[s.stickyInput, s.noteInput]}
                            value={swapNote}
                            onChangeText={setSwapNote}
                            placeholder="Nhập ghi chú..."
                            placeholderTextColor="#9CA3AF"
                            multiline
                            numberOfLines={3}
                        />
                        <View style={{ flexDirection: "row", gap: 10 }}>
                            <TouchableOpacity
                                style={[
                                    s.modalBtn,
                                    { backgroundColor: "#F3F4F6" },
                                ]}
                                onPress={() => setShowSwapModal(false)}
                            >
                                <Text
                                    style={[
                                        s.modalBtnText,
                                        { color: "#6B7280" },
                                    ]}
                                >
                                    Huỷ
                                </Text>
                            </TouchableOpacity>
                            <TouchableOpacity
                                style={[
                                    s.modalBtn,
                                    { backgroundColor: "#4F46E5" },
                                ]}
                                onPress={handleSwap}
                                disabled={swapping}
                            >
                                <Text
                                    style={[s.modalBtnText, { color: "#fff" }]}
                                >
                                    {swapping ? "Đang gửi..." : "Xác nhận"}
                                </Text>
                            </TouchableOpacity>
                        </View>
                    </View>
                </Pressable>
            </Modal>
        </KeyboardAvoidingView>
    );
}

const s = StyleSheet.create({
    container: { flex: 1, backgroundColor: "#F9FAFB" },
    swappedBanner: {
        flexDirection: "row",
        alignItems: "center",
        gap: 8,
        backgroundColor: "#FFFBEB",
        marginHorizontal: 16,
        marginTop: 12,
        padding: 12,
        borderRadius: 10,
        borderWidth: 1,
        borderColor: "#FDE68A",
    },
    swappedBannerText: {
        flex: 1,
        fontSize: 13,
        fontWeight: "600",
        color: "#92400E",
    },
    heroCard: {
        backgroundColor: "#fff",
        margin: 16,
        marginBottom: 4,
        padding: 16,
        borderRadius: 14,
        borderWidth: 1,
        borderColor: "#F3F4F6",
    },
    heroRow: { flexDirection: "row", alignItems: "center", gap: 12 },
    tripCode: { fontSize: 20, fontWeight: "800", color: "#111827" },
    plate: { fontSize: 14, color: "#6B7280", marginTop: 3 },
    statusPill: {
        flexDirection: "row",
        alignItems: "center",
        gap: 4,
        paddingHorizontal: 10,
        paddingVertical: 5,
        borderRadius: 20,
    },
    statusPillText: { fontSize: 12, fontWeight: "700" },
    badgeEmpty: {
        backgroundColor: "#8B5CF6",
        paddingHorizontal: 8,
        paddingVertical: 3,
        borderRadius: 10,
    },
    badgeEmptyText: { color: "#fff", fontSize: 11, fontWeight: "700" },
    badgeEmptyRow: {
        flexDirection: "row",
        justifyContent: "flex-end",
        marginTop: 8,
    },
    actionBtn: {
        flexDirection: "row",
        alignItems: "center",
        justifyContent: "center",
        gap: 8,
        paddingVertical: 12,
        borderRadius: 12,
    },
    actionBtnText: { color: "#fff", fontSize: 15, fontWeight: "700" },
    statsGrid: {
        flexDirection: "row",
        flexWrap: "wrap",
        paddingHorizontal: 12,
        gap: 8,
        marginBottom: 8,
    },
    statCard: {
        width: "47.5%",
        backgroundColor: "#fff",
        padding: 14,
        borderRadius: 14,
        borderWidth: 1,
        borderColor: "#F3F4F6",
        flexDirection: "row",
        alignItems: "center",
        gap: 12,
    },
    statIcon: {
        width: 42,
        height: 42,
        borderRadius: 12,
        alignItems: "center",
        justifyContent: "center",
    },
    statValue: { fontSize: 20, fontWeight: "800", color: "#111827" },
    statLabel: { fontSize: 11, color: "#9CA3AF", marginBottom: 2 },
    timeCard: {
        backgroundColor: "#fff",
        marginHorizontal: 16,
        padding: 14,
        borderRadius: 12,
        borderWidth: 1,
        borderColor: "#F3F4F6",
        marginBottom: 16,
        gap: 8,
    },
    timeRow: { flexDirection: "row", alignItems: "center", gap: 8 },
    timeText: { fontSize: 13, color: "#374151" },
    sectionHeader: {
        flexDirection: "row",
        justifyContent: "space-between",
        alignItems: "center",
        paddingHorizontal: 16,
        marginBottom: 10,
        marginTop: 4,
    },
    sectionTitle: { fontSize: 16, fontWeight: "700", color: "#111827" },
    orderCard: {
        flexDirection: "row",
        alignItems: "center",
        backgroundColor: "#fff",
        marginHorizontal: 16,
        marginBottom: 8,
        padding: 12,
        borderRadius: 12,
        borderWidth: 1,
        borderColor: "#F3F4F6",
        gap: 12,
        shadowColor: "#000",
        shadowOpacity: 0.04,
        shadowRadius: 6,
        shadowOffset: { width: 0, height: 1 },
        elevation: 1,
    },
    orderSeq: {
        width: 28,
        height: 28,
        borderRadius: 14,
        backgroundColor: "#4F46E5",
        alignItems: "center",
        justifyContent: "center",
    },
    seqText: { color: "#fff", fontSize: 12, fontWeight: "700" },
    orderCode: { fontSize: 14, fontWeight: "700", color: "#111827" },
    orderBadge: { paddingHorizontal: 7, paddingVertical: 2, borderRadius: 5 },
    orderBadgeText: { fontSize: 10, fontWeight: "700" },
    orderCargo: { fontSize: 13, color: "#6B7280", marginTop: 2 },
    orderCustomer: { fontSize: 12, color: "#9CA3AF", marginTop: 1 },
    routeWrap: {
        flexDirection: "row",
        alignItems: "center",
        gap: 6,
        marginBottom: 10,
        paddingBottom: 10,
        borderBottomWidth: 1,
        borderBottomColor: "#F3F4F6",
    },
    orderRoute: { fontSize: 13, fontWeight: "700", color: "#4F46E5", flex: 1 },
    orderKm: { fontSize: 11, color: "#9CA3AF", marginTop: 2 },
    orderLoading: { fontSize: 12, color: "#6B7280", marginTop: 2 },
    empty: { alignItems: "center", paddingVertical: 32 },
    emptyText: { color: "#9CA3AF", marginTop: 6, fontSize: 13 },
    // Sticky bottom bar
    stickyBar: {
        backgroundColor: "#fff",
        padding: 12,
        paddingBottom: 32,
        borderTopWidth: 1,
        borderTopColor: "#F3F4F6",
    },
    stickyBtn: {
        flex: 1,
        flexDirection: "row",
        alignItems: "center",
        justifyContent: "center",
        gap: 8,
        paddingVertical: 14,
        borderRadius: 12,
    },
    stickyBtnText: { color: "#fff", fontSize: 16, fontWeight: "700" },
    stickyInput: {
        flex: 1,
        backgroundColor: "#F9FAFB",
        padding: 12,
        borderRadius: 12,
        borderWidth: 1,
        borderColor: "#E5E7EB",
        fontSize: 16,
        color: "#111827",
    },
    // Complete modal
    modalOverlay: {
        flex: 1,
        backgroundColor: "rgba(0,0,0,0.5)",
        justifyContent: "center",
        alignItems: "center",
        paddingHorizontal: 20,
    },
    modalCard: {
        backgroundColor: "#fff",
        borderRadius: 16,
        padding: 20,
        width: "100%",
        maxWidth: 360,
    },
    modalTitle: {
        fontSize: 18,
        fontWeight: "800",
        color: "#111827",
        marginBottom: 16,
        textAlign: "center",
    },
    modalSectionLabel: {
        fontSize: 12,
        fontWeight: "700",
        color: "#6B7280",
        marginBottom: 8,
        textTransform: "uppercase",
        letterSpacing: 0.5,
    },
    modalBtn: {
        flex: 1,
        paddingVertical: 12,
        borderRadius: 12,
        alignItems: "center",
        justifyContent: "center",
    },
    modalBtnText: { fontSize: 15, fontWeight: "700" },
    warnBox: {
        backgroundColor: "#FFFBEB",
        padding: 10,
        borderRadius: 8,
        marginTop: 8,
        borderWidth: 1,
        borderColor: "#FDE68A",
    },
    warnText: {
        color: "#92400E",
        fontSize: 12,
        fontWeight: "600",
        textAlign: "center",
    },
    noteInput: {
        marginBottom: 16,
        minHeight: 72,
        textAlignVertical: "top",
        paddingTop: 8,
    },
    reasonChip: {
        paddingHorizontal: 12,
        paddingVertical: 8,
        borderRadius: 8,
        borderWidth: 1.5,
        borderColor: "#E5E7EB",
        backgroundColor: "#F9FAFB",
        flexDirection: "row",
        alignItems: "center",
        gap: 6,
    },
    reasonChipActive: { borderColor: "#4F46E5", backgroundColor: "#EEF2FF" },
    reasonChipText: { fontSize: 13, fontWeight: "500", color: "#374151" },
    reasonChipTextActive: { fontWeight: "700", color: "#4338CA" },
});
