import { Platform } from "react-native";

// ─── Cấu hình API Laravel ───────────────────────────────────────────
// Thay IP này thành IP máy chạy Laravel backend
const API = Platform.select({
  ios: "http://localhost:8000/api/driver",
  android: "http://10.0.2.2:8000/api/driver",
  default: "https://tms.asgl.net.vn/api/driver",
});

// ─── Helpers ─────────────────────────────────────────────────────────

async function fetchApi<T>(path: string, token?: string, options?: RequestInit): Promise<T> {
  const headers: Record<string, string> = { Accept: "application/json" };
  if (token) headers["Authorization"] = `Bearer ${token}`;
  if (options?.body && !(options.body instanceof FormData)) headers["Content-Type"] = "application/json";

  const res = await fetch(`${API}${path}`, { ...options, headers });
  const json = await res.json().catch(() => null);

  if (!res.ok) {
    let msg = json?.message || json?.error || `HTTP ${res.status}`;
    // Laravel validation errors: { field: [msg1, msg2, ...] }
    if (typeof msg === "object" && !Array.isArray(msg)) {
      msg = Object.values(msg).flat().join("\n");
    }
    throw new Error(typeof msg === "string" ? msg : JSON.stringify(msg));
  }
  return json as T;
}

// ─── Auth ────────────────────────────────────────────────────────────

export function login(email: string, password: string) {
  return fetchApi<{ token: string }>("/login", undefined, {
    method: "POST",
    body: JSON.stringify({ email, password }),
  });
}

// ─── API endpoints ───────────────────────────────────────────────────

export const api = {
  // Shifts
  shifts: {
    current: (t: string) => fetchApi<{ shift: any }>("/shifts/current", t),
    start: (body: { shift_type: string; start_gps_lat?: number; start_gps_lng?: number }, t: string) =>
      fetchApi<{ shift: any }>("/shifts/start", t, { method: "POST", body: JSON.stringify(body) }),
    end: (t: string) =>
      fetchApi<{ shift: any }>("/shifts/end", t, { method: "POST", body: JSON.stringify({}) }),
    endVehicle: (shiftId: string, kmReading: number, t: string) =>
      fetchApi<{ checkpoint: any; vehicle: any }>(`/shifts/${shiftId}/end-vehicle`, t, {
        method: "POST", body: JSON.stringify({ km_reading: kmReading }),
      }),
    switchVehicle: (body: { new_vehicle_id: number; handover_km: number }, t: string) =>
      fetchApi<{ shift: any }>("/shifts/switch-vehicle", t, { method: "POST", body: JSON.stringify(body) }),
  },

  // Trips
  trips: {
    active: (t: string) => fetchApi<{ data: any[] }>("/trips/active", t),
    history: (params: { per_page?: number; from_date?: string; to_date?: string; status?: string; vehicle_id?: number }, t: string) => {
      const qs = new URLSearchParams();
      Object.entries(params).forEach(([k, v]) => { if (v !== undefined) qs.set(k, String(v)); });
      return fetchApi<{ data: any[]; meta: any }>(`/trips/history?${qs}`, t);
    },
    detail: (id: string, t: string) => fetchApi<{ data: any }>(`/trips/${id}`, t),
    complete: (tripId: string, endKm: number, t: string) =>
      fetchApi<{ data: any }>(`/trips/${tripId}/complete`, t, { method: "POST", body: JSON.stringify({ end_km: endKm }) }),
    checkpoint: (tripId: string, body: any, t: string) => {
      const hasPhotos = body.photos && Array.isArray(body.photos) && body.photos.length > 0;
      if (hasPhotos) {
        const fd = new FormData();
        Object.entries(body).forEach(([k, v]) => {
          if (k === "photos") {
            (v as string[]).forEach((uri, i) => fd.append(`photos[${i}]`, { uri, name: `photo_${i}.jpg`, type: "image/jpeg" } as any));
          } else if (v !== undefined && v !== null) {
            fd.append(k, String(v));
          }
        });
        return fetchApi<any>(`/trips/${tripId}/checkpoints`, t, { method: "POST", body: fd });
      }
      return fetchApi<any>(`/trips/${tripId}/checkpoints`, t, { method: "POST", body: JSON.stringify(body) });
    },
  },

  // Orders
  orders: {
    list: (t: string) => fetchApi<{ data: any[]; meta?: any }>("/orders?per_page=50", t),
    detail: (id: string, t: string) => fetchApi<{ data: any }>(`/orders/${id}`, t),
    history: (params: { per_page?: number; from_date?: string; to_date?: string }, t: string) => {
      const qs = new URLSearchParams();
      Object.entries(params).forEach(([k, v]) => { if (v !== undefined) qs.set(k, String(v)); });
      return fetchApi<{ data: any[]; meta: any }>(`/orders/history?${qs}`, t);
    },
    stats: (t: string) => fetchApi<{ data: any }>("/orders/stats", t),
  },

  // Stats
  stats: (t: string) => fetchApi<{ data: any }>("/trips/stats", t),

  // Vehicles
  vehicles: {
    search: (query: string, t: string) => fetchApi<{ data: any[] }>(`/vehicles/search?q=${encodeURIComponent(query)}`, t),
    available: (t: string) => fetchApi<{ data: any[] }>("/vehicles/available", t),
    detail: (id: string, t: string) => fetchApi<{ data: any }>(`/vehicles/${id}`, t),
  },

  // Locations
  locations: (params: { search?: string; area_id?: number }, t: string) => {
    const qs = new URLSearchParams();
    if (params.search) qs.set("search", params.search);
    if (params.area_id) qs.set("area_id", String(params.area_id));
    return fetchApi<{ data: any[] }>(`/locations?${qs}`, t);
  },
};

// ─── Type helpers ────────────────────────────────────────────────────

export interface ShiftResource {
  id: number;
  shift_type: string;
  start_time: string;
  end_time: string | null;
  start_km: string | null;
  end_km: string | null;
  total_km: string | null;
  total_km_loaded: string | null;
  total_km_empty: string | null;
}

export interface TripResource {
  id: number;
  trip_code: string;
  status: string;
  started_at: string | null;
  completed_at: string | null;
  start_km: string | null;
  end_km: string | null;
  total_km: string | null;
  total_km_loaded: string | null;
  total_km_empty: string | null;
  vehicle?: { plate_number: string; current_mileage: string };
  orders?: OrderResource[];
  checkpoints?: CheckpointResource[];
}

export interface OrderResource {
  id: number;
  order_code: string;
  status: string;
  cargo_name: string | null;
  total_packages: number | null;
  total_weight: string | null;
  customer?: { name: string };
  pickup_address: string | null;
  loaded_km: string | null;
}

export interface CheckpointResource {
  id: number;
  checkpoint_type: string;
  km_reading: string | null;
  occurred_at: string;
  voice_note: string | null;
  photos?: { url: string }[];
}
