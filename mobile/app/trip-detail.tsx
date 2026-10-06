import { formatKm } from "../src/lib/format";
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
    Image,
    ActivityIndicator,
} from "react-native";
import * as ImagePicker from "expo-image-picker";
import {
    resolveNextAction,
    getPhysicalDeliveryStops,
    createActionForDeliveryStop,
    type NextAction,
    type PhysicalDeliveryStop,
} from "../src/lib/tripActions";
import { useLocalSearchParams, useRouter, useFocusEffect } from "expo-router";
import { useAuth } from "../src/lib/auth";
import { useLoading } from "../src/lib/loading";
import { api, SWAP_REASONS, type SwapReason } from "../src/lib/api";
import { showAlert, showDestructiveConfirm } from "../src/lib/alert";
import { clearNotificationBadge } from "../src/lib/notifications";
import { Ionicons } from "@expo/vector-icons";
import {
    getCheckpointGps,
    toFakePoint,
    FakePoint,
} from "../src/lib/fakeLocation";
import { flushBeforeCheckpoint } from "../src/tracking/tracker";
import FakeLocationPicker from "../src/components/FakeLocationPicker";

const statusConfig: Record<string, { icon: string; bg: string; text: string }> =
    {
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
    const { token, shift, user } = useAuth();
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

    // Quick action checkpoint modal
    const [quickActionModal, setQuickActionModal] = useState<NextAction | null>(null);
    const [quickPhotos, setQuickPhotos] = useState<string[]>([]);
    const [quickNote, setQuickNote] = useState("");
    const [submittingQuick, setSubmittingQuick] = useState(false);

    const handleTakeQuickCamera = async () => {
        try {
            const { status } = await ImagePicker.requestCameraPermissionsAsync();
            if (status !== "granted") {
                showAlert("Quyền truy cập", "Cần cấp quyền camera để chụp ảnh");
                return;
            }
            const res = await ImagePicker.launchCameraAsync({
                allowsEditing: false,
                quality: 0.7,
            });
            if (!res.canceled && res.assets?.[0]?.uri) {
                setQuickPhotos((prev) => [...prev, res.assets[0].uri]);
            }
        } catch (e: any) {
            const msg = String(e?.message || "");
            if (
                msg.toLowerCase().includes("simulator") ||
                msg.toLowerCase().includes("not available") ||
                msg.toLowerCase().includes("unavailable")
            ) {
                await handlePickQuickGallery();
                return;
            }
            showAlert("Lỗi chụp ảnh", msg || "Không thể mở camera");
        }
    };

    const handlePickQuickGallery = async () => {
        try {
            const res = await ImagePicker.launchImageLibraryAsync({
                mediaTypes: ["images"],
                allowsEditing: false,
                quality: 0.7,
            });
            if (!res.canceled && res.assets?.[0]?.uri) {
                setQuickPhotos((prev) => [...prev, res.assets[0].uri]);
            }
        } catch (e: any) {
            showAlert("Lỗi chọn ảnh", e?.message || "Không thể mở thư viện ảnh");
        }
    };

    const handleActionPress = (act: NextAction) => {
        if (!token) return;
        if (!shift && act.type !== "started") {
            showAlert("Cần vào ca", "Bạn cần vào ca trước khi thực hiện thao tác");
            return;
        }
        if (act.type === "end") {
            handleEnd();
            return;
        }
        setQuickPhotos([]);
        setQuickNote("");
        setQuickActionModal(act);
    };

    const handleSubmitQuickAction = async () => {
        if (!quickActionModal || !token || !tripId) return;
        const act = quickActionModal;

        setSubmittingQuick(true);
        showLoading();
        try {
            await flushBeforeCheckpoint();
            const fakeFallback = act.targetLocation
                ? toFakePoint(act.pointLabel || act.label, act.targetLocation)
                : (act.type === "started" ? startFakePoint : null);
            const gps = await getCheckpointGps(fakeFallback);

            const body: any = {
                checkpoint_type: act.type,
                occurred_at: localISO(),
            };
            if (gps) {
                body.gps_lat = gps.gps_lat;
                body.gps_lng = gps.gps_lng;
            }
            if (quickPhotos.length > 0) {
                body.photos = quickPhotos;
            }
            if (quickNote.trim().length > 0) {
                body.voice_note = quickNote.trim();
            }
            if (act.orderId) body.order_id = act.orderId;
            if (act.deliveryPointId) {
                body.delivery_point_id = act.deliveryPointId;
            } else if (act.targetLocation?.id) {
                body.new_delivery_location_id = act.targetLocation.id;
            }

            await api.trips.checkpoint(String(tripId), body, token);

            showAlert("Thành công", `Đã cập nhật: ${act.label}`);
            setQuickActionModal(null);
            setQuickPhotos([]);
            setQuickNote("");
            await load();
        } catch (e: any) {
            showAlert("Lỗi cập nhật", e.message || "Không thể thực hiện");
        } finally {
            setSubmittingQuick(false);
            hideLoading();
        }
    };
    const userId = user?.id || shift?.driver?.id;

    const fmt = formatKm;

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
    const nextAct = resolveNextAction(detail || trip, userId);
    const deliveryStops = getPhysicalDeliveryStops(detail || trip);

    const legs: any[] = detail?.legs || trip?.legs || [];
    const legsTotalKm =
        legs.length > 0
            ? legs.reduce(
                  (acc: number, l: any) =>
                      acc + (Number(l.distance_km) || 0),
                  0,
              )
            : null;
    const legsLoadedKm =
        legs.length > 0
            ? legs
                  .filter((l: any) => l.is_loaded)
                  .reduce(
                      (acc: number, l: any) =>
                          acc + (Number(l.distance_km) || 0),
                      0,
                  )
            : null;
    const legsEmptyKm =
        legs.length > 0
            ? legs
                  .filter((l: any) => !l.is_loaded)
                  .reduce(
                      (acc: number, l: any) =>
                          acc + (Number(l.distance_km) || 0),
                      0,
                  )
            : null;

    const displayTotalKm = detail?.total_km ?? legsTotalKm ?? trip?.total_km;
    const displayLoadedKm =
        detail?.total_km_loaded ?? legsLoadedKm ?? trip?.total_km_loaded;
    const displayEmptyKm =
        detail?.total_km_empty ?? legsEmptyKm ?? trip?.total_km_empty;
    const displayDriverKm =
        detail?.driver_km != null && Number(detail.driver_km) > 0
            ? detail.driver_km
            : (displayTotalKm ?? null);
    const displayDriverLoadedKm =
        detail?.driver_km_loaded != null && Number(detail.driver_km_loaded) > 0
            ? detail.driver_km_loaded
            : (displayLoadedKm ?? null);
    const displayDriverEmptyKm =
        detail?.driver_km_empty != null && Number(detail.driver_km_empty) > 0
            ? detail.driver_km_empty
            : (displayEmptyKm ?? null);

    // Giả lập vị trí khi test: bắt đầu = điểm lấy của đơn đầu, kết thúc = điểm giao cuối của đơn cuối
    const startFakePoint = toFakePoint(
        `Lấy: ${orders[0]?.pickup_location?.code || "điểm lấy"}`,
        orders[0]?.pickup_location,
    );
    const lastDeliveryPoints: any[] =
        orders[orders.length - 1]?.delivery_points || [];
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
            await flushBeforeCheckpoint();
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
            await load();
            showAlert("Thành công", "Đã bắt đầu chuyến");
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
                    await flushBeforeCheckpoint();
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
                    {(Boolean(detail?.is_multi_driver || isSwapped)
                        ? [
                              {
                                  icon: "speedometer-outline",
                                  label: "Km của bạn",
                                  value: fmt(displayDriverKm),
                                  color: "#4F46E5",
                                  bg: "#EEF2FF",
                              },
                              {
                                  icon: "map-outline",
                                  label: "Toàn chuyến",
                                  value: fmt(displayTotalKm),
                                  color: "#0D9488",
                                  bg: "#F0FDFA",
                              },
                              {
                                  icon: "cube-outline",
                                  label: "Km có hàng",
                                  value: fmt(displayDriverLoadedKm),
                                  color: "#3B82F6",
                                  bg: "#EFF6FF",
                              },
                              {
                                  icon: "arrow-undo-outline",
                                  label: "Km rỗng",
                                  value: fmt(displayDriverEmptyKm),
                                  color: "#F59E0B",
                                  bg: "#FFFBEB",
                              },
                          ]
                        : [
                              {
                                  icon: "speedometer-outline",
                                  label: "Tổng Km",
                                  value: fmt(displayTotalKm),
                                  color: "#4F46E5",
                                  bg: "#EEF2FF",
                                  fullWidth: true,
                              },
                              {
                                  icon: "cube-outline",
                                  label: "Km có hàng",
                                  value: fmt(displayLoadedKm),
                                  color: "#3B82F6",
                                  bg: "#EFF6FF",
                              },
                              {
                                  icon: "arrow-undo-outline",
                                  label: "Km rỗng",
                                  value: fmt(displayEmptyKm),
                                  color: "#F59E0B",
                                  bg: "#FFFBEB",
                              },
                          ]
                    ).map((st2: any, i: number) => (
                        <View
                            key={i}
                            style={[
                                s.statCard,
                                st2.fullWidth ? { width: "100%" } : null,
                            ]}
                        >
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
                        <Text style={s.emptyText}>Chưa có đơn hàng</Text>
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
                                            codes.push(o.pickup_location.code);
                                        (o.delivery_points || []).forEach(
                                            (dp: any) => {
                                                if (dp.location?.code)
                                                    codes.push(
                                                        dp.location.code,
                                                    );
                                            },
                                        );
                                        if (codes.length > 0)
                                            return (
                                                <View style={s.routeWrap}>
                                                    <Ionicons
                                                        name="navigate"
                                                        size={12}
                                                        color="#4F46E5"
                                                    />
                                                    <Text style={s.orderRoute}>
                                                        {codes.join("  →  ")}
                                                    </Text>
                                                </View>
                                            );
                                        return null;
                                    })()}
                                    <View
                                        style={{
                                            flexDirection: "row",
                                            justifyContent: "space-between",
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
                                                        o.type === "HHHK"
                                                            ? "#E0F2FE"
                                                            : "#FEF3C7",
                                                    marginLeft: 6,
                                                }}
                                            >
                                                <Text
                                                    style={{
                                                        fontSize: 10,
                                                        fontWeight: "600",
                                                        color:
                                                            o.type === "HHHK"
                                                                ? "#0369A1"
                                                                : "#B45309",
                                                    }}
                                                >
                                                    {o.type_label || o.type}
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
                                        📏 Km có hàng: {fmt(o.loaded_km ?? (orders.length === 1 ? displayLoadedKm : null))} km
                                    </Text>

                                    {/* Danh sách các điểm giao của đơn */}
                                    {(o.delivery_points || []).length > 0 && (
                                        <View style={s.orderDpList}>
                                            {o.delivery_points.map((dp: any, dpIdx: number) => {
                                                const loc = dp.location;
                                                const dpCode =
                                                    loc?.code ||
                                                    dp.code ||
                                                    `Điểm ${dp.sequence || dpIdx + 1}`;
                                                const dpDone =
                                                    dp.status === "delivered" ||
                                                    dp.status === "completed" ||
                                                    (detail?.checkpoints || trip?.checkpoints || []).some(
                                                        (cp: any) =>
                                                            cp.checkpoint_type === "completed" &&
                                                            Number(cp.delivery_point_id) === Number(dp.id),
                                                    );
                                                const dpArrived =
                                                    dpDone ||
                                                    dp.status === "arrived" ||
                                                    (detail?.checkpoints || trip?.checkpoints || []).some(
                                                        (cp: any) =>
                                                            cp.checkpoint_type === "arrived_delivery" &&
                                                            Number(cp.delivery_point_id) === Number(dp.id),
                                                    );

                                                return (
                                                    <View key={dp.id || dpIdx} style={s.orderDpRow}>
                                                        <View style={s.orderDpDot}>
                                                            <Text style={s.orderDpSeq}>
                                                                {dp.sequence || dpIdx + 1}
                                                            </Text>
                                                        </View>
                                                        <View style={{ flex: 1, marginRight: 8 }}>
                                                            <Text style={s.orderDpName} numberOfLines={1}>
                                                                {dpCode}
                                                            </Text>
                                                            <Text style={s.orderDpAddr} numberOfLines={1}>
                                                                {dp.address || loc?.address || loc?.name || "Điểm giao"}
                                                            </Text>
                                                        </View>
                                                        {dpDone ? (
                                                            <View style={s.dpStatusDoneBadge}>
                                                                <Ionicons
                                                                    name="checkmark-circle"
                                                                    size={13}
                                                                    color="#059669"
                                                                />
                                                                <Text style={s.dpStatusDoneText}>Đã giao</Text>
                                                            </View>
                                                        ) : (
                                                            <TouchableOpacity
                                                                style={[
                                                                    s.dpUpdateBtn,
                                                                    {
                                                                        backgroundColor: dpArrived
                                                                            ? "#10B981"
                                                                            : "#3B82F6",
                                                                    },
                                                                ]}
                                                                onPress={(e) => {
                                                                    e.stopPropagation?.();
                                                                    const locId = dp.location_id || loc?.id;
                                                                    const stop = deliveryStops.find(
                                                                        (st) =>
                                                                            (locId && st.locationId === locId) ||
                                                                            st.deliveryPointIds.includes(dp.id),
                                                                    );
                                                                    if (stop) {
                                                                        handleActionPress(
                                                                            createActionForDeliveryStop(stop),
                                                                        );
                                                                    } else {
                                                                        handleActionPress({
                                                                            type: dpArrived
                                                                                ? "completed"
                                                                                : "arrived_delivery",
                                                                            label: dpArrived
                                                                                ? `Hoàn thành giao (${dpCode})`
                                                                                : `Đến giao hàng (${dpCode})`,
                                                                            sub: dp.address || loc?.name,
                                                                            icon: dpArrived
                                                                                ? "checkmark-circle"
                                                                                : "location",
                                                                            color: dpArrived
                                                                                ? "#10B981"
                                                                                : "#3B82F6",
                                                                            bg: dpArrived
                                                                                ? "#ECFDF5"
                                                                                : "#EFF6FF",
                                                                            orderId: o.id,
                                                                            deliveryPointId: dp.id,
                                                                            targetLocation: loc,
                                                                            pointLabel: dpCode,
                                                                        });
                                                                    }
                                                                }}
                                                                activeOpacity={0.8}
                                                            >
                                                                <Ionicons
                                                                    name={dpArrived ? "checkmark" : "location"}
                                                                    size={12}
                                                                    color="#fff"
                                                                />
                                                                <Text style={s.dpUpdateBtnText}>
                                                                    {dpArrived ? "Giao xong" : "Đến giao"}
                                                                </Text>
                                                            </TouchableOpacity>
                                                        )}
                                                    </View>
                                                );
                                            })}
                                        </View>
                                    )}
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

            {/* Sticky bottom — Cập nhật bước tiếp theo & Đảo lái */}
            {(nextAct || canSwap || canEnd) && !isSwapped && (
                <View style={[s.stickyBar, { flexDirection: "row", gap: 10 }]}>
                    {canSwap && (
                        <TouchableOpacity
                            style={[
                                s.stickyBtn,
                                {
                                    backgroundColor: "#F3F4F6",
                                    flex: nextAct ? 0.8 : 1,
                                    borderWidth: 1,
                                    borderColor: "#E5E7EB",
                                },
                            ]}
                            onPress={() => setShowSwapModal(true)}
                            disabled={swapping || submittingQuick}
                            activeOpacity={0.8}
                        >
                            <Ionicons
                                name="swap-horizontal"
                                size={18}
                                color="#4B5563"
                            />
                            <Text style={[s.stickyBtnText, { color: "#374151" }]}>
                                Đảo lái
                            </Text>
                        </TouchableOpacity>
                    )}
                    {nextAct && (
                        <TouchableOpacity
                            style={[
                                s.stickyBtn,
                                {
                                    backgroundColor: nextAct.color,
                                    flex: canSwap ? 2.2 : 1,
                                },
                            ]}
                            onPress={() => handleActionPress(nextAct)}
                            disabled={starting || completing || submittingQuick}
                            activeOpacity={0.8}
                        >
                            <Ionicons
                                name={(nextAct.icon as any) || "cube"}
                                size={20}
                                color="#fff"
                            />
                            <View style={{ alignItems: "center" }}>
                                <Text style={s.stickyBtnText} numberOfLines={1}>
                                    {nextAct.label}
                                </Text>
                                {nextAct.multiOrderCount && nextAct.multiOrderCount > 1 ? (
                                    <Text
                                        style={{
                                            fontSize: 10,
                                            color: "rgba(255,255,255,0.9)",
                                            fontWeight: "600",
                                        }}
                                    >
                                        ({nextAct.multiOrderCount} đơn cùng điểm)
                                    </Text>
                                ) : null}
                            </View>
                        </TouchableOpacity>
                    )}
                    {!nextAct && canEnd && (
                        <TouchableOpacity
                            style={[
                                s.stickyBtn,
                                { backgroundColor: "#DC2626", flex: canSwap ? 2.2 : 1 },
                            ]}
                            onPress={handleEnd}
                            disabled={completing || submittingQuick}
                            activeOpacity={0.8}
                        >
                            <Ionicons name="flag" size={20} color="#fff" />
                            <Text style={s.stickyBtnText}>
                                {completing
                                    ? "Đang xử lý..."
                                    : "Kết thúc chuyến"}
                            </Text>
                        </TouchableOpacity>
                    )}
                </View>
            )}

            {/* Modal Cập nhật nhanh bước tiếp theo */}
            <Modal
                visible={!!quickActionModal}
                transparent
                animationType="fade"
                onRequestClose={() => setQuickActionModal(null)}
            >
                <KeyboardAvoidingView
                    behavior={Platform.OS === "ios" ? "padding" : undefined}
                    style={s.modalOverlayCenter}
                >
                    <Pressable
                        style={s.modalBackdrop}
                        onPress={() => setQuickActionModal(null)}
                    />
                    <View style={s.quickModalCard}>
                        {/* Header */}
                        <View style={s.quickModalHeader}>
                            <View
                                style={[
                                    s.quickModalIconWrap,
                                    { backgroundColor: quickActionModal?.bg || "#EEF2FF" },
                                ]}
                            >
                                <Ionicons
                                    name={(quickActionModal?.icon as any) || "cube"}
                                    size={22}
                                    color={quickActionModal?.color || "#4F46E5"}
                                />
                            </View>
                            <View style={{ flex: 1 }}>
                                <Text style={s.quickModalTitle}>
                                    {quickActionModal?.label}
                                </Text>
                                {quickActionModal?.sub ? (
                                    <Text style={s.quickModalSub} numberOfLines={2}>
                                        {quickActionModal.sub}
                                    </Text>
                                ) : null}
                            </View>
                            <TouchableOpacity
                                onPress={() => setQuickActionModal(null)}
                                hitSlop={12}
                                style={s.quickModalCloseBtn}
                            >
                                <Ionicons name="close" size={20} color="#64748B" />
                            </TouchableOpacity>
                        </View>

                        {/* Bộ chọn điểm giao hàng (khi có nhiều hơn 1 điểm và đang ở bước giao hàng) */}
                        {(quickActionModal?.type === "arrived_delivery" || quickActionModal?.type === "completed") &&
                            deliveryStops.length > 1 && (
                                <View style={{ marginBottom: 14 }}>
                                    <Text style={s.quickSectionLabel}>
                                        CHỌN ĐIỂM GIAO ({deliveryStops.length} ĐIỂM)
                                    </Text>
                                    <ScrollView
                                        horizontal
                                        showsHorizontalScrollIndicator={false}
                                        contentContainerStyle={{ gap: 8, paddingVertical: 2 }}
                                    >
                                        {deliveryStops.map((stop) => {
                                            const isSelected = quickActionModal.stopKey === stop.key;
                                            const isDone = stop.allCompleted;
                                            const statusText = isDone
                                                ? "Đã giao"
                                                : stop.anyArrived
                                                  ? "Đã đến"
                                                  : "Chờ giao";
                                            const statusBg = isDone
                                                ? "#D1FAE5"
                                                : stop.anyArrived
                                                  ? "#FEF3C7"
                                                  : "#F1F5F9";
                                            const statusColor = isDone
                                                ? "#059669"
                                                : stop.anyArrived
                                                  ? "#D97706"
                                                  : "#64748B";

                                            return (
                                                <TouchableOpacity
                                                    key={stop.key}
                                                    style={[
                                                        s.stopChip,
                                                        isSelected && s.stopChipSelected,
                                                        isDone && { opacity: 0.6 },
                                                    ]}
                                                    onPress={() => {
                                                        if (isDone) {
                                                            showAlert(
                                                                "Đã hoàn thành",
                                                                "Điểm giao này đã được giao xong",
                                                            );
                                                            return;
                                                        }
                                                        const newAct = createActionForDeliveryStop(stop);
                                                        setQuickActionModal(newAct);
                                                    }}
                                                    activeOpacity={0.7}
                                                >
                                                    <View
                                                        style={{
                                                            flexDirection: "row",
                                                            alignItems: "center",
                                                            gap: 6,
                                                            marginBottom: 3,
                                                        }}
                                                    >
                                                        <Ionicons
                                                            name={
                                                                isSelected
                                                                    ? "radio-button-on"
                                                                    : "radio-button-off"
                                                            }
                                                            size={15}
                                                            color={isSelected ? "#4F46E5" : "#94A3B8"}
                                                        />
                                                        <Text
                                                            style={[
                                                                s.stopChipName,
                                                                isSelected && {
                                                                    color: "#4F46E5",
                                                                    fontWeight: "700",
                                                                },
                                                            ]}
                                                            numberOfLines={1}
                                                        >
                                                            {stop.locationCode ||
                                                                stop.locationName ||
                                                                `Điểm ${stop.sequence}`}
                                                        </Text>
                                                    </View>
                                                    <View
                                                        style={{
                                                            flexDirection: "row",
                                                            alignItems: "center",
                                                            gap: 5,
                                                        }}
                                                    >
                                                        <View
                                                            style={[
                                                                s.stopStatusPill,
                                                                { backgroundColor: statusBg },
                                                            ]}
                                                        >
                                                            <Text
                                                                style={[
                                                                    s.stopStatusText,
                                                                    { color: statusColor },
                                                                ]}
                                                            >
                                                                {statusText}
                                                            </Text>
                                                        </View>
                                                        {stop.orderIds.length > 1 && (
                                                            <Text style={s.stopMultiText}>
                                                                {stop.orderIds.length} đơn
                                                            </Text>
                                                        )}
                                                    </View>
                                                </TouchableOpacity>
                                            );
                                        })}
                                    </ScrollView>
                                </View>
                            )}

                        {/* Multi-order notice */}
                        {quickActionModal?.multiOrderCount && quickActionModal.multiOrderCount > 1 ? (
                            <View style={s.multiOrderNoticeBox}>
                                <Ionicons name="layers" size={16} color="#0369A1" />
                                <Text style={s.multiOrderNoticeText}>
                                    Cùng điểm: áp dụng cho {quickActionModal.multiOrderCount} đơn (
                                    {quickActionModal.orderCodes?.join(", ")})
                                </Text>
                            </View>
                        ) : null}

                        {/* Photo row */}
                        <Text style={s.quickSectionLabel}>HÌNH ẢNH XÁC THỰC</Text>
                        <View style={s.quickPhotoRow}>
                            <TouchableOpacity
                                style={s.quickAddPhotoBtn}
                                activeOpacity={0.7}
                                onPress={handleTakeQuickCamera}
                            >
                                <Ionicons name="camera" size={22} color="#4F46E5" />
                                <Text style={s.quickAddPhotoText}>Chụp ảnh</Text>
                            </TouchableOpacity>

                            <TouchableOpacity
                                style={[
                                    s.quickAddPhotoBtn,
                                    { backgroundColor: "#F8FAFC", borderColor: "#CBD5E1" },
                                ]}
                                activeOpacity={0.7}
                                onPress={handlePickQuickGallery}
                            >
                                <Ionicons name="images" size={22} color="#64748B" />
                                <Text style={[s.quickAddPhotoText, { color: "#64748B" }]}>Thư viện</Text>
                            </TouchableOpacity>

                            {quickPhotos.map((uri, idx) => (
                                <View key={idx} style={s.quickThumbWrap}>
                                    <Image source={{ uri }} style={s.quickThumb} />
                                    <TouchableOpacity
                                        style={s.quickThumbRemove}
                                        onPress={() =>
                                            setQuickPhotos((prev) => prev.filter((_, i) => i !== idx))
                                        }
                                    >
                                        <Ionicons name="close" size={12} color="#fff" />
                                    </TouchableOpacity>
                                </View>
                            ))}
                        </View>

                        {/* Notes */}
                        <Text style={s.quickSectionLabel}>GHI CHÚ (KHÔNG BẮT BUỘC)</Text>
                        <TextInput
                            style={s.quickNoteInput}
                            placeholder="Ghi chú thêm nếu có..."
                            placeholderTextColor="#94A3B8"
                            value={quickNote}
                            onChangeText={setQuickNote}
                            multiline
                            numberOfLines={3}
                        />

                        {/* Buttons */}
                        <View style={s.quickBtnRow}>
                            <TouchableOpacity
                                style={s.quickCancelBtn}
                                onPress={() => setQuickActionModal(null)}
                                disabled={submittingQuick}
                            >
                                <Text style={s.quickCancelText}>Huỷ</Text>
                            </TouchableOpacity>
                            <TouchableOpacity
                                style={[
                                    s.quickSubmitBtn,
                                    { backgroundColor: quickActionModal?.color || "#4F46E5" },
                                    submittingQuick && { opacity: 0.7 },
                                ]}
                                activeOpacity={0.85}
                                onPress={handleSubmitQuickAction}
                                disabled={submittingQuick}
                            >
                                {submittingQuick ? (
                                    <ActivityIndicator size="small" color="#fff" />
                                ) : (
                                    <Text style={s.quickSubmitText}>Xác nhận gửi</Text>
                                )}
                            </TouchableOpacity>
                        </View>
                    </View>
                </KeyboardAvoidingView>
            </Modal>

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
    legsCard: {
        backgroundColor: "#fff",
        borderRadius: 14,
        padding: 14,
        marginBottom: 14,
        borderWidth: 1,
        borderColor: "#E5E7EB",
    },
    legItem: {
        flexDirection: "row",
        alignItems: "flex-start",
        marginBottom: 12,
    },
    legIndicator: {
        alignItems: "center",
        width: 18,
        marginRight: 8,
        marginTop: 4,
    },
    legDot: {
        width: 10,
        height: 10,
        borderRadius: 5,
    },
    legLine: {
        width: 2,
        height: 38,
        backgroundColor: "#E5E7EB",
        marginTop: 2,
    },
    legContent: {
        flex: 1,
    },
    legTitleRow: {
        marginBottom: 4,
    },
    legTitle: {
        fontSize: 13,
        fontWeight: "600",
        color: "#1F2937",
    },
    legMetaRow: {
        flexDirection: "row",
        alignItems: "center",
        gap: 6,
    },
    legBadge: {
        paddingHorizontal: 6,
        paddingVertical: 1,
        borderRadius: 4,
    },
    legBadgeText: {
        fontSize: 10,
        fontWeight: "700",
    },
    legKmText: {
        fontSize: 13,
        fontWeight: "700",
        color: "#111827",
    },
    legSourceText: {
        fontSize: 11,
        color: "#9CA3AF",
    },
    modalOverlayCenter: {
        flex: 1,
        backgroundColor: "rgba(15, 23, 42, 0.5)",
        justifyContent: "center",
        alignItems: "center",
        paddingHorizontal: 16,
    },
    modalBackdrop: {
        position: "absolute",
        top: 0,
        left: 0,
        right: 0,
        bottom: 0,
    },
    quickModalCard: {
        backgroundColor: "#FFFFFF",
        borderRadius: 20,
        padding: 20,
        width: "100%",
        maxWidth: 390,
        shadowColor: "#0F172A",
        shadowOffset: { width: 0, height: 10 },
        shadowOpacity: 0.15,
        shadowRadius: 24,
        elevation: 8,
    },
    quickModalHeader: {
        flexDirection: "row",
        alignItems: "center",
        gap: 12,
        marginBottom: 14,
        paddingBottom: 12,
        borderBottomWidth: 1,
        borderBottomColor: "#F1F5F9",
    },
    quickModalIconWrap: {
        width: 44,
        height: 44,
        borderRadius: 14,
        alignItems: "center",
        justifyContent: "center",
    },
    quickModalTitle: {
        fontSize: 16,
        fontWeight: "800",
        color: "#0F172A",
        letterSpacing: -0.2,
    },
    quickModalSub: {
        fontSize: 12,
        color: "#64748B",
        marginTop: 2,
    },
    quickModalCloseBtn: {
        width: 32,
        height: 32,
        borderRadius: 16,
        backgroundColor: "#F1F5F9",
        alignItems: "center",
        justifyContent: "center",
    },
    multiOrderNoticeBox: {
        flexDirection: "row",
        alignItems: "center",
        gap: 8,
        backgroundColor: "#E0F2FE",
        paddingHorizontal: 12,
        paddingVertical: 8,
        borderRadius: 10,
        marginBottom: 14,
    },
    multiOrderNoticeText: {
        fontSize: 12,
        color: "#0369A1",
        fontWeight: "600",
        flex: 1,
    },
    quickSectionLabel: {
        fontSize: 11,
        fontWeight: "700",
        color: "#64748B",
        marginBottom: 8,
        letterSpacing: 0.5,
    },
    quickPhotoRow: {
        flexDirection: "row",
        alignItems: "center",
        gap: 8,
        marginBottom: 16,
        flexWrap: "wrap",
    },
    quickAddPhotoBtn: {
        width: 72,
        height: 72,
        borderRadius: 12,
        borderWidth: 1.5,
        borderColor: "#C7D2FE",
        borderStyle: "dashed",
        backgroundColor: "#EEF2FF",
        alignItems: "center",
        justifyContent: "center",
        gap: 4,
    },
    quickAddPhotoText: {
        fontSize: 11,
        fontWeight: "700",
        color: "#4F46E5",
    },
    quickThumbWrap: {
        position: "relative",
    },
    quickThumb: {
        width: 72,
        height: 72,
        borderRadius: 12,
        borderWidth: 1,
        borderColor: "#E2E8F0",
    },
    quickThumbRemove: {
        position: "absolute",
        top: -5,
        right: -5,
        backgroundColor: "#EF4444",
        width: 20,
        height: 20,
        borderRadius: 10,
        alignItems: "center",
        justifyContent: "center",
    },
    quickNoteInput: {
        borderRadius: 12,
        backgroundColor: "#F8FAFC",
        borderWidth: 1,
        borderColor: "#E2E8F0",
        padding: 12,
        fontSize: 14,
        color: "#0F172A",
        minHeight: 74,
        textAlignVertical: "top",
        marginBottom: 18,
    },
    quickBtnRow: {
        flexDirection: "row",
        gap: 10,
    },
    quickCancelBtn: {
        flex: 1,
        height: 46,
        borderRadius: 12,
        backgroundColor: "#F1F5F9",
        alignItems: "center",
        justifyContent: "center",
    },
    quickCancelText: {
        fontSize: 14,
        fontWeight: "600",
        color: "#475569",
    },
    quickSubmitBtn: {
        flex: 1.6,
        height: 46,
        borderRadius: 12,
        alignItems: "center",
        justifyContent: "center",
        shadowColor: "#0F172A",
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.18,
        shadowRadius: 5,
        elevation: 3,
    },
    quickSubmitText: {
        fontSize: 14,
        fontWeight: "700",
        color: "#FFFFFF",
    },
    stopChip: {
        borderRadius: 12,
        borderWidth: 1.5,
        borderColor: "#E2E8F0",
        backgroundColor: "#F8FAFC",
        paddingHorizontal: 12,
        paddingVertical: 8,
        minWidth: 125,
    },
    stopChipSelected: {
        borderColor: "#4F46E5",
        backgroundColor: "#EEF2FF",
    },
    stopChipName: {
        fontSize: 13,
        fontWeight: "600",
        color: "#1E293B",
    },
    stopStatusPill: {
        paddingHorizontal: 6,
        paddingVertical: 2,
        borderRadius: 6,
    },
    stopStatusText: {
        fontSize: 10,
        fontWeight: "700",
    },
    stopMultiText: {
        fontSize: 10,
        color: "#64748B",
        fontWeight: "600",
    },
    orderDpList: {
        marginTop: 10,
        paddingTop: 8,
        borderTopWidth: 1,
        borderTopColor: "#F1F5F9",
        gap: 6,
    },
    orderDpRow: {
        flexDirection: "row",
        alignItems: "center",
        backgroundColor: "#F8FAFC",
        padding: 8,
        borderRadius: 10,
    },
    orderDpDot: {
        width: 20,
        height: 20,
        borderRadius: 10,
        backgroundColor: "#EEF2FF",
        alignItems: "center",
        justifyContent: "center",
        marginRight: 8,
    },
    orderDpSeq: {
        fontSize: 11,
        fontWeight: "700",
        color: "#4F46E5",
    },
    orderDpName: {
        fontSize: 13,
        fontWeight: "700",
        color: "#1E293B",
    },
    orderDpAddr: {
        fontSize: 11,
        color: "#64748B",
    },
    dpStatusDoneBadge: {
        flexDirection: "row",
        alignItems: "center",
        gap: 3,
        paddingHorizontal: 8,
        paddingVertical: 4,
        borderRadius: 8,
        backgroundColor: "#ECFDF5",
    },
    dpStatusDoneText: {
        fontSize: 11,
        fontWeight: "700",
        color: "#059669",
    },
    dpUpdateBtn: {
        flexDirection: "row",
        alignItems: "center",
        gap: 4,
        paddingHorizontal: 10,
        paddingVertical: 5,
        borderRadius: 8,
    },
    dpUpdateBtnText: {
        fontSize: 11,
        fontWeight: "700",
        color: "#FFFFFF",
    },
});