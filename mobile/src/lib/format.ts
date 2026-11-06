/**
 * Định dạng số km nhập liệu có dấu chấm phân cách hàng nghìn (VD: 332295 -> 332.295).
 */
export const formatKmInput = (digits: string | number | null | undefined): string => {
    if (digits == null) return "";
    const clean = String(digits).replace(/\D/g, "");
    if (!clean) return "";
    return clean.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
};

/**
 * Trích xuất số nguyên từ chuỗi km có định dạng dấu chấm/phẩy (VD: "332.295" -> 332295).
 */
export const parseKmInput = (value: string | number | null | undefined): number => {
    if (value == null) return 0;
    const clean = String(value).replace(/\D/g, "");
    return clean ? parseFloat(clean) : 0;
};

