import { useState, useCallback, useEffect, useMemo, useRef } from "react";
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
    Image,
    Keyboard,
    Pressable,
    Linking,
} from "react-native";
import { useLocalSearchParams, useRouter, useFocusEffect } from "expo-router";
import { useAuth } from "../src/lib/auth";
import { useLoading } from "../src/lib/loading";
import { api, type GeofenceError } from "../src/lib/api";
import GeofenceBlockedModal from "../src/components/GeofenceBlockedModal";
import CheckpointTimeline from "../src/components/CheckpointTimeline";
import OrderPanel from "../src/components/OrderPanel";
import { showAlert, showDestructiveConfirm } from "../src/lib/alert";
import { clearNotificationBadge, useRefreshOnNotification } from "../src/lib/notifications";
import { Ionicons } from "@expo/vector-icons";
import * as Location from "expo-location";
import { getFastGpsCoordinates } from "../src/lib/location";
import * as ImagePicker from "expo-image-picker";

const statusConfig: Record<
    string,
    { icon: string; bg: string; text: string; label: string }
> = {
    pending: {
        icon: "time-outline",
        bg: "#F3F4F6",
        text: "#6B7280",
        label: "Chờ",
    },
    started: {
        icon: "play-circle-outline",
        bg: "#FEF3C7",
        text: "#D97706",
        label: "Đang chạy",
    },
    arrived_pickup: {
        icon: "cube-outline",
        bg: "#FEF3C7",
        text: "#D97706",
        label: "Đến lấy",
    },
    delivering: {
        icon: "car-outline",
        bg: "#DBEAFE",
        text: "#2563EB",
        label: "Đang giao",
    },
    arrived_delivery: {
        icon: "location-outline",
        bg: "#FEF3C7",
        text: "#D97706",
        label: "Đến giao",
    },
    delivered: {
        icon: "checkmark-done",
        bg: "#D1FAE5",
        text: "#059669",
        label: "Đã giao",
    },
    completed: {
        icon: "checkmark-circle",
        bg: "#D1FAE5",
        text: "#059669",
        label: "Hoàn thành",
    },
    driver_swap: {
        icon: "swap-horizontal",
        bg: "#E0E7FF",
        text: "#4F46E5",
        label: "Đảo lái",
    },
    return_trip: {
        icon: "arrow-undo",
        bg: "#FEE2E2",
        text: "#DC2626",
        label: "Quay đầu",
    },
    cancelled: {
        icon: "close-circle",
        bg: "#FEE2E2",
        text: "#DC2626",
        label: "Đã huỷ",
    },
};

const orderStatusLabel: Record<string, string> = {
    assigned: "Đã gán",
    sent: "Chờ lấy",
    in_transit: "Đang giao",
    completed: "Xong",
    driver_swap: "Đảo lái",
    cancelled: "Huỷ",
};

const KM_REASONS = [
    "Gõ nhầm số km",
    "Đồng hồ taplo lệch",
    "Nhận ca trước bàn giao sai",
    "Khác",
];

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
    const [completeKm, setCompleteKm] = useState("");
    const [completing, setCompleting] = useState(false);
    const [starting, setStarting] = useState(false);
    const [startKmInput, setStartKmInput] = useState("");
    const [returnStarted, setReturnStarted] = useState(false);
    const [showCompleteModal, setShowCompleteModal] = useState(false);
    const [showReportModal, setShowReportModal] = useState(false);
    const [reportKm, setReportKm] = useState("");
    const [reportReason, setReportReason] = useState(KM_REASONS[0]);
    const [reportNote, setReportNote] = useState("");
    const [reportPhoto, setReportPhoto] = useState<string | null>(null);
    const [submittingReport, setSubmittingReport] = useState(false);
    const lastSuggestedVehicleKmRef = useRef("");
    const userId = shift?.driver?.id;

    const handlePressPhone = async (rawPhone: string) => {
        const digits = rawPhone.replace(/\D/g, "");
        const url = digits.startsWith("84") ? `tel:+${digits}` : `tel:${digits}`;
        try {
            const supported = await Linking.canOpenURL(url);
            if (supported) {
                await Linking.openURL(url);
            } else {
                showAlert(
                    "Thông báo",
                    `Thiết bị không hỗ trợ gọi trực tiếp số: ${rawPhone}`,
                );
            }
        } catch {
            showAlert("Thông báo", `Không thể mở ứng dụng cuộc gọi cho số: ${rawPhone}`);
        }
    };

    const renderTextWithPhones = (text: string | null | undefined) => {
        if (!text) return null;
        const regex = /(?:\+?84[\s.-]*|0)[\d\s.-]{8,15}\d/g;
        const parts: React.ReactNode[] = [];
        let lastIndex = 0;
        let match: RegExpExecArray | null;

        regex.lastIndex = 0;
        while ((match = regex.exec(text)) !== null) {
            const fullMatch = match[0];
            const digits = fullMatch.replace(/\D/g, "");
            if (digits.length >= 9 && digits.length <= 12) {
                if (match.index > lastIndex) {
                    parts.push(text.substring(lastIndex, match.index));
                }
                parts.push(
                    <Text
                        key={match.index}
                        style={{
                            color: "#2563EB",
                            fontWeight: "700",
                            textDecorationLine: "underline",
                        }}
                        onPress={() => handlePressPhone(fullMatch)}
                    >
                        {fullMatch}
                    </Text>,
                );
                lastIndex = match.index + fullMatch.length;
            }
        }
        if (lastIndex < text.length) {
            parts.push(text.substring(lastIndex));
        }
        return parts.length > 0 ? parts : text;
    };

    // Format: bỏ .0, hiển thị số nguyên
    const fmt = (v: any) =>
        v != null ? parseInt(v).toLocaleString("vi-VN") : "-";

    const formatDateTimeHM = (val: string | null | undefined) => {
        if (!val) return "";
        const d = new Date(val);
        if (isNaN(d.getTime())) return "";
        const pad = (n: number) => String(n).padStart(2, "0");
        return `${pad(d.getHours())}:${pad(d.getMinutes())} ${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
    };

    const getCurrentStepInfo = () => {
        const cps = detail?.checkpoints || [];
        const driverCps = userId
            ? cps.filter((c: any) => c.driver_id === userId)
            : cps;
        const latestCp = (driverCps.length > 0 ? driverCps : cps).slice(-1)[0];

        const typeLabels: Record<string, string> = {
            started: "Bắt đầu chuyến",
            arrived_pickup: "Đến lấy hàng",
            left_pickup: "Bắt đầu đi giao hàng",
            arrived_delivery: "Đến giao hàng",
            completed: "Hoàn thành",
            driver_swap: "Đảo lái",
            end: "Kết thúc xe",
            cancelled: "Huỷ chuyến",
        };

        if (!latestCp) {
            return {
                id: undefined,
                name: "Bắt đầu chuyến",
                km: detail?.start_km != null ? Number(detail.start_km) : null,
            };
        }

        return {
            id: latestCp.id,
            name:
                typeLabels[latestCp.checkpoint_type] ||
                latestCp.checkpoint_type ||
                "Mốc hiện tại",
            km:
                latestCp.km_reading != null
                    ? Number(latestCp.km_reading)
                    : null,
        };
    };

    const handlePickReportPhoto = async () => {
        const { status } =
            await ImagePicker.requestMediaLibraryPermissionsAsync();
        if (status !== "granted") {
            showAlert("Quyền truy cập", "Cần cấp quyền truy cập thư viện ảnh");
            return;
        }
        const res = await ImagePicker.launchImageLibraryAsync({
            mediaTypes: ImagePicker.MediaTypeOptions.Images,
            allowsEditing: false,
            quality: 0.8,
        });
        if (!res.canceled && res.assets?.[0]?.uri) {
            setReportPhoto(res.assets[0].uri);
        }
    };

    const handleTakeReportPhoto = async () => {
        const { status } = await ImagePicker.requestCameraPermissionsAsync();
        if (status !== "granted") {
            showAlert("Quyền truy cập", "Cần cấp quyền chụp ảnh");
            return;
        }
        const res = await ImagePicker.launchCameraAsync({
            allowsEditing: false,
            quality: 0.8,
        });
        if (!res.canceled && res.assets?.[0]?.uri) {
            setReportPhoto(res.assets[0].uri);
        }
    };

    const handleSubmitReport = async () => {
        const km = parseFloat(reportKm);
        if (!km || km < 0) {
            showAlert(
                "Thiếu thông tin",
                "Vui lòng nhập số km thực tế trên taplo",
            );
            return;
        }
        if (!tripId || !token) return;

        const currentStep = getCurrentStepInfo();

        setSubmittingReport(true);
        showLoading();
        try {
            await api.trips.reportKmIssue(
                String(tripId),
                {
                    reported_km: km,
                    checkpoint_id: currentStep.id,
                    note: reportNote
                        ? `${reportReason}: ${reportNote}`
                        : reportReason,
                    photo: reportPhoto || undefined,
                },
                token,
            );
            showAlert(
                "Đã gửi báo cáo",
                "Điều hành sẽ kiểm tra và cập nhật lại số km cho bạn.",
            );
            setShowReportModal(false);
            setReportKm("");
            setReportReason(KM_REASONS[0]);
            setReportNote("");
            setReportPhoto(null);
            await refreshAll();
        } catch (e: any) {
            showAlert("Lỗi gửi báo cáo", e.message);
        } finally {
            setSubmittingReport(false);
            hideLoading();
        }
    };

    const getGps = async () => {
        return getFastGpsCoordinates();
    };

    // Đổi giá trị để khung đơn nhúng bên dưới tải lại cùng lúc
    const [orderReloadToken, setOrderReloadToken] = useState(0);

    const load = useCallback(async () => {
        if (!token || !tripId) return;
        try {
            const r = await api.trips.detail(String(tripId), token);
            const data = r.data || r;
            setDetail(data);
            return data;
        } finally {
            setLoading(false);
        }
    }, [token, tripId]);

    const refreshAll = useCallback(async () => {
        setOrderReloadToken((t) => t + 1);
        await load();
    }, [load]);

    useFocusEffect(
        useCallback(() => {
            clearNotificationBadge();
            load();
        }, [load]),
    );

    useRefreshOnNotification(refreshAll);

    // Khi đang tạm dừng chờ Điều hành xác nhận báo sai lệch Km trên web,
    // tự động kiểm tra định kỳ để cập nhật ngay cả khi thông báo đẩy bị tắt hoặc trễ.
    useEffect(() => {
        if (!detail?.is_paused || !token || !tripId) return;
        const interval = setInterval(() => {
            refreshAll();
        }, 5000);
        return () => clearInterval(interval);
    }, [detail?.is_paused, token, tripId, refreshAll]);

    const onRefresh = async () => {
        setRefreshing(true);
        await refreshAll();
        setRefreshing(false);
    };

    const currentStatus = detail?.status || trip?.status || "pending";
    const tripOrders: any[] = detail?.orders || trip?.orders || [];
    const isTripClosed = ["completed", "cancelled"].includes(currentStatus);
    const isOrderDone =
        isTripClosed ||
        (tripOrders.length > 0 && tripOrders.every((o: any) => o.status === "completed"));
    const isReturnTrip = currentStatus === "return_trip";
    const isSwapped = detail && userId && detail.driver_id !== userId;
    const currentShiftId = shift?.id;
    const tripShiftId = detail?.shift_id ?? trip?.shift_id;
    const isCurrentShift =
        !!currentShiftId &&
        (!tripShiftId || String(tripShiftId) === String(currentShiftId));
    const canStart =
        currentStatus === "pending" &&
        !isReturnTrip &&
        !isSwapped &&
        !!shift &&
        isCurrentShift;
    const canComplete =
        (!["pending", "completed", "driver_swap", "cancelled"].includes(
            currentStatus,
        ) ||
            (isReturnTrip && currentStatus !== "completed")) &&
        !isSwapped &&
        !!shift &&
        isCurrentShift;
    const isEmptyRun =
        (detail?.is_empty_run ?? trip?.is_empty_run ?? false) === true ||
        currentStatus === "return_trip";
    const hasStarted = detail?.start_km != null || trip?.start_km != null;
    const rawOrders: any[] = detail?.orders || trip?.orders || [];
    const orders: any[] = useMemo(() => {
        return [...rawOrders].sort((a: any, b: any) => {
            // Đơn đang giao (in_transit) ưu tiên lên trên
            const curA = a.status === "in_transit" ? 1 : 0;
            const curB = b.status === "in_transit" ? 1 : 0;
            if (curA !== curB) return curB - curA;

            // FIFO: Gửi lệnh trước (hoặc tạo trước) nằm trên cùng
            const timeA = new Date(a.sent_at || a.created_at || 0).getTime();
            const timeB = new Date(b.sent_at || b.created_at || 0).getTime();
            return timeA - timeB;
        });
    }, [rawOrders]);
    const hasIncompleteOrders =
        orders.length > 0 && orders.some((o: any) => o.status !== "completed");
    const tripCheckpoints: any[] = detail?.checkpoints || trip?.checkpoints || [];
    const orderHasEndCheckpoint = (o: any) =>
        (o.trip_checkpoints || []).some((cp: any) => cp.checkpoint_type === "end") ||
        tripCheckpoints.some(
            (cp: any) => cp.checkpoint_type === "end" && (!cp.order_id || cp.order_id === o.id),
        );
    // Ẩn thanh "Kết thúc chuyến" khi đơn trong chuyến vẫn đang hiển thị nút "Kết thúc đơn hàng"
    const hasOrdersAwaitingEnd =
        orders.length > 0 &&
        orders.some((o: any) => o.status !== "completed" || !orderHasEndCheckpoint(o));
    const showStickyCompleteBar =
        canComplete && (isReturnTrip || orders.length === 0 || !hasOrdersAwaitingEnd);
    const showHandover = canComplete && hasIncompleteOrders && !isReturnTrip;
    // Đã bàn giao (đảo lái) thì không còn báo sai lệch km cho chuyến này
    const showReportKm =
        !isSwapped && !!shift && isCurrentShift && !isOrderDone && currentStatus !== "driver_swap";

    // Auto lấy km hiện tại của xe
    const vehicleKm = detail?.vehicle?.km_reading ?? trip?.vehicle?.km_reading;

    // Auto-fill KM input và tự cập nhật khi điều hành chỉnh Km xe trên web
    useEffect(() => {
        if (vehicleKm != null) {
            const nextKm = String(Math.round(Number(vehicleKm)));
            setStartKmInput((prev) =>
                !prev || prev === lastSuggestedVehicleKmRef.current ? nextKm : prev,
            );
            setCompleteKm((prev) =>
                !prev || prev === lastSuggestedVehicleKmRef.current ? nextKm : prev,
            );
            lastSuggestedVehicleKmRef.current = nextKm;
        }
    }, [vehicleKm]);

    const handleStart = async () => {
        if (!tripId || !token) return;
        setStarting(true);
        showLoading();
        try {
            const gps = await getGps();
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
        } catch (e: any) {
            const msg = e.message || "";
            let targetTripId: string | number | undefined = e.active_trip_id;
            if (
                !targetTripId &&
                (msg.includes("chưa hoàn thiện chuyến") ||
                    msg.includes("đang có chuyến hàng") ||
                    msg.includes("đang chạy chuyến"))
            ) {
                try {
                    const activeRes = await api.trips.active(token!);
                    const data: any = activeRes?.data;
                    targetTripId = Array.isArray(data) ? data[0]?.id : data?.id;
                } catch {}
            }
            if (!targetTripId) {
                const match = msg.match(/#(\d+)/);
                if (match) targetTripId = match[1];
            }
            showAlert("Không thể bắt đầu", msg, () => {
                if (targetTripId) {
                    router.push({
                        pathname: "/trip-detail",
                        params: { id: String(targetTripId) },
                    });
                }
            });
        } finally {
            setStarting(false);
            hideLoading();
        }
    };

    const handleStartReturn = async () => {
        const sKm = parseFloat(startKmInput);
        if (!sKm) {
            showAlert("Thiếu", "Nhập Km bắt đầu");
            return;
        }
        if (!tripId || !token) return;
        setStarting(true);
        try {
            const cps = detail?.checkpoints || [];
            const startedCp = cps.find(
                (cp: any) => cp.checkpoint_type === "started",
            );
            if (startedCp) {
                await api.trips.checkpoint(
                    String(tripId),
                    {
                        checkpoint_type: "started",
                        km_reading: sKm,
                        occurred_at: startedCp.occurred_at,
                    },
                    token,
                );
            }
            setReturnStarted(true);
            showAlert("Thành công", "Đã ghi nhận Km bắt đầu");
            await load();
        } catch (e: any) {
            showAlert("Lỗi", e.message);
        } finally {
            setStarting(false);
            hideLoading();
        }
    };

    const handleCompleteReturn = async () => {
        const eKm = parseFloat(completeKm);
        if (!eKm) {
            showAlert("Thiếu", "Nhập Km kết thúc");
            return;
        }
        const sKm = detail?.start_km ?? trip?.start_km;
        if (sKm != null && eKm < sKm) {
            showAlert(
                "Lỗi",
                `Km kết thúc (${eKm}) phải >= Km bắt đầu (${sKm})`,
            );
            return;
        }
        if (!tripId || !token) return;
        showDestructiveConfirm(
            "Kết thúc chuyến quay đầu",
            "Bạn có chắc chắn muốn kết thúc chuyến quay đầu?",
            async () => {
                setCompleting(true);
                showLoading();
                try {
                    const cps = detail?.checkpoints || [];
                    const endCp = cps.find(
                        (cp: any) => cp.checkpoint_type === "end",
                    );
                    if (endCp) {
                        await api.trips.checkpoint(
                            String(tripId),
                            {
                                checkpoint_type: "end",
                                km_reading: eKm,
                                occurred_at: endCp.occurred_at,
                            },
                            token,
                        );
                    }
                    await api.trips.complete(String(tripId), eKm, token);
                    showAlert("Thành công", "Đã kết thúc chuyến quay đầu");
                    await load();
                } catch (e: any) {
                    showAlert("Lỗi", e.message);
                } finally {
                    setCompleting(false);
                    hideLoading();
                }
            },
        );
    };

    const [geofenceBlock, setGeofenceBlock] = useState<{ error: GeofenceError; km: number } | null>(null);

    const doComplete = async (km: number) => {
        if (!tripId || !token) return;
        setCompleting(true);
        showLoading();
        try {
            const gps = await getGps();
            await api.trips.complete(
                String(tripId),
                km,
                token,
                gps ?? undefined,
            );
            const hasIncomplete = orders.some(
                (o: any) => o.status !== "completed",
            );
            showAlert(
                "Thành công",
                hasIncomplete
                    ? "Chuyến → Đảo lái (đơn chưa xong)"
                    : "Đã kết thúc chuyến",
            );
            setShowCompleteModal(false);
            // Tải lại cả chuyến lẫn khung đơn nhúng để giao diện chuyển sang trạng thái mới (đảo lái/hoàn thành)
            setOrderReloadToken((t) => t + 1);
            await load();
        } catch (e: any) {
            if (e.geofence) {
                setGeofenceBlock({ error: e.geofence, km });
            } else {
                showAlert("Lỗi", e.message);
            }
        } finally {
            setCompleting(false);
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

    // Chuyến đang chạy: các bước của đơn hiện ngay trong trang (mỗi chuyến 1 đơn)
    const showOrderPanel = !isTripClosed && currentStatus !== "pending" && orders.length > 0;
    const kmAndActions = (
        <>
        {/* Km stats — ẩn với chuyến quay đầu */}
        {!isReturnTrip && (
            <View style={s.kmStrip}>
                {[
                    {
                        icon: "speedometer-outline",
                        label: "Tổng km",
                        value: fmt(detail?.total_km ?? trip?.total_km),
                        color: "#4F46E5",
                        bg: "#EEF2FF",
                    },
                    {
                        icon: "cube-outline",
                        label: "Có hàng",
                        value: fmt(
                            detail?.total_km_loaded ??
                                trip?.total_km_loaded,
                        ),
                        color: "#3B82F6",
                        bg: "#EFF6FF",
                    },
                    {
                        icon: "arrow-down-circle-outline",
                        label: "Bắt đầu",
                        value: fmt(detail?.start_km ?? trip?.start_km),
                        color: "#10B981",
                        bg: "#ECFDF5",
                    },
                    {
                        icon: "arrow-up-circle-outline",
                        label:
                            currentStatus === "completed"
                                ? "Kết thúc"
                                : currentStatus === "pending"
                                  ? "Km hiện tại của xe"
                                  : "Km xe",
                        value: fmt(
                            currentStatus === "completed"
                                ? (detail?.end_km ?? trip?.end_km)
                                : vehicleKm,
                        ),
                        color: "#F59E0B",
                        bg: "#FFFBEB",
                    },
                ]
                    // Chưa bắt đầu: chỉ cần km hiện tại của xe
                    .filter((_, i) => currentStatus !== "pending" || i === 3)
                    .map((st2, i) => (
                    <View key={i} style={s.kmCell}>
                        <Text style={s.kmLabel}>{st2.label}</Text>
                        <Text style={[s.kmValue, { color: st2.color }]} numberOfLines={1}>
                            {st2.value}
                        </Text>
                    </View>
                ))}
            </View>
        )}

        {/* Hai nút phụ chung 1 hàng: bàn giao (đảo lái) và báo sai lệch km */}
        {(showHandover || showReportKm) && (
            <View style={s.actionRow}>
                {showHandover && (
                    <TouchableOpacity
                        style={[s.actionBtnHandoff, s.actionRowBtn]}
                        onPress={() => {
                            setCompleteKm(
                                String(
                                    vehicleKm != null
                                        ? Math.round(vehicleKm)
                                        : "",
                                ),
                            );
                            setShowCompleteModal(true);
                        }}
                        disabled={completing}
                        activeOpacity={0.8}
                    >
                        <Ionicons
                            name="swap-horizontal"
                            size={18}
                            color="#B45309"
                        />
                        <Text style={s.actionRowText}>
                            {completing ? "Đang xử lý..." : "Bàn giao chuyến"}
                        </Text>
                    </TouchableOpacity>
                )}
                {showReportKm && (
                    <TouchableOpacity
                        style={[s.reportKmBtn, s.actionRowBtn, s.reportKmRowBtn]}
                        onPress={() => {
                            setReportKm(
                                vehicleKm != null
                                    ? String(Math.round(vehicleKm))
                                    : "",
                            );
                            setShowReportModal(true);
                        }}
                    >
                        <Ionicons
                            name="warning-outline"
                            size={18}
                            color="#DC2626"
                        />
                        <Text style={[s.actionRowText, { color: "#B91C1C" }]}>
                            Báo sai lệch Km
                        </Text>
                    </TouchableOpacity>
                )}
            </View>
        )}
        </>
    );

    return (
        <KeyboardAvoidingView
            style={{ flex: 1 }}
            // Android đã có `adjustResize` nên không cộng dồn thêm padding (tránh tự đóng bàn phím)
            behavior={Platform.OS === "ios" ? "padding" : undefined}
            keyboardVerticalOffset={Platform.OS === "ios" ? 88 : 0}
        >
            <ScrollView
                style={s.container}
                keyboardShouldPersistTaps="always"
                keyboardDismissMode="none"
                automaticallyAdjustKeyboardInsets={Platform.OS === "ios"}
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

                {/* Tạm dừng chờ xác nhận Km banner */}
                {detail?.is_paused && (
                    <View style={s.pausedBanner}>
                        <Ionicons
                            name="hourglass-outline"
                            size={18}
                            color="#B45309"
                        />
                        <Text style={s.pausedBannerText}>
                            Đang chờ Điều hành xác nhận báo sai lệch Km — màn hình sẽ tự động cập nhật.
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
                                {st.label}
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
                    {/* Mỗi chuyến 1 đơn: mã đơn nằm ngay dưới biển số */}
                    {orders.map((o: any) => {
                        return (
                            <View key={o.id} style={{ marginTop: 8 }}>
                            {/* Mã đơn và Badges */}
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
                                            paddingHorizontal: 5,
                                            paddingVertical: 2,
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
                                                fontWeight: "700",
                                                color:
                                                    o.type ===
                                                    "HHHK"
                                                        ? "#0369A1"
                                                        : "#B45309",
                                            }}
                                        >
                                            {o.type_label || o.type}
                                        </Text>
                                    </View>
                                </View>
                            </View>
                                {!!o.planned_loading_at && (
                                    <Text style={s.loadingTime}>
                                        🕐 Đóng hàng: {formatDateTimeHM(o.planned_loading_at)}
                                    </Text>
                                )}
                            </View>
                        );
                    })}
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

                {/* Km + nút phụ: khi đang làm đơn thì nằm ngay trên khung cập nhật chốt chặng */}
                {!showOrderPanel && kmAndActions}

                {/* Thời gian: chuyến có đơn đã có lịch sử chốt chặng bên dưới */}
                {detail?.started_at && orders.length === 0 && (
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

                {/* Orders hoặc Return Trip Info */}
                {isReturnTrip ? (
                    <View style={s.infoCard}>
                        {currentStatus === "completed" ? (
                            <>
                                <Ionicons
                                    name="checkmark-circle"
                                    size={48}
                                    color="#059669"
                                    style={{
                                        alignSelf: "center",
                                        marginBottom: 8,
                                    }}
                                />
                                <Text
                                    style={[s.infoTitle, { color: "#059669" }]}
                                >
                                    Chuyến quay đầu đã hoàn thành
                                </Text>
                                <View
                                    style={{
                                        flexDirection: "row",
                                        gap: 8,
                                        marginTop: 12,
                                    }}
                                >
                                    <View
                                        style={{
                                            flex: 1,
                                            backgroundColor: "#ECFDF5",
                                            padding: 12,
                                            borderRadius: 10,
                                        }}
                                    >
                                        <Text
                                            style={{
                                                fontSize: 11,
                                                color: "#6B7280",
                                            }}
                                        >
                                            Km bắt đầu
                                        </Text>
                                        <Text
                                            style={{
                                                fontSize: 18,
                                                fontWeight: "800",
                                                color: "#059669",
                                            }}
                                        >
                                            {fmt(
                                                detail?.start_km ??
                                                    trip?.start_km,
                                            )}
                                        </Text>
                                    </View>
                                    <View
                                        style={{
                                            flex: 1,
                                            backgroundColor: "#ECFDF5",
                                            padding: 12,
                                            borderRadius: 10,
                                        }}
                                    >
                                        <Text
                                            style={{
                                                fontSize: 11,
                                                color: "#6B7280",
                                            }}
                                        >
                                            Km kết thúc
                                        </Text>
                                        <Text
                                            style={{
                                                fontSize: 18,
                                                fontWeight: "800",
                                                color: "#059669",
                                            }}
                                        >
                                            {fmt(
                                                detail?.end_km ?? trip?.end_km,
                                            )}
                                        </Text>
                                    </View>
                                </View>
                                <Text
                                    style={{
                                        fontSize: 13,
                                        color: "#6B7280",
                                        textAlign: "center",
                                        marginTop: 8,
                                    }}
                                >
                                    Tổng:{" "}
                                    <Text
                                        style={{
                                            fontWeight: "700",
                                            color: "#111827",
                                        }}
                                    >
                                        {fmt(
                                            detail?.total_km ?? trip?.total_km,
                                        )}{" "}
                                        km
                                    </Text>
                                </Text>
                            </>
                        ) : (
                            <>
                                <Ionicons
                                    name="car-outline"
                                    size={40}
                                    color="#DC2626"
                                    style={{
                                        alignSelf: "center",
                                        marginBottom: 12,
                                    }}
                                />
                                <Text style={s.infoTitle}>
                                    Chuyến quay đầu — không hàng
                                </Text>
                                <Text
                                    style={{
                                        fontSize: 13,
                                        color: "#6B7280",
                                        textAlign: "center",
                                        marginTop: 4,
                                    }}
                                >
                                    Km xe hiện tại:{" "}
                                    <Text
                                        style={{
                                            fontWeight: "700",
                                            color: "#4F46E5",
                                        }}
                                    >
                                        {fmt(vehicleKm)}
                                    </Text>
                                </Text>
                                {hasStarted || returnStarted ? (
                                    <>
                                        <Text style={s.infoDesc}>
                                            Bước 2: Nhập Km kết thúc
                                        </Text>
                                        <View
                                            style={{
                                                flexDirection: "row",
                                                alignItems: "center",
                                                gap: 8,
                                                marginTop: 8,
                                                backgroundColor: "#ECFDF5",
                                                padding: 10,
                                                borderRadius: 10,
                                            }}
                                        >
                                            <Ionicons
                                                name="checkmark-circle"
                                                size={16}
                                                color="#059669"
                                            />
                                            <Text
                                                style={{
                                                    fontSize: 13,
                                                    color: "#059669",
                                                    fontWeight: "600",
                                                }}
                                            >
                                                Km bắt đầu:{" "}
                                                {fmt(
                                                    detail?.start_km ??
                                                        trip?.start_km,
                                                )}
                                            </Text>
                                        </View>
                                        <TextInput
                                            style={[
                                                s.kmInput,
                                                { marginTop: 12 },
                                            ]}
                                            placeholder="Km kết thúc"
                                            placeholderTextColor="#D1D5DB"
                                            keyboardType="numeric"
                                            value={completeKm}
                                            onChangeText={setCompleteKm}
                                            autoCorrect={false}
                                            autoComplete="off"
                                            importantForAutofill="no"
                                            textContentType="none"
                                        />
                                    </>
                                ) : (
                                    <>
                                        <Text style={s.infoDesc}>
                                            Bước 1: Nhập Km bắt đầu
                                        </Text>
                                        <TextInput
                                            style={[
                                                s.kmInput,
                                                {
                                                    marginTop: 12,
                                                    marginBottom: 12,
                                                },
                                            ]}
                                            placeholder="Km bắt đầu"
                                            placeholderTextColor="#D1D5DB"
                                            keyboardType="numeric"
                                            value={startKmInput}
                                            onChangeText={setStartKmInput}
                                            autoCorrect={false}
                                            autoComplete="off"
                                            importantForAutofill="no"
                                            textContentType="none"
                                        />
                                        {!!shift ? (
                                            <TouchableOpacity
                                                style={[
                                                    s.actionBtn,
                                                    {
                                                        backgroundColor:
                                                            "#10B981",
                                                        marginTop: 4,
                                                    },
                                                ]}
                                                onPress={handleStartReturn}
                                                disabled={starting}
                                            >
                                                <Ionicons
                                                    name="play-circle"
                                                    size={20}
                                                    color="#fff"
                                                />
                                                <Text style={s.actionBtnText}>
                                                    {starting
                                                        ? "Đang xử lý..."
                                                        : "Bắt đầu chuyến"}
                                                </Text>
                                            </TouchableOpacity>
                                        ) : (
                                            <View style={s.warnBox}>
                                                <Text style={s.warnText}>
                                                    Bạn cần vào ca trước khi bắt
                                                    đầu chuyến.
                                                </Text>
                                            </View>
                                        )}
                                    </>
                                )}
                            </>
                        )}
                    </View>
                ) : (
                    <>
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
                                const orderDeliveryPoints: any[] = o.delivery_points || [];
                                const pickupCode =
                                    o.pickup_location?.code ||
                                    o.pickup_address?.split(",")[0] ||
                                    "Điểm lấy hàng";
                                const pickupAddr =
                                    o.pickup_location?.address || o.pickup_address || null;
                                return (
                                    <View key={o.id}>
                                    {/* Chuyến đang chờ: hiển thị thông tin đơn hàng dạng tĩnh (chỉ đọc, không bấm chuyển trang) */}
                                    {currentStatus === "pending" && (
                                        <View style={s.pendingOrderCard}>
                                            <View style={s.pendingOrderHeader}>
                                                <Ionicons
                                                    name="document-text-outline"
                                                    size={16}
                                                    color="#4F46E5"
                                                />
                                                <Text style={s.pendingOrderTitle}>
                                                    Thông tin đơn hàng
                                                    {orders.length > 1 && o.order_code
                                                        ? ` • ${o.order_code}`
                                                        : ""}
                                                </Text>
                                            </View>

                                            {/* Điểm lấy -> Điểm giao */}
                                            <View style={s.pendingRouteBlock}>
                                                <View style={s.pendingRouteRow}>
                                                    <View
                                                        style={[
                                                            s.pendingRouteDot,
                                                            { backgroundColor: "#10B981" },
                                                        ]}
                                                    />
                                                    <View style={{ flex: 1 }}>
                                                        <Text style={s.pendingRouteCode}>
                                                            {pickupCode}
                                                        </Text>
                                                        {pickupAddr ? (
                                                            <Text style={s.pendingRouteAddr}>
                                                                {pickupAddr}
                                                            </Text>
                                                        ) : null}
                                                    </View>
                                                </View>

                                                {orderDeliveryPoints.length > 0 ? (
                                                    orderDeliveryPoints.map((dp: any, dpIdx: number) => {
                                                        const dpCode =
                                                            dp.code ||
                                                            dp.location?.code ||
                                                            `Điểm ${dp.sequence || dpIdx + 1}`;
                                                        const dpAddr =
                                                            dp.address ||
                                                            dp.location?.address ||
                                                            null;
                                                        const isLastDp =
                                                            dpIdx === orderDeliveryPoints.length - 1;
                                                        return (
                                                            <View key={dp.id ?? dpIdx}>
                                                                <View style={s.pendingRouteArrow}>
                                                                    <Ionicons
                                                                        name="arrow-down"
                                                                        size={13}
                                                                        color="#D1D5DB"
                                                                    />
                                                                </View>
                                                                <View style={s.pendingRouteRow}>
                                                                    <View
                                                                        style={[
                                                                            s.pendingRouteDot,
                                                                            {
                                                                                backgroundColor: isLastDp
                                                                                    ? "#EF4444"
                                                                                    : "#3B82F6",
                                                                            },
                                                                        ]}
                                                                    />
                                                                    <View style={{ flex: 1 }}>
                                                                        <Text style={s.pendingRouteCode}>
                                                                            {dpCode}
                                                                        </Text>
                                                                        {dpAddr ? (
                                                                            <Text style={s.pendingRouteAddr}>
                                                                                {dpAddr}
                                                                            </Text>
                                                                        ) : null}
                                                                    </View>
                                                                </View>
                                                            </View>
                                                        );
                                                    })
                                                ) : (
                                                    <View>
                                                        <View style={s.pendingRouteArrow}>
                                                            <Ionicons
                                                                name="arrow-down"
                                                                size={13}
                                                                color="#D1D5DB"
                                                            />
                                                        </View>
                                                        <View style={s.pendingRouteRow}>
                                                            <View
                                                                style={[
                                                                    s.pendingRouteDot,
                                                                    { backgroundColor: "#9CA3AF" },
                                                                ]}
                                                            />
                                                            <Text style={s.pendingRouteMuted}>
                                                                Chưa có điểm giao cố định (chọn khi đi giao)
                                                            </Text>
                                                        </View>
                                                    </View>
                                                )}
                                            </View>

                                            {/* Chi tiết hàng hoá & thời gian */}
                                            <View style={s.pendingMetaList}>
                                                <View style={s.pendingMetaRow}>
                                                    <Text style={s.pendingMetaLabel}>Hàng hoá</Text>
                                                    <Text style={s.pendingMetaValue}>
                                                        {o.cargo_name || o.cargo_type_label || "-"}
                                                    </Text>
                                                </View>
                                                <View style={s.pendingMetaRow}>
                                                    <Text style={s.pendingMetaLabel}>Khách hàng</Text>
                                                    <Text style={s.pendingMetaValue}>
                                                        {o.customer?.code || o.customer?.name || "-"}
                                                    </Text>
                                                </View>
                                                <View style={s.pendingMetaRow}>
                                                    <Text style={s.pendingMetaLabel}>Số kiện / Tải trọng</Text>
                                                    <Text style={s.pendingMetaValue}>
                                                        {o.total_packages != null
                                                            ? `${o.total_packages} kiện`
                                                            : "-"}{" "}
                                                        •{" "}
                                                        {o.total_weight
                                                            ? `${o.total_weight} tấn`
                                                            : "-"}
                                                    </Text>
                                                </View>
                                                {o.sent_at ? (
                                                    <View style={s.pendingMetaRow}>
                                                        <Text style={s.pendingMetaLabel}>Gửi lệnh</Text>
                                                        <Text style={s.pendingMetaValue}>
                                                            {formatDateTimeHM(o.sent_at)}
                                                        </Text>
                                                    </View>
                                                ) : null}
                                                {o.planned_loading_at ? (
                                                    <View style={s.pendingMetaRow}>
                                                        <Text style={s.pendingMetaLabel}>Giờ đóng hàng</Text>
                                                        <Text style={s.pendingMetaValue}>
                                                            {formatDateTimeHM(o.planned_loading_at)}
                                                        </Text>
                                                    </View>
                                                ) : null}
                                            </View>

                                            {/* Ghi chú đơn hàng */}
                                            {o.notes ? (
                                                <View style={s.pendingNotesBox}>
                                                    <Text style={s.pendingNotesLabel}>Ghi chú:</Text>
                                                    <Text style={s.pendingNotesText} selectable>
                                                        {renderTextWithPhones(o.notes)}
                                                    </Text>
                                                </View>
                                            ) : null}
                                        </View>
                                    )}
                                    {/* Chuyến đang chạy: làm các bước của đơn ngay tại đây (mỗi chuyến 1 đơn) */}
                                    {showOrderPanel && (
                                        <OrderPanel
                                            embedded
                                            beforeForm={kmAndActions}
                                            order={{
                                                ...o,
                                                trip_id: trip?.id || params.id,
                                                vehicle: detail?.vehicle || trip?.vehicle,
                                                is_swapped: isSwapped,
                                            }}
                                            reloadToken={orderReloadToken}
                                            onChanged={refreshAll}
                                        />
                                    )}
                                    {/* Chuyến đã đóng: hiện luôn các mốc, không cần vào chi tiết đơn */}
                                    {isTripClosed && (
                                        <CheckpointTimeline
                                            checkpoints={o.trip_checkpoints || []}
                                            myDriverId={userId}
                                        />
                                    )}
                                    </View>
                                );
                            })
                        )}
                    </>
                )}

                <View
                    style={{
                        height: showStickyCompleteBar ? 100 : 32,
                    }}
                />
            </ScrollView>

            {/* Sticky bottom — Kết thúc chuyến */}
            {showStickyCompleteBar && (
                <View style={s.stickyBar}>
                    {isReturnTrip && (returnStarted || hasStarted) ? (
                        <TouchableOpacity
                            style={[
                                {
                                    backgroundColor: "#DC2626",
                                    flexDirection: "row",
                                    alignItems: "center",
                                    justifyContent: "center",
                                    gap: 8,
                                    paddingVertical: 14,
                                    borderRadius: 12,
                                },
                            ]}
                            onPress={handleCompleteReturn}
                            disabled={completing}
                            activeOpacity={0.8}
                        >
                            <Ionicons name="flag" size={20} color="#fff" />
                            <Text
                                style={{
                                    color: "#fff",
                                    fontSize: 17,
                                    fontWeight: "800",
                                }}
                            >
                                {completing
                                    ? "Đang xử lý..."
                                    : "Kết thúc chuyến quay đầu"}
                            </Text>
                        </TouchableOpacity>
                    ) : isReturnTrip ? null : (
                        <TouchableOpacity
                            style={[
                                {
                                    backgroundColor: "#DC2626",
                                    flexDirection: "row",
                                    alignItems: "center",
                                    justifyContent: "center",
                                    gap: 8,
                                    paddingVertical: 14,
                                    borderRadius: 12,
                                },
                            ]}
                            onPress={() => {
                                setCompleteKm(
                                    String(
                                        vehicleKm != null
                                            ? Math.round(vehicleKm)
                                            : "",
                                    ),
                                );
                                setShowCompleteModal(true);
                            }}
                            disabled={completing}
                            activeOpacity={0.8}
                        >
                            <Ionicons name="flag" size={20} color="#fff" />
                            <Text
                                style={{
                                    color: "#fff",
                                    fontSize: 17,
                                    fontWeight: "800",
                                }}
                            >
                                {completing
                                    ? "Đang xử lý..."
                                    : "Kết thúc chuyến"}
                            </Text>
                        </TouchableOpacity>
                    )}
                </View>
            )}
            {/* Modal Báo sai lệch số Km */}
            <Modal
                visible={showReportModal}
                transparent
                animationType="fade"
                onRequestClose={() => setShowReportModal(false)}
            >
                <KeyboardAvoidingView
                    behavior={Platform.OS === "ios" ? "padding" : undefined}
                    style={s.modalOverlay}
                >
                    <Pressable
                        style={StyleSheet.absoluteFill}
                        onPress={Keyboard.dismiss}
                    />
                    <View
                        style={[
                            s.modalCard,
                            {
                                maxWidth: 420,
                                maxHeight: "82%",
                                paddingBottom: 16,
                                display: "flex",
                                flexDirection: "column",
                            },
                        ]}
                    >
                        {/* Header: Cố định */}
                        <View
                            style={{
                                flexDirection: "row",
                                alignItems: "center",
                                gap: 8,
                                marginBottom: 12,
                            }}
                        >
                            <Ionicons
                                name="warning"
                                size={22}
                                color="#D97706"
                            />
                            <Text
                                style={[
                                    s.modalTitle,
                                    {
                                        marginBottom: 0,
                                        textAlign: "left",
                                        flex: 1,
                                    },
                                ]}
                            >
                                Báo sai lệch số Km
                            </Text>
                            <TouchableOpacity
                                onPress={() => setShowReportModal(false)}
                                hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}
                            >
                                <Ionicons name="close" size={22} color="#9CA3AF" />
                            </TouchableOpacity>
                        </View>

                        {/* Nội dung có thể cuộn */}
                        <ScrollView
                            style={{ flexShrink: 1 }}
                            contentContainerStyle={{ paddingBottom: 12 }}
                            showsVerticalScrollIndicator={true}
                            keyboardShouldPersistTaps="handled"
                        >
                            {/* Hiển thị bước hiện tại */}
                            {(() => {
                                const step = getCurrentStepInfo();
                                return (
                                    <View
                                        style={{
                                            backgroundColor: "#FEF3C7",
                                            borderColor: "#FCD34D",
                                            borderWidth: 1,
                                            borderRadius: 8,
                                            padding: 10,
                                            marginBottom: 12,
                                        }}
                                    >
                                        <Text
                                            style={{
                                                fontSize: 12,
                                                color: "#92400E",
                                                fontWeight: "600",
                                            }}
                                        >
                                            Mốc hành trình báo sai:
                                        </Text>
                                        <Text
                                            style={{
                                                fontSize: 14,
                                                color: "#78350F",
                                                fontWeight: "700",
                                                marginTop: 2,
                                            }}
                                        >
                                            {step.name}{" "}
                                            {step.km != null
                                                ? `(Đang ghi nhận: ${step.km.toLocaleString("vi-VN")} km)`
                                                : ""}
                                        </Text>
                                    </View>
                                );
                            })()}

                            <Text style={s.modalSectionLabel}>
                                Số Km thực tế trên Taplo xe *
                            </Text>
                            <TextInput
                                style={[s.modalKmInput, { marginBottom: 12 }]}
                                value={reportKm}
                                onChangeText={setReportKm}
                                placeholder="VD: 100085"
                                placeholderTextColor="#9CA3AF"
                                keyboardType="numeric"
                                returnKeyType="done"
                                autoCorrect={false}
                                autoComplete="off"
                                importantForAutofill="no"
                                textContentType="none"
                            />

                            <Text style={s.modalSectionLabel}>
                                Ảnh chụp đồng hồ Taplo xe
                            </Text>
                            {reportPhoto ? (
                                <View
                                    style={{
                                        marginBottom: 12,
                                        alignItems: "center",
                                    }}
                                >
                                    <Image
                                        source={{ uri: reportPhoto }}
                                        style={{
                                            width: "100%",
                                            height: 140,
                                            borderRadius: 10,
                                        }}
                                        resizeMode="cover"
                                    />
                                    <TouchableOpacity
                                        onPress={() => setReportPhoto(null)}
                                        style={{ marginTop: 6 }}
                                    >
                                        <Text
                                            style={{
                                                color: "#DC2626",
                                                fontSize: 13,
                                                fontWeight: "600",
                                            }}
                                        >
                                            Xoá ảnh
                                        </Text>
                                    </TouchableOpacity>
                                </View>
                            ) : (
                                <View
                                    style={{
                                        flexDirection: "row",
                                        gap: 8,
                                        marginBottom: 12,
                                    }}
                                >
                                    <TouchableOpacity
                                        style={s.photoPickerBtn}
                                        onPress={handleTakeReportPhoto}
                                    >
                                        <Ionicons
                                            name="camera"
                                            size={18}
                                            color="#4F46E5"
                                        />
                                        <Text style={s.photoPickerBtnText}>
                                            Chụp ảnh
                                        </Text>
                                    </TouchableOpacity>
                                    <TouchableOpacity
                                        style={s.photoPickerBtn}
                                        onPress={handlePickReportPhoto}
                                    >
                                        <Ionicons
                                            name="images"
                                            size={18}
                                            color="#4F46E5"
                                        />
                                        <Text style={s.photoPickerBtnText}>
                                            Thư viện
                                        </Text>
                                    </TouchableOpacity>
                                </View>
                            )}

                            <Text style={s.modalSectionLabel}>
                                Lý do báo sai lệch *
                            </Text>
                            <View
                                style={{
                                    flexDirection: "row",
                                    flexWrap: "wrap",
                                    gap: 8,
                                    marginBottom: reportReason === "Khác" ? 10 : 16,
                                }}
                            >
                                {KM_REASONS.map((r) => {
                                    const isSelected = reportReason === r;
                                    return (
                                        <TouchableOpacity
                                            key={r}
                                            onPress={() => setReportReason(r)}
                                            style={{
                                                paddingHorizontal: 12,
                                                paddingVertical: 8,
                                                borderRadius: 8,
                                                borderWidth: 1.5,
                                                borderColor: isSelected
                                                    ? "#D97706"
                                                    : "#E5E7EB",
                                                backgroundColor: isSelected
                                                    ? "#FFFBEB"
                                                    : "#F9FAFB",
                                                flexDirection: "row",
                                                alignItems: "center",
                                                gap: 6,
                                            }}
                                        >
                                            {isSelected && (
                                                <Ionicons
                                                    name="checkmark-circle"
                                                    size={16}
                                                    color="#D97706"
                                                />
                                            )}
                                            <Text
                                                style={{
                                                    fontSize: 13,
                                                    fontWeight: isSelected
                                                        ? "700"
                                                        : "500",
                                                    color: isSelected
                                                        ? "#B45309"
                                                        : "#374151",
                                                }}
                                            >
                                                {r}
                                            </Text>
                                        </TouchableOpacity>
                                    );
                                })}
                            </View>

                            {reportReason === "Khác" && (
                                <TextInput
                                    style={[
                                        s.stickyInput,
                                        {
                                            marginBottom: 16,
                                            minHeight: 60,
                                            height: 60,
                                            textAlignVertical: "top",
                                            paddingTop: 8,
                                        },
                                    ]}
                                    value={reportNote}
                                    onChangeText={setReportNote}
                                    placeholder="Nhập lý do cụ thể..."
                                    placeholderTextColor="#9CA3AF"
                                    multiline
                                    numberOfLines={2}
                                />
                            )}
                        </ScrollView>

                        {/* Footer: Cố định bên dưới */}
                        <View style={{ flexDirection: "row", gap: 10, paddingTop: 12, borderTopWidth: 1, borderTopColor: "#F3F4F6" }}>
                            <TouchableOpacity
                                style={[
                                    s.modalBtn,
                                    { backgroundColor: "#F3F4F6" },
                                ]}
                                onPress={() => setShowReportModal(false)}
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
                                    { backgroundColor: "#D97706" },
                                ]}
                                onPress={handleSubmitReport}
                                disabled={submittingReport}
                            >
                                <Text
                                    style={[s.modalBtnText, { color: "#fff" }]}
                                >
                                    {submittingReport
                                        ? "Đang gửi..."
                                        : "Gửi báo cáo"}
                                </Text>
                            </TouchableOpacity>
                        </View>
                    </View>
                </KeyboardAvoidingView>
            </Modal>

            {/* Complete Modal */}
            <Modal
                visible={showCompleteModal}
                animationType="fade"
                transparent
                onRequestClose={() => setShowCompleteModal(false)}
            >
                <KeyboardAvoidingView
                    behavior={Platform.OS === "ios" ? "padding" : undefined}
                    style={s.modalOverlay}
                >
                    <Pressable
                        style={StyleSheet.absoluteFill}
                        onPress={Keyboard.dismiss}
                    />
                    <View
                        style={[
                            s.modalCard,
                            {
                                maxWidth: 420,
                                maxHeight: "82%",
                                paddingBottom: 16,
                                display: "flex",
                                flexDirection: "column",
                            },
                        ]}
                    >
                        {/* Header cố định */}
                        <View
                            style={{
                                flexDirection: "row",
                                alignItems: "center",
                                justifyContent: "space-between",
                                marginBottom: 12,
                            }}
                        >
                            <Text style={[s.modalTitle, { marginBottom: 0, textAlign: "left", flex: 1 }]}>
                                {hasIncompleteOrders
                                    ? "Bàn giao chuyến chưa xong"
                                    : "Kết thúc chuyến"}
                            </Text>
                            <TouchableOpacity
                                onPress={() => setShowCompleteModal(false)}
                                hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}
                            >
                                <Ionicons name="close" size={22} color="#9CA3AF" />
                            </TouchableOpacity>
                        </View>

                        {/* Nội dung có thể cuộn */}
                        <ScrollView
                            style={{ flexShrink: 1 }}
                            contentContainerStyle={{ paddingBottom: 10 }}
                            showsVerticalScrollIndicator={true}
                            keyboardShouldPersistTaps="handled"
                        >
                            <Text style={s.modalSectionLabel}>
                                Trạng thái đơn hàng ({orders.length})
                            </Text>
                            {orders.map((o: any, i: number) => {
                                const hasEnd = (o.trip_checkpoints || []).some(
                                    (cp: any) => cp.checkpoint_type === "end",
                                );
                                return (
                                    <View
                                        key={i}
                                        style={{
                                            flexDirection: "row",
                                            justifyContent: "space-between",
                                            alignItems: "center",
                                            paddingVertical: 6,
                                            borderBottomWidth: 1,
                                            borderBottomColor: "#F3F4F6",
                                        }}
                                    >
                                        <View style={{ flex: 1 }}>
                                            <Text
                                                style={{
                                                    fontSize: 14,
                                                    fontWeight: "600",
                                                    color: "#111827",
                                                }}
                                            >
                                                {o.order_code || `#${o.id}`}
                                            </Text>
                                        </View>
                                        <Text
                                            style={{
                                                fontSize: 13,
                                                color:
                                                    o.status === "completed"
                                                        ? "#059669"
                                                        : "#D97706",
                                                fontWeight: "600",
                                                marginRight: 6,
                                            }}
                                        >
                                            {orderStatusLabel[o.status] || o.status}
                                        </Text>
                                        {o.status === "completed" && (
                                            <Text
                                                style={{
                                                    fontSize: 11,
                                                    fontWeight: "700",
                                                    color: hasEnd
                                                        ? "#059669"
                                                        : "#F59E0B",
                                                }}
                                            >
                                                {hasEnd ? "✓ End" : "✗ End"}
                                            </Text>
                                        )}
                                    </View>
                                );
                            })}

                            <Text style={[s.modalSectionLabel, { marginTop: 16 }]}>
                                Km đồng hồ
                            </Text>
                            <TextInput
                                style={s.modalKmInput}
                                placeholder="Km hiện tại"
                                placeholderTextColor="#D1D5DB"
                                keyboardType="numeric"
                                returnKeyType="done"
                                value={completeKm}
                                onChangeText={setCompleteKm}
                                autoCorrect={false}
                                autoComplete="off"
                                importantForAutofill="no"
                                textContentType="none"
                            />

                            {hasIncompleteOrders && (
                                <View
                                    style={{
                                        backgroundColor: "#FEF3C7",
                                        padding: 10,
                                        borderRadius: 8,
                                        marginTop: 12,
                                    }}
                                >
                                    <Text
                                        style={{
                                            fontSize: 12,
                                            color: "#D97706",
                                            textAlign: "center",
                                        }}
                                    >
                                        ⚠️ Bàn giao chuyến sẽ tạo Đảo lái cho đơn
                                        chưa xong
                                    </Text>
                                </View>
                            )}

                            {orders.some(
                                (o: any) =>
                                    o.status === "completed" &&
                                    !(o.trip_checkpoints || []).some(
                                        (cp: any) => cp.checkpoint_type === "end",
                                    ),
                            ) && (
                                <View
                                    style={{
                                        backgroundColor: "#FEF3C7",
                                        padding: 10,
                                        borderRadius: 8,
                                        marginTop: 12,
                                    }}
                                >
                                    <Text
                                        style={{
                                            fontSize: 12,
                                            color: "#D97706",
                                            textAlign: "center",
                                        }}
                                    >
                                        ⚠️ Một số đơn chưa có chốt "Kết thúc đơn
                                        hàng" — sẽ được tự động tạo
                                    </Text>
                                </View>
                            )}
                        </ScrollView>

                        {/* Footer: Cố định bên dưới */}
                        <View
                            style={{
                                flexDirection: "row",
                                gap: 10,
                                paddingTop: 12,
                                borderTopWidth: 1,
                                borderTopColor: "#F3F4F6",
                            }}
                        >
                            <TouchableOpacity
                                style={[
                                    s.modalBtn,
                                    { backgroundColor: "#F3F4F6" },
                                ]}
                                onPress={() => setShowCompleteModal(false)}
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
                                    {
                                        backgroundColor: hasIncompleteOrders
                                            ? "#D97706"
                                            : "#DC2626",
                                    },
                                ]}
                                onPress={() => {
                                    const km = parseFloat(completeKm);
                                    if (!km) {
                                        showAlert("Thiếu", "Vui lòng nhập Km");
                                        return;
                                    }
                                    const startKm =
                                        detail?.start_km ?? trip?.start_km;
                                    if (startKm != null && km < startKm) {
                                        showAlert(
                                            "Lỗi",
                                            `Km kết thúc (${km}) phải >= Km bắt đầu (${startKm})`,
                                        );
                                        return;
                                    }
                                    doComplete(km);
                                }}
                                disabled={completing}
                            >
                                <Text
                                    style={[s.modalBtnText, { color: "#fff" }]}
                                >
                                    {completing
                                        ? "Đang xử lý..."
                                        : hasIncompleteOrders
                                          ? "Bàn giao"
                                          : "Kết thúc"}
                                </Text>
                            </TouchableOpacity>
                        </View>
                    </View>
                </KeyboardAvoidingView>
            </Modal>
            <GeofenceBlockedModal
                error={geofenceBlock?.error ?? null}
                onClose={() => setGeofenceBlock(null)}
                onRetry={() => {
                    const km = geofenceBlock?.km;
                    setGeofenceBlock(null);
                    if (km != null) doComplete(km);
                }}
            />
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
        margin: 12,
        marginBottom: 8,
        padding: 12,
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
    loadingTime: { fontSize: 12, color: "#6B7280", marginTop: 6 },
    actionRow: {
        flexDirection: "row",
        gap: 8,
        marginHorizontal: 12,
        marginBottom: 8,
    },
    actionRowBtn: {
        flex: 1,
        marginHorizontal: 0,
        marginBottom: 0,
        paddingVertical: 10,
        paddingHorizontal: 8,
    },
    reportKmRowBtn: { backgroundColor: "#FEF2F2", borderColor: "#FCA5A5" },
    actionRowText: { fontSize: 13, fontWeight: "700", color: "#B45309" },
    kmStrip: {
        flexDirection: "row",
        backgroundColor: "#fff",
        marginHorizontal: 12,
        marginBottom: 8,
        paddingVertical: 8,
        borderRadius: 12,
        borderWidth: 1,
        borderColor: "#F3F4F6",
    },
    kmCell: { flex: 1, alignItems: "center" },
    kmLabel: { fontSize: 11, color: "#9CA3AF", marginBottom: 2 },
    kmValue: { fontSize: 15, fontWeight: "800" },
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
        backgroundColor: "#fff",
        marginHorizontal: 16,
        marginBottom: 10,
        padding: 14,
        borderRadius: 14,
        borderWidth: 1,
        borderColor: "#E5E7EB",
        shadowColor: "#000",
        shadowOpacity: 0.04,
        shadowRadius: 6,
        shadowOffset: { width: 0, height: 1 },
        elevation: 1,
    },
    orderGuideBanner: {
        flexDirection: "row",
        alignItems: "center",
        backgroundColor: "#EEF2FF",
        marginHorizontal: 16,
        marginBottom: 10,
        paddingHorizontal: 12,
        paddingVertical: 9,
        borderRadius: 8,
        borderWidth: 1,
        borderColor: "#C7D2FE",
        gap: 6,
    },
    orderGuideText: {
        fontSize: 12,
        fontWeight: "600",
        color: "#4338CA",
        flex: 1,
    },
    actionBtnHandoff: {
        flexDirection: "row",
        alignItems: "center",
        justifyContent: "center",
        gap: 6,
        paddingVertical: 10,
        paddingHorizontal: 12,
        borderRadius: 10,
        backgroundColor: "#FFFBEB",
        borderWidth: 1,
        borderColor: "#FCD34D",
    },
    actionBtnHandoffText: {
        fontSize: 13,
        fontWeight: "600",
        color: "#B45309",
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
        marginBottom: 8,
        paddingBottom: 8,
        borderBottomWidth: 1,
        borderBottomColor: "#F3F4F6",
    },
    orderRoute: { fontSize: 13, fontWeight: "700", color: "#4F46E5", flex: 1 },
    orderKm: { fontSize: 11, color: "#9CA3AF", marginTop: 2 },
    orderSent: {
        fontSize: 12,
        color: "#4F46E5",
        marginTop: 2,
        fontWeight: "600",
    },
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
    // Return trip info
    infoCard: {
        backgroundColor: "#fff",
        marginHorizontal: 16,
        padding: 20,
        borderRadius: 14,
        borderWidth: 1,
        borderColor: "#F3F4F6",
        marginBottom: 12,
    },
    infoTitle: {
        fontSize: 16,
        fontWeight: "700",
        color: "#DC2626",
        textAlign: "center",
    },
    infoDesc: {
        fontSize: 13,
        color: "#6B7280",
        textAlign: "center",
        marginTop: 6,
        lineHeight: 18,
    },
    kmInput: {
        backgroundColor: "#F9FAFB",
        padding: 12,
        borderRadius: 10,
        borderWidth: 1,
        borderColor: "#E5E7EB",
        fontSize: 18,
        fontWeight: "700",
        color: "#111827",
        textAlign: "center",
    },
    // Complete modal
    modalOverlay: {
        flex: 1,
        backgroundColor: "rgba(0,0,0,0.5)",
        justifyContent: "center",
        alignItems: "center",
        paddingHorizontal: 20,
        paddingVertical: 24,
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
    modalKmInput: {
        backgroundColor: "#F9FAFB",
        padding: 14,
        borderRadius: 12,
        borderWidth: 1,
        borderColor: "#E5E7EB",
        fontSize: 20,
        fontWeight: "700",
        color: "#111827",
        textAlign: "center",
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
    reportKmBtn: {
        flexDirection: "row",
        alignItems: "center",
        justifyContent: "center",
        gap: 6,
        marginHorizontal: 16,
        marginBottom: 12,
        paddingVertical: 14,
        paddingHorizontal: 12,
        backgroundColor: "#FFFBEB",
        borderRadius: 12,
        borderWidth: 1.5,
        borderColor: "#FCD34D",
    },
    reportKmBtnText: { fontSize: 15, fontWeight: "700", color: "#B45309" },
    photoPickerBtn: {
        flex: 1,
        flexDirection: "row",
        alignItems: "center",
        justifyContent: "center",
        gap: 6,
        paddingVertical: 10,
        backgroundColor: "#EEF2FF",
        borderRadius: 10,
        borderWidth: 1,
        borderColor: "#C7D2FE",
    },
    photoPickerBtnText: { fontSize: 13, fontWeight: "600", color: "#4F46E5" },
    pausedBanner: {
        flexDirection: "row",
        alignItems: "center",
        gap: 8,
        backgroundColor: "#FFFBEB",
        marginHorizontal: 12,
        marginTop: 12,
        marginBottom: 4,
        padding: 12,
        borderRadius: 10,
        borderWidth: 1,
        borderColor: "#FCD34D",
    },
    pausedBannerText: {
        flex: 1,
        fontSize: 13,
        fontWeight: "600",
        color: "#92400E",
    },
    pendingOrderCard: {
        backgroundColor: "#fff",
        marginHorizontal: 12,
        marginBottom: 10,
        padding: 14,
        borderRadius: 14,
        borderWidth: 1,
        borderColor: "#E5E7EB",
    },
    pendingOrderHeader: {
        flexDirection: "row",
        alignItems: "center",
        gap: 6,
        marginBottom: 10,
        paddingBottom: 8,
        borderBottomWidth: 1,
        borderBottomColor: "#F3F4F6",
    },
    pendingOrderTitle: {
        fontSize: 14,
        fontWeight: "700",
        color: "#111827",
    },
    pendingRouteBlock: {
        marginBottom: 10,
        paddingBottom: 10,
        borderBottomWidth: 1,
        borderBottomColor: "#F3F4F6",
    },
    pendingRouteRow: {
        flexDirection: "row",
        alignItems: "flex-start",
        gap: 8,
    },
    pendingRouteDot: {
        width: 9,
        height: 9,
        borderRadius: 5,
        marginTop: 5,
    },
    pendingRouteArrow: {
        paddingLeft: 2,
        paddingVertical: 3,
    },
    pendingRouteCode: {
        fontSize: 14,
        fontWeight: "700",
        color: "#111827",
    },
    pendingRouteAddr: {
        fontSize: 12,
        color: "#6B7280",
        marginTop: 1,
    },
    pendingRouteMuted: {
        flex: 1,
        fontSize: 13,
        color: "#9CA3AF",
        fontStyle: "italic",
    },
    pendingMetaList: {
        gap: 6,
    },
    pendingMetaRow: {
        flexDirection: "row",
        justifyContent: "space-between",
        alignItems: "flex-start",
        gap: 12,
    },
    pendingMetaLabel: {
        fontSize: 12,
        color: "#6B7280",
    },
    pendingMetaValue: {
        flex: 1,
        fontSize: 13,
        fontWeight: "600",
        color: "#111827",
        textAlign: "right",
    },
    pendingNotesBox: {
        marginTop: 10,
        padding: 10,
        borderRadius: 8,
        backgroundColor: "#EEF2FF",
        borderWidth: 1,
        borderColor: "#E0E7FF",
    },
    pendingNotesLabel: {
        fontSize: 12,
        fontWeight: "700",
        color: "#4338CA",
        marginBottom: 2,
    },
    pendingNotesText: {
        fontSize: 13,
        color: "#1F2937",
        lineHeight: 18,
    },
});
