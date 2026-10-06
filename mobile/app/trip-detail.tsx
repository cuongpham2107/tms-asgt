import { resolveNextAction, type NextAction } from "../src/lib/tripActions";
import * as ImagePicker from "expo-image-picker";
import { formatKm } from "../src/lib/format";
import { useState, useCallback, useMemo } from "react";
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
} from "react-native";
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

const SWAP_REASON_DESCRIPTIONS: Record<SwapReason, { desc: string }> = {
    shift_handover: {
        desc: "Hết ca làm việc, chuyển giao xe cho lái khác",
    },
    cargo_not_unloaded: {
        desc: "Kho chưa nhận hàng, bàn giao xe lại cho điều hành",
    },
    other: {
        desc: "Lý do cá nhân hoặc sự cố phát sinh",
    },
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
    const userId = user?.id || shift?.driver?.id;
    const [actionSubmitting, setActionSubmitting] = useState(false);
    const [actionPhotos, setActionPhotos] = useState<string[]>([]);
    const [actionNote, setActionNote] = useState("");
    const [showNoteModal, setShowNoteModal] = useState(false);

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

    const allFakePoints = useMemo(() => {
        const pts: FakePoint[] = [];
        orders.forEach((o: any) => {
            if (o.pickup_location) {
                const p = toFakePoint(
                    `Lấy: ${o.pickup_location.code || "Kho lấy"}`,
                    o.pickup_location,
                );
                if (p && !pts.some((x) => x.lat === p.lat && x.lng === p.lng)) {
                    pts.push(p);
                }
            }
            (o.delivery_points || []).forEach((dp: any) => {
                const loc = dp.location;
                if (loc) {
                    const p = toFakePoint(
                        `Giao: ${loc.code || dp.code || `Điểm ${dp.sequence}`}`,
                        loc,
                    );
                    if (p && !pts.some((x) => x.lat === p.lat && x.lng === p.lng)) {
                        pts.push(p);
                    }
                }
            });
        });
        return pts.length > 0 ? pts : fakePoints;
    }, [orders, fakePoints]);

    const nextAction = resolveNextAction(detail || trip, userId);

    const handlePickPhoto = async () => {
        try {
            const { status } =
                await ImagePicker.requestCameraPermissionsAsync();
            if (status !== "granted") {
                showAlert("Quyền truy cập", "Cần cấp quyền camera để chụp ảnh");
                return;
            }
            const res = await ImagePicker.launchCameraAsync({
                allowsEditing: false,
                quality: 0.7,
            });
            if (!res.canceled && res.assets?.[0]?.uri) {
                setActionPhotos((prev) => [...prev, res.assets[0].uri]);
            }
        } catch (e: any) {
            const msg = String(e?.message || "");
            // Trình giả lập (Simulator) không có phần cứng camera -> fallback mở thư viện ảnh
            if (
                msg.toLowerCase().includes("simulator") ||
                msg.toLowerCase().includes("not available") ||
                msg.toLowerCase().includes("unavailable")
            ) {
                try {
                    const libRes = await ImagePicker.launchImageLibraryAsync({
                        mediaTypes: ImagePicker.MediaTypeOptions.Images,
                        allowsEditing: false,
                        quality: 0.7,
                    });
                    if (!libRes.canceled && libRes.assets?.[0]?.uri) {
                        setActionPhotos((prev) => [...prev, libRes.assets[0].uri]);
                    }
                    return;
                } catch (libErr: any) {
                    showAlert("Lỗi chọn ảnh", libErr?.message || "Không thể mở thư viện ảnh");
                    return;
                }
            }
            showAlert("Lỗi chụp ảnh", msg || "Không thể mở camera");
        }
    };

    const handleExecuteNextAction = async (act: NextAction) => {
        if (!tripId || !token) return;

        if (!shift && act.type !== "started") {
            showAlert("Cần vào ca", "Bạn cần vào ca trước khi thực hiện thao tác");
            return;
        }

        if (act.type === "end") {
            showDestructiveConfirm(
                "Kết thúc xe",
                "Bạn có chắc chắn muốn kết thúc xe và hoàn thành chuyến đi?",
                async () => {
                    await doSubmitCheckpoint(act);
                },
            );
            return;
        }

        await doSubmitCheckpoint(act);
    };

    const doSubmitCheckpoint = async (act: NextAction) => {
        if (!token || !tripId) return;
        setActionSubmitting(true);
        showLoading();
        try {
            await flushBeforeCheckpoint();

            const fakeFallback = act.targetLocation
                ? toFakePoint(act.pointLabel || act.label, act.targetLocation)
                : null;
            const gps = await getCheckpointGps(fakeFallback);

            const body: any = {
                checkpoint_type: act.type,
                occurred_at: localISO(),
            };
            if (gps) {
                body.gps_lat = gps.gps_lat;
                body.gps_lng = gps.gps_lng;
            }
            if (actionNote.trim()) body.voice_note = actionNote.trim();
            if (actionPhotos.length > 0) body.photos = actionPhotos;
            if (act.orderId) body.order_id = act.orderId;
            if (act.deliveryPointId) body.delivery_point_id = act.deliveryPointId;

            await api.trips.checkpoint(String(tripId), body, token);
            showAlert("Thành công", `Đã cập nhật: ${act.label}`);
            setActionNote("");
            setActionPhotos([]);
            setShowNoteModal(false);
            await load();
        } catch (e: any) {
            const msg = e.message || "";
            const match = msg.match(/#(\d+)/);
            showAlert("Không thể cập nhật", msg, () => {
                if (match)
                    router.push({
                        pathname: "/trip-detail",
                        params: { id: match[1] },
                    });
            });
        } finally {
            setActionSubmitting(false);
            hideLoading();
        }
    };

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
            const updated = await load();
            const currentOrders: any[] =
                updated?.orders || detail?.orders || trip?.orders || [];
            const targetOrder =
                currentOrders.find((o: any) => o.status !== "completed") ||
                currentOrders[0];
            if (targetOrder) {
                router.push({
                    pathname: "/order-detail",
                    params: {
                        id: targetOrder.id,
                        order: JSON.stringify({
                            ...targetOrder,
                            trip_id: tripId,
                            vehicle:
                                updated?.vehicle ||
                                detail?.vehicle ||
                                trip?.vehicle,
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
                    {/* Fake location picker khi test */}
                    {allFakePoints.length > 0 && (
                        <FakeLocationPicker points={allFakePoints} />
                    )}
                    {!shift && currentStatus !== "completed" && (
                        <View style={s.warnBox}>
                            <Text style={s.warnText}>
                                Bạn cần vào ca trước khi thao tác chuyến.
                            </Text>
                        </View>
                    )}
                </View>

                {/* COCKPIT ACTION CARD — KHỐI HÀNH ĐỘNG KẾ TIẾP */}
                {nextAction && !isSwapped && (
                    <View style={s.cockpitCard}>
                        {/* Thanh chỉ báo màu mốc hành động */}
                        <View style={[s.cockpitAccentBar, { backgroundColor: nextAction.color }]} />

                        <View style={s.cockpitBody}>
                            <View style={s.cockpitHeader}>
                                <View style={[s.cockpitTagPill, { backgroundColor: nextAction.bg }]}>
                                    <View style={[s.pulsingDot, { backgroundColor: nextAction.color }]} />
                                    <Text style={[s.cockpitTag, { color: nextAction.color }]}>
                                        BƯỚC TIẾP THEO
                                    </Text>
                                </View>
                                {nextAction.pointLabel && (
                                    <View style={s.locBadge}>
                                        <Ionicons name="location-sharp" size={11} color="#475569" />
                                        <Text style={s.locBadgeText}>
                                            {nextAction.pointLabel}
                                        </Text>
                                    </View>
                                )}
                            </View>

                            <Text style={s.cockpitTitle}>{nextAction.label}</Text>
                            {nextAction.sub ? (
                                <View style={s.cockpitSubRow}>
                                    <Ionicons name="navigate-outline" size={13} color="#94A3B8" />
                                    <Text style={s.cockpitSub} numberOfLines={2}>
                                        {nextAction.sub}
                                    </Text>
                                </View>
                            ) : null}

                            {/* Preview ảnh đính kèm */}
                            {actionPhotos.length > 0 && (
                                <ScrollView
                                    horizontal
                                    style={s.photoPreviewRow}
                                    showsHorizontalScrollIndicator={false}
                                >
                                    {actionPhotos.map((uri, idx) => (
                                        <View key={idx} style={s.photoThumbWrap}>
                                            <Image source={{ uri }} style={s.photoThumb} />
                                            <TouchableOpacity
                                                style={s.photoRemoveBtn}
                                                onPress={() =>
                                                    setActionPhotos((prev) =>
                                                        prev.filter((_, i) => i !== idx),
                                                    )
                                                }
                                            >
                                                <Ionicons name="close" size={12} color="#fff" />
                                            </TouchableOpacity>
                                        </View>
                                    ))}
                                </ScrollView>
                            )}

                            {/* Preview ghi chú đính kèm */}
                            {actionNote.trim().length > 0 && (
                                <View style={s.notePreviewBox}>
                                    <Ionicons
                                        name="document-text"
                                        size={14}
                                        color="#4F46E5"
                                    />
                                    <Text style={s.notePreviewText} numberOfLines={1}>
                                        {actionNote}
                                    </Text>
                                    <TouchableOpacity
                                        onPress={() => setActionNote("")}
                                        hitSlop={8}
                                    >
                                        <Ionicons name="close-circle" size={15} color="#94A3B8" />
                                    </TouchableOpacity>
                                </View>
                            )}

                            {/* Nút bấm hành động chính (Big Button) */}
                            <TouchableOpacity
                                style={[s.bigActionBtn, { backgroundColor: nextAction.color }]}
                                activeOpacity={0.85}
                                onPress={() => handleExecuteNextAction(nextAction)}
                                disabled={actionSubmitting}
                            >
                                <Ionicons name={nextAction.icon as any} size={20} color="#fff" />
                                <Text style={s.bigActionBtnText}>
                                    {actionSubmitting ? "Đang xử lý..." : nextAction.label}
                                </Text>
                                <Ionicons name="arrow-forward" size={16} color="rgba(255,255,255,0.85)" />
                            </TouchableOpacity>

                            {/* Thanh công cụ phụ: Chụp ảnh, Ghi chú, Đảo lái */}
                            <View style={s.subActionsRow}>
                                <TouchableOpacity
                                    style={[
                                        s.subActionBtn,
                                        actionPhotos.length > 0 && s.subActionBtnActive,
                                    ]}
                                    onPress={handlePickPhoto}
                                    activeOpacity={0.7}
                                >
                                    <Ionicons
                                        name="camera-outline"
                                        size={16}
                                        color={actionPhotos.length > 0 ? "#4F46E5" : "#475569"}
                                    />
                                    <Text
                                        style={[
                                            s.subActionText,
                                            actionPhotos.length > 0 && s.subActionTextActive,
                                        ]}
                                    >
                                        {actionPhotos.length > 0
                                            ? `Ảnh (${actionPhotos.length})`
                                            : "Chụp ảnh"}
                                    </Text>
                                </TouchableOpacity>

                                <TouchableOpacity
                                    style={[
                                        s.subActionBtn,
                                        actionNote.trim().length > 0 && s.subActionBtnActive,
                                    ]}
                                    onPress={() => setShowNoteModal(true)}
                                    activeOpacity={0.7}
                                >
                                    <Ionicons
                                        name="document-text-outline"
                                        size={16}
                                        color={actionNote.trim().length > 0 ? "#4F46E5" : "#475569"}
                                    />
                                    <Text
                                        style={[
                                            s.subActionText,
                                            actionNote.trim().length > 0 && s.subActionTextActive,
                                        ]}
                                    >
                                        {actionNote.trim().length > 0 ? "Sửa ghi chú" : "Ghi chú"}
                                    </Text>
                                </TouchableOpacity>

                                {canSwap && (
                                    <TouchableOpacity
                                        style={[s.subActionBtn, s.subActionSwapBtn]}
                                        onPress={() => setShowSwapModal(true)}
                                        activeOpacity={0.7}
                                    >
                                        <Ionicons
                                            name="swap-horizontal"
                                            size={16}
                                            color="#D97706"
                                        />
                                        <Text style={[s.subActionText, { color: "#B45309" }]}>
                                            Đảo lái
                                        </Text>
                                    </TouchableOpacity>
                                )}
                            </View>
                        </View>
                    </View>
                )}
                {/* Km stats (tính từ GPS ở server, null → —) */}
                <View style={s.statsGrid}>
                    {(Boolean(detail?.is_multi_driver || isSwapped)
                        ? [
                              {
                                  icon: "speedometer-outline",
                                  label: "Km của bạn",
                                  value: fmt(
                                      detail?.driver_km ??
                                          detail?.total_km ??
                                          trip?.total_km,
                                  ),
                                  color: "#4F46E5",
                                  bg: "#EEF2FF",
                              },
                              {
                                  icon: "map-outline",
                                  label: "Toàn chuyến",
                                  value: fmt(
                                      detail?.total_km ?? trip?.total_km,
                                  ),
                                  color: "#0D9488",
                                  bg: "#F0FDFA",
                              },
                              {
                                  icon: "cube-outline",
                                  label: "Km có hàng",
                                  value: fmt(
                                      detail?.driver_km_loaded ??
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
                                      detail?.driver_km_empty ??
                                          detail?.total_km_empty ??
                                          trip?.total_km_empty,
                                  ),
                                  color: "#F59E0B",
                                  bg: "#FFFBEB",
                              },
                          ]
                        : [
                              {
                                  icon: "speedometer-outline",
                                  label: "Tổng Km",
                                  value: fmt(
                                      detail?.total_km ?? trip?.total_km,
                                  ),
                                  color: "#4F46E5",
                                  bg: "#EEF2FF",
                                  fullWidth: true,
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
                            style={[
                                s.stickyBtn,
                                { backgroundColor: "#4F46E5" },
                            ]}
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
                            style={[
                                s.stickyBtn,
                                { backgroundColor: "#DC2626" },
                            ]}
                            onPress={handleEnd}
                            disabled={completing}
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

            {/* Modal Đảo lái */}
            <Modal
                visible={showSwapModal}
                transparent
                animationType="fade"
                onRequestClose={() => setShowSwapModal(false)}
            >
                <KeyboardAvoidingView
                    behavior={Platform.OS === "ios" ? "padding" : undefined}
                    style={s.modalOverlay}
                >
                    <Pressable style={s.modalBackdrop} onPress={() => setShowSwapModal(false)} />
                    <View style={[s.modalCard, { maxWidth: 390 }]}>
                        {/* Header */}
                        <View style={s.modalHeaderRow}>
                            <View style={s.modalHeaderIconWrap}>
                                <Ionicons name="swap-horizontal" size={20} color="#D97706" />
                            </View>
                            <View style={{ flex: 1 }}>
                                <Text style={s.modalHeaderTitle}>Xin đảo lái</Text>
                                <Text style={s.modalHeaderSubtitle}>Bàn giao chuyến cho điều hành</Text>
                            </View>
                            <TouchableOpacity
                                onPress={() => setShowSwapModal(false)}
                                hitSlop={12}
                                style={s.modalCloseBtn}
                            >
                                <Ionicons name="close" size={20} color="#64748B" />
                            </TouchableOpacity>
                        </View>

                        {/* Lý do */}
                        <Text style={s.modalSectionLabel}>LÝ DO BÀN GIAO *</Text>
                        <View style={{ gap: 8, marginBottom: 14 }}>
                            {SWAP_REASONS.map((r) => {
                                const isSelected = swapReason === r.key;
                                const meta = SWAP_REASON_DESCRIPTIONS[r.key];
                                return (
                                    <TouchableOpacity
                                        key={r.key}
                                        activeOpacity={0.7}
                                        onPress={() => setSwapReason(r.key)}
                                        style={[
                                            s.swapReasonCard,
                                            isSelected && s.swapReasonCardActive,
                                        ]}
                                    >
                                        <View style={[s.radioCircle, isSelected && s.radioCircleActive]}>
                                            {isSelected && <View style={s.radioDot} />}
                                        </View>
                                        <View style={{ flex: 1 }}>
                                            <Text
                                                style={[
                                                    s.swapReasonTitle,
                                                    isSelected && s.swapReasonTitleActive,
                                                ]}
                                            >
                                                {r.label}
                                            </Text>
                                            <Text style={s.swapReasonDesc}>
                                                {meta.desc}
                                            </Text>
                                        </View>
                                    </TouchableOpacity>
                                );
                            })}
                        </View>

                        {/* Ghi chú */}
                        <Text style={s.modalSectionLabel}>
                            GHI CHÚ{swapReason === "other" ? " *" : " (KHÔNG BẮT BUỘC)"}
                        </Text>
                        <TextInput
                            style={[s.stickyInput, s.swapNoteInput]}
                            value={swapNote}
                            onChangeText={setSwapNote}
                            placeholder="Nhập thông tin bàn giao cho điều hành..."
                            placeholderTextColor="#94A3B8"
                            multiline
                            numberOfLines={3}
                        />

                        {/* Action buttons */}
                        <View style={{ flexDirection: "row", gap: 10, marginTop: 4 }}>
                            <TouchableOpacity
                                style={s.modalCancelBtn}
                                onPress={() => setShowSwapModal(false)}
                            >
                                <Text style={s.modalCancelBtnText}>Huỷ</Text>
                            </TouchableOpacity>
                            <TouchableOpacity
                                style={[
                                    s.modalConfirmBtn,
                                    swapping && { opacity: 0.7 },
                                ]}
                                onPress={handleSwap}
                                disabled={swapping}
                            >
                                <Text style={s.modalConfirmBtnText}>
                                    {swapping ? "Đang gửi..." : "Xác nhận gửi"}
                                </Text>
                            </TouchableOpacity>
                        </View>
                    </View>
                </KeyboardAvoidingView>
            </Modal>

            {/* Modal Ghi chú chốt chặng */}
            <Modal
                visible={showNoteModal}
                transparent
                animationType="fade"
                onRequestClose={() => setShowNoteModal(false)}
            >
                <KeyboardAvoidingView
                    behavior={Platform.OS === "ios" ? "padding" : undefined}
                    style={s.modalOverlay}
                >
                    <Pressable
                        style={s.modalBackdrop}
                        onPress={() => setShowNoteModal(false)}
                    />
                    <View style={[s.modalCard, { maxWidth: 390 }]}>
                        <View style={s.modalHeaderRow}>
                            <View style={[s.modalHeaderIconWrap, { backgroundColor: "#EEF2FF" }]}>
                                <Ionicons name="document-text" size={18} color="#4F46E5" />
                            </View>
                            <View style={{ flex: 1 }}>
                                <Text style={s.modalHeaderTitle}>Ghi chú hành động</Text>
                                <Text style={s.modalHeaderSubtitle}>Đính kèm vào mốc cập nhật này</Text>
                            </View>
                            <TouchableOpacity
                                onPress={() => setShowNoteModal(false)}
                                hitSlop={12}
                                style={s.modalCloseBtn}
                            >
                                <Ionicons name="close" size={20} color="#64748B" />
                            </TouchableOpacity>
                        </View>
                        <TextInput
                            style={[s.stickyInput, s.swapNoteInput, { minHeight: 90 }]}
                            placeholder="Nhập ghi chú (VD: Đã dỡ hàng xong, bảo vệ ký...)"
                            placeholderTextColor="#94A3B8"
                            value={actionNote}
                            onChangeText={setActionNote}
                            multiline
                            numberOfLines={4}
                            autoFocus
                        />
                        <View style={{ flexDirection: "row", gap: 10, marginTop: 4 }}>
                            <TouchableOpacity
                                style={s.modalCancelBtn}
                                onPress={() => setShowNoteModal(false)}
                            >
                                <Text style={s.modalCancelBtnText}>Huỷ</Text>
                            </TouchableOpacity>
                            {actionNote.trim().length > 0 && (
                                <TouchableOpacity
                                    style={[s.modalCancelBtn, { backgroundColor: "#FEE2E2", flex: 1 }]}
                                    onPress={() => {
                                        setActionNote("");
                                        setShowNoteModal(false);
                                    }}
                                >
                                    <Text style={{ color: "#DC2626", fontWeight: "700", fontSize: 14 }}>Xoá</Text>
                                </TouchableOpacity>
                            )}
                            <TouchableOpacity
                                style={[s.modalConfirmBtn, { flex: actionNote.trim().length > 0 ? 1.5 : 2 }]}
                                onPress={() => setShowNoteModal(false)}
                            >
                                <Text style={s.modalConfirmBtnText}>Lưu ghi chú</Text>
                            </TouchableOpacity>
                        </View>
                    </View>
                </KeyboardAvoidingView>
            </Modal>
        </KeyboardAvoidingView>
    );
}

const s = StyleSheet.create({
    container: { flex: 1, backgroundColor: "#F9FAFB" },
    cockpitCard: {
        backgroundColor: "#FFFFFF",
        marginHorizontal: 16,
        marginBottom: 14,
        borderRadius: 18,
        borderWidth: 1,
        borderColor: "#E2E8F0",
        shadowColor: "#0F172A",
        shadowOffset: { width: 0, height: 4 },
        shadowOpacity: 0.06,
        shadowRadius: 12,
        elevation: 3,
        overflow: "hidden",
    },
    cockpitAccentBar: {
        height: 3,
        width: "100%",
    },
    cockpitBody: {
        padding: 16,
    },
    cockpitHeader: {
        flexDirection: "row",
        justifyContent: "space-between",
        alignItems: "center",
        marginBottom: 8,
    },
    cockpitTagPill: {
        flexDirection: "row",
        alignItems: "center",
        paddingHorizontal: 8,
        paddingVertical: 3,
        borderRadius: 8,
        gap: 6,
    },
    pulsingDot: {
        width: 6,
        height: 6,
        borderRadius: 3,
    },
    cockpitTag: {
        fontSize: 10,
        fontWeight: "800",
        letterSpacing: 0.6,
    },
    locBadge: {
        flexDirection: "row",
        alignItems: "center",
        backgroundColor: "#F1F5F9",
        paddingHorizontal: 8,
        paddingVertical: 3,
        borderRadius: 8,
        gap: 4,
    },
    locBadgeText: {
        fontSize: 11,
        fontWeight: "700",
        color: "#334155",
    },
    cockpitTitle: {
        fontSize: 19,
        fontWeight: "800",
        color: "#0F172A",
        marginBottom: 4,
        letterSpacing: -0.3,
    },
    cockpitSubRow: {
        flexDirection: "row",
        alignItems: "flex-start",
        gap: 5,
        marginBottom: 14,
    },
    cockpitSub: {
        flex: 1,
        fontSize: 13,
        color: "#64748B",
        lineHeight: 18,
    },
    bigActionBtn: {
        flexDirection: "row",
        alignItems: "center",
        justifyContent: "center",
        height: 52,
        borderRadius: 14,
        gap: 8,
        shadowColor: "#0F172A",
        shadowOffset: { width: 0, height: 4 },
        shadowOpacity: 0.18,
        shadowRadius: 8,
        elevation: 3,
    },
    bigActionBtnText: {
        color: "#FFFFFF",
        fontSize: 16,
        fontWeight: "700",
    },
    subActionsRow: {
        flexDirection: "row",
        alignItems: "center",
        gap: 8,
        marginTop: 10,
    },
    subActionBtn: {
        flex: 1,
        height: 38,
        flexDirection: "row",
        alignItems: "center",
        justifyContent: "center",
        borderRadius: 10,
        borderWidth: 1,
        borderColor: "#E2E8F0",
        backgroundColor: "#F8FAFC",
        gap: 5,
    },
    subActionBtnActive: {
        borderColor: "#C7D2FE",
        backgroundColor: "#EEF2FF",
    },
    subActionSwapBtn: {
        borderColor: "#FDE68A",
        backgroundColor: "#FFFBEB",
    },
    subActionText: {
        fontSize: 12,
        fontWeight: "600",
        color: "#475569",
    },
    subActionTextActive: {
        color: "#4F46E5",
        fontWeight: "700",
    },
    photoPreviewRow: {
        flexDirection: "row",
        marginBottom: 10,
    },
    photoThumbWrap: {
        position: "relative",
        marginRight: 8,
    },
    photoThumb: {
        width: 52,
        height: 52,
        borderRadius: 10,
        borderWidth: 1,
        borderColor: "#E2E8F0",
    },
    photoRemoveBtn: {
        position: "absolute",
        top: -4,
        right: -4,
        backgroundColor: "#EF4444",
        width: 18,
        height: 18,
        borderRadius: 9,
        alignItems: "center",
        justifyContent: "center",
    },
    notePreviewBox: {
        flexDirection: "row",
        alignItems: "center",
        backgroundColor: "#F8FAFC",
        borderWidth: 1,
        borderColor: "#E2E8F0",
        paddingHorizontal: 10,
        paddingVertical: 7,
        borderRadius: 10,
        gap: 6,
        marginBottom: 10,
    },
    notePreviewText: {
        flex: 1,
        fontSize: 12,
        color: "#334155",
        fontWeight: "500",
    },
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
        width: "48%",
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
    modalBackdrop: { position: "absolute", top: 0, left: 0, right: 0, bottom: 0 },
    modalOverlay: {
        flex: 1,
        backgroundColor: "rgba(15, 23, 42, 0.5)",
        justifyContent: "center",
        alignItems: "center",
        paddingHorizontal: 16,
    },
    modalCard: {
        backgroundColor: "#FFFFFF",
        borderRadius: 20,
        padding: 20,
        width: "100%",
        shadowColor: "#0F172A",
        shadowOffset: { width: 0, height: 10 },
        shadowOpacity: 0.15,
        shadowRadius: 24,
        elevation: 8,
    },
    modalHeaderRow: {
        flexDirection: "row",
        alignItems: "center",
        gap: 12,
        marginBottom: 16,
        paddingBottom: 12,
        borderBottomWidth: 1,
        borderBottomColor: "#F1F5F9",
    },
    modalHeaderIconWrap: {
        width: 40,
        height: 40,
        borderRadius: 12,
        backgroundColor: "#FFFBEB",
        alignItems: "center",
        justifyContent: "center",
    },
    modalHeaderTitle: {
        fontSize: 17,
        fontWeight: "800",
        color: "#0F172A",
        letterSpacing: -0.2,
    },
    modalHeaderSubtitle: {
        fontSize: 12,
        color: "#64748B",
        marginTop: 1,
    },
    modalCloseBtn: {
        width: 32,
        height: 32,
        borderRadius: 16,
        backgroundColor: "#F1F5F9",
        alignItems: "center",
        justifyContent: "center",
    },
    modalTitle: {
        fontSize: 18,
        fontWeight: "800",
        color: "#111827",
        marginBottom: 16,
        textAlign: "center",
    },
    modalSectionLabel: {
        fontSize: 11,
        fontWeight: "700",
        color: "#64748B",
        marginBottom: 8,
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
    swapReasonCard: {
        flexDirection: "row",
        alignItems: "center",
        padding: 12,
        borderRadius: 12,
        borderWidth: 1.5,
        borderColor: "#E2E8F0",
        backgroundColor: "#F8FAFC",
        gap: 12,
    },
    swapReasonCardActive: {
        borderColor: "#4F46E5",
        backgroundColor: "#EEF2FF",
    },
    radioCircle: {
        width: 20,
        height: 20,
        borderRadius: 10,
        borderWidth: 2,
        borderColor: "#CBD5E1",
        alignItems: "center",
        justifyContent: "center",
    },
    radioCircleActive: {
        borderColor: "#4F46E5",
    },
    radioDot: {
        width: 10,
        height: 10,
        borderRadius: 5,
        backgroundColor: "#4F46E5",
    },
    swapReasonTitle: {
        fontSize: 14,
        fontWeight: "600",
        color: "#1E293B",
        marginBottom: 2,
    },
    swapReasonTitleActive: {
        fontWeight: "700",
        color: "#4338CA",
    },
    swapReasonDesc: {
        fontSize: 11,
        color: "#64748B",
        lineHeight: 15,
    },
    swapNoteInput: {
        borderRadius: 12,
        backgroundColor: "#F8FAFC",
        borderWidth: 1,
        borderColor: "#E2E8F0",
        padding: 12,
        fontSize: 14,
        color: "#0F172A",
        minHeight: 74,
        textAlignVertical: "top",
        marginBottom: 16,
    },
    modalCancelBtn: {
        flex: 1,
        height: 46,
        borderRadius: 12,
        backgroundColor: "#F1F5F9",
        alignItems: "center",
        justifyContent: "center",
    },
    modalCancelBtnText: {
        fontSize: 14,
        fontWeight: "600",
        color: "#475569",
    },
    modalConfirmBtn: {
        flex: 1.5,
        height: 46,
        borderRadius: 12,
        backgroundColor: "#4F46E5",
        alignItems: "center",
        justifyContent: "center",
        shadowColor: "#4F46E5",
        shadowOffset: { width: 0, height: 3 },
        shadowOpacity: 0.25,
        shadowRadius: 6,
        elevation: 3,
    },
    modalConfirmBtnText: {
        fontSize: 14,
        fontWeight: "700",
        color: "#FFFFFF",
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
});
