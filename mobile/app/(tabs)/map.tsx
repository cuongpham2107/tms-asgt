import { useState, useCallback, useEffect, useRef } from "react";
import { View, Text, StyleSheet, ActivityIndicator, TouchableOpacity, Platform } from "react-native";
import { useAuth } from "../../src/lib/auth";
import { api } from "../../src/lib/api";
import { useFocusEffect } from "expo-router";
import { Ionicons } from "@expo/vector-icons";
import L from "leaflet";
import "leaflet/dist/leaflet.css";

// Fix Leaflet default icon paths
delete (L.Icon.Default.prototype as any)._getIconUrl;
L.Icon.Default.mergeOptions({
  iconRetinaUrl: "https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png",
  iconUrl: "https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png",
  shadowUrl: "https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png",
});

const API_BASE = "/api/driver";
const HANOI_CENTER: [number, number] = [21.0285, 105.8542];
const colors = ["#4F46E5", "#059669", "#F59E0B", "#EF4444", "#8B5CF6"];

const vehicleIcon = L.divIcon({
  className: "",
  html: '<div style="width:34px;height:34px;border-radius:17px;background:#4F46E5;display:flex;align-items:center;justify-content:center;border:2px solid #fff;font-size:16px;">🚛</div>',
  iconSize: [34, 34],
  iconAnchor: [17, 17],
});

const pickupIcon = new L.Icon({
  iconUrl: "https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png",
  iconRetinaUrl: "https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png",
  shadowUrl: "https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png",
  iconSize: [25, 41],
  iconAnchor: [12, 41],
});

const deliveryIcon = new L.Icon({
  iconUrl: "https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png",
  shadowUrl: "https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png",
  iconSize: [25, 41],
  iconAnchor: [12, 41],
});

export default function MapScreen() {
  const { token } = useAuth();
  const [trip, setTrip] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const mapRef = useRef<L.Map | null>(null);
  const mapContainerRef = useRef<HTMLDivElement | null>(null);
  const layersRef = useRef<L.Layer[]>([]);

  const load = async () => {
    if (!token) return;
    try {
      const [tripRes] = await Promise.all([
        api.trips.active(token).catch(() => ({ data: [] })),
      ]);
      const trips = tripRes.data || [];
      const activeTrip = trips.find((t: any) =>
        t.status !== "pending" && t.status !== "completed" && t.status !== "cancelled" && t.status !== "driver_swap"
      );
      setTrip(activeTrip || null);
      renderMap(activeTrip);
    } finally { setLoading(false); }
  };

  const clearLayers = () => {
    layersRef.current.forEach((l) => mapRef.current?.removeLayer(l));
    layersRef.current = [];
  };

  const renderMap = (activeTrip: any) => {
    if (!mapRef.current) return;
    clearLayers();

    if (!activeTrip) return;

    const orders = activeTrip.orders || [];
    const vehicleLat = activeTrip.vehicle?.last_gps_lat || null;
    const vehicleLng = activeTrip.vehicle?.last_gps_lng || null;

    const bounds = L.latLngBounds([]);
    let hasPoints = false;

    // Vehicle marker
    if (vehicleLat && vehicleLng) {
      const m = L.marker([vehicleLat, vehicleLng], { icon: vehicleIcon })
        .bindPopup(activeTrip.vehicle?.plate_number || "Vị trí xe")
        .addTo(mapRef.current!);
      layersRef.current.push(m);
      bounds.extend([vehicleLat, vehicleLng]);
      hasPoints = true;
    }

    // Order markers + fetch routes
    orders.forEach((o: any, i: number) => {
      if (o.pickup_location?.lat) {
        const m = L.marker([o.pickup_location.lat, o.pickup_location.lng], { icon: pickupIcon })
          .bindPopup(`<b>${o.order_code}</b><br/>${o.pickup_location.code || "Điểm lấy"}`)
          .addTo(mapRef.current!);
        layersRef.current.push(m);
        bounds.extend([o.pickup_location.lat, o.pickup_location.lng]);
        hasPoints = true;
      }

      (o.delivery_points || []).forEach((dp: any, di: number) => {
        if (dp.location?.lat) {
          const m = L.marker([dp.location.lat, dp.location.lng], { icon: deliveryIcon })
            .bindPopup(`<b>${o.order_code}</b><br/>${dp.location.code || `Giao ${di + 1}`}`)
            .addTo(mapRef.current!);
          layersRef.current.push(m);
          bounds.extend([dp.location.lat, dp.location.lng]);
          hasPoints = true;
        }
      });

      // Fetch route
      const pickup = o.pickup_location;
      const dp = o.delivery_points?.[0];
      if (pickup?.lat && pickup?.lng && dp?.location?.lat && dp?.location?.lng) {
        fetchRoute(pickup, dp, o.delivery_points, i);
      }
    });

    if (hasPoints) {
      mapRef.current.fitBounds(bounds, { padding: [50, 50] });
    } else {
      mapRef.current.setView(HANOI_CENTER, 13);
    }
  };

  const fetchRoute = async (pickup: any, dp: any, allDps: any[], idx: number) => {
    try {
      const body: any = {
        origin_lat: pickup.lat,
        origin_lng: pickup.lng,
        destination_lat: dp.location.lat,
        destination_lng: dp.location.lng,
      };
      if (allDps?.length > 1) {
        body.waypoints = allDps.slice(0, -1).map((d: any) => ({
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
      if (json.success && json.data?.geometry?.coordinates && mapRef.current) {
        const coords: [number, number][] = json.data.geometry.coordinates.map(
          (c: [number, number]) => [c[1], c[0]] as [number, number]
        );
        const poly = L.polyline(coords, {
          color: colors[idx % colors.length],
          weight: 3,
        }).addTo(mapRef.current);
        layersRef.current.push(poly);
      }
    } catch {}
  };

  useEffect(() => {
    if (typeof window === "undefined") return;
    if (mapContainerRef.current && !mapRef.current) {
      mapRef.current = L.map(mapContainerRef.current, {
        center: HANOI_CENTER,
        zoom: 13,
        zoomControl: true,
      });
      L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
      }).addTo(mapRef.current);
    }
    return () => {
      mapRef.current?.remove();
      mapRef.current = null;
    };
  }, []);

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

  return (
    <View style={s.container}>
      <div ref={mapContainerRef as any} style={{ height: "100%", width: "100%" }} />
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
  center: { flex: 1, alignItems: "center", justifyContent: "center", backgroundColor: "#F9FAFB", gap: 12 },
  emptyText: { color: "#9CA3AF", fontSize: 14 },
  infoBar: { flexDirection: "row", alignItems: "center", backgroundColor: "#fff", paddingHorizontal: 16, paddingVertical: 12, borderTopWidth: 1, borderTopColor: "#F3F4F6", gap: 12 },
  infoPlate: { fontSize: 15, fontWeight: "700", color: "#111827" },
  infoText: { fontSize: 13, color: "#6B7280", marginTop: 2 },
  refreshBtn: { padding: 8, backgroundColor: "#EEF2FF", borderRadius: 8 },
});
