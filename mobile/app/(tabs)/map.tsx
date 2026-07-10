import { useState, useCallback } from "react";
import { View, Text, StyleSheet, ActivityIndicator, TouchableOpacity, Platform } from "react-native";
import MapView, { Marker, Polyline } from "react-native-maps";
import { useAuth } from "../../src/lib/auth";
import { api } from "../../src/lib/api";
import { useFocusEffect } from "expo-router";
import { Ionicons } from "@expo/vector-icons";

const API_BASE = Platform.select({
  ios: "http://localhost:8000/api/driver",
  android: "http://10.0.2.2:8000/api/driver",
  default: "/api/driver",
});

const HANOI_CENTER = { latitude: 21.0285, longitude: 105.8542 };

export default function MapScreen() {
  const { token } = useAuth();
  const [trip, setTrip] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [routes, setRoutes] = useState<{ points: { latitude: number; longitude: number }[]; color: string }[]>([]);

  const load = async () => {
    if (!token) return;
    try {
      const [tripRes] = await Promise.all([
        api.trips.active(token).catch(() => ({ data: [] })),
      ]);
      const trips = tripRes.data || [];
      // Lấy trip đầu tiên đang chạy (không pending)
      const activeTrip = trips.find((t: any) => t.status !== "pending" && t.status !== "completed" && t.status !== "cancelled" && t.status !== "driver_swap");
      setTrip(activeTrip || null);

      // Fetch route nếu có trip
      if (activeTrip?.orders?.length > 0) {
        await fetchRoutes(activeTrip);
      }
    } finally { setLoading(false); }
  };

  const fetchRoutes = async (trip: any) => {
    const orders = trip.orders || [];
    const allRoutes: { points: { latitude: number; longitude: number }[]; color: string }[] = [];
    const colors = ["#4F46E5", "#059669", "#F59E0B", "#EF4444", "#8B5CF6"];

    for (let i = 0; i < orders.length; i++) {
      const o = orders[i];
      const pickup = o.pickup_location;
      const dp = o.delivery_points?.[0];

      if (pickup?.lat && pickup?.lng && dp?.location?.lat && dp?.location?.lng) {
        try {
          const body: any = {
            origin_lat: pickup.lat,
            origin_lng: pickup.lng,
            destination_lat: dp.location.lat,
            destination_lng: dp.location.lng,
          };
          // Add intermediate delivery points as waypoints
          if (o.delivery_points?.length > 1) {
            body.waypoints = o.delivery_points.slice(0, -1).map((d: any) => ({
              lat: d.location?.lat,
              lng: d.location?.lng,
            })).filter((w: any) => w.lat && w.lng);
          }

          const res = await fetch(`${API_BASE}/route`, {
            method: "POST",
            headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
            body: JSON.stringify(body),
          });
          const json = await res.json();
          if (json.success && json.data?.geometry?.coordinates) {
            // GeoJSON → [{latitude, longitude}]
            const coords = json.data.geometry.coordinates.map((c: [number, number]) => ({
              latitude: c[1],
              longitude: c[0],
            }));
            allRoutes.push({ points: coords, color: colors[i % colors.length] });
          }
        } catch {}
      }
    }
    setRoutes(allRoutes);
  };

  useFocusEffect(useCallback(() => { load(); }, [token]));

  if (loading) {
    return <View style={s.center}><ActivityIndicator size="large" color="#4F46E5" /></View>;
  }

  if (!trip) {
    return (
      <View style={s.center}>
        <Ionicons name="map-outline" size={56} color="#D1D5DB" />
        <Text style={s.emptyText}>Chưa có chuyến đang chạy</Text>
      </View>
    );
  }

  const orders = trip.orders || [];
  const vehicleLat = trip.vehicle?.last_gps_lat || null;
  const vehicleLng = trip.vehicle?.last_gps_lng || null;

  // Tính region từ tất cả điểm
  const allPoints = orders.flatMap((o: any) => {
    const pts: { latitude: number; longitude: number }[] = [];
    if (o.pickup_location?.lat) pts.push({ latitude: o.pickup_location.lat, longitude: o.pickup_location.lng });
    (o.delivery_points || []).forEach((dp: any) => {
      if (dp.location?.lat) pts.push({ latitude: dp.location.lat, longitude: dp.location.lng });
    });
    return pts;
  });
  if (vehicleLat && vehicleLng) {
    allPoints.push({ latitude: vehicleLat, longitude: vehicleLng });
  }
  const region = allPoints.length > 0
    ? { latitude: allPoints[0].latitude, longitude: allPoints[0].longitude, latitudeDelta: 0.05, longitudeDelta: 0.05 }
    : { ...HANOI_CENTER, latitudeDelta: 0.05, longitudeDelta: 0.05 };

  return (
    <View style={s.container}>
      <MapView style={s.map} initialRegion={region} showsUserLocation={false}>
        {/* Vehicle position */}
        {vehicleLat && vehicleLng && (
          <Marker coordinate={{ latitude: vehicleLat, longitude: vehicleLng }} title="Vị trí xe" pinColor="#4F46E5">
            <View style={s.vehicleMarker}>
              <Ionicons name="car" size={18} color="#fff" />
            </View>
          </Marker>
        )}

        {/* Pickup & delivery markers */}
        {orders.map((o: any) => (
          <View key={o.id}>
            {o.pickup_location?.lat && (
              <Marker
                coordinate={{ latitude: o.pickup_location.lat, longitude: o.pickup_location.lng }}
                title={o.pickup_location.code || "Điểm lấy"}
                description={o.order_code}
                pinColor="#10B981"
              />
            )}
            {(o.delivery_points || []).map((dp: any, di: number) =>
              dp.location?.lat ? (
                <Marker
                  key={dp.id || di}
                  coordinate={{ latitude: dp.location.lat, longitude: dp.location.lng }}
                  title={`${dp.location.code || `Giao ${di + 1}`} — ${o.order_code}`}
                  description={dp.address || dp.location.name}
                  pinColor="#EF4444"
                />
              ) : null
            )}
          </View>
        ))}

        {/* Route polylines */}
        {routes.map((r, i) => (
          <Polyline key={i} coordinates={r.points} strokeColor={r.color} strokeWidth={3} />
        ))}
      </MapView>

      {/* Trip info bar */}
      <View style={s.infoBar}>
        <View style={{ flex: 1 }}>
          <Text style={s.infoPlate}>{trip.vehicle?.plate_number || "Chưa gán xe"}</Text>
          <Text style={s.infoText}>{orders.length} đơn hàng</Text>
        </View>
        <TouchableOpacity style={s.refreshBtn} onPress={load}>
          <Ionicons name="refresh" size={20} color="#4F46E5" />
        </TouchableOpacity>
      </View>
    </View>
  );
}

const s = StyleSheet.create({
  container: { flex: 1 },
  map: { flex: 1 },
  center: { flex: 1, alignItems: "center", justifyContent: "center", backgroundColor: "#F9FAFB", gap: 12 },
  emptyText: { color: "#9CA3AF", fontSize: 14 },
  vehicleMarker: { width: 34, height: 34, borderRadius: 17, backgroundColor: "#4F46E5", alignItems: "center", justifyContent: "center", borderWidth: 2, borderColor: "#fff" },
  infoBar: { flexDirection: "row", alignItems: "center", backgroundColor: "#fff", paddingHorizontal: 16, paddingVertical: 12, borderTopWidth: 1, borderTopColor: "#F3F4F6", gap: 12 },
  infoPlate: { fontSize: 15, fontWeight: "700", color: "#111827" },
  infoText: { fontSize: 13, color: "#6B7280", marginTop: 2 },
  refreshBtn: { padding: 8, backgroundColor: "#EEF2FF", borderRadius: 8 },
});
