// Import ở top-level của app (app/_layout.tsx) để TaskManager.defineTask chạy trước khi OS gọi task nền.
import * as TaskManager from "expo-task-manager";
import type { LocationObject } from "expo-location";
import AsyncStorage from "@react-native-async-storage/async-storage";
import { LAST_POINT_KEY, LOCATION_TASK, getTrackingContext } from "./tracker";
import { enqueue } from "./queue";
import { onPointsQueued } from "./uploader";

/** ISO8601 theo giờ máy kèm offset, vd 2026-10-04T08:15:00+07:00. */
function isoWithOffset(ms: number): string {
    const d = new Date(ms);
    const pad = (n: number) => String(Math.floor(Math.abs(n))).padStart(2, "0");
    const offset = -d.getTimezoneOffset();
    const sign = offset >= 0 ? "+" : "-";
    return (
        `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` +
        `T${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}` +
        `${sign}${pad(offset / 60)}:${pad(offset % 60)}`
    );
}

const orNull = (v: number | null | undefined) => (v == null || v < 0 ? null : v);

TaskManager.defineTask<{ locations: LocationObject[] }>(LOCATION_TASK, async ({ data, error }) => {
    if (error || !data?.locations?.length) return;
    const ctx = await getTrackingContext();
    if (!ctx) return;
    await enqueue(
        data.locations.map((l) => ({
            shift_id: ctx.shiftId,
            vehicle_id: ctx.vehicleId,
            recorded_at: isoWithOffset(l.timestamp),
            lat: l.coords.latitude,
            lng: l.coords.longitude,
            speed: l.coords.speed != null && l.coords.speed >= 0 ? l.coords.speed * 3.6 : null, // m/s → km/h
            heading: orNull(l.coords.heading),
            accuracy: orNull(l.coords.accuracy),
            mocked: l.mocked === true,
        })),
    );
    await AsyncStorage.setItem(LAST_POINT_KEY, String(Date.now()));
    await onPointsQueued();
});
