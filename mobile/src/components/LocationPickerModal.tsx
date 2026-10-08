import { useState, useEffect, useCallback, useRef } from "react";
import {
    Modal,
    View,
    Text,
    TextInput,
    TouchableOpacity,
    FlatList,
    ActivityIndicator,
    StyleSheet,
    KeyboardAvoidingView,
    Platform,
    SafeAreaView,
} from "react-native";
import { Ionicons } from "@expo/vector-icons";
import { api } from "../lib/api";
import { useAuth } from "../lib/auth";

export interface LocationItem {
    id: number;
    code?: string;
    name: string;
    address?: string;
    lat?: number | string;
    lng?: number | string;
    area_id?: number;
    area?: {
        id: number;
        code?: string;
        name?: string;
    };
}

export interface LocationPickerModalProps {
    visible: boolean;
    onClose: () => void;
    onSelect: (location: LocationItem) => void;
    selectedLocationId?: number | null;
    areaId?: number | null;
    title?: string;
}

export default function LocationPickerModal({
    visible,
    onClose,
    onSelect,
    selectedLocationId,
    areaId,
    title = "Chọn điểm hạ hàng",
}: LocationPickerModalProps) {
    const { token } = useAuth();
    const [search, setSearch] = useState("");
    const [locations, setLocations] = useState<LocationItem[]>([]);
    const [loading, setLoading] = useState(false);
    const searchTimeout = useRef<any>(null);

    const fetchLocations = useCallback(
        async (query: string) => {
            if (!token) return;
            setLoading(true);
            try {
                // Thử tìm theo area_id nếu có
                let res = await api.locations(
                    {
                        search: query.trim() || undefined,
                        area_id: areaId ?? undefined,
                        limit: 30,
                    },
                    token,
                );
                let items: LocationItem[] = res?.data || [];

                // Nếu không có kết quả và có lọc area_id, tìm rộng ra toàn bộ hệ thống
                if (items.length === 0 && areaId) {
                    res = await api.locations(
                        {
                            search: query.trim() || undefined,
                            limit: 30,
                        },
                        token,
                    );
                    items = res?.data || [];
                }

                setLocations(items);
            } catch (e) {
                console.error("fetchLocations error:", e);
            } finally {
                setLoading(false);
            }
        },
        [token, areaId],
    );

    useEffect(() => {
        if (visible) {
            setSearch("");
            fetchLocations("");
        }
    }, [visible, fetchLocations]);

    const handleSearchChange = (text: string) => {
        setSearch(text);
        if (searchTimeout.current) {
            clearTimeout(searchTimeout.current);
        }
        searchTimeout.current = setTimeout(() => {
            fetchLocations(text);
        }, 300);
    };

    const handleClearSearch = () => {
        setSearch("");
        fetchLocations("");
    };

    return (
        <Modal
            visible={visible}
            animationType="slide"
            presentationStyle={Platform.OS === "ios" ? "pageSheet" : "fullScreen"}
            onRequestClose={onClose}
        >
            <SafeAreaView style={styles.safeArea}>
                <KeyboardAvoidingView
                    behavior={Platform.OS === "ios" ? "padding" : undefined}
                    style={styles.container}
                >
                    {/* Header */}
                    <View style={styles.header}>
                        <View style={{ flex: 1 }}>
                            <Text style={styles.title}>{title}</Text>
                            <Text style={styles.subtitle}>
                                Chọn điểm giao hàng / điểm đến của đơn hàng
                            </Text>
                        </View>
                        <TouchableOpacity
                            onPress={onClose}
                            style={styles.closeBtn}
                            hitSlop={12}
                        >
                            <Ionicons name="close" size={24} color="#1E293B" />
                        </TouchableOpacity>
                    </View>

                    {/* Search Bar */}
                    <View style={styles.searchBar}>
                        <Ionicons name="search" size={18} color="#94A3B8" />
                        <TextInput
                            style={styles.searchInput}
                            placeholder="Tìm theo mã, tên hoặc địa chỉ..."
                            placeholderTextColor="#94A3B8"
                            value={search}
                            onChangeText={handleSearchChange}
                            autoCorrect={false}
                            clearButtonMode="never"
                        />
                        {loading ? (
                            <ActivityIndicator size="small" color="#4F46E5" />
                        ) : search.length > 0 ? (
                            <TouchableOpacity onPress={handleClearSearch} hitSlop={8}>
                                <Ionicons
                                    name="close-circle"
                                    size={18}
                                    color="#94A3B8"
                                />
                            </TouchableOpacity>
                        ) : null}
                    </View>

                    {/* Results List */}
                    <FlatList
                        data={locations}
                        keyExtractor={(item) => String(item.id)}
                        keyboardShouldPersistTaps="handled"
                        contentContainerStyle={styles.listContent}
                        renderItem={({ item }) => {
                            const isSelected = selectedLocationId === item.id;
                            return (
                                <TouchableOpacity
                                    style={[
                                        styles.itemCard,
                                        isSelected && styles.itemCardSelected,
                                    ]}
                                    onPress={() => {
                                        onSelect(item);
                                        onClose();
                                    }}
                                    activeOpacity={0.7}
                                >
                                    <View
                                        style={[
                                            styles.iconWrap,
                                            isSelected && styles.iconWrapSelected,
                                        ]}
                                    >
                                        <Ionicons
                                            name="location"
                                            size={20}
                                            color={isSelected ? "#4F46E5" : "#64748B"}
                                        />
                                    </View>
                                    <View style={styles.itemInfo}>
                                        <View style={styles.itemHeaderRow}>
                                            {item.code ? (
                                                <View style={styles.codeBadge}>
                                                    <Text style={styles.codeBadgeText}>
                                                        {item.code}
                                                    </Text>
                                                </View>
                                            ) : null}
                                            <Text
                                                style={[
                                                    styles.itemName,
                                                    isSelected && styles.itemNameSelected,
                                                ]}
                                                numberOfLines={1}
                                            >
                                                {item.name}
                                            </Text>
                                        </View>
                                        <Text
                                            style={styles.itemAddress}
                                            numberOfLines={2}
                                        >
                                            {item.address || item.code || "Chưa có địa chỉ chi tiết"}
                                        </Text>
                                    </View>
                                    <View style={styles.radioWrap}>
                                        <Ionicons
                                            name={
                                                isSelected
                                                    ? "checkmark-circle"
                                                    : "chevron-forward"
                                            }
                                            size={20}
                                            color={isSelected ? "#4F46E5" : "#CBD5E1"}
                                        />
                                    </View>
                                </TouchableOpacity>
                            );
                        }}
                        ListEmptyComponent={
                            !loading ? (
                                <View style={styles.emptyContainer}>
                                    <Ionicons
                                        name="location-outline"
                                        size={48}
                                        color="#CBD5E1"
                                    />
                                    <Text style={styles.emptyTitle}>
                                        Không tìm thấy điểm đến
                                    </Text>
                                    <Text style={styles.emptySubtitle}>
                                        Thử nhập từ khóa khác hoặc tên khu công nghiệp
                                    </Text>
                                </View>
                            ) : null
                        }
                    />
                </KeyboardAvoidingView>
            </SafeAreaView>
        </Modal>
    );
}

const styles = StyleSheet.create({
    safeArea: {
        flex: 1,
        backgroundColor: "#F8FAFC",
    },
    container: {
        flex: 1,
        backgroundColor: "#F8FAFC",
    },
    header: {
        flexDirection: "row",
        alignItems: "center",
        justifyContent: "space-between",
        paddingHorizontal: 16,
        paddingTop: 16,
        paddingBottom: 12,
        backgroundColor: "#FFFFFF",
        borderBottomWidth: 1,
        borderBottomColor: "#E2E8F0",
    },
    title: {
        fontSize: 18,
        fontWeight: "800",
        color: "#0F172A",
    },
    subtitle: {
        fontSize: 12,
        color: "#64748B",
        marginTop: 2,
    },
    closeBtn: {
        padding: 6,
        borderRadius: 8,
        backgroundColor: "#F1F5F9",
    },
    searchBar: {
        flexDirection: "row",
        alignItems: "center",
        gap: 8,
        margin: 12,
        paddingHorizontal: 12,
        paddingVertical: 10,
        backgroundColor: "#FFFFFF",
        borderRadius: 12,
        borderWidth: 1,
        borderColor: "#E2E8F0",
    },
    searchInput: {
        flex: 1,
        fontSize: 14,
        color: "#0F172A",
        padding: 0,
    },
    listContent: {
        paddingHorizontal: 12,
        paddingBottom: 24,
        gap: 8,
    },
    itemCard: {
        flexDirection: "row",
        alignItems: "center",
        padding: 12,
        backgroundColor: "#FFFFFF",
        borderRadius: 12,
        borderWidth: 1,
        borderColor: "#E2E8F0",
        gap: 12,
    },
    itemCardSelected: {
        borderColor: "#4F46E5",
        backgroundColor: "#EEF2FF",
    },
    iconWrap: {
        width: 36,
        height: 36,
        borderRadius: 10,
        backgroundColor: "#F1F5F9",
        alignItems: "center",
        justifyContent: "center",
    },
    iconWrapSelected: {
        backgroundColor: "#E0E7FF",
    },
    itemInfo: {
        flex: 1,
        gap: 2,
    },
    itemHeaderRow: {
        flexDirection: "row",
        alignItems: "center",
        gap: 6,
    },
    codeBadge: {
        backgroundColor: "#EEF2FF",
        paddingHorizontal: 6,
        paddingVertical: 2,
        borderRadius: 6,
    },
    codeBadgeText: {
        fontSize: 11,
        fontWeight: "700",
        color: "#4F46E5",
    },
    itemName: {
        fontSize: 14,
        fontWeight: "700",
        color: "#1E293B",
        flex: 1,
    },
    itemNameSelected: {
        color: "#4F46E5",
    },
    itemAddress: {
        fontSize: 12,
        color: "#64748B",
        lineHeight: 16,
    },
    radioWrap: {
        paddingLeft: 4,
    },
    emptyContainer: {
        alignItems: "center",
        justifyContent: "center",
        paddingVertical: 48,
        gap: 8,
    },
    emptyTitle: {
        fontSize: 15,
        fontWeight: "700",
        color: "#475569",
    },
    emptySubtitle: {
        fontSize: 13,
        color: "#94A3B8",
        textAlign: "center",
        paddingHorizontal: 32,
    },
});
