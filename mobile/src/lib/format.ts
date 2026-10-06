/**
 * Format số km:
 * - Giữ 1 chữ số thập phân nếu có phần lẻ (ví dụ: 11.9, 0.2, 56.7)
 * - Bỏ .0 nếu là số nguyên tròn (ví dụ: 15, 9)
 * - Trả về "—" nếu null/undefined/NaN
 */
export const formatKm = (v: any): string => {
    if (v === null || v === undefined || v === "") return "—";
    const n = typeof v === "number" ? v : parseFloat(v);
    if (isNaN(n)) return "—";
    return n.toFixed(1);
};

/**
 * Lấy mốc thời gian cập nhật/hoạt động gần nhất của chuyến đi
 */
export const formatTripTime = (t: any): string | null => {
    if (!t) return null;
    const times = [t.completed_at, t.updated_at, t.started_at, t.created_at]
        .filter(Boolean)
        .map((d: string) => new Date(d).getTime())
        .filter((ms: number) => !isNaN(ms));
    if (times.length === 0) return null;
    const d = new Date(Math.max(...times));
    return d.toLocaleString("vi-VN");
};
