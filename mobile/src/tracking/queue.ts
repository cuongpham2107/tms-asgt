import * as SQLite from "expo-sqlite";
import type { GpsPoint } from "../lib/api";

/** Điểm chờ gửi: GpsPoint + ca/xe tại thời điểm ghi. `seq` do SQLite cấp (AUTOINCREMENT, không bao giờ dùng lại). */
export type QueuedPoint = GpsPoint & { shift_id: number; vehicle_id: number | null };

let db: SQLite.SQLiteDatabase | null = null;

function getDb(): SQLite.SQLiteDatabase {
    if (!db) {
        db = SQLite.openDatabaseSync("gps_queue.db");
        db.execSync(
            "CREATE TABLE IF NOT EXISTS gps_queue (seq INTEGER PRIMARY KEY AUTOINCREMENT, payload TEXT NOT NULL)",
        );
    }
    return db;
}

export async function enqueue(points: Omit<QueuedPoint, "seq">[]): Promise<void> {
    if (points.length === 0) return;
    const conn = getDb();
    await conn.withTransactionAsync(async () => {
        for (const p of points) {
            await conn.runAsync("INSERT INTO gps_queue (payload) VALUES (?)", JSON.stringify(p));
        }
    });
}

export async function peekBatch(limit = 500): Promise<QueuedPoint[]> {
    const rows = await getDb().getAllAsync<{ seq: number; payload: string }>(
        "SELECT seq, payload FROM gps_queue ORDER BY seq LIMIT ?",
        limit,
    );
    return rows.map((r) => ({ ...JSON.parse(r.payload), seq: r.seq }));
}

export async function ackUpTo(seq: number): Promise<void> {
    await getDb().runAsync("DELETE FROM gps_queue WHERE seq <= ?", seq);
}

export async function pendingCount(): Promise<number> {
    const row = await getDb().getFirstAsync<{ n: number }>("SELECT COUNT(*) AS n FROM gps_queue");
    return row?.n ?? 0;
}
