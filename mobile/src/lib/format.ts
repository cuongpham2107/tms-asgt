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
    return n % 1 === 0 ? String(n) : n.toFixed(1);
};
