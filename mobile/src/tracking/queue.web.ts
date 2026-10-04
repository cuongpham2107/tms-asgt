// Web không ghi GPS nền: hàng đợi rỗng (tránh nạp expo-sqlite/WASM trên web).
import type { GpsPoint } from "../lib/api";

export type QueuedPoint = GpsPoint & { shift_id: number; vehicle_id: number | null };

export async function enqueue(_points: Omit<QueuedPoint, "seq">[]): Promise<void> {}
export async function peekBatch(_limit = 500): Promise<QueuedPoint[]> {
    return [];
}
export async function ackUpTo(_seq: number): Promise<void> {}
export async function pendingCount(): Promise<number> {
    return 0;
}
