import { useState, useCallback, useEffect } from "react";
import {
  View,
  Text,
  StyleSheet,
  ScrollView,
  TouchableOpacity,
  TextInput,
  RefreshControl,
  FlatList,
  Modal,
  Image,
} from "react-native";
import { useLocalSearchParams, useFocusEffect } from "expo-router";
import { useAuth } from "../src/lib/auth";
import { api } from "../src/lib/api";
import { showAlert } from "../src/lib/alert";
import * as ImagePicker from "expo-image-picker";
import * as Location from "expo-location";
import { Ionicons } from "@expo/vector-icons";

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
};

const cpInfo: Record<string, { icon: string; color: string; label: string }> = {
  started: { icon: "play", color: "#4F46E5", label: "Bắt đầu" },
  arrived_pickup: { icon: "cube", color: "#F59E0B", label: "Đến lấy hàng" },
  left_pickup: {
    icon: "arrow-forward",
    color: "#8B5CF6",
    label: "Rời lấy hàng",
  },
  arrived_delivery: {
    icon: "location",
    color: "#3B82F6",
    label: "Đến giao hàng",
  },
  completed: { icon: "flag", color: "#10B981", label: "Giao hàng xong" },
  end: { icon: "close-circle", color: "#EF4444", label: "Kết thúc đơn hàng" },
};

export default function OrderDetailScreen() {
  const { token } = useAuth();
  const params = useLocalSearchParams<{ id: string; order: string }>();
  const order = params.order ? JSON.parse(params.order) : null;
  const [km, setKm] = useState("");
  const [note, setNote] = useState("");
  const [loading, setLoading] = useState(false);
  const [detail, setDetail] = useState<any>(null);
  const [refreshing, setRefreshing] = useState(false);

  // Location picker for orders without delivery point
  const [locSearch, setLocSearch] = useState("");
  const [locations, setLocations] = useState<any[]>([]);
  const [showLocPicker, setShowLocPicker] = useState(false);
  const [selectedLoc, setSelectedLoc] = useState<any>(null);

  // Photo capture
  const [photos, setPhotos] = useState<string[]>([]);

  // Fetch full order detail with checkpoints from API
  const loadDetail = async () => {
    if (!token || !order?.id) return;
    try {
      const r = await api.orders.detail(String(order.id), token);
      setDetail(r.data || r);
    } catch {}
  };

  useFocusEffect(
    useCallback(() => {
      loadDetail();
    }, [order?.id, token]),
  );
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
      // Thử với area_id trước
      let r = await api.locations(
        { search: q || undefined, area_id: order?.area_id },
        token,
      );
      let data = r.data || [];
      // Nếu không có kết quả, fallback bỏ area_id filter
      if (data.length === 0 && order?.area_id) {
        r = await api.locations({ search: q || undefined }, token);
        data = r.data || [];
      }
      setLocations(data);
    } catch {}
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
  const vehicleKm = d.vehicle?.current_mileage ?? d.vehicle?.km_reading ?? null;

  // Auto-fill KM from vehicle's current mileage
  useEffect(() => {
    if (vehicleKm != null && !km) {
      setKm(String(Math.round(vehicleKm)));
    }
  }, [vehicleKm]);
  // Find next pending delivery point (for multi-DP orders)
  const deliveryPoints: any[] = d.delivery_points || [];
  const nextPendingDp = deliveryPoints.find((dp: any) => dp.status !== "delivered" && dp.status !== "completed");
  const dpId = nextPendingDp?.id || deliveryPoints[0]?.id;
  const checkpoints = d.trip_checkpoints || [];
  // Map DP id to sequence for timeline display
  const dpSeqMap: Record<string, number> = {};
  deliveryPoints.forEach((dp: any) => { dpSeqMap[dp.id] = dp.sequence; });
  const hasDeliveryPoint = !!dpId || deliveryPoints.length > 0;
  const hasEndCheckpoint = checkpoints.some((cp: any) => cp.checkpoint_type === "end");

  // Sequential: chỉ hiện 1 action tại 1 thời điểm, theo đúng luồng
  const hasArrivedPickup = checkpoints.some((cp: any) => cp.checkpoint_type === "arrived_pickup");
  const hasLeftPickup = checkpoints.some((cp: any) => cp.checkpoint_type === "left_pickup");

  // Check next pending DP for arrived_delivery/completed
  const pendingDpHasCp = (cpType: string) =>
    nextPendingDp ? checkpoints.some((cp: any) =>
      cp.checkpoint_type === cpType && cp.delivery_point_id === nextPendingDp.id
    ) : false;
  const hasArrivedDelivery = pendingDpHasCp("arrived_delivery");
  const hasCompleted = pendingDpHasCp("completed");

  const canArrivePickup =
    (d.status === "assigned" || d.status === "sent") && !hasArrivedPickup;
  const canLeftPickup =
    d.status === "sent" && hasArrivedPickup && !hasLeftPickup;
  const canArriveDelivery = d.status === "in_transit" && !!nextPendingDp && !hasArrivedDelivery;
  const canComplete =
    d.status === "in_transit" && !!nextPendingDp && hasArrivedDelivery && !hasCompleted;
  const canEnd = d.status === "completed" && !hasEndCheckpoint;

  async function pickImage() {
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

  async function getGpsCoordinates() {
    try {
      const { status } = await Location.requestForegroundPermissionsAsync();
      if (status !== "granted") return null;
      const pos = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
      return { gps_lat: pos.coords.latitude, gps_lng: pos.coords.longitude };
    } catch { return null; }
  }

  async function submitCheckpoint(type: string) {
    if (!tripId || !token || !d.id) return;
    if (
      !km &&
      ["arrived_pickup", "arrived_delivery", "completed", "end"].includes(type)
    ) {
      showAlert("Thiếu", "Vui lòng nhập số Km");
      return;
    }
    const body: any = {
      checkpoint_type: type,
      occurred_at: new Date().toISOString(),
    };
    // Capture GPS coordinates
    const gps = await getGpsCoordinates();
    if (gps) {
      body.gps_lat = gps.gps_lat;
      body.gps_lng = gps.gps_lng;
    }
    if (km) body.km_reading = parseFloat(km);
    if (note) body.voice_note = note;
    if (d.id) body.order_id = d.id;
    if (photos.length > 0) body.photos = photos;

    // Nếu có delivery_point_id thì dùng, nếu không có nhưng đã chọn location thì gửi new_delivery_location_id
    if (dpId && ["arrived_delivery", "completed", "end"].includes(type)) {
      body.delivery_point_id = dpId;
    } else if (
      !hasDeliveryPoint &&
      selectedLoc &&
      ["arrived_delivery", "completed", "end"].includes(type)
    ) {
      body.new_delivery_location_id = selectedLoc.id;
    }

    setLoading(true);
    try {
      await api.trips.checkpoint(String(tripId), body, token);
      showAlert("Thành công", `Đã cập nhật: ${cpInfo[type]?.label || type}`);
      setKm("");
      setNote("");
      setPhotos([]);
      setSelectedLoc(null);
      await loadDetail(); // refresh
    } catch (e: any) {
      showAlert("Lỗi", e.message);
    } finally {
      setLoading(false);
    }
  }

  return (
    <ScrollView
      style={s.container}
      showsVerticalScrollIndicator={false}
      refreshControl={
        <RefreshControl
          refreshing={refreshing}
          onRefresh={onRefresh}
          tintColor="#4F46E5"
        />
      }
    >
      {/* Hero */}
      <View
        style={[s.heroCard, { borderLeftColor: st.text, borderLeftWidth: 4 }]}
      >
        <View style={s.heroRow}>
          <View style={{ flex: 1 }}>
            <Text style={s.orderCode}>
              {d.order_code}
            </Text>
            <View style={{ paddingHorizontal: 6, paddingVertical: 2, borderRadius: 6, backgroundColor: d.type === 'HHHK' ? '#E0F2FE' : '#FEF3C7', alignSelf: 'flex-start' }}>
              <Text style={{ fontSize: 11, fontWeight: '700', color: d.type === 'HHHK' ? '#0369A1' : '#B45309' }}>{d.type_label || d.type}</Text>
            </View>
            <Text style={s.cargoName}>
              {d.cargo_name || "Chưa có tên hàng"}
            </Text>
          </View>
          <View style={[s.statusPill, { backgroundColor: st.bg }]}>
            <Ionicons name={st.icon as any} size={14} color={st.text} />
            <Text style={[s.statusPillText, { color: st.text }]}>
              {st.label}
            </Text>
          </View>
        </View>
      </View>

      {/* Info grid */}
      <View style={s.infoGrid}>
        {[
          {
            icon: "business",
            label: "Khách hàng",
            value: d.customer?.name || "-",
          },
          { icon: "cube", label: "Hàng hóa", value: d.cargo_name || "-" },
          {
            icon: "file-tray-full",
            label: "Số kiện",
            value: String(d.total_packages || "-"),
          },
          {
            icon: "barbell",
            label: "Trọng lượng",
            value: d.total_weight ? `${d.total_weight} tấn` : "-",
          },
          {
            icon: "car",
            label: "Biển số",
            value: d.vehicle?.plate_number || d.vehicle_plate_number || "-",
          },
          {
            icon: "speedometer",
            label: "Km có hàng",
            value: d.loaded_km ? `${d.loaded_km} km` : "-",
          },
        ].map((t, i) => (
          <View key={i} style={s.tile}>
            <Ionicons name={t.icon as any} size={18} color="#4F46E5" />
            <Text style={s.tileLabel}>{t.label}</Text>
            <Text style={s.tileValue} numberOfLines={1}>
              {t.value}
            </Text>
          </View>
        ))}
      </View>

      {d.pickup_address && (
        <View style={s.addressCard}>
          <View style={s.addressIcon}>
            <Ionicons name="location" size={18} color="#4F46E5" />
          </View>
          <View style={{ flex: 1 }}>
            <Text style={s.addressLabel}>Điểm lấy hàng</Text>
            <Text style={s.addressValue}>{d.pickup_location?.code || d.pickup_address}</Text>
            {d.pickup_location?.name && (
              <Text style={s.addressSub}>{d.pickup_location.name}</Text>
            )}
          </View>
        </View>
      )}

      {d.delivery_points?.length > 0 &&
        d.delivery_points.map((dp: any, i: number) => (
          <View key={dp.id || i} style={s.addressCard}>
            <View
              style={[
                s.addressIcon,
                {
                  backgroundColor:
                    dp.status === "delivered"
                      ? "#D1FAE5"
                      : dp.status === "arrived"
                        ? "#FEF3C7"
                        : "#EEF2FF",
                },
              ]}
            >
              <Ionicons name="navigate" size={18} color="#4F46E5" />
            </View>
            <View style={{ flex: 1 }}>
              <Text style={s.addressLabel}>
                Điểm giao{" "}
                {d.delivery_points.length > 1 ? `#${dp.sequence || i + 1}` : ""}
                {dp.status === "delivered"
                  ? " ✅"
                  : dp.status === "arrived"
                    ? " 📍"
                    : ""}
              </Text>
              <Text style={s.addressValue}>
                {dp.location?.code || dp.location_name || dp.address || "Địa chỉ đã chọn"}
              </Text>
              {dp.location?.name && (
                <Text style={s.addressSub}>{dp.location.name}</Text>
              )}
            </View>
          </View>
        ))}

      {/* Location picker — khi đơn chưa có điểm đến */}
      {!hasDeliveryPoint && (canArriveDelivery || canComplete) && (
        <>
          <Text style={s.sectionTitle}>📍 Chọn điểm đến</Text>
          <View style={s.formCard}>
            {selectedLoc ? (
              <View style={s.selectedLoc}>
                <View style={{ flex: 1 }}>
                  <Text style={s.selectedLocName}>{selectedLoc.name}</Text>
                  <Text style={s.selectedLocAddr}>
                    {selectedLoc.address || selectedLoc.code}
                  </Text>
                </View>
                <TouchableOpacity
                  onPress={() => {
                    setSelectedLoc(null);
                  }}
                >
                  <Ionicons name="close-circle" size={20} color="#EF4444" />
                </TouchableOpacity>
              </View>
            ) : (
              <TouchableOpacity
                style={s.pickerBtn}
                onPress={() => {
                  setShowLocPicker(true);
                  searchLocations("");
                }}
              >
                <Ionicons name="location-outline" size={18} color="#4F46E5" />
                <Text style={s.pickerBtnText}>Chạm để chọn điểm đến</Text>
                <Ionicons name="chevron-down" size={16} color="#9CA3AF" />
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
      >
        <View style={s.modalContainer}>
          <View style={s.modalHeader}>
            <Text style={s.modalTitle}>Chọn điểm đến</Text>
            <TouchableOpacity onPress={() => setShowLocPicker(false)}>
              <Ionicons name="close" size={24} color="#111827" />
            </TouchableOpacity>
          </View>
          <View style={s.searchWrap}>
            <Ionicons name="search-outline" size={18} color="#9CA3AF" />
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
            renderItem={({ item }) => (
              <TouchableOpacity
                style={s.modalLocItem}
                onPress={() => {
                  setSelectedLoc(item);
                  setShowLocPicker(false);
                }}
              >
                <Ionicons name="location-outline" size={20} color="#4F46E5" />
                <View style={{ flex: 1 }}>
                  <Text style={s.modalLocName}>{item.name}</Text>
                  <Text style={s.modalLocAddr} numberOfLines={1}>
                    {item.address || item.code}
                  </Text>
                </View>
                <Ionicons name="chevron-forward" size={16} color="#D1D5DB" />
              </TouchableOpacity>
            )}
            ListEmptyComponent={
              <View style={{ alignItems: "center", padding: 32 }}>
                <Text style={{ color: "#9CA3AF" }}>
                  Không tìm thấy điểm đến
                </Text>
              </View>
            }
          />
        </View>
      </Modal>

      {/* Checkpoint form */}
      {(canArrivePickup ||
        canLeftPickup ||
        canArriveDelivery ||
        canComplete ||
        canEnd) && (
        <>
          <View
            style={{
              flexDirection: "row",
              justifyContent: "space-between",
              alignItems: "center",
              paddingHorizontal: 16,
              marginBottom: 10,
              marginTop: 8,
            }}
          >
            <Text style={{ fontSize: 16, fontWeight: "700", color: "#111827" }}>
              📋 Cập nhật chốt chặng
              {deliveryPoints.length > 1 && nextPendingDp && (
                <Text style={{ fontSize: 13, fontWeight: "600", color: "#4F46E5" }}>
                  {" "}• Điểm {nextPendingDp.sequence || (deliveryPoints.indexOf(nextPendingDp) + 1)}/{deliveryPoints.length}
                </Text>
              )}
            </Text>
            <Text style={{ fontSize: 13, color: "#6B7280" }}>
              Km xe:{" "}
              <Text style={{ fontWeight: "700", color: "#4F46E5" }}>
                {d.vehicle?.current_mileage != null
                  ? parseInt(d.vehicle.current_mileage).toLocaleString("vi-VN")
                  : "?"}
              </Text>
            </Text>
          </View>
          <View style={s.formCard}>
            <View style={s.inputRow}>
              <Ionicons
                name="speedometer-outline"
                size={18}
                color="#9CA3AF"
                style={{ marginTop: 12 }}
              />
              <TextInput
                style={s.input}
                placeholder="Số Km hiện tại"
                placeholderTextColor="#D1D5DB"
                keyboardType="numeric"
                value={km}
                onChangeText={setKm}
              />
              {vehicleKm != null && (
                <Text style={{ fontSize: 11, color: "#9CA3AF", marginTop: 2 }}>
                  Km hiện tại của xe: {Math.round(vehicleKm).toLocaleString("vi-VN")} km
                </Text>
              )}
            </View>
            <View style={s.inputRow}>
              <Ionicons
                name="mic-outline"
                size={18}
                color="#9CA3AF"
                style={{ marginTop: 12 }}
              />
              <TextInput
                style={s.input}
                placeholder="Ghi chú"
                placeholderTextColor="#D1D5DB"
                value={note}
                onChangeText={setNote}
              />
            </View>
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
                <View key={i} style={{ position: "relative" }}>
                  <Image
                    source={{ uri }}
                    style={{ width: 56, height: 56, borderRadius: 10 }}
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
                      setPhotos((p) => p.filter((_, j) => j !== i))
                    }
                  >
                    <Ionicons name="close-circle" size={18} color="#EF4444" />
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
                <Ionicons name="camera-outline" size={22} color="#9CA3AF" />
              </TouchableOpacity>
            </View>
            <View style={s.btnGroup}>
              {canArrivePickup && (
                <TouchableOpacity
                  style={[
                    s.ckBtn,
                    { backgroundColor: cpInfo.arrived_pickup.color },
                  ]}
                  onPress={() => submitCheckpoint("arrived_pickup")}
                  disabled={loading}
                >
                  <Ionicons name="cube" size={16} color="#fff" />
                  <Text style={s.btnText}>Đến lấy hàng</Text>
                </TouchableOpacity>
              )}
              {canLeftPickup && (
                <TouchableOpacity
                  style={[
                    s.ckBtn,
                    { backgroundColor: cpInfo.left_pickup.color },
                  ]}
                  onPress={() => submitCheckpoint("left_pickup")}
                  disabled={loading}
                >
                  <Ionicons name="arrow-forward" size={16} color="#fff" />
                  <Text style={s.btnText}>Rời lấy hàng</Text>
                </TouchableOpacity>
              )}
              {canArriveDelivery && (
                <TouchableOpacity
                  style={[
                    s.ckBtn,
                    { backgroundColor: cpInfo.arrived_delivery.color },
                  ]}
                  onPress={() => submitCheckpoint("arrived_delivery")}
                  disabled={loading}
                >
                  <Ionicons name="location" size={16} color="#fff" />
                  <Text style={s.btnText}>Đến giao hàng</Text>
                </TouchableOpacity>
              )}
              {canComplete && (
                <TouchableOpacity
                  style={[s.ckBtn, { backgroundColor: "#10B981" }]}
                  onPress={() => submitCheckpoint("completed")}
                  disabled={loading}
                >
                  <Ionicons name="flag" size={16} color="#fff" />
                  <Text style={s.btnText}>Giao hàng xong</Text>
                </TouchableOpacity>
              )}
              {canEnd && (
                <TouchableOpacity
                  style={[s.ckBtn, { backgroundColor: cpInfo.end.color }]}
                  onPress={() => submitCheckpoint("end")}
                  disabled={loading}
                >
                  <Ionicons
                    name={cpInfo.end.icon as any}
                    size={16}
                    color="#fff"
                  />
                  <Text style={s.btnText}>{cpInfo.end.label}</Text>
                </TouchableOpacity>
              )}
            </View>
          </View>
        </>
      )}

      {/* Timeline — lịch sử chốt chặng */}
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
                    style={[s.timelineDot, { backgroundColor: ci.color }]}
                  />
                  {i < checkpoints.length - 1 && <View style={s.timelineBar} />}
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
                    <Text style={[s.tlLabel, { color: ci.color }]}>
                      {ci.label}
                      {cp.delivery_point_id && dpSeqMap[cp.delivery_point_id] && deliveryPoints.length > 1
                        ? ` (${dpSeqMap[cp.delivery_point_id]})`
                        : ""}
                    </Text>
                  </View>
                  <Text style={s.tlInfo}>
                    Km:{" "}
                    {cp.km_reading != null
                      ? parseInt(cp.km_reading).toLocaleString("vi-VN")
                      : "-"}{" "}
                    • {new Date(cp.occurred_at).toLocaleString("vi-VN")}
                  </Text>
                  {cp.voice_note ? (
                    <Text style={s.tlNote}>💬 {cp.voice_note}</Text>
                  ) : null}
                  {cp.photos?.length > 0 && (
                    <ScrollView horizontal style={{ marginTop: 6 }} showsHorizontalScrollIndicator={false}>
                      {cp.photos.map((p: any, pi: number) => {
                        const uri = p.photo_url || p.photo_path || (typeof p === 'string' ? p : null);
                        if (!uri) return null;
                        return (
                          <Image key={pi} source={{ uri }} style={{ width: 48, height: 48, borderRadius: 8, marginRight: 4 }} />
                        );
                      })}
                    </ScrollView>
                  )}
                </View>
              </View>
            );
          })}
        </>
      )}

      {d.status === "completed" && (
        <View style={s.doneCard}>
          <Ionicons name="checkmark-circle" size={48} color="#10B981" />
          <Text style={s.doneText}>Đơn hàng đã hoàn thành</Text>
          {d.loaded_km && (
            <Text style={s.doneKm}>Km có hàng: {d.loaded_km} km</Text>
          )}
        </View>
      )}

      <View style={{ height: 40 }} />
    </ScrollView>
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
  orderCode: { fontSize: 20, fontWeight: "800", color: "#111827" },
  typeBadge: { fontSize: 11, fontWeight: "700", paddingHorizontal: 6, paddingVertical: 2, borderRadius: 6, overflow: "hidden" },
  cargoName: { fontSize: 14, color: "#6B7280", marginTop: 3 },
  statusPill: {
    flexDirection: "row",
    alignItems: "center",
    gap: 4,
    paddingHorizontal: 10,
    paddingVertical: 5,
    borderRadius: 20,
  },
  statusPillText: { fontSize: 12, fontWeight: "700" },
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
    marginHorizontal: 16,
    padding: 14,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: "#F3F4F6",
    marginBottom: 12,
  },
  inputRow: { flexDirection: "row", gap: 8 },
  input: {
    flex: 1,
    padding: 10,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: "#E5E7EB",
    fontSize: 15,
    marginBottom: 8,
    color: "#111827",
  },
  btnGroup: { flexDirection: "row", gap: 8, flexWrap: "wrap" },
  ckBtn: {
    flex: 1,
    minWidth: "45%",
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 6,
    paddingVertical: 11,
    borderRadius: 10,
  },
  btnText: { color: "#fff", fontWeight: "700", fontSize: 12 },
  // Timeline
  timelineItem: { flexDirection: "row", paddingHorizontal: 16 },
  timelineLine: { alignItems: "center", width: 24, marginRight: 12 },
  timelineDot: { width: 12, height: 12, borderRadius: 6 },
  timelineBar: { width: 2, flex: 1, backgroundColor: "#E5E7EB", marginTop: 4 },
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
  tlNote: { fontSize: 12, color: "#374151", marginTop: 3, fontStyle: "italic" },
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
  searchInput: { flex: 1, paddingVertical: 10, fontSize: 15, color: "#111827" },
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
