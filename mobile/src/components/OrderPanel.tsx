import { useState, useCallback, useEffect, useMemo, useRef } from "react";
import {
    View,
    Text,
    StyleSheet,
    ScrollView,
    TouchableOpacity,
    Pressable,
    TextInput,
    RefreshControl,
    FlatList,
    Modal,
    Image,
    KeyboardAvoidingView,
    Keyboard,
    Platform,
    Linking,
} from "react-native";
import { useFocusEffect } from "expo-router";
import { useAuth } from "../lib/auth";
import { useLoading } from "../lib/loading";
import { api, type GeofenceError } from "../lib/api";
import GeofenceBlockedModal from "./GeofenceBlockedModal";
import CheckpointTimeline, { cpInfo } from "./CheckpointTimeline";
import { showAlert } from "../lib/alert";
import { useRefreshOnNotification } from "../lib/notifications";
import { logUiEvent } from "../lib/ui-debug";
import * as ImagePicker from "expo-image-picker";
import * as Location from "expo-location";
import {
    getRequiredGpsCoordinates,
    promptEnableGps,
} from "../lib/location";
import { Ionicons } from "@expo/vector-icons";
import { SafeAreaView } from "react-native-safe-area-context";

// Trên một số máy Android (Samsung One UI) bàn phím hiện lên rồi tự đóng ngay sau cú chạm
// đầu tiên. Nếu blur xảy ra quá sớm và người dùng chưa bấm sang nút khác, tự focus lại.
const REFOCUS_ELAPSED_MS = 700;
const REFOCUS_MAX_ATTEMPTS = 3;

const statusConfig: Record<
    string,
    { icon: string; bg: string; text: string; label: string }
> = {
    assigned: {
        icon: "person-outline",
        bg: "#DBEAFE",
        text: "#2563EB",
        label: "Đã gán",
    },
    sent: {
        icon: "send-outline",
        bg: "#E0E7FF",
        text: "#4F46E5",
        label: "Chờ lấy",
    },
    in_transit: {
        icon: "car-outline",
        bg: "#FEF3C7",
        text: "#D97706",
        label: "Đang giao",
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
        text: "#8B5CF6",
        label: "Đảo lái",
    },
    cancelled: {
        icon: "close-circle",
        bg: "#FEE2E2",
        text: "#DC2626",
        label: "Huỷ",
    },
};


const localISO = (d: Date = new Date()) => {
    const pad = (n: number) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
};

const formatDateTimeHM = (val: string | null | undefined) => {
    if (!val) return "";
    const d = new Date(val);
    if (isNaN(d.getTime())) return "";
    const pad = (n: number) => String(n).padStart(2, "0");
    return `${pad(d.getHours())}:${pad(d.getMinutes())} ${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
};

import { formatKmInput } from "../lib/format";
export { formatKmInput };

interface OrderPanelProps {
    /** Đơn kèm trip_id, vehicle, is_swapped (từ danh sách/chi tiết chuyến) */
    order: any;
    /** Nhúng trong trang chuyến: bỏ thẻ đầu trang và ScrollView riêng */
    embedded?: boolean;
    /** Đổi giá trị để tải lại đơn (kéo làm mới ở màn cha) */
    reloadToken?: number;
    /** Gọi sau khi gửi mốc thành công để màn cha cập nhật */
    onChanged?: () => void;
    /** Nội dung màn cha chèn ngay trên khung cập nhật chốt chặng (km, nút phụ) */
    beforeForm?: React.ReactNode;
}

/** Thông tin đơn + các bước chốt mốc (lấy hàng → giao hàng → kết thúc) + lịch sử chốt chặng. */
export default function OrderPanel({ order, embedded = false, reloadToken = 0, onChanged, beforeForm }: OrderPanelProps) {
    const { token, shift } = useAuth();
    const { showLoading, hideLoading } = useLoading();
    const isSwapped = order?.is_swapped || false;
    const [km, setKm] = useState("");
    const [loading, setLoading] = useState(false);
    const [detail, setDetail] = useState<any>(null);
    const [refreshing, setRefreshing] = useState(false);

    // Location picker for orders without delivery point
    const [locSearch, setLocSearch] = useState("");
    const [locations, setLocations] = useState<any[]>([]);
    // Phân trang danh sách điểm đến (cuộn xuống tải thêm)
    const [locPage, setLocPage] = useState(1);
    const [locHasMore, setLocHasMore] = useState(false);
    const [locUseArea, setLocUseArea] = useState(true);
    const [locLoadingMore, setLocLoadingMore] = useState(false);
    const [showLocPicker, setShowLocPicker] = useState(false);
    const [selectedLoc, setSelectedLoc] = useState<any>(null);

    // Delivery point selection (for multi-DP orders)
    const [selectedDpId, setSelectedDpId] = useState<number | null>(null);

    // Photo capture
    const [photos, setPhotos] = useState<string[]>([]);
    const [geofenceBlock, setGeofenceBlock] = useState<{ error: GeofenceError; type: string } | null>(null);

    const kmInputRef = useRef<TextInput>(null);
    const kmFocusState = useRef<{ active: boolean; at: number; retries: number }>({
        active: false,
        at: 0,
        retries: 0,
    });
    const suppressRefocus = useRef(false);
    const lastSuggestedKmRef = useRef("");
    const prevVehicleKmRef = useRef<any>(null);
    const prevReloadTokenRef = useRef(reloadToken);
    const prevPausedRef = useRef<boolean | null>(null);

    const handleKmFocus = () => {
        const prev = kmFocusState.current;
        const keepRetries =
            prev.active && Date.now() - prev.at < 1500 ? prev.retries : 0;
        kmFocusState.current = { active: true, at: Date.now(), retries: keepRetries };
        suppressRefocus.current = false;
        logUiEvent("focus", "order-km");
    };

    const handleKmBlur = () => {
        logUiEvent("blur", "order-km");
        const st = kmFocusState.current;
        if (!st.active) return;

        const elapsed = Date.now() - st.at;
        if (suppressRefocus.current || elapsed > REFOCUS_ELAPSED_MS) {
            st.active = false;
            return;
        }
        if (st.retries >= REFOCUS_MAX_ATTEMPTS) {
            st.active = false;
            return;
        }

        st.retries += 1;
        st.at = Date.now();
        logUiEvent("refocus", `order-km lần ${st.retries} (${Math.round(elapsed)}ms)`);
        setTimeout(() => {
            if (kmFocusState.current.active && !suppressRefocus.current) {
                kmInputRef.current?.focus();
            }
        }, 150);
    };

    // Fetch full order detail with checkpoints from API
    const loadDetail = useCallback(async () => {
        if (!token || !order?.id) return;
        try {
            const r = await api.orders.detail(String(order.id), token);
            setDetail(r.data || r);
        } catch {}
    }, [order?.id, token]);

    useRefreshOnNotification(
        useCallback(() => {
            if (!embedded) {
                loadDetail();
            }
        }, [embedded, loadDetail]),
    );

    useFocusEffect(
        useCallback(() => {
            loadDetail();
        }, [order?.id, token]),
    );
    useEffect(() => {
        if (reloadToken) loadDetail();
    }, [reloadToken]);
    const onRefresh = async () => {
        setRefreshing(true);
        await loadDetail();
        setRefreshing(false);
    };

    // Search locations for delivery point selection
    const searchLocations = async (q: string) => {
        setLocSearch(q);
        if (!token) return;
        try {
            // Ưu tiên địa điểm cùng khu vực với đơn; không có kết quả thì tìm trên toàn bộ
            let r = await api.locations(
                { search: q || undefined, area_id: order?.area_id },
                token,
            );
            let usedArea = !!order?.area_id;
            if ((r.data || []).length === 0 && order?.area_id) {
                r = await api.locations({ search: q || undefined }, token);
                usedArea = false;
            }
            setLocations(r.data || []);
            setLocPage(r.current_page || 1);
            setLocHasMore((r.current_page || 1) < (r.last_page || 1));
            setLocUseArea(usedArea);
        } catch {}
    };

    const loadMoreLocations = async () => {
        if (!token || !locHasMore || locLoadingMore) return;
        setLocLoadingMore(true);
        try {
            const r = await api.locations(
                {
                    search: locSearch || undefined,
                    area_id: locUseArea ? order?.area_id : undefined,
                    page: locPage + 1,
                },
                token,
            );
            setLocations((prev) => [...prev, ...(r.data || [])]);
            setLocPage(r.current_page || locPage + 1);
            setLocHasMore((r.current_page || 0) < (r.last_page || 0));
        } catch {
        } finally {
            setLocLoadingMore(false);
        }
    };

    if (!order) return null;

    // Merge API detail with route params
    const d = detail || order;
    const st = statusConfig[d.status] || {
        icon: "document-text-outline",
        bg: "#F3F4F6",
        text: "#6B7280",
        label: d.status,
    };
    const tripId = d.trip_id;
    const vehicleKm = embedded
        ? (order?.vehicle?.km_reading ??
           order?.vehicle?.current_mileage ??
           detail?.vehicle?.km_reading ??
           detail?.vehicle?.current_mileage ??
           null)
        : (detail?.vehicle?.current_mileage ??
           detail?.vehicle?.km_reading ??
           order?.vehicle?.current_mileage ??
           order?.vehicle?.km_reading ??
           null);

    // Format tel URI
    const formatTelUri = (rawPhone: string) => {
        const digits = rawPhone.replace(/\D/g, "");
        if (digits.startsWith("84")) {
            return `tel:+${digits}`;
        }
        return `tel:${digits}`;
    };

    // Safely open phone dialer with simulator fallback handling
    const handlePressPhone = async (rawPhone: string) => {
        const url = formatTelUri(rawPhone);
        try {
            const supported = await Linking.canOpenURL(url);
            if (supported) {
                await Linking.openURL(url);
            } else {
                showAlert(
                    "Thông báo",
                    `Trình giả lập (Simulator) hoặc thiết bị không hỗ trợ ứng dụng gọi điện trực tiếp số: ${rawPhone}`,
                );
            }
        } catch {
            showAlert(
                "Thông báo",
                `Không thể mở ứng dụng cuộc gọi cho số: ${rawPhone}`,
            );
        }
    };

    // Detect and render notes text with clickable inline phone numbers
    const renderedNotesText = useMemo(() => {
        if (!d?.notes) return null;
        const text = d.notes;
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
    }, [d?.notes]);

    // Auto-fill KM từ km hiện tại của xe; tự động cập nhật khi điều hành sửa Km trên web
    // hoặc khi chuyến vừa hết tạm dừng (admin duyệt báo sai km).
    useEffect(() => {
        if (vehicleKm != null) {
            const nextKm = String(Math.round(Number(vehicleKm)));
            const vehicleKmChanged =
                prevVehicleKmRef.current != null &&
                String(Math.round(Number(prevVehicleKmRef.current))) !== nextKm;
            const reloadTokenChanged = prevReloadTokenRef.current !== reloadToken;
            const isPausedCurrent = order?.is_paused ?? detail?.is_paused ?? false;
            const unpaused = prevPausedRef.current === true && !isPausedCurrent;

            prevVehicleKmRef.current = vehicleKm;
            prevReloadTokenRef.current = reloadToken;
            prevPausedRef.current = isPausedCurrent;

            // Khi Điều hành sửa Km trên web (vehicleKm đổi số) HOẶC chuyến vừa hết tạm dừng (unpaused)
            // HOẶC reloadToken thay đổi và tài xế không đang gõ phím:
            // Luôn cập nhật thẳng số km mới nhất vào ô input
            const forceUpdate =
                vehicleKmChanged ||
                unpaused ||
                (reloadTokenChanged && !kmFocusState.current.active);

            setKm((prev) => {
                if (!prev || prev === lastSuggestedKmRef.current || forceUpdate) {
                    return nextKm;
                }
                return prev;
            });
            lastSuggestedKmRef.current = nextKm;
        }
    }, [vehicleKm, detail, reloadToken, order?.is_paused]);
    // Find next pending delivery point (for multi-DP orders)
    const deliveryPoints: any[] = d.delivery_points || [];
    const nextPendingDp = deliveryPoints.find(
        (dp: any) => dp.status !== "delivered" && dp.status !== "completed",
    );
    // Use selected DP, or fallback to next pending, or last DP (final destination)
    const activeDpId =
        selectedDpId ||
        nextPendingDp?.id ||
        deliveryPoints[deliveryPoints.length - 1]?.id;
    const checkpoints = d.trip_checkpoints || [];

    // Compute per-DP checkpoint status
    const dpStatusMap = useMemo(() => {
        const map: Record<number, { arrived: boolean; completed: boolean }> =
            {};
        deliveryPoints.forEach((dp: any) => {
            map[dp.id] = {
                arrived: checkpoints.some(
                    (cp: any) =>
                        cp.checkpoint_type === "arrived_delivery" &&
                        cp.delivery_point_id === dp.id,
                ),
                completed: checkpoints.some(
                    (cp: any) =>
                        cp.checkpoint_type === "completed" &&
                        cp.delivery_point_id === dp.id,
                ),
            };
        });

        return map;
    }, [deliveryPoints, checkpoints]);

    // Auto-select only DP for single-DP orders; reset on complete
    useEffect(() => {
        if (deliveryPoints.length === 1 && !selectedDpId) {
            const onlyDp = deliveryPoints[0];
            if (!dpStatusMap[onlyDp.id]?.completed) {
                setSelectedDpId(onlyDp.id);
            }
        }
        // If selected DP is now completed, auto-advance to next pending
        if (selectedDpId && dpStatusMap[selectedDpId]?.completed) {
            const next = deliveryPoints.find(
                (dp: any) =>
                    dp.id !== selectedDpId &&
                    !dpStatusMap[dp.id]?.completed &&
                    dp.status !== "delivered",
            );
            if (next) {
                setSelectedDpId(next.id);
            }
        }
    }, [deliveryPoints, selectedDpId, dpStatusMap]);
    const activeDp = deliveryPoints.find((dp: any) => dp.id === activeDpId);
    // Map DP id to location code for timeline display
    const dpCodeMap: Record<string, string> = {};
    deliveryPoints.forEach((dp: any) => {
        dpCodeMap[dp.id] =
            dp.code || dp.location?.code || `Điểm ${dp.sequence}`;
    });
    const hasDeliveryPoint = !!activeDpId || deliveryPoints.length > 0;
    const hasEndCheckpoint = checkpoints.some(
        (cp: any) => cp.checkpoint_type === "end",
    );

    // Sequential: chỉ hiện 1 action tại 1 thời điểm, theo đúng luồng
    const hasArrivedPickup = checkpoints.some(
        (cp: any) => cp.checkpoint_type === "arrived_pickup",
    );
    const hasLeftPickup = checkpoints.some(
        (cp: any) => cp.checkpoint_type === "left_pickup",
    );

    // Check SELECTED DP for arrived_delivery/completed
    const activeDpHasCp = (cpType: string) =>
        activeDpId
            ? checkpoints.some(
                  (cp: any) =>
                      cp.checkpoint_type === cpType &&
                      cp.delivery_point_id === activeDpId,
              )
            : false;
    const hasArrivedDelivery = activeDpHasCp("arrived_delivery");
    const hasCompleted = activeDpHasCp("completed");

    // Mid-delivery: đã đến DP nhưng chưa giao xong → lock, không chọn được DP khác
    const isMidDelivery = hasArrivedDelivery && !hasCompleted;
    const canSelectDp = !isMidDelivery;

    // Can do actions on the SELECTED DP (not necessarily next pending)
    const canArrivePickup =
        (d.status === "assigned" || d.status === "sent") &&
        !hasArrivedPickup &&
        !!shift;
    const canLeftPickup =
        d.status === "sent" && hasArrivedPickup && !hasLeftPickup && !!shift;
    const canArriveDelivery =
        d.status === "in_transit" &&
        (!!activeDpId || !!selectedLoc) &&
        !hasArrivedDelivery &&
        !!shift;
    const canComplete =
        d.status === "in_transit" &&
        (!!activeDpId || !!selectedLoc) &&
        hasArrivedDelivery &&
        !hasCompleted &&
        !!shift;
    const canEnd = d.status === "completed" && !hasEndCheckpoint && !!shift;

    async function pickImage() {
        suppressRefocus.current = true;
        const { status } = await ImagePicker.requestCameraPermissionsAsync();
        if (status !== "granted") {
            showAlert("Quyền", "Cần cấp quyền camera");
            return;
        }
        const result = await ImagePicker.launchCameraAsync({ quality: 0.7 });
        if (!result.canceled && result.assets[0].uri) {
            setPhotos((prev) => [...prev, result.assets[0].uri]);
        }
    }

    async function submitCheckpoint(type: string) {
        suppressRefocus.current = true;
        if (!tripId || !token || !d.id) return;
        if (
            !km &&
            ["arrived_pickup", "arrived_delivery", "completed", "end"].includes(
                type,
            )
        ) {
            showAlert("Thiếu", "Vui lòng nhập số Km");
            return;
        }

        // Bật loading ngay lập tức để người dùng có phản hồi trực quan
        setLoading(true);
        showLoading();

        try {
            // Bắt buộc bật GPS trên điện thoại mới cho gửi checkpoint
            const gpsRes = await getRequiredGpsCoordinates();
            if (!gpsRes.ok) {
                promptEnableGps(gpsRes.reason);
                return;
            }

            const body: any = {
                checkpoint_type: type,
                occurred_at: localISO(),
            };
            if (gpsRes.coords) {
                body.gps_lat = gpsRes.coords.gps_lat;
                body.gps_lng = gpsRes.coords.gps_lng;
                if (gpsRes.coords.gps_accuracy != null) {
                    body.gps_accuracy = gpsRes.coords.gps_accuracy;
                }
            }
            if (km) body.km_reading = parseFloat(km);
            if (d.id) body.order_id = d.id;
            if (photos.length > 0) body.photos = photos;

            // Nếu có delivery_point_id thì dùng, nếu không có nhưng đã chọn location thì gửi new_delivery_location_id
            if (
                activeDpId &&
                ["arrived_delivery", "completed", "end"].includes(type)
            ) {
                body.delivery_point_id = activeDpId;
            } else if (
                !hasDeliveryPoint &&
                selectedLoc &&
                ["arrived_delivery", "completed", "end"].includes(type)
            ) {
                body.new_delivery_location_id = selectedLoc.id;
            }

            await api.trips.checkpoint(String(tripId), body, token);
            showAlert(
                "Thành công",
                `Đã cập nhật: ${cpInfo[type]?.label || type}`,
            );
            lastSuggestedKmRef.current = "";
            setKm("");
            setPhotos([]);
            setSelectedLoc(null);
            await Promise.all([loadDetail(), Promise.resolve(onChanged?.())]);
        } catch (e: any) {
            if (e.geofence) {
                setGeofenceBlock({ error: e.geofence, type });
            } else {
                showAlert("Lỗi", e.message);
            }
        } finally {
            setLoading(false);
            hideLoading();
        }
    }

    // Nhúng trong trang chuyến thì màn cha lo cuộn và bàn phím;
    // Android đã có `adjustResize` nên không cộng dồn thêm behavior="padding".
    const Outer: any = embedded ? View : KeyboardAvoidingView;
    const Scroll: any = embedded ? View : ScrollView;
    const outerProps = embedded
        ? {}
        : {
              style: { flex: 1 },
              behavior: Platform.OS === "ios" ? "padding" : undefined,
              keyboardVerticalOffset: Platform.OS === "ios" ? 88 : 0,
          };
    const scrollProps = embedded
        ? {}
        : {
              style: s.container,
              showsVerticalScrollIndicator: false,
              keyboardShouldPersistTaps: "handled" as const,
              keyboardDismissMode: "on-drag" as const,
              automaticallyAdjustKeyboardInsets: Platform.OS === "ios",
              refreshControl: (
                  <RefreshControl
                      refreshing={refreshing}
                      onRefresh={onRefresh}
                      tintColor="#4F46E5"
                  />
              ),
          };

    return (
        <Outer {...outerProps}>
            <Scroll {...scrollProps}>
                {!embedded && (
                <View
                    style={[
                        s.heroCard,
                        { borderLeftColor: st.text, borderLeftWidth: 4 },
                    ]}
                >
                    {/* Route bar: pickup ↓ dp1 ↓ dp2 ... */}
                    <View style={s.routeBar}>
                        <View style={s.routeRow}>
                            <View style={s.routeDot} />
                            <View style={{ flex: 1 }}>
                                <Text style={s.routeCode}>
                                    {d.pickup_location?.code ||
                                        d.pickup_address?.split(",")[0] ||
                                        "..."}
                                </Text>
                                {(d.pickup_location?.address ||
                                    d.pickup_address) && (
                                    <Text style={s.routeAddr}>
                                        {d.pickup_location?.address ||
                                            d.pickup_address}
                                    </Text>
                                )}
                            </View>
                        </View>
                        {deliveryPoints.map((dp: any, i: number) => (
                            <View key={i}>
                                <View style={s.routeArrow}>
                                    <Ionicons
                                        name="arrow-down"
                                        size={14}
                                        color="#D1D5DB"
                                    />
                                </View>
                                <View style={s.routeRow}>
                                    <View
                                        style={[
                                            s.routeDot,
                                            {
                                                backgroundColor:
                                                    i ===
                                                    deliveryPoints.length - 1
                                                        ? "#EF4444"
                                                        : "#3B82F6",
                                            },
                                        ]}
                                    />
                                    <View style={{ flex: 1 }}>
                                        <Text style={s.routeCode}>
                                            {dp.code ||
                                                dp.location?.code ||
                                                `Điểm ${dp.sequence || i + 1}`}
                                        </Text>
                                        {(dp.address ||
                                            dp.location?.address) && (
                                            <Text style={s.routeAddr}>
                                                {dp.address ||
                                                    dp.location?.address}
                                            </Text>
                                        )}
                                    </View>
                                </View>
                            </View>
                        ))}
                    </View>

                    <View style={s.heroRow}>
                        <View style={{ flex: 1 }}>
                            <View
                                style={{
                                    flexDirection: "row",
                                    alignItems: "center",
                                    gap: 8,
                                }}
                            >
                                <Text style={s.orderCode}>{d.order_code}</Text>
                                <View
                                    style={{
                                        paddingHorizontal: 6,
                                        paddingVertical: 2,
                                        borderRadius: 6,
                                        backgroundColor:
                                            d.type === "HHHK"
                                                ? "#E0F2FE"
                                                : "#FEF3C7",
                                    }}
                                >
                                    <Text
                                        style={{
                                            fontSize: 11,
                                            fontWeight: "700",
                                            color:
                                                d.type === "HHHK"
                                                    ? "#0369A1"
                                                    : "#B45309",
                                        }}
                                    >
                                        {d.type_label || d.type}
                                    </Text>
                                </View>
                            </View>
                            <Text style={s.cargoName}>
                                {d.cargo_name || "Chưa có tên hàng"}
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
                </View>
                )}

                {/* Notes */}
                {d.notes ? (
                    <View style={s.notesCard}>
                        <View
                            style={{
                                flexDirection: "row",
                                alignItems: "center",
                                gap: 6,
                                marginBottom: 6,
                            }}
                        >
                            <Ionicons
                                name="document-text-outline"
                                size={16}
                                color="#4F46E5"
                            />
                            <Text
                                style={{
                                    fontSize: 13,
                                    fontWeight: "700",
                                    color: "#4F46E5",
                                }}
                            >
                                Ghi chú
                            </Text>
                        </View>
                        <Text style={s.notesText} selectable={true}>
                            {renderedNotesText}
                        </Text>
                    </View>
                ) : null}

                {/* Info grid — nhúng trong trang chuyến thì là 1 dải gọn */}
                {embedded ? (
                    <View style={s.infoStrip}>
                        {[
                            ["Khách hàng", d.customer?.code || "-"],
                            ["Số kiện", String(d.total_packages || "-")],
                            ["Trọng lượng", d.total_weight ? `${d.total_weight} tấn` : "-"],
                        ].map(([label, value]) => (
                            <View key={label} style={s.infoCell}>
                                <Text style={s.tileLabel}>{label}</Text>
                                <Text style={s.infoValue} numberOfLines={1}>
                                    {value}
                                </Text>
                            </View>
                        ))}
                    </View>
                ) : (
                <View style={s.infoGrid}>
                    {[
                        {
                            icon: "business",
                            label: "Khách hàng",
                            value: d.customer?.code || "-",
                        },
                        {
                            icon: "file-tray-full",
                            label: "Số kiện",
                            value: String(d.total_packages || "-"),
                        },
                        {
                            icon: "barbell",
                            label: "Trọng lượng",
                            value: d.total_weight
                                ? `${d.total_weight} tấn`
                                : "-",
                        },
                        {
                            icon: "paper-plane",
                            label: "Gửi lệnh",
                            value: d.sent_at ? formatDateTimeHM(d.sent_at) : "-",
                        },
                        {
                            icon: "time",
                            label: "Đóng hàng",
                            value: d.planned_loading_at
                                ? formatDateTimeHM(d.planned_loading_at)
                                : "-",
                        },
                        {
                            icon: "car",
                            label: "Biển số",
                            value:
                                d.vehicle?.plate_number ||
                                d.vehicle_plate_number ||
                                "-",
                        },
                    ].map((t, i) => (
                        <View
                            key={i}
                            style={[
                                s.tile,
                                i < 3
                                    ? { width: "30%" }
                                    : i < 5
                                      ? { width: "48%" }
                                      : {
                                            width: "100%",
                                            flexDirection: "row",
                                            justifyContent: "center",
                                            alignItems: "center",
                                            gap: 8,
                                            paddingVertical: 10,
                                        },
                            ]}
                        >
                            <Ionicons
                                name={t.icon as any}
                                size={18}
                                color="#4F46E5"
                            />
                            <Text style={s.tileLabel}>{t.label}</Text>
                            <Text style={s.tileValue} numberOfLines={1}>
                                {t.value}
                            </Text>
                        </View>
                    ))}
                </View>
                )}

                {beforeForm}

                {/* Location picker — chỉ hiện khi trạng thái tiếp theo là đến điểm giao hàng */}
                {d.status === "in_transit" &&
                    hasLeftPickup &&
                    !hasDeliveryPoint &&
                    !hasArrivedDelivery && (
                        <>
                            <Text style={s.sectionTitle}>📍 Chọn điểm đến</Text>
                            <View style={s.formCard}>
                                {selectedLoc ? (
                                    // Chạm vào ô để chọn lại điểm khác, không cần bấm ⓧ trước
                                    <TouchableOpacity
                                        style={s.selectedLoc}
                                        activeOpacity={0.7}
                                        onPress={() => {
                                            setShowLocPicker(true);
                                            searchLocations("");
                                        }}
                                    >
                                        <Text style={[s.selectedLocName, { flex: 1 }]}>
                                            {selectedLoc.code || selectedLoc.name}
                                        </Text>
                                        <Text style={{ fontSize: 12, color: "#6366F1", fontWeight: "600" }}>
                                            Đổi
                                        </Text>
                                        <Ionicons
                                            name="chevron-down"
                                            size={16}
                                            color="#6366F1"
                                        />
                                    </TouchableOpacity>
                                ) : (
                                    <TouchableOpacity
                                        style={s.pickerBtn}
                                        onPress={() => {
                                            setShowLocPicker(true);
                                            searchLocations("");
                                        }}
                                    >
                                        <Ionicons
                                            name="location-outline"
                                            size={18}
                                            color="#4F46E5"
                                        />
                                        <Text style={s.pickerBtnText}>
                                            Chạm để chọn điểm đến
                                        </Text>
                                        <Ionicons
                                            name="chevron-down"
                                            size={16}
                                            color="#9CA3AF"
                                        />
                                    </TouchableOpacity>
                                )}
                            </View>
                        </>
                    )}

                {/* Modal chọn điểm đến */}
                <Modal
                    visible={showLocPicker}
                    animationType="slide"
                    presentationStyle="pageSheet"
                    onRequestClose={() => setShowLocPicker(false)}
                >
                    <SafeAreaView style={s.modalContainer} edges={["top", "bottom"]}>
                        <View style={s.modalHeader}>
                            <Text style={s.modalTitle}>Chọn điểm đến</Text>
                            <TouchableOpacity
                                onPress={() => setShowLocPicker(false)}
                                hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}
                            >
                                <Ionicons
                                    name="close"
                                    size={24}
                                    color="#111827"
                                />
                            </TouchableOpacity>
                        </View>
                        <View style={s.searchWrap}>
                            <Ionicons
                                name="search-outline"
                                size={18}
                                color="#9CA3AF"
                            />
                            <TextInput
                                style={s.searchInput}
                                placeholder="Tìm kiếm..."
                                placeholderTextColor="#9CA3AF"
                                value={locSearch}
                                onChangeText={searchLocations}
                                autoFocus
                            />
                        </View>
                        <FlatList
                            data={locations}
                            keyExtractor={(loc: any) => String(loc.id)}
                            keyboardShouldPersistTaps="handled"
                            keyboardDismissMode="on-drag"
                            contentContainerStyle={{ paddingBottom: 24 }}
                            onEndReached={loadMoreLocations}
                            onEndReachedThreshold={0.4}
                            ListFooterComponent={
                                locLoadingMore ? (
                                    <Text style={{ textAlign: "center", color: "#9CA3AF", padding: 12 }}>
                                        Đang tải thêm...
                                    </Text>
                                ) : null
                            }
                            renderItem={({ item }) => (
                                <TouchableOpacity
                                    style={s.modalLocItem}
                                    onPress={() => {
                                        setSelectedLoc(item);
                                        setShowLocPicker(false);
                                    }}
                                >
                                    <Ionicons
                                        name="location-outline"
                                        size={20}
                                        color="#4F46E5"
                                    />
                                    <Text style={[s.modalLocName, { flex: 1 }]}>
                                        {item.code || item.name}
                                    </Text>
                                    <Ionicons
                                        name="chevron-forward"
                                        size={16}
                                        color="#D1D5DB"
                                    />
                                </TouchableOpacity>
                            )}
                            ListEmptyComponent={
                                <View
                                    style={{
                                        alignItems: "center",
                                        padding: 32,
                                    }}
                                >
                                    <Text style={{ color: "#9CA3AF" }}>
                                        Không tìm thấy điểm đến
                                    </Text>
                                </View>
                            }
                        />
                    </SafeAreaView>
                </Modal>

                {/* Delivery point selector (multi-DP, only during delivery stage) */}
                {deliveryPoints.length > 1 &&
                    (canArriveDelivery || canComplete) && (
                        <View style={{ marginHorizontal: 16, marginTop: 16 }}>
                            <Text
                                style={{
                                    fontSize: 12,
                                    fontWeight: "700",
                                    color: "#6B7280",
                                    marginBottom: 8,
                                    textTransform: "uppercase",
                                    letterSpacing: 0.5,
                                }}
                            >
                                Chọn điểm giao
                            </Text>
                            <View
                                style={{
                                    flexDirection: "row",
                                    gap: 6,
                                    flexWrap: "wrap",
                                }}
                            >
                                {deliveryPoints
                                    .filter((dp: any) => {
                                        // Ẩn DP đã giao xong
                                        if (
                                            dp.status === "delivered" ||
                                            dp.status === "completed"
                                        )
                                            return false;
                                        const s = dpStatusMap[dp.id];
                                        // Ẩn DP đã có cả arrived_delivery + completed checkpoint
                                        if (s?.completed) return false;
                                        return true;
                                    })
                                    .map((dp: any) => {
                                        const isSelected = dp.id === activeDpId;
                                        const s = dpStatusMap[dp.id];
                                        const isArrived = s?.arrived || false;
                                        // Locked: DP khác không chọn được khi đang mid-delivery, hoặc DP đã arrived nhưng chưa selected
                                        const isLocked =
                                            !isSelected &&
                                            (isMidDelivery ||
                                                (isArrived && !s?.completed));
                                        return (
                                            <TouchableOpacity
                                                key={dp.id}
                                                onPress={() =>
                                                    canSelectDp &&
                                                    !isLocked &&
                                                    setSelectedDpId(dp.id)
                                                }
                                                disabled={isLocked}
                                                activeOpacity={0.7}
                                                style={{
                                                    flexDirection: "row",
                                                    alignItems: "center",
                                                    gap: 6,
                                                    backgroundColor: isSelected
                                                        ? "#4F46E5"
                                                        : isLocked
                                                          ? "#F9FAFB"
                                                          : "#F3F4F6",
                                                    paddingHorizontal: 12,
                                                    paddingVertical: 8,
                                                    borderRadius: 20,
                                                    opacity: isLocked ? 0.5 : 1,
                                                }}
                                            >
                                                {isArrived && (
                                                    <Ionicons
                                                        name="time"
                                                        size={12}
                                                        color={
                                                            isSelected
                                                                ? "#A5B4FC"
                                                                : "#F59E0B"
                                                        }
                                                    />
                                                )}
                                                <Text
                                                    style={{
                                                        fontSize: 12,
                                                        fontWeight: "700",
                                                        color: isSelected
                                                            ? "#fff"
                                                            : isLocked
                                                              ? "#D1D5DB"
                                                              : "#6B7280",
                                                    }}
                                                >
                                                    Điểm {dp.sequence || "?"}
                                                </Text>
                                                <Text
                                                    style={{
                                                        fontSize: 12,
                                                        fontWeight: "600",
                                                        color: isSelected
                                                            ? "#C7D2FE"
                                                            : isLocked
                                                              ? "#E5E7EB"
                                                              : "#9CA3AF",
                                                    }}
                                                >
                                                    {dp.code ||
                                                        dp.location?.code ||
                                                        "..."}
                                                </Text>
                                            </TouchableOpacity>
                                        );
                                    })}
                            </View>
                        </View>
                    )}

                {/* Checkpoint form */}
                {(canArrivePickup ||
                    canLeftPickup ||
                    canArriveDelivery ||
                    canComplete ||
                    canEnd) &&
                    !isSwapped && (
                        <>
                            <View
                                style={{
                                    flexDirection: "row",
                                    justifyContent: "space-between",
                                    alignItems: "center",
                                    flexWrap: "wrap",
                                    rowGap: 4,
                                    paddingHorizontal: 12,
                                    marginBottom: 8,
                                    marginTop: 4,
                                }}
                            >
                                <Text
                                    style={{
                                        flexShrink: 1,
                                        fontSize: 16,
                                        fontWeight: "700",
                                        color: "#111827",
                                    }}
                                >
                                    📋 Cập nhật chốt chặng
                                    {deliveryPoints.length > 1 &&
                                        nextPendingDp && (
                                            <Text
                                                style={{
                                                    fontSize: 13,
                                                    fontWeight: "600",
                                                    color: "#4F46E5",
                                                }}
                                            >
                                                {" "}
                                                • Điểm{" "}
                                                {nextPendingDp.sequence ||
                                                    deliveryPoints.indexOf(
                                                        nextPendingDp,
                                                    ) + 1}
                                                /{deliveryPoints.length}
                                            </Text>
                                        )}
                                </Text>
                                <Text
                                    style={{ fontSize: 13, color: "#6B7280" }}
                                >
                                    Km xe:{" "}
                                    <Text
                                        style={{
                                            fontWeight: "700",
                                            color: "#4F46E5",
                                        }}
                                    >
                                        {vehicleKm != null
                                            ? parseInt(
                                                  String(vehicleKm),
                                                  10,
                                              ).toLocaleString("vi-VN")
                                            : "?"}
                                    </Text>
                                </Text>
                            </View>
                            <View style={s.formCard}>
                                <Pressable
                                    style={s.inputBox}
                                    onPress={() => kmInputRef.current?.focus()}
                                >
                                    <Ionicons
                                        name="speedometer-outline"
                                        size={18}
                                        color="#9CA3AF"
                                    />
                                    <TextInput
                                        ref={kmInputRef}
                                        style={s.inputField}
                                        placeholder="Số Km hiện tại"
                                        placeholderTextColor="#D1D5DB"
                                        keyboardType="number-pad"
                                        returnKeyType="done"
                                        onSubmitEditing={Keyboard.dismiss}
                                        value={formatKmInput(km)}
                                        onChangeText={(text) => setKm(text.replace(/\D/g, ""))}
                                        onFocus={handleKmFocus}
                                        onBlur={handleKmBlur}
                                        autoCorrect={false}
                                        autoComplete="off"
                                        importantForAutofill="no"
                                        textContentType="none"
                                    />
                                </Pressable>
                                {/* Photo row */}
                                <View
                                    style={{
                                        flexDirection: "row",
                                        gap: 8,
                                        marginBottom: 10,
                                        flexWrap: "wrap",
                                    }}
                                >
                                    {photos.map((uri, i) => (
                                        <View
                                            key={i}
                                            style={{ position: "relative" }}
                                        >
                                            <Image
                                                source={{ uri }}
                                                style={{
                                                    width: 56,
                                                    height: 56,
                                                    borderRadius: 10,
                                                }}
                                            />
                                            <TouchableOpacity
                                                style={{
                                                    position: "absolute",
                                                    top: -6,
                                                    right: -6,
                                                    backgroundColor: "#fff",
                                                    borderRadius: 10,
                                                }}
                                                onPress={() =>
                                                    setPhotos((p) =>
                                                        p.filter(
                                                            (_, j) => j !== i,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Ionicons
                                                    name="close-circle"
                                                    size={18}
                                                    color="#EF4444"
                                                />
                                            </TouchableOpacity>
                                        </View>
                                    ))}
                                    <TouchableOpacity
                                        style={{
                                            width: 56,
                                            height: 56,
                                            borderRadius: 10,
                                            borderWidth: 1.5,
                                            borderColor: "#E5E7EB",
                                            borderStyle: "dashed",
                                            alignItems: "center",
                                            justifyContent: "center",
                                        }}
                                        onPress={pickImage}
                                    >
                                        <Ionicons
                                            name="camera-outline"
                                            size={22}
                                            color="#9CA3AF"
                                        />
                                    </TouchableOpacity>
                                </View>
                                <View style={s.btnGroup}>
                                    {canArrivePickup && (
                                        <TouchableOpacity
                                            style={[
                                                s.ckBtn,
                                                {
                                                    backgroundColor:
                                                        cpInfo.arrived_pickup
                                                            .color,
                                                },
                                            ]}
                                            onPress={() =>
                                                submitCheckpoint(
                                                    "arrived_pickup",
                                                )
                                            }
                                            disabled={loading}
                                        >
                                            <Ionicons
                                                name="cube"
                                                size={16}
                                                color="#fff"
                                            />
                                            <Text style={s.btnText}>
                                                Đến lấy hàng
                                            </Text>
                                        </TouchableOpacity>
                                    )}
                                    {canLeftPickup && (
                                        <TouchableOpacity
                                            style={[
                                                s.ckBtn,
                                                {
                                                    backgroundColor:
                                                        cpInfo.left_pickup
                                                            .color,
                                                },
                                            ]}
                                            onPress={() =>
                                                submitCheckpoint("left_pickup")
                                            }
                                            disabled={loading}
                                        >
                                            <Ionicons
                                                name="arrow-forward"
                                                size={16}
                                                color="#fff"
                                            />
                                            <Text style={s.btnText}>
                                                Bắt đầu đi giao hàng
                                            </Text>
                                        </TouchableOpacity>
                                    )}
                                    {canArriveDelivery && (
                                        <TouchableOpacity
                                            style={[
                                                s.ckBtn,
                                                {
                                                    backgroundColor:
                                                        cpInfo.arrived_delivery
                                                            .color,
                                                },
                                            ]}
                                            onPress={() =>
                                                submitCheckpoint(
                                                    "arrived_delivery",
                                                )
                                            }
                                            disabled={loading}
                                        >
                                            <Ionicons
                                                name="location"
                                                size={16}
                                                color="#fff"
                                            />
                                            <Text style={s.btnText}>
                                                Đến giao hàng
                                                {activeDp?.sequence
                                                    ? ` (Điểm ${activeDp.sequence})`
                                                    : ""}
                                            </Text>
                                        </TouchableOpacity>
                                    )}
                                    {canComplete && (
                                        <TouchableOpacity
                                            style={[
                                                s.ckBtn,
                                                { backgroundColor: "#10B981" },
                                            ]}
                                            onPress={() =>
                                                submitCheckpoint("completed")
                                            }
                                            disabled={loading}
                                        >
                                            <Ionicons
                                                name="flag"
                                                size={16}
                                                color="#fff"
                                            />
                                            <Text style={s.btnText}>
                                                Giao hàng xong
                                                {activeDp?.sequence
                                                    ? ` (Điểm ${activeDp.sequence})`
                                                    : ""}
                                            </Text>
                                        </TouchableOpacity>
                                    )}
                                    {canEnd && (
                                        <TouchableOpacity
                                            style={[
                                                s.ckBtn,
                                                {
                                                    backgroundColor:
                                                        cpInfo.end.color,
                                                },
                                            ]}
                                            onPress={() =>
                                                submitCheckpoint("end")
                                            }
                                            disabled={loading}
                                        >
                                            <Ionicons
                                                name={cpInfo.end.icon as any}
                                                size={16}
                                                color="#fff"
                                            />
                                            <Text style={s.btnText}>
                                                {cpInfo.end.label}
                                            </Text>
                                        </TouchableOpacity>
                                    )}
                                </View>
                            </View>
                        </>
                    )}

                <CheckpointTimeline
                    checkpoints={checkpoints}
                    dpCodeMap={dpCodeMap}
                    showDpCode={deliveryPoints.length > 1}
                    myDriverId={shift?.driver?.id}
                />

                {d.status === "completed" && (
                    <View style={s.doneCard}>
                        <Ionicons
                            name="checkmark-circle"
                            size={48}
                            color="#10B981"
                        />
                        <Text style={s.doneText}>Đơn hàng đã hoàn thành</Text>
                        {d.loaded_km && (
                            <Text style={s.doneKm}>
                                Km có hàng: {d.loaded_km} km
                            </Text>
                        )}
                    </View>
                )}

                {!embedded && <View style={{ height: 40 }} />}
            </Scroll>
            <GeofenceBlockedModal
                error={geofenceBlock?.error ?? null}
                onClose={() => setGeofenceBlock(null)}
                onRetry={() => {
                    const type = geofenceBlock?.type;
                    setGeofenceBlock(null);
                    if (type) submitCheckpoint(type);
                }}
            />
        </Outer>
    );
}

const s = StyleSheet.create({
    container: { flex: 1, backgroundColor: "#F9FAFB" },
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
    routeBar: {
        marginBottom: 12,
        paddingBottom: 10,
        borderBottomWidth: 1,
        borderBottomColor: "#F3F4F6",
    },
    routeRow: { flexDirection: "row", alignItems: "flex-start", gap: 10 },
    routeDot: {
        width: 10,
        height: 10,
        borderRadius: 5,
        backgroundColor: "#10B981",
        marginTop: 4,
    },
    routeArrow: { paddingLeft: 4, paddingVertical: 2 },
    routeCode: { fontSize: 14, fontWeight: "700", color: "#111827" },
    routeAddr: { fontSize: 12, color: "#6B7280", marginTop: 2 },
    orderCode: { fontSize: 20, fontWeight: "800", color: "#111827" },
    typeBadge: {
        fontSize: 11,
        fontWeight: "700",
        paddingHorizontal: 6,
        paddingVertical: 2,
        borderRadius: 6,
        overflow: "hidden",
    },
    cargoName: { fontSize: 14, color: "#6B7280", marginTop: 3 },
    notesCard: {
        backgroundColor: "#fff",
        marginHorizontal: 12,
        marginBottom: 8,
        padding: 10,
        borderRadius: 12,
        borderWidth: 1,
        borderColor: "#EEF2FF",
        borderLeftWidth: 3,
        borderLeftColor: "#4F46E5",
    },
    notesText: { fontSize: 14, color: "#374151", lineHeight: 20 },
    statusPill: {
        flexDirection: "row",
        alignItems: "center",
        gap: 4,
        paddingHorizontal: 10,
        paddingVertical: 5,
        borderRadius: 20,
    },
    statusPillText: { fontSize: 12, fontWeight: "700" },
    infoStrip: {
        flexDirection: "row",
        backgroundColor: "#fff",
        marginHorizontal: 12,
        marginBottom: 8,
        paddingVertical: 8,
        borderRadius: 12,
        borderWidth: 1,
        borderColor: "#F3F4F6",
    },
    infoCell: { flex: 1, alignItems: "center" },
    infoValue: { fontSize: 14, fontWeight: "700", color: "#111827", marginTop: 2 },
    infoGrid: {
        flexDirection: "row",
        flexWrap: "wrap",
        paddingHorizontal: 12,
        gap: 8,
        marginBottom: 8,
    },
    tile: {
        width: "30%",
        backgroundColor: "#fff",
        padding: 12,
        borderRadius: 12,
        borderWidth: 1,
        borderColor: "#F3F4F6",
        alignItems: "center",
        gap: 4,
    },
    tileLabel: { fontSize: 11, color: "#9CA3AF" },
    tileValue: {
        fontSize: 13,
        fontWeight: "600",
        color: "#111827",
        textAlign: "center",
    },
    addressCard: {
        flexDirection: "row",
        alignItems: "center",
        gap: 12,
        backgroundColor: "#fff",
        marginHorizontal: 16,
        padding: 14,
        borderRadius: 12,
        borderWidth: 1,
        borderColor: "#F3F4F6",
        marginBottom: 16,
    },
    addressIcon: {
        width: 40,
        height: 40,
        borderRadius: 12,
        backgroundColor: "#EEF2FF",
        alignItems: "center",
        justifyContent: "center",
    },
    addressLabel: { fontSize: 12, color: "#9CA3AF" },
    addressValue: {
        fontSize: 14,
        fontWeight: "600",
        color: "#111827",
        marginTop: 2,
    },
    addressSub: { fontSize: 12, color: "#9CA3AF", marginTop: 1 },
    sectionTitle: {
        fontSize: 16,
        fontWeight: "700",
        color: "#111827",
        paddingHorizontal: 16,
        marginBottom: 10,
        marginTop: 8,
    },
    formCard: {
        backgroundColor: "#fff",
        marginHorizontal: 12,
        padding: 10,
        borderRadius: 14,
        borderWidth: 1.5,
        borderColor: "#C7D2FE",
        marginBottom: 8,
    },
    // Ô nhập có icon nằm bên trong
    inputBox: {
        flexDirection: "row",
        alignItems: "center",
        gap: 8,
        paddingHorizontal: 12,
        borderRadius: 10,
        borderWidth: 1,
        borderColor: "#E5E7EB",
        marginBottom: 8,
    },
    inputField: { flex: 1, paddingVertical: 10, fontSize: 15, color: "#111827" },
    btnGroup: { flexDirection: "row", gap: 8, flexWrap: "wrap" },
    // Nút bước chính: to, viền sáng + đổ bóng để nổi bật trên nền trắng
    ckBtn: {
        flex: 1,
        minWidth: "45%",
        flexDirection: "row",
        alignItems: "center",
        justifyContent: "center",
        gap: 8,
        paddingVertical: 12,
        borderRadius: 12,
        borderWidth: 2,
        borderColor: "rgba(255,255,255,0.45)",
        shadowColor: "#1E1B4B",
        shadowOpacity: 0.28,
        shadowRadius: 8,
        shadowOffset: { width: 0, height: 4 },
        elevation: 5,
    },
    btnText: { color: "#fff", fontWeight: "800", fontSize: 15 },
    // Done
    doneCard: { alignItems: "center", paddingVertical: 32 },
    doneText: {
        fontSize: 16,
        fontWeight: "600",
        color: "#059669",
        marginTop: 10,
    },
    doneKm: { fontSize: 14, color: "#6B7280", marginTop: 4 },
    // Location picker
    pickerBtn: {
        flexDirection: "row",
        alignItems: "center",
        gap: 8,
        paddingVertical: 12,
        paddingHorizontal: 10,
        borderRadius: 10,
        borderWidth: 1,
        borderColor: "#E5E7EB",
    },
    pickerBtnText: { flex: 1, fontSize: 15, color: "#9CA3AF" },
    selectedLoc: { flexDirection: "row", alignItems: "center", gap: 8 },
    selectedLocName: { fontSize: 15, fontWeight: "600", color: "#111827" },
    selectedLocAddr: { fontSize: 12, color: "#6B7280", marginTop: 2 },
    // Modal
    modalContainer: { flex: 1, backgroundColor: "#F9FAFB" },
    modalHeader: {
        flexDirection: "row",
        justifyContent: "space-between",
        alignItems: "center",
        padding: 16,
        backgroundColor: "#fff",
        borderBottomWidth: 1,
        borderBottomColor: "#F3F4F6",
    },
    modalTitle: { fontSize: 18, fontWeight: "700", color: "#111827" },
    searchWrap: {
        flexDirection: "row",
        alignItems: "center",
        margin: 12,
        backgroundColor: "#fff",
        borderRadius: 12,
        paddingHorizontal: 12,
        borderWidth: 1,
        borderColor: "#E5E7EB",
        gap: 8,
    },
    searchInput: {
        flex: 1,
        paddingVertical: 10,
        fontSize: 15,
        color: "#111827",
    },
    modalLocItem: {
        flexDirection: "row",
        alignItems: "center",
        gap: 12,
        padding: 14,
        backgroundColor: "#fff",
        marginHorizontal: 12,
        marginBottom: 6,
        borderRadius: 12,
        borderWidth: 1,
        borderColor: "#F3F4F6",
    },
    modalLocName: { fontSize: 15, fontWeight: "600", color: "#111827" },
    modalLocAddr: { fontSize: 12, color: "#9CA3AF", marginTop: 2 },
});
