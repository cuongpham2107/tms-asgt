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
    multiOrderCount?: number;
    orderCodes?: string[];
    stopKey?: string;
}

export interface PhysicalDeliveryStop {
    key: string;
    sequence: number;
    locationId?: number;
    locationCode?: string;
    locationName?: string;
    location?: any;
    address?: string;
    orderIds: number[];
    orderCodes: string[];
    deliveryPointIds: number[];
    allCompleted: boolean;
    anyArrived: boolean;
}

export function getPhysicalDeliveryStops(trip: any): PhysicalDeliveryStop[] {
    if (!trip) return [];
    const checkpoints: any[] = trip.checkpoints || [];
    const orders: any[] = trip.orders || [];
    const stopMap: Record<string, PhysicalDeliveryStop> = {};

    for (let oIdx = 0; oIdx < orders.length; oIdx++) {
        const order = orders[oIdx];
        const dps: any[] = order.delivery_points || [];

        if (dps.length > 0) {
            for (const dp of dps) {
                const locId = dp.location_id || dp.location?.id;
                // Nếu cùng location_id thì gộp chung vào 1 điểm dừng vật lý (Samsung YP, Foxconn...)
                const stopKey = locId ? `loc_${locId}` : `dp_${dp.id}`;
                const seq = Number(dp.sequence ?? (oIdx * 10 + 1));

                const isDpCompleted =
                    dp.status === "delivered" ||
                    dp.status === "completed" ||
                    checkpoints.some(
                        (cp) =>
                            cp.checkpoint_type === "completed" &&
                            Number(cp.delivery_point_id) === Number(dp.id)
                    );

                const isDpArrived =
                    dp.status === "arrived" ||
                    isDpCompleted ||
                    checkpoints.some(
                        (cp) =>
                            cp.checkpoint_type === "arrived_delivery" &&
                            Number(cp.delivery_point_id) === Number(dp.id)
                    );

                if (!stopMap[stopKey]) {
                    const loc = dp.location;
                    stopMap[stopKey] = {
                        key: stopKey,
                        sequence: seq,
                        locationId: locId,
                        locationCode: loc?.code || dp.code || `Điểm ${seq}`,
                        locationName: loc?.name || dp.name,
                        location: loc,
                        address: dp.address || loc?.address || loc?.name,
                        orderIds: [order.id],
                        orderCodes: order.order_code ? [order.order_code] : [],
                        deliveryPointIds: [dp.id],
                        allCompleted: isDpCompleted,
                        anyArrived: isDpArrived,
                    };
                } else {
                    const s = stopMap[stopKey];
                    if (!s.orderIds.includes(order.id)) {
                        s.orderIds.push(order.id);
                        if (order.order_code) s.orderCodes.push(order.order_code);
                    }
                    if (!s.deliveryPointIds.includes(dp.id)) {
                        s.deliveryPointIds.push(dp.id);
                    }
                    s.sequence = Math.min(s.sequence, seq);
                    s.allCompleted = s.allCompleted && isDpCompleted;
                    s.anyArrived = s.anyArrived || isDpArrived;
                }
            }
        } else {
            // Đơn không có delivery_points con -> dùng destination_location của đơn
            const loc = order.destination_location;
            const locId = order.destination_location_id || loc?.id;
            const stopKey = locId ? `loc_${locId}` : `ord_${order.id}`;
            const seq = (oIdx + 1) * 10;

            const isOrdCompleted =
                order.status === "completed" ||
                checkpoints.some(
                    (cp) =>
                        cp.checkpoint_type === "completed" &&
                        Number(cp.order_id) === Number(order.id)
                );

            const isOrdArrived =
                isOrdCompleted ||
                checkpoints.some(
                    (cp) =>
                        cp.checkpoint_type === "arrived_delivery" &&
                        Number(cp.order_id) === Number(order.id)
                );

            if (!stopMap[stopKey]) {
                stopMap[stopKey] = {
                    key: stopKey,
                    sequence: seq,
                    locationId: locId,
                    locationCode: loc?.code || order.order_code || `Đơn #${order.id}`,
                    locationName: loc?.name || order.delivery_address,
                    location: loc,
                    address: order.delivery_address || loc?.address || loc?.name,
                    orderIds: [order.id],
                    orderCodes: order.order_code ? [order.order_code] : [],
                    deliveryPointIds: [],
                    allCompleted: isOrdCompleted,
                    anyArrived: isOrdArrived,
                };
            } else {
                const s = stopMap[stopKey];
                if (!s.orderIds.includes(order.id)) {
                    s.orderIds.push(order.id);
                    if (order.order_code) s.orderCodes.push(order.order_code);
                }
                s.sequence = Math.min(s.sequence, seq);
                s.allCompleted = s.allCompleted && isOrdCompleted;
                s.anyArrived = s.anyArrived || isOrdArrived;
            }
        }
    }

    return Object.values(stopMap).sort((a, b) => a.sequence - b.sequence);
}

export function createActionForDeliveryStop(stop: PhysicalDeliveryStop): NextAction {
    const stopLabel = stop.locationCode || stop.locationName || "Điểm giao";
    const hasMultipleOrdersAtStop = stop.orderIds.length > 1;
    const multiOrderPrefix = hasMultipleOrdersAtStop
        ? `${stop.orderIds.length} đơn cùng điểm trả (${stop.orderCodes.join(", ")}) • `
        : "";

    if (!stop.anyArrived) {
        return {
            type: "arrived_delivery",
            label: `Đến giao hàng (${stopLabel})`,
            sub: `${multiOrderPrefix}${stop.address || "Đến điểm giao hàng"}`,
            icon: "location",
            color: "#3B82F6",
            bg: "#EFF6FF",
            orderId: stop.orderIds[0],
            deliveryPointId: stop.deliveryPointIds[0],
            targetLocation: stop.location,
            pointLabel: stopLabel,
            multiOrderCount: stop.orderIds.length,
            orderCodes: stop.orderCodes,
            stopKey: stop.key,
        };
    } else {
        return {
            type: "completed",
            label: `Hoàn thành giao (${stopLabel})`,
            sub: hasMultipleOrdersAtStop
                ? `Xác nhận giao xong ${stop.orderIds.length} đơn (${stop.orderCodes.join(", ")})`
                : (nextStopSubtitle(stop)),
            icon: "checkmark-circle",
            color: "#10B981",
            bg: "#ECFDF5",
            orderId: stop.orderIds[0],
            deliveryPointId: stop.deliveryPointIds[0],
            targetLocation: stop.location,
            pointLabel: stopLabel,
            multiOrderCount: stop.orderIds.length,
            orderCodes: stop.orderCodes,
            stopKey: stop.key,
        };
    }
}

function nextStopSubtitle(stop: PhysicalDeliveryStop): string {
    return stop.locationName || stop.address || "Đã giao hàng xong";
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

    // Chuyến không hàng
    if (trip.is_empty_run && (!trip.orders || trip.orders.length === 0)) {
        const checkpoints: any[] = trip.checkpoints || [];
        const hasEnd = checkpoints.some((cp) => cp.checkpoint_type === "end");
        if (!hasEnd && trip.status !== "completed") {
            return {
                type: "end",
                label: "Kết thúc xe",
                sub: "Hoàn thành chuyến không hàng",
                icon: "flag",
                color: "#DC2626",
                bg: "#FEF2F2",
            };
        }
        return null;
    }

    const checkpoints: any[] = trip.checkpoints || [];
    const orders: any[] = trip.orders || [];
    const firstOrder = orders[0];

    // 2. Chuyến đã bắt đầu -> Kiểm tra đã Đến lấy hàng chưa (áp dụng cho toàn bộ đơn trong chuyến)
    const hasArrivedPickup = checkpoints.some(
        (cp) => cp.checkpoint_type === "arrived_pickup"
    );

    if (trip.status === "started" || !hasArrivedPickup) {
        const pLoc = firstOrder?.pickup_location;
        const pCode = pLoc?.code || pLoc?.name || "Điểm lấy hàng";
        const multiNote = orders.length > 1 ? `${orders.length} đơn cùng điểm đi • ` : "";
        return {
            type: "arrived_pickup",
            label: `Đến lấy hàng (${pCode})`,
            sub: `${multiNote}${pLoc?.address || firstOrder?.pickup_address || "Đến kho nhận hàng"}`,
            icon: "cube",
            color: "#EA580C",
            bg: "#FFF7ED",
            orderId: firstOrder?.id,
            targetLocation: pLoc,
            pointLabel: pCode,
            multiOrderCount: orders.length,
            orderCodes: orders.map((o: any) => o.order_code).filter(Boolean),
        };
    }

    // 3. Đã Đến lấy hàng -> Kiểm tra đã Rời lấy hàng chưa
    const hasLeftPickup = checkpoints.some(
        (cp) => cp.checkpoint_type === "left_pickup"
    );

    if (trip.status === "arrived_pickup" || !hasLeftPickup) {
        const pLoc = firstOrder?.pickup_location;
        const pCode = pLoc?.code || pLoc?.name || "Điểm lấy hàng";
        const multiNote = orders.length > 1 ? `${orders.length} đơn hàng • ` : "";
        return {
            type: "left_pickup",
            label: `Rời lấy hàng (${pCode})`,
            sub: `${multiNote}Đã bốc hàng xong, xuất phát giao hàng`,
            icon: "arrow-forward-circle",
            color: "#4F46E5",
            bg: "#EEF2FF",
            orderId: firstOrder?.id,
            targetLocation: pLoc,
            pointLabel: pCode,
            multiOrderCount: orders.length,
            orderCodes: orders.map((o: any) => o.order_code).filter(Boolean),
        };
    }

    // 4. Trả hàng: Lấy danh sách điểm dừng vật lý
    const sortedStops = getPhysicalDeliveryStops(trip);

    // Tìm điểm dừng chưa hoàn thành đầu tiên
    const nextStop = sortedStops.find((s) => !s.allCompleted);

    if (nextStop) {
        return createActionForDeliveryStop(nextStop);
    }

    // 5. Đã giao hết các điểm -> Kết thúc xe
    const hasEnd = checkpoints.some((cp) => cp.checkpoint_type === "end");
    if (!hasEnd && trip.status !== "completed") {
        return {
            type: "end",
            label: "Kết thúc xe",
            sub: orders.length > 1
                ? `Hoàn thành toàn bộ lộ trình (${orders.length} đơn hàng)`
                : "Hoàn thành toàn bộ chuyến đi",
            icon: "flag",
            color: "#DC2626",
            bg: "#FEF2F2",
            orderId: firstOrder?.id,
        };
    }

    return null;
}
