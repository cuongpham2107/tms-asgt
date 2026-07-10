import { useState, useCallback, useRef, useEffect } from "react";
import { View, Text, StyleSheet, ActivityIndicator, TouchableOpacity, Platform } from "react-native";
import MapView, { Marker, Polyline, Callout } from "react-native-maps";
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
  const [loadingRoutes, setLoadingRoutes] = useState(false);
  const [routes, setRoutes] = useState<{ points: { latitude: number; longitude: number }[]; color: string }[]>([]);
  const [region, setRegion] = useState({ ...HANOI_CENTER, latitudeDelta: 0.05, longitudeDelta: 0.05 });
  const mapRef = useRef<MapView>(null);

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
    setLoadingRoutes(true);
    const orders = trip.orders || [];
    const allRoutes: { points: { latitude: number; longitude: number }[]; color: string }[] = [];
    const colors = ["#4F46E5", "#059669", "#F59E0B", "#EF4444", "#8B5CF6"];

    for (let i = 0; i < orders.length; i++) {
      const o = orders[i];
      const pickup = o.pickup_location;
      const dp = o.delivery_points?.[0];

      if (!pickup?.lat || !pickup?.lng) {
        console.warn(`[map] pickup location missing lat/lng for order ${o.order_code}`);
        continue;
      }
      if (!dp?.location?.lat || !dp?.location?.lng) {
        console.warn(`[map] delivery location missing lat/lng for order ${o.order_code}`, dp);
        continue;
      }

      try {
        const body: any = {
          origin_lat: pickup.lat,
          origin_lng: pickup.lng,
          destination_lat: dp.location.lat,
          destination_lng: dp.location.lng,
        };
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
        console.log(`[map] route for ${o.order_code}:`, JSON.stringify({ success: json.success, distance: json.data?.distance, hasCoords: !!json.data?.geometry?.coordinates }));
        if (json.success && json.data?.geometry?.coordinates) {
          const coords = json.data.geometry.coordinates.map((c: [number, number]) => ({
            latitude: c[1],
            longitude: c[0],
          }));
          allRoutes.push({ points: coords, color: colors[i % colors.length] });
        }
      } catch (err) {
        console.warn(`[map] route fetch error for ${o.order_code}:`, err);
      }
    }
    setRoutes(allRoutes);
    setLoadingRoutes(false);
  };

  useFocusEffect(useCallback(() => { load(); }, [token]));

  const orders = trip?.orders || [];
  const vehicleLat = trip?.vehicle?.last_gps_lat || null;
  const vehicleLng = trip?.vehicle?.last_gps_lng || null;

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

  // Auto-zoom to fit all points when data is loaded (must be BEFORE conditional returns)
  useEffect(() => {
    if (allPoints.length > 0 && mapRef.current) {
      const coords = allPoints.map((p: { latitude: number; longitude: number }) => ({ latitude: p.latitude, longitude: p.longitude }));
      mapRef.current.fitToCoordinates(coords, {
        edgePadding: { top: 100, right: 100, bottom: 100, left: 100 },
        animated: true,
      });
    }
  }, [allPoints.length, routes.length]);

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

  return (
    <View style={s.container}>
      <MapView ref={mapRef} style={s.map} region={region} onRegionChangeComplete={setRegion} showsUserLocation={true} showsCompass={false} showsScale={true}>
        {/* Vehicle position */}
        {vehicleLat && vehicleLng && (
          <Marker coordinate={{ latitude: vehicleLat, longitude: vehicleLng }} title="Vị trí xe" pinColor="#4F46E5">
            <View style={s.vehicleMarker}>
              <Ionicons name="car" size={18} color="#fff" />
            </View>
            <Callout>
              <View style={{ width: 160 }}>
                <Text style={{ fontWeight: "700", fontSize: 14, color: "#111827" }}>{trip.vehicle?.plate_number}</Text>
                <Text style={{ fontSize: 12, color: "#6B7280", marginTop: 2 }}>Vị trí hiện tại</Text>
              </View>
            </Callout>
          </Marker>
        )}

        {/* Pickup & delivery markers with rich callout */}
        {orders.map((o: any) => (
          <View key={o.id}>
            {o.pickup_location?.lat && (
              <Marker
                coordinate={{ latitude: o.pickup_location.lat, longitude: o.pickup_location.lng }}
                pinColor="#10B981"
              >
                <Callout>
                  <View style={{ width: 200 }}>
                    <Text style={s.calloutCode}>{o.order_code}</Text>
                    <View style={{ flexDirection: "row", alignItems: "center", gap: 4, marginTop: 4 }}>
                      <View style={{ width: 6, height: 6, borderRadius: 3, backgroundColor: "#10B981" }} />
                      <Text style={s.calloutText}>Lấy: {o.pickup_location.code || "?"}</Text>
                    </View>
                    <Text style={s.calloutSub}>{o.cargo_name || "—"}</Text>
                    <Text style={s.calloutSub}>{o.customer?.name || "—"}</Text>
                  </View>
                </Callout>
              </Marker>
            )}
            {(o.delivery_points || []).map((dp: any, di: number) => {
              if (!dp.location?.lat) return null;
              const isDone = dp.status === "delivered" || dp.status === "completed";
              return (
                <Marker
                  key={dp.id || di}
                  coordinate={{ latitude: dp.location.lat, longitude: dp.location.lng }}
                  pinColor={isDone ? "#6EE7B7" : "#EF4444"}
                >
                  <Callout>
                    <View style={{ width: 200 }}>
                      <Text style={s.calloutCode}>{o.order_code}</Text>
                      <View style={{ flexDirection: "row", alignItems: "center", gap: 4, marginTop: 4 }}>
                        <View style={{ width: 6, height: 6, borderRadius: 3, backgroundColor: isDone ? "#10B981" : "#EF4444" }} />
                        <Text style={s.calloutText}>
                          Giao{di + 1}: {dp.location.code || dp.location_name || "?"}
                          {isDone ? " ✅" : ""}
                        </Text>
                      </View>
                      <Text style={s.calloutSub}>{dp.address || dp.location?.name || "—"}</Text>
                    </View>
                  </Callout>
                </Marker>
              );
            })}
          </View>
        ))}

        {/* Route polylines */}
        {routes.map((r, i) => (
          <Polyline key={i} coordinates={r.points} strokeColor={r.color} strokeWidth={3} />
        ))}
      </MapView>

      {/* Custom zoom controls */}
      <View style={s.zoomControls}>
        <TouchableOpacity style={s.zoomBtn} onPress={() => {
          const newLat = Math.max(0.0001, region.latitudeDelta / 3);
          const newLng = Math.max(0.0001, region.longitudeDelta / 3);
          setRegion((r: any) => ({ ...r, latitudeDelta: newLat, longitudeDelta: newLng }));
        }}>
          <Ionicons name="add" size={22} color="#374151" />
        </TouchableOpacity>
        <View style={s.zoomSep} />
        <TouchableOpacity style={s.zoomBtn} onPress={() => {
          const newLat = Math.min(100, region.latitudeDelta * 3);
          const newLng = Math.min(100, region.longitudeDelta * 3);
          setRegion((r: any) => ({ ...r, latitudeDelta: newLat, longitudeDelta: newLng }));
        }}>
          <Ionicons name="remove" size={22} color="#374151" />
        </TouchableOpacity>
      </View>

      {/* Trip info bar */}
      <View style={s.infoBar}>
        <View style={{ flex: 1 }}>
          <Text style={s.infoPlate}>{trip.vehicle?.plate_number || "Chưa gán xe"}</Text>
          <Text style={s.infoText}>{orders.length} đơn hàng{loadingRoutes ? " · Đang tải lộ trình..." : routes.length > 0 ? ` · ${routes.length} tuyến đường` : ""}</Text>
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
  calloutCode: { fontWeight: "700", fontSize: 14, color: "#111827" },
  calloutText: { fontSize: 12, color: "#374151", fontWeight: "600" },
  calloutSub: { fontSize: 11, color: "#6B7280", marginTop: 2 },
  zoomControls: { position: "absolute", right: 12, top: 12, backgroundColor: "#fff", borderRadius: 8, shadowColor: "#000", shadowOpacity: 0.1, shadowRadius: 6, elevation: 3 },
  zoomBtn: { width: 36, height: 36, alignItems: "center", justifyContent: "center" },
  zoomSep: { height: 1, backgroundColor: "#F3F4F6", marginHorizontal: 8 },
});
