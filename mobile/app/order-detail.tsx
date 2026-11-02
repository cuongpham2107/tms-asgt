import { useMemo } from "react";
import { useLocalSearchParams } from "expo-router";
import OrderPanel from "../src/components/OrderPanel";

/** Màn chi tiết đơn (mở từ thông báo / tab Đơn hàng); phần lõi dùng chung với trang chuyến. */
export default function OrderDetailScreen() {
    const params = useLocalSearchParams<{ id: string; order: string }>();
    const order = useMemo(() => {
        try {
            return params.order ? JSON.parse(params.order) : null;
        } catch {
            return null;
        }
    }, [params.order]);

    if (!order) return null;

    return <OrderPanel order={order} />;
}
