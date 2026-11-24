import { useState, useCallback, useMemo, useRef } from "react";
import {
  View,
  Text,
  TouchableOpacity,
  TextInput,
  StyleSheet,
  Alert,
  ScrollView,
  Keyboard,
  ActivityIndicator,
} from "react-native";
import { router, useFocusEffect } from "expo-router";
import { useAuth } from "../src/lib/auth";
import { useLoading } from "../src/lib/loading";
import { api, type ShiftVehicleOption } from "../src/lib/api";
import { showAlert } from "../src/lib/alert";
import { formatKmInput, parseKmInput } from "../src/lib/format";
import { Ionicons } from "@expo/vector-icons";
import { getFastGpsCoordinates } from "../src/lib/location";

const shiftOptions = [
  { key: "full", label: "Cả ca (X)", desc: "Làm việc toàn thời gian" },
  { key: "morning_half", label: "Nửa ca ngày (X/2)", desc: "Buổi sáng" },
  { key: "night_half", label: "Nửa ca đêm (Y/2)", desc: "Buổi tối" },
];

const INCOMPLETE_ORDER_STATUSES = ["sent", "in_transit", "assigned"];

export default function ShiftScreen() {
  const { token, setAuth, shift, setShift } = useAuth();
  const { showLoading, hideLoading } = useLoading();
  const [loading, setLoading] = useState(false);
  const [showEnd, setShowEnd] = useState(Boolean(shift && !shift.end_time));
  const [endKm, setEndKm] = useState("");
  const [ending, setEnding] = useState(false);
  const [activeWarning, setActiveWarning] = useState<string | null>(null);
  const [activeWarningTripId, setActiveWarningTripId] = useState<string | number | null>(null);

  const [selectedShiftType, setSelectedShiftType] = useState<string | null>(null);
  const [vehicles, setVehicles] = useState<ShiftVehicleOption[]>([]);
  const [loadingVehicles, setLoadingVehicles] = useState(false);
  const [vehicleQuery, setVehicleQuery] = useState("");
  const [suggestedVehicleId, setSuggestedVehicleId] = useState<number | null>(null);
  const [selectedVehicleId, setSelectedVehicleId] = useState<number | null>(null);
  const hasTouchedVehicleRef = useRef(false);

  useFocusEffect(
    useCallback(() => {
      if (!token) return;
      if (shift) {
        api.shifts
          .current(token)
          .then((res) => {
            const nextShift = res?.shift ?? null;
            // Ca đã kết thúc (kể cả bị auto-end 08:00/20:00) → clear state cũ của app
            if (!nextShift || nextShift.end_time) {
              setShift(null);
              setShowEnd(false);
              return;
            }
            setShift(nextShift);
            setShowEnd(true);
            const vKm = nextShift.vehicle?.current_mileage;
            if (vKm != null && !endKm) setEndKm(String(Math.round(Number(vKm))));
          })
          .catch(() => {});
      } else {
        setShowEnd(false);
      }
    }, [token, shift?.id]),
  );

  useFocusEffect(
    useCallback(() => {
      if (!token || showEnd) return;
      let active = true;
      setLoadingVehicles(true);
      api.vehicles
        .shiftOptions(token)
        .then((res) => {
          if (!active) return;
          const list = Array.isArray(res?.data) ? res.data : [];
          setVehicles(list);
          const suggested = res?.suggested_vehicle_id ?? null;
          setSuggestedVehicleId(suggested);
          if (!hasTouchedVehicleRef.current && suggested) {
            setSelectedVehicleId(suggested);
          }
        })
        .catch(() => {
          if (!active) return;
          setVehicles([]);
        })
        .finally(() => {
          if (active) setLoadingVehicles(false);
        });

      return () => {
        active = false;
      };
    }, [token, showEnd]),
  );

  useFocusEffect(
    useCallback(() => {
      if (!token) return;
      api.trips
        .active(token)
        .then((res) => {
          const trips = res?.data;
          if (!trips || !Array.isArray(trips) || trips.length === 0) {
            setActiveWarning(null);
            setActiveWarningTripId(null);
            return;
          }
          const incompleteOrders: { tripId: number | string; tripCode: string; count: number }[] = [];
          trips.forEach((trip: any) => {
            const cnt = (trip.orders || []).filter((o: any) =>
              INCOMPLETE_ORDER_STATUSES.includes(o.status),
            ).length;
            if (cnt > 0) incompleteOrders.push({ tripId: trip.id, tripCode: trip.trip_code, count: cnt });
          });
          if (incompleteOrders.length > 0) {
            setActiveWarningTripId(incompleteOrders[0].tripId);
            const lines = incompleteOrders
              .map((item) => `${item.tripCode}: ${item.count} đơn chưa xong`)
              .join(", ");
            setActiveWarning(`${trips.length} chuyến đang hoạt động — ${lines}`);
          } else {
            setActiveWarning(null);
            setActiveWarningTripId(null);
          }
        })
        .catch(() => {
          setActiveWarning(null);
          setActiveWarningTripId(null);
        });
    }, [token]),
  );

  const selectedVehicle = useMemo(
    () => vehicles.find((v) => v.id === selectedVehicleId) ?? null,
    [vehicles, selectedVehicleId],
  );

  const filteredVehicles = useMemo(() => {
    const q = vehicleQuery.trim().toLowerCase();
    const list = q
      ? vehicles.filter((v) => v.plate_number.toLowerCase().includes(q))
      : vehicles;

    return [...list].sort((a, b) => {
      const aSelected = a.id === selectedVehicleId ? 1 : 0;
      const bSelected = b.id === selectedVehicleId ? 1 : 0;
      if (aSelected !== bSelected) return bSelected - aSelected;

      const aSuggested = a.id === suggestedVehicleId ? 1 : 0;
      const bSuggested = b.id === suggestedVehicleId ? 1 : 0;
      if (aSuggested !== bSuggested) return bSuggested - aSuggested;

      return a.plate_number.localeCompare(b.plate_number);
    });
  }, [vehicles, vehicleQuery, selectedVehicleId, suggestedVehicleId]);

  function handleSelectVehicle(vehicleId: number) {
    Keyboard.dismiss();
    hasTouchedVehicleRef.current = true;
    setSelectedVehicleId((prev) => (prev === vehicleId ? null : vehicleId));
  }

  function handleClearVehicle() {
    hasTouchedVehicleRef.current = true;
    setSelectedVehicleId(null);
  }

  async function handleStartShift() {
    if (!selectedShiftType) {
      showAlert("Thiếu thông tin", "Vui lòng chọn loại ca trước khi bắt đầu.");
      return;
    }

    setLoading(true);
    showLoading();
    try {
      let gps = null;
      try {
        const coords = await getFastGpsCoordinates();
        if (coords) {
          gps = { start_gps_lat: coords.gps_lat, start_gps_lng: coords.gps_lng };
        }
      } catch (e) {
        // Không lấy được GPS: vẫn cho vào ca, chỉ ghi log để debug
        console.warn("[shift] Không lấy được GPS khi vào ca:", e);
      }
      const res = await api.shifts.start(
        {
          shift_type: selectedShiftType,
          vehicle_id: selectedVehicleId,
          ...(gps || {}),
        },
        token!,
      );
      if (res?.shift?.id) {
        setAuth(token!, String(res.shift.id), res.shift);
        router.replace("/");
      }
    } catch (e: any) {
      showAlert("Lỗi", e.message || "Không thể bắt đầu ca làm việc");
    } finally {
      setLoading(false);
      hideLoading();
    }
  }

  async function handleEndShift() {
    const km = parseKmInput(endKm);
    if (!km || km <= 0) {
      Alert.alert("Thiếu", "Nhập số Km kết thúc");
      return;
    }
    if (!shift?.id) return;

    setEnding(true);
    showLoading();
    try {
      await api.shifts.endVehicle(String(shift.id), km, token!);
      const res = await api.shifts.end(token!);
      setShift(res?.shift || null);
      setShowEnd(false);
      Alert.alert("Thành công", "Đã kết thúc ca");
    } catch (e: any) {
      const msg = e.message || "Không thể kết thúc ca";
      const title = msg.includes("chưa hoàn thiện chuyến") ? "Không thể kết thúc ca" : "Lỗi";
      let targetTripId: string | number | undefined = e.active_trip_id || activeWarningTripId || undefined;
      if (!targetTripId && msg.includes("chưa hoàn thiện chuyến")) {
        try {
          const activeRes = await api.trips.active(token!);
          const data: any = activeRes?.data;
          targetTripId = Array.isArray(data) ? data[0]?.id : data?.id;
        } catch (err) {
          // Không tìm được chuyến đang chạy để điều hướng: chỉ ghi log
          console.warn("[shift] Không lấy được chuyến đang chạy:", err);
        }
      }
      showAlert(title, msg, () => {
        if (targetTripId) {
          router.push({
            pathname: "/trip-detail",
            params: { id: String(targetTripId) },
          });
        }
      });
    } finally {
      setEnding(false);
      hideLoading();
    }
  }

  const activeVehiclePlate =
    shift?.selected_vehicle?.plate_number || shift?.vehicle?.plate_number || null;

  return (
    <View style={s.screen}>
      {!showEnd ? (
        <>
          <ScrollView
            style={s.scroll}
            contentContainerStyle={s.scrollContent}
            keyboardShouldPersistTaps="handled"
            keyboardDismissMode="on-drag"
          >
            <Text style={s.title}>Bắt đầu ca làm việc</Text>
            <Text style={s.subtitle}>Chọn loại ca và xe bạn chạy (nếu có)</Text>

            {shiftOptions.map((opt) => {
              const isSelected = selectedShiftType === opt.key;
              return (
                <TouchableOpacity
                  key={opt.key}
                  style={[s.card, isSelected && s.cardSelected]}
                  onPress={() => setSelectedShiftType(opt.key)}
                  disabled={loading}
                  activeOpacity={0.75}
                >
                  <View style={s.cardRow}>
                    <View style={{ flex: 1 }}>
                      <Text style={[s.cardTitle, isSelected && s.cardTitleSelected]}>{opt.label}</Text>
                      <Text style={s.cardDesc}>{opt.desc}</Text>
                    </View>
                    <Ionicons
                      name={isSelected ? "checkmark-circle" : "ellipse-outline"}
                      size={24}
                      color={isSelected ? "#4F46E5" : "#D1D5DB"}
                    />
                  </View>
                </TouchableOpacity>
              );
            })}

            <View style={s.vehicleSection}>
              <View style={s.vehicleHeader}>
                <Text style={s.vehicleSectionTitle}>Chọn xe trong ca</Text>
                <Text style={s.vehicleOptional}>Không bắt buộc</Text>
              </View>

              {selectedVehicle && (
                <View style={s.selectedVehicleCard}>
                  <View style={s.selectedVehicleLeft}>
                    <Ionicons name="car-sport" size={18} color="#4F46E5" />
                    <Text style={s.selectedVehiclePlate}>{selectedVehicle.plate_number}</Text>
                    {selectedVehicle.id === suggestedVehicleId && (
                      <View style={s.suggestedBadge}>
                        <Text style={s.suggestedBadgeText}>Gợi ý</Text>
                      </View>
                    )}
                  </View>
                  <TouchableOpacity
                    onPress={handleClearVehicle}
                    style={s.clearSelectedBtn}
                    hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}
                  >
                    <Text style={s.clearSelectedText}>Bỏ chọn</Text>
                  </TouchableOpacity>
                </View>
              )}

              <View style={s.searchWrap}>
                <Ionicons name="search" size={18} color="#9CA3AF" />
                <TextInput
                  style={s.searchInput}
                  placeholder="Tìm biển số hoặc 4 số cuối..."
                  placeholderTextColor="#9CA3AF"
                  value={vehicleQuery}
                  onChangeText={setVehicleQuery}
                  autoCapitalize="characters"
                  returnKeyType="done"
                  onSubmitEditing={Keyboard.dismiss}
                />
                {vehicleQuery.length > 0 && (
                  <TouchableOpacity
                    onPress={() => setVehicleQuery("")}
                    hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}
                  >
                    <Ionicons name="close-circle" size={18} color="#9CA3AF" />
                  </TouchableOpacity>
                )}
              </View>

              <View style={s.vehicleListBox}>
                {loadingVehicles ? (
                  <View style={s.vehicleEmpty}>
                    <ActivityIndicator size="small" color="#4F46E5" />
                    <Text style={s.vehicleEmptyText}>Đang tải danh sách xe...</Text>
                  </View>
                ) : filteredVehicles.length === 0 ? (
                  <View style={s.vehicleEmpty}>
                    <Text style={s.vehicleEmptyText}>
                      {vehicleQuery.trim()
                        ? `Không tìm thấy xe khớp "${vehicleQuery.trim()}"`
                        : "Không có xe công ty đang hoạt động"}
                    </Text>
                  </View>
                ) : (
                  <ScrollView
                    style={s.vehicleListScroll}
                    nestedScrollEnabled
                    keyboardShouldPersistTaps="handled"
                  >
                    {filteredVehicles.map((v, idx) => {
                      const isSelected = v.id === selectedVehicleId;
                      const isSuggested = v.id === suggestedVehicleId;
                      return (
                        <TouchableOpacity
                          key={v.id}
                          style={[
                            s.vehicleRow,
                            idx > 0 && s.vehicleRowBorder,
                            isSelected && s.vehicleRowSelected,
                          ]}
                          onPress={() => handleSelectVehicle(v.id)}
                          activeOpacity={0.7}
                        >
                          <View style={{ flex: 1 }}>
                            <View style={s.vehicleRowTitleWrap}>
                              <Text style={[s.vehiclePlate, isSelected && s.vehiclePlateSelected]}>
                                {v.plate_number}
                              </Text>
                              {isSuggested && (
                                <View style={s.suggestedBadge}>
                                  <Text style={s.suggestedBadgeText}>Gợi ý</Text>
                                </View>
                              )}
                            </View>
                            <Text style={s.vehicleMeta}>
                              {v.vehicle_type_label}
                              {v.load_capacity != null ? ` · ${v.load_capacity} tấn` : ""}
                            </Text>
                          </View>
                          <Ionicons
                            name={isSelected ? "checkmark-circle" : "ellipse-outline"}
                            size={20}
                            color={isSelected ? "#4F46E5" : "#D1D5DB"}
                          />
                        </TouchableOpacity>
                      );
                    })}
                  </ScrollView>
                )}
              </View>
            </View>
          </ScrollView>

          <View style={s.footer}>
            <TouchableOpacity
              style={[s.startBtn, (!selectedShiftType || loading) && s.btnDisabled]}
              onPress={handleStartShift}
              disabled={!selectedShiftType || loading}
              activeOpacity={0.8}
            >
              <Text style={s.startBtnText}>
                {loading
                  ? "Đang lưu..."
                  : selectedVehicle
                    ? `Lưu & Bắt đầu ca (${selectedVehicle.plate_number})`
                    : "Lưu & Bắt đầu ca"}
              </Text>
            </TouchableOpacity>
          </View>
        </>
      ) : (
        <ScrollView
          style={s.scroll}
          contentContainerStyle={s.scrollContent}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="on-drag"
        >
          <Text style={s.title}>Kết thúc ca làm việc</Text>
          <Text style={s.subtitle}>Ca đang hoạt động — nhập Km đồng hồ để kết thúc</Text>

          {activeVehiclePlate && (
            <View style={s.activeVehicleInfo}>
              <Ionicons name="car-sport-outline" size={18} color="#4F46E5" />
              <Text style={s.activeVehicleInfoText}>Xe vào ca: {activeVehiclePlate}</Text>
            </View>
          )}

          {activeWarning && (
            <TouchableOpacity
              style={s.warnBox}
              activeOpacity={0.8}
              onPress={() => {
                if (activeWarningTripId) {
                  router.push({
                    pathname: "/trip-detail",
                    params: { id: String(activeWarningTripId) },
                  });
                }
              }}
            >
              <View style={s.warnHeader}>
                <Ionicons name="information-circle" size={18} color="#2563EB" />
                <Text style={s.warnTitle}>Chú ý</Text>
                <Ionicons name="chevron-forward" size={16} color="#2563EB" style={{ marginLeft: "auto" }} />
              </View>
              <Text style={s.warnText}>{activeWarning}</Text>
              <Text style={s.warnHint}>Nhấn vào đây để xem chi tiết chuyến dở dang</Text>
            </TouchableOpacity>
          )}

          <View style={s.endCard}>
            <Text style={s.endLabel}>Km đồng hồ hiện tại</Text>
            <TextInput
              style={s.endInput}
              placeholder="Nhập số Km đồng hồ"
              placeholderTextColor="#D1D5DB"
              keyboardType="number-pad"
              returnKeyType="done"
              onSubmitEditing={Keyboard.dismiss}
              value={formatKmInput(endKm)}
              onChangeText={(t) => setEndKm(t.replace(/\D/g, ""))}
            />
          </View>
          <TouchableOpacity style={[s.endBtn, ending && s.btnDisabled]} onPress={handleEndShift} disabled={ending}>
            <Text style={s.endBtnText}>{ending ? "Đang xử lý..." : "Kết thúc ca"}</Text>
          </TouchableOpacity>
        </ScrollView>
      )}
    </View>
  );
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: "#F9FAFB" },
  scroll: { flex: 1 },
  scrollContent: { padding: 20, paddingBottom: 24 },
  title: { fontSize: 22, fontWeight: "700", color: "#111827", marginBottom: 4 },
  subtitle: { fontSize: 14, color: "#6B7280", marginBottom: 16 },
  card: {
    backgroundColor: "#fff",
    padding: 16,
    borderRadius: 12,
    borderWidth: 1.5,
    borderColor: "#E5E7EB",
    marginBottom: 10,
  },
  cardSelected: {
    backgroundColor: "#EEF2FF",
    borderColor: "#4F46E5",
  },
  cardRow: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: 12 },
  cardTitle: { fontSize: 17, fontWeight: "600", color: "#111827" },
  cardTitleSelected: { color: "#4F46E5" },
  cardDesc: { fontSize: 13, color: "#6B7280", marginTop: 2 },
  vehicleSection: {
    marginTop: 10,
    backgroundColor: "#fff",
    borderRadius: 12,
    borderWidth: 1,
    borderColor: "#E5E7EB",
    padding: 14,
  },
  vehicleHeader: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    marginBottom: 10,
  },
  vehicleSectionTitle: { fontSize: 15, fontWeight: "700", color: "#111827" },
  vehicleOptional: { fontSize: 12, color: "#6B7280" },
  selectedVehicleCard: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    backgroundColor: "#EEF2FF",
    borderWidth: 1,
    borderColor: "#C7D2FE",
    borderRadius: 10,
    paddingHorizontal: 12,
    paddingVertical: 10,
    marginBottom: 10,
  },
  selectedVehicleLeft: { flexDirection: "row", alignItems: "center", gap: 8 },
  selectedVehiclePlate: { fontSize: 15, fontWeight: "700", color: "#4F46E5" },
  clearSelectedBtn: { paddingHorizontal: 8, paddingVertical: 4 },
  clearSelectedText: { fontSize: 12, fontWeight: "600", color: "#DC2626" },
  suggestedBadge: {
    backgroundColor: "#DBEAFE",
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 6,
  },
  suggestedBadgeText: { fontSize: 10, fontWeight: "700", color: "#1D4ED8" },
  searchWrap: {
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    backgroundColor: "#F9FAFB",
    borderWidth: 1,
    borderColor: "#E5E7EB",
    borderRadius: 10,
    paddingHorizontal: 12,
    height: 42,
    marginBottom: 10,
  },
  searchInput: { flex: 1, fontSize: 14, color: "#111827", paddingVertical: 0 },
  vehicleListBox: {
    borderWidth: 1,
    borderColor: "#F3F4F6",
    borderRadius: 10,
    overflow: "hidden",
    backgroundColor: "#fff",
  },
  vehicleListScroll: { maxHeight: 220 },
  vehicleRow: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    paddingHorizontal: 12,
    paddingVertical: 10,
    gap: 10,
  },
  vehicleRowBorder: { borderTopWidth: 1, borderTopColor: "#F3F4F6" },
  vehicleRowSelected: { backgroundColor: "#EEF2FF" },
  vehicleRowTitleWrap: { flexDirection: "row", alignItems: "center", gap: 8 },
  vehiclePlate: { fontSize: 15, fontWeight: "600", color: "#111827" },
  vehiclePlateSelected: { color: "#4F46E5" },
  vehicleMeta: { fontSize: 12, color: "#6B7280", marginTop: 2 },
  vehicleEmpty: { padding: 18, alignItems: "center", gap: 8 },
  vehicleEmptyText: { fontSize: 13, color: "#6B7280", textAlign: "center" },
  footer: {
    paddingHorizontal: 20,
    paddingVertical: 14,
    backgroundColor: "#fff",
    borderTopWidth: 1,
    borderTopColor: "#E5E7EB",
  },
  startBtn: {
    backgroundColor: "#4F46E5",
    paddingVertical: 15,
    borderRadius: 12,
    alignItems: "center",
  },
  startBtnText: { color: "#fff", fontSize: 16, fontWeight: "700" },
  activeVehicleInfo: {
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    backgroundColor: "#EEF2FF",
    borderWidth: 1,
    borderColor: "#C7D2FE",
    borderRadius: 10,
    paddingHorizontal: 12,
    paddingVertical: 10,
    marginBottom: 14,
  },
  activeVehicleInfoText: { fontSize: 14, fontWeight: "600", color: "#3730A3" },
  endCard: {
    backgroundColor: "#fff",
    padding: 20,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: "#E5E7EB",
    marginBottom: 16,
  },
  endLabel: { fontSize: 14, fontWeight: "600", color: "#111827", marginBottom: 10 },
  endInput: {
    backgroundColor: "#F9FAFB",
    padding: 14,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: "#E5E7EB",
    fontSize: 18,
    color: "#111827",
  },
  endBtn: { backgroundColor: "#EF4444", padding: 16, borderRadius: 12, alignItems: "center" },
  endBtnText: { color: "#fff", fontSize: 16, fontWeight: "600" },
  btnDisabled: { opacity: 0.5 },
  warnBox: {
    backgroundColor: "#EFF6FF",
    borderWidth: 1,
    borderColor: "#93C5FD",
    borderRadius: 12,
    padding: 14,
    marginBottom: 16,
  },
  warnHeader: { flexDirection: "row", alignItems: "center", gap: 8, marginBottom: 6 },
  warnTitle: { fontSize: 15, fontWeight: "700", color: "#1E40AF" },
  warnText: { fontSize: 13, color: "#1E3A8A", lineHeight: 20 },
  warnHint: { fontSize: 12, color: "#2563EB", fontWeight: "600", marginTop: 6 },
});
