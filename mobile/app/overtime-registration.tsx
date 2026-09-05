import React, { useState, useEffect, useCallback } from "react";
import {
  View,
  Text,
  ScrollView,
  TouchableOpacity,
  TextInput,
  StyleSheet,
  RefreshControl,
  ActivityIndicator,
  Alert,
  Platform,
} from "react-native";
import { useAuth } from "../src/lib/auth";
import { useLoading } from "../src/lib/loading";
import { api, OvertimeRegistrationResource } from "../src/lib/api";
import { Ionicons } from "@expo/vector-icons";

interface DayOption {
  dateString: string; // YYYY-MM-DD
  dayOfWeek: string;
  dayOfMonth: string;
  month: string;
  isToday: boolean;
}

const shiftOptions = [
  {
    key: "full",
    label: "Cả ca (X)",
    time: "24 Giờ",
    description: "Ca làm việc cả ngày",
    icon: "sunny-outline" as const,
  },
  {
    key: "morning_half",
    label: "Nửa ca ngày (X/2)",
    time: "06:00 - 18:00",
    description: "Ca ban ngày (12 tiếng)",
    icon: "partly-sunny-outline" as const,
  },
  {
    key: "night_half",
    label: "Nửa ca đêm (Y/2)",
    time: "18:00 - 06:00",
    description: "Ca ban đêm (12 tiếng)",
    icon: "moon-outline" as const,
  },
];

function notify(title: string, message: string) {
  if (Platform.OS === "web") {
    window.alert(`${title}: ${message}`);
  } else {
    Alert.alert(title, message);
  }
}

export default function OvertimeRegistrationScreen() {
  const { token } = useAuth();
  const { showLoading, hideLoading } = useLoading();

  const [days, setDays] = useState<DayOption[]>([]);
  const [selectedDate, setSelectedDate] = useState<string>("");
  const [selectedShift, setSelectedShift] = useState<string>("full");
  const [notes, setNotes] = useState<string>("");
  const [registrations, setRegistrations] = useState<OvertimeRegistrationResource[]>([]);
  const [loadingList, setLoadingList] = useState<boolean>(false);
  const [submitting, setSubmitting] = useState<boolean>(false);
  const [refreshing, setRefreshing] = useState<boolean>(false);

  // Tạo danh sách 14 ngày tới
  useEffect(() => {
    const list: DayOption[] = [];
    const dayNames = ["CN", "T2", "T3", "T4", "T5", "T6", "T7"];
    const today = new Date();

    for (let i = 0; i < 14; i++) {
      const d = new Date(today);
      d.setDate(today.getDate() + i);

      const yyyy = d.getFullYear();
      const mm = String(d.getMonth() + 1).padStart(2, "0");
      const dd = String(d.getDate()).padStart(2, "0");
      const dateString = `${yyyy}-${mm}-${dd}`;

      list.push({
        dateString,
        dayOfWeek: i === 0 ? "Hôm nay" : i === 1 ? "Ngày mai" : dayNames[d.getDay()],
        dayOfMonth: dd,
        month: `Th${d.getMonth() + 1}`,
        isToday: i === 0,
      });
    }

    setDays(list);
    if (list.length > 1) {
      setSelectedDate(list[1].dateString); // Mặc định chọn ngày mai
    } else if (list.length > 0) {
      setSelectedDate(list[0].dateString);
    }
  }, []);

  const loadRegistrations = useCallback(async () => {
    if (!token) return;
    try {
      setLoadingList(true);
      const res = await api.overtime.list({}, token);
      setRegistrations(res.data || []);
    } catch (err: any) {
      console.log("Error fetching overtime list:", err);
    } finally {
      setLoadingList(false);
      setRefreshing(false);
    }
  }, [token]);

  useEffect(() => {
    loadRegistrations();
  }, [loadRegistrations]);

  const onRefresh = () => {
    setRefreshing(true);
    loadRegistrations();
  };

  const handleSubmit = async () => {
    if (!token) {
      notify("Lỗi", "Vui lòng đăng nhập lại.");
      return;
    }

    if (!selectedDate) {
      notify("Thông báo", "Vui lòng chọn ngày tăng cường.");
      return;
    }

    if (!selectedShift) {
      notify("Thông báo", "Vui lòng chọn loại ca.");
      return;
    }

    try {
      setSubmitting(true);
      showLoading();
      const res = await api.overtime.register(
        {
          shift_type: selectedShift,
          overtime_date: selectedDate,
          notes: notes.trim() || undefined,
        },
        token
      );

      notify("Thành công", res.message || "Đăng ký tăng cường thành công.");
      setNotes("");
      loadRegistrations();
    } catch (err: any) {
      notify("Lỗi đăng ký", err.message || "Không thể gửi đăng ký tăng cường.");
    } finally {
      setSubmitting(false);
      hideLoading();
    }
  };

  const handleCancel = (item: OvertimeRegistrationResource) => {
    if (!token) return;

    const doCancel = async () => {
      try {
        showLoading();
        const res = await api.overtime.cancel(item.id, token);
        notify("Thành công", res.message || "Đã huỷ đăng ký tăng cường.");
        loadRegistrations();
      } catch (err: any) {
        notify("Lỗi", err.message || "Không thể huỷ đăng ký.");
      } finally {
        hideLoading();
      }
    };

    if (Platform.OS === "web") {
      if (window.confirm("Bạn có chắc chắn muốn huỷ yêu cầu đăng ký tăng cường này không?")) {
        doCancel();
      }
    } else {
      Alert.alert(
        "Huỷ đăng ký",
        `Bạn có chắc chắn muốn huỷ đăng ký ngày ${item.overtime_date}?`,
        [
          { text: "Không", style: "cancel" },
          { text: "Huỷ đăng ký", style: "destructive", onPress: doCancel },
        ]
      );
    }
  };

  const getStatusBadge = (status: string, label: string) => {
    let bg = "#FEF3C7";
    let text = "#D97706";
    let icon = "time-outline";

    if (status === "confirmed") {
      bg = "#D1FAE5";
      text = "#059669";
      icon = "checkmark-circle-outline";
    } else if (status === "rejected") {
      bg = "#FEE2E2";
      text = "#DC2626";
      icon = "close-circle-outline";
    }

    return (
      <View style={[s.badge, { backgroundColor: bg }]}>
        <Ionicons name={icon as any} size={13} color={text} style={{ marginRight: 4 }} />
        <Text style={[s.badgeText, { color: text }]}>{label}</Text>
      </View>
    );
  };

  return (
    <ScrollView
      style={s.container}
      contentContainerStyle={s.contentContainer}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />}
      keyboardShouldPersistTaps="handled"
    >
      {/* 1. Chọn ngày tăng cường */}
      <View style={s.section}>
        <View style={s.sectionHeader}>
          <Ionicons name="calendar" size={18} color="#4F46E5" />
          <Text style={s.sectionTitle}>1. Chọn ngày tăng cường</Text>
        </View>

        <ScrollView
          horizontal
          showsHorizontalScrollIndicator={false}
          contentContainerStyle={s.daysRow}
        >
          {days.map((item) => {
            const isSelected = item.dateString === selectedDate;
            return (
              <TouchableOpacity
                key={item.dateString}
                style={[s.dayCard, isSelected && s.dayCardActive]}
                onPress={() => setSelectedDate(item.dateString)}
                activeOpacity={0.7}
              >
                <Text style={[s.dayOfWeek, isSelected && s.dayOfWeekActive]}>
                  {item.dayOfWeek}
                </Text>
                <Text style={[s.dayOfMonth, isSelected && s.dayOfMonthActive]}>
                  {item.dayOfMonth}
                </Text>
                <Text style={[s.monthText, isSelected && s.monthTextActive]}>
                  {item.month}
                </Text>
              </TouchableOpacity>
            );
          })}
        </ScrollView>
      </View>

      {/* 2. Chọn loại ca */}
      <View style={s.section}>
        <View style={s.sectionHeader}>
          <Ionicons name="briefcase" size={18} color="#4F46E5" />
          <Text style={s.sectionTitle}>2. Chọn ca tăng cường</Text>
        </View>

        <View style={s.shiftsContainer}>
          {shiftOptions.map((opt) => {
            const isSelected = selectedShift === opt.key;
            return (
              <TouchableOpacity
                key={opt.key}
                style={[s.shiftCard, isSelected && s.shiftCardActive]}
                onPress={() => setSelectedShift(opt.key)}
                activeOpacity={0.7}
              >
                <View style={s.shiftIconBox}>
                  <Ionicons
                    name={opt.icon}
                    size={22}
                    color={isSelected ? "#4F46E5" : "#6B7280"}
                  />
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={[s.shiftLabel, isSelected && s.shiftLabelActive]}>
                    {opt.label}
                  </Text>
                  <Text style={s.shiftDesc}>{opt.description}</Text>
                </View>
                <View style={[s.timeBadge, isSelected && s.timeBadgeActive]}>
                  <Text style={[s.timeBadgeText, isSelected && s.timeBadgeTextActive]}>
                    {opt.time}
                  </Text>
                </View>
              </TouchableOpacity>
            );
          })}
        </View>
      </View>

      {/* 3. Ghi chú */}
      <View style={s.section}>
        <View style={s.sectionHeader}>
          <Ionicons name="chatbox-ellipses" size={18} color="#4F46E5" />
          <Text style={s.sectionTitle}>3. Ghi chú (Tuỳ chọn)</Text>
        </View>

        <TextInput
          style={s.noteInput}
          placeholder="Ví dụ: Xin chạy tuyến Nội Bài, hoặc lưu ý riêng..."
          placeholderTextColor="#9CA3AF"
          value={notes}
          onChangeText={setNotes}
          multiline
          numberOfLines={3}
        />
      </View>

      {/* Nút gửi */}
      <TouchableOpacity
        style={[s.submitBtn, submitting && s.submitBtnDisabled]}
        onPress={handleSubmit}
        disabled={submitting}
        activeOpacity={0.8}
      >
        {submitting ? (
          <ActivityIndicator color="#fff" />
        ) : (
          <>
            <Ionicons name="send" size={18} color="#fff" style={{ marginRight: 8 }} />
            <Text style={s.submitBtnText}>Gửi đăng ký tăng cường</Text>
          </>
        )}
      </TouchableOpacity>

      {/* 4. Lịch sử đăng ký của tôi */}
      <View style={[s.section, { marginTop: 28 }]}>
        <View style={s.sectionHeader}>
          <Ionicons name="time" size={18} color="#4F46E5" />
          <Text style={s.sectionTitle}>Lịch sử đăng ký tăng cường</Text>
        </View>

        {loadingList && !refreshing ? (
          <ActivityIndicator size="small" color="#4F46E5" style={{ marginVertical: 20 }} />
        ) : registrations.length === 0 ? (
          <View style={s.emptyBox}>
            <Ionicons name="calendar-clear-outline" size={40} color="#D1D5DB" />
            <Text style={s.emptyText}>Chưa có lịch đăng ký tăng cường nào</Text>
          </View>
        ) : (
          <View style={s.regList}>
            {registrations.map((item) => (
              <View key={item.id} style={s.regCard}>
                <View style={s.regHeader}>
                  <View style={s.regDateBox}>
                    <Text style={s.regDateText}>{item.overtime_date}</Text>
                    <Text style={s.regShiftText}>{item.shift_type_label}</Text>
                  </View>
                  {getStatusBadge(item.status, item.status_label)}
                </View>

                {item.notes ? (
                  <Text style={s.regNotes} numberOfLines={2}>
                    💬 {item.notes}
                  </Text>
                ) : null}

                <View style={s.regFooter}>
                  <Text style={s.regTimeText}>Gửi lúc: {item.created_at}</Text>
                  {item.status === "pending" && (
                    <TouchableOpacity
                      style={s.cancelBtn}
                      onPress={() => handleCancel(item)}
                      activeOpacity={0.7}
                    >
                      <Ionicons name="trash-outline" size={14} color="#EF4444" />
                      <Text style={s.cancelBtnText}>Huỷ</Text>
                    </TouchableOpacity>
                  )}
                </View>
              </View>
            ))}
          </View>
        )}
      </View>
    </ScrollView>
  );
}

const s = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: "#F9FAFB",
  },
  contentContainer: {
    padding: 16,
    paddingBottom: 40,
  },
  section: {
    marginBottom: 20,
  },
  sectionHeader: {
    flexDirection: "row",
    alignItems: "center",
    marginBottom: 12,
    gap: 8,
  },
  sectionTitle: {
    fontSize: 16,
    fontWeight: "700",
    color: "#1F2937",
  },
  daysRow: {
    gap: 10,
    paddingVertical: 4,
  },
  dayCard: {
    width: 68,
    paddingVertical: 12,
    paddingHorizontal: 6,
    borderRadius: 14,
    backgroundColor: "#fff",
    borderWidth: 1.5,
    borderColor: "#E5E7EB",
    alignItems: "center",
  },
  dayCardActive: {
    backgroundColor: "#4F46E5",
    borderColor: "#4F46E5",
  },
  dayOfWeek: {
    fontSize: 11,
    fontWeight: "600",
    color: "#6B7280",
    marginBottom: 4,
  },
  dayOfWeekActive: {
    color: "#E0E7FF",
  },
  dayOfMonth: {
    fontSize: 20,
    fontWeight: "800",
    color: "#111827",
    marginBottom: 2,
  },
  dayOfMonthActive: {
    color: "#fff",
  },
  monthText: {
    fontSize: 11,
    color: "#9CA3AF",
  },
  monthTextActive: {
    color: "#C7D2FE",
  },
  shiftsContainer: {
    gap: 10,
  },
  shiftCard: {
    flexDirection: "row",
    alignItems: "center",
    backgroundColor: "#fff",
    borderRadius: 14,
    borderWidth: 1.5,
    borderColor: "#E5E7EB",
    padding: 14,
    gap: 12,
  },
  shiftCardActive: {
    borderColor: "#4F46E5",
    backgroundColor: "#F5F3FF",
  },
  shiftIconBox: {
    width: 42,
    height: 42,
    borderRadius: 10,
    backgroundColor: "#F3F4F6",
    alignItems: "center",
    justifyContent: "center",
  },
  shiftLabel: {
    fontSize: 15,
    fontWeight: "700",
    color: "#1F2937",
    marginBottom: 2,
  },
  shiftLabelActive: {
    color: "#4F46E5",
  },
  shiftDesc: {
    fontSize: 12,
    color: "#6B7280",
  },
  timeBadge: {
    backgroundColor: "#F3F4F6",
    paddingHorizontal: 8,
    paddingVertical: 4,
    borderRadius: 8,
  },
  timeBadgeActive: {
    backgroundColor: "#E0E7FF",
  },
  timeBadgeText: {
    fontSize: 11,
    fontWeight: "600",
    color: "#4B5563",
  },
  timeBadgeTextActive: {
    color: "#4338CA",
  },
  noteInput: {
    backgroundColor: "#fff",
    borderRadius: 12,
    borderWidth: 1,
    borderColor: "#D1D5DB",
    padding: 12,
    fontSize: 14,
    color: "#111827",
    textAlignVertical: "top",
    minHeight: 72,
  },
  submitBtn: {
    flexDirection: "row",
    backgroundColor: "#4F46E5",
    borderRadius: 14,
    padding: 16,
    alignItems: "center",
    justifyContent: "center",
    marginTop: 6,
    shadowColor: "#4F46E5",
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.25,
    shadowRadius: 8,
    elevation: 4,
  },
  submitBtnDisabled: {
    opacity: 0.6,
  },
  submitBtnText: {
    color: "#fff",
    fontSize: 16,
    fontWeight: "700",
  },
  emptyBox: {
    backgroundColor: "#fff",
    borderRadius: 14,
    borderWidth: 1,
    borderColor: "#E5E7EB",
    padding: 32,
    alignItems: "center",
    justifyContent: "center",
    gap: 8,
  },
  emptyText: {
    fontSize: 14,
    color: "#9CA3AF",
  },
  regList: {
    gap: 12,
  },
  regCard: {
    backgroundColor: "#fff",
    borderRadius: 14,
    borderWidth: 1,
    borderColor: "#E5E7EB",
    padding: 14,
  },
  regHeader: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "flex-start",
    marginBottom: 8,
  },
  regDateBox: {
    gap: 2,
  },
  regDateText: {
    fontSize: 16,
    fontWeight: "700",
    color: "#111827",
  },
  regShiftText: {
    fontSize: 13,
    color: "#4F46E5",
    fontWeight: "600",
  },
  badge: {
    flexDirection: "row",
    alignItems: "center",
    paddingHorizontal: 8,
    paddingVertical: 4,
    borderRadius: 8,
  },
  badgeText: {
    fontSize: 12,
    fontWeight: "700",
  },
  regNotes: {
    fontSize: 13,
    color: "#4B5563",
    backgroundColor: "#F9FAFB",
    padding: 8,
    borderRadius: 8,
    marginBottom: 8,
  },
  regFooter: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    marginTop: 4,
    borderTopWidth: 1,
    borderTopColor: "#F3F4F6",
    paddingTop: 8,
  },
  regTimeText: {
    fontSize: 11,
    color: "#9CA3AF",
  },
  cancelBtn: {
    flexDirection: "row",
    alignItems: "center",
    gap: 4,
    paddingHorizontal: 8,
    paddingVertical: 4,
    borderRadius: 6,
    backgroundColor: "#FEF2F2",
  },
  cancelBtnText: {
    fontSize: 12,
    fontWeight: "600",
    color: "#EF4444",
  },
});
