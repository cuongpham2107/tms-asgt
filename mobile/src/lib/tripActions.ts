/**
 * Helper xác định hành động chốt chặng tiếp theo của Chuyến xe.
 * Phục vụ trải nghiệm "1-Tap Cockpit" — tài xế cập nhật trực tiếp
 * mà không cần phải chuyển màn hình lồng nhau.
 */

export interface NextAction {
    type: "started" | "arrived_pickup" | "left_pickup" | "arrived_delivery" | "completed" | "end";
    label: string;
    sub?: string;
    icon: string;
    color: string;
    bg: string;
    orderId?: number;
    deliveryPointId?: number;
    targetLocation?: {
        id?: number;
        code?: string;
        name?: string;
        address?: string;
        lat?: number | string;
        lng?: number | string;
    };
    pointLabel?: string;
}

export function resolveNextAction(trip: any, userId?: number | null): NextAction | null {
    if (!trip) return null;

    // Đã hoàn thành hoặc đã huỷ
    if (trip.status === "completed" || trip.status === "cancelled") {
        return null;
    }

    // Đang chờ gán lái mới sau khi đảo lái
    if (trip.status === "driver_swap") {
        return null;
    }

    // Nếu chuyến không thuộc về tài xế đang đăng nhập
    if (userId && trip.driver_id && Number(trip.driver_id) !== Number(userId)) {
        return null;
    }

    // 1. Chuyến chưa bắt đầu
    if (trip.status === "pending") {
        return {
            type: "started",
            label: "Bắt đầu chuyến",
            sub: trip.vehicle?.plate_number ? `Xe ${trip.vehicle.plate_number}` : "Bắt đầu hành trình",
            icon: "play-circle",
            color: "#059669",
            bg: "#ECFDF5",
        };
    }

    const checkpoints: any[] = trip.checkpoints || [];
    const orders: any[] = trip.orders || [];
    const firstOrder = orders[0];

    // 2. Chuyến đã bắt đầu -> Kiểm tra đã Đến lấy hàng chưa
    const hasArrivedPickup = checkpoints.some(
        (cp) => cp.checkpoint_type === "arrived_pickup"
    );

    if (trip.status === "started" || !hasArrivedPickup) {
        const pLoc = firstOrder?.pickup_location;
        const pCode = pLoc?.code || pLoc?.name || "Điểm lấy hàng";
        return {
            type: "arrived_pickup",
            label: `Đến lấy hàng (${pCode})`,
            sub: pLoc?.address || firstOrder?.pickup_address || "Đến kho nhận hàng",
            icon: "cube",
            color: "#EA580C",
            bg: "#FFF7ED",
            orderId: firstOrder?.id,
            targetLocation: pLoc,
            pointLabel: pCode,
        };
    }

    // 3. Đã Đến lấy hàng -> Kiểm tra đã Rời lấy hàng chưa
    const hasLeftPickup = checkpoints.some(
        (cp) => cp.checkpoint_type === "left_pickup"
    );

    if (trip.status === "arrived_pickup" || !hasLeftPickup) {
        const pLoc = firstOrder?.pickup_location;
        const pCode = pLoc?.code || pLoc?.name || "Điểm lấy hàng";
        return {
            type: "left_pickup",
            label: `Rời lấy hàng (${pCode})`,
            sub: "Đã bốc hàng xong, xuất phát giao hàng",
            icon: "arrow-forward-circle",
            color: "#4F46E5",
            bg: "#EEF2FF",
            orderId: firstOrder?.id,
            targetLocation: pLoc,
            pointLabel: pCode,
        };
    }

    // 4. Đang đi giao hàng (hoặc sau khi đã rời lấy hàng)
    // Duyệt qua các điểm giao hàng theo trình tự
    for (const order of orders) {
        const dps: any[] = order.delivery_points || [];
        if (dps.length > 0) {
            const sortedDps = [...dps].sort(
                (a, b) => (a.sequence || 0) - (b.sequence || 0)
            );
            for (const dp of sortedDps) {
                const isCompleted =
                    dp.status === "delivered" ||
                    dp.status === "completed" ||
                    checkpoints.some(
                        (cp) =>
                            cp.checkpoint_type === "completed" &&
                            Number(cp.delivery_point_id) === Number(dp.id)
                    );

                if (!isCompleted) {
                    const hasArrived = checkpoints.some(
                        (cp) =>
                            cp.checkpoint_type === "arrived_delivery" &&
                            Number(cp.delivery_point_id) === Number(dp.id)
                    );
                    const dpCode =
                        dp.location?.code || dp.code || `Điểm ${dp.sequence || 1}`;
                    const loc = dp.location;

                    if (!hasArrived) {
                        return {
                            type: "arrived_delivery",
                            label: `Đến giao hàng (${dpCode})`,
                            sub: dp.address || loc?.name || loc?.address || "Đến điểm giao hàng",
                            icon: "location",
                            color: "#3B82F6",
                            bg: "#EFF6FF",
                            orderId: order.id,
                            deliveryPointId: dp.id,
                            targetLocation: loc,
                            pointLabel: dpCode,
                        };
                    } else {
                        return {
                            type: "completed",
                            label: `Hoàn thành giao (${dpCode})`,
                            sub: loc?.name || dp.address || "Đã giao hàng xong",
                            icon: "checkmark-circle",
                            color: "#10B981",
                            bg: "#ECFDF5",
                            orderId: order.id,
                            deliveryPointId: dp.id,
                            targetLocation: loc,
                            pointLabel: dpCode,
                        };
                    }
                }
            }
        } else {
            // Đơn không chia nhỏ delivery_points
            const isCompleted =
                order.status === "completed" ||
                checkpoints.some(
                    (cp) =>
                        cp.checkpoint_type === "completed" &&
                        Number(cp.order_id) === Number(order.id)
                );

            if (!isCompleted) {
                const hasArrived = checkpoints.some(
                    (cp) =>
                        cp.checkpoint_type === "arrived_delivery" &&
                        Number(cp.order_id) === Number(order.id)
                );
                const destCode =
                    order.destination_location?.code ||
                    order.code ||
                    `Đơn #${order.id}`;

                if (!hasArrived) {
                    return {
                        type: "arrived_delivery",
                        label: `Đến giao hàng (${destCode})`,
                        sub:
                            order.delivery_address ||
                            order.destination_location?.address ||
                            "Đến điểm giao",
                        icon: "location",
                        color: "#3B82F6",
                        bg: "#EFF6FF",
                        orderId: order.id,
                        targetLocation: order.destination_location,
                        pointLabel: destCode,
                    };
                } else {
                    return {
                        type: "completed",
                        label: `Hoàn thành giao (${destCode})`,
                        sub:
                            order.destination_location?.name ||
                            "Đã giao hàng xong",
                        icon: "checkmark-circle",
                        color: "#10B981",
                        bg: "#ECFDF5",
                        orderId: order.id,
                        targetLocation: order.destination_location,
                        pointLabel: destCode,
                    };
                }
            }
        }
    }

    // 5. Đã giao hết các điểm -> Kết thúc xe
    const hasEnd = checkpoints.some((cp) => cp.checkpoint_type === "end");
    if (!hasEnd) {
        return {
            type: "end",
            label: "Kết thúc xe",
            sub: "Hoàn thành toàn bộ chuyến đi",
            icon: "flag",
            color: "#DC2626",
            bg: "#FEF2F2",
            orderId: firstOrder?.id,
        };
    }

    return null;
}
