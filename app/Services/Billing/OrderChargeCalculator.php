<?php

namespace App\Services\Billing;

use App\Models\FreightRate;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\WaitingFeeRate;
use App\Services\Export\TripExcelExportService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tính cước cho 1 đơn hàng ngoài và lưu vào order_charges.
 *
 * Lần đầu: lấy dữ liệu đầu vào từ đơn (loại hàng, trọng tải tính cước, trọng lượng); % dầu nhập tay trên page Thống kê.
 * Các lần sau: giữ nguyên các trường người dùng đã sửa, chỉ tra lại giá, giờ chờ và tổng tiền.
 * Dòng đã xuất bảng kê (exported_at) thì không đụng tới.
 */
class OrderChargeCalculator
{
    public function __construct(private readonly TripExcelExportService $tripSummary) {}

    public function calculate(Order $order): OrderCharge
    {
        $charge = OrderCharge::firstOrNew(['order_id' => $order->id]);

        if ($charge->isLocked()) {
            return $charge;
        }

        $order->loadMissing(['pickupLocation', 'deliveryPoints.location', 'trip.vehicle']);
        $chargeDate = $order->planned_loading_at ?? $order->created_at ?? now();

        if (! $charge->exists) {
            $charge->fill([
                'customer_id' => $order->customer_id,
                'cargo_type' => $order->cargo_type,
                'rate_type' => (string) $order->rate_type,
                'chargeable_tonnage' => $order->chargeable_weight,
                'is_return_trip' => (bool) $order->is_return_trip,
                'delivery_notes' => [[
                    'number' => null,
                    'actual_weight' => $order->total_weight === null ? null : (float) $order->total_weight,
                    'chargeable_weight' => $order->total_weight === null ? null : (float) $order->total_weight,
                ]],
            ]);
        }

        $charge->trip_id = $order->trip_id;

        $this->applyFreightRate($order, $charge, $chargeDate);

        $waiting = $this->waiting($order, $charge, $chargeDate);
        [$charge->waiting_minutes, $charge->waiting_fee] = [$waiting['minutes'], $waiting['fee']];

        $charge->recalculateTotals()->save();

        return $charge;
    }

    /**
     * Tra lại đơn giá theo các giá trị đang sửa trên form (chưa lưu) — dùng để xem trước.
     *
     * @param  array{cargo_type?: mixed, rate_type?: ?string, chargeable_tonnage?: mixed, is_return_trip?: mixed, return_trip_percent?: mixed}  $attributes
     */
    public function previewFreightRate(Order $order, OrderCharge $charge, array $attributes): OrderCharge
    {
        $draft = (clone $charge)->fill([
            'rate_type' => (string) ($attributes['rate_type'] ?? ''),
            'chargeable_tonnage' => is_numeric($attributes['chargeable_tonnage'] ?? null) ? $attributes['chargeable_tonnage'] : null,
            'is_return_trip' => (bool) ($attributes['is_return_trip'] ?? false),
            'return_trip_percent' => (float) ($attributes['return_trip_percent'] ?? 0),
        ]);

        $order->loadMissing(['pickupLocation', 'deliveryPoints.location', 'trip.vehicle']);
        $this->applyFreightRate($order, $draft, $order->planned_loading_at ?? $order->created_at ?? now());

        return $draft;
    }

    private function applyFreightRate(Order $order, OrderCharge $charge, CarbonInterface $date): void
    {
        $rate = $this->findFreightRate($order, $charge, $date);
        $charge->freight_rate_id = $rate?->id;
        $charge->price_code = $rate?->priceCode($charge->chargeable_tonnage);
        $charge->base_price = $rate === null
            ? null
            : $rate->priceFor($charge->chargeable_tonnage) * ($charge->is_return_trip ? (float) $charge->return_trip_percent / 100 : 1);
    }

    /**
     * Phí giờ chờ cho số phút đã trừ miễn phí: đủ block thì tính, phần lẻ vượt "thời gian miễn thêm" tính thêm 1 block.
     */
    public static function waitingFee(int $billableMinutes, float $fee, int $blockMinutes, int $graceMinutes): int
    {
        $blockMinutes = max(1, $blockMinutes);
        $blocks = intdiv($billableMinutes, $blockMinutes)
            + ($billableMinutes % $blockMinutes > $graceMinutes ? 1 : 0);

        return (int) round($blocks * $fee);
    }

    /**
     * Tuyến khu vực tính cước của điểm đi → khu vực của điểm trả đầu tiên, không có thì thử chiều ngược lại;
     * bảng giá phải có đơn giá cho trọng tải tính cước. Giá đúng loại xe được ưu tiên hơn giá áp mọi loại xe.
     */
    private function findFreightRate(Order $order, OrderCharge $charge, CarbonInterface $date): ?FreightRate
    {
        $origin = $order->pickupLocation?->billing_zone_id;
        $destination = $order->deliveryPoints->sortBy('sequence')->first()?->location?->billing_zone_id;

        if ($origin === null || $destination === null || $charge->cargo_type === null || $charge->chargeable_tonnage === null) {
            return null;
        }

        $vehicleType = $order->trip?->vehicle?->vehicle_type;

        foreach ([[$origin, $destination], [$destination, $origin]] as [$from, $to]) {
            $rate = FreightRate::query()
                ->with(['customer', 'originZone', 'destinationZone'])
                ->where('customer_id', $charge->customer_id)
                ->where('origin_zone_id', $from)
                ->where('destination_zone_id', $to)
                ->where('cargo_type', $charge->cargo_type)
                ->where('rate_type', $charge->rate_type)
                ->where(fn (Builder $q) => $q->whereNull('vehicle_type')->orWhere('vehicle_type', $vehicleType))
                ->effectiveOn($date)
                ->orderByDesc('vehicle_type')
                ->get()
                ->first(fn (FreightRate $rate): bool => $rate->priceFor($charge->chargeable_tonnage) !== null);

            if ($rate !== null) {
                return $rate;
            }
        }

        return null;
    }

    /**
     * Thời gian chờ = (đến điểm lấy hàng → rời điểm lấy hàng) + (đến điểm trả → hoàn thành).
     * Phí chỉ tính trên phần vượt thời gian miễn phí của từng chặng.
     * Lấy một dòng phí giờ chờ theo khách hàng + loại hình tính phụ phí, ưu tiên kiểu chờ thường.
     * ponytail: chưa tách giá nổ máy / không nổ máy theo chặng — làm khi có quy tắc chốt.
     *
     * @return array{minutes: int, fee: float, loading: int, unloading: int, rate: ?WaitingFeeRate, tier: ?array{tonnage: mixed, fee: mixed, free_minutes: mixed}, billable: int}
     */
    private function waiting(Order $order, OrderCharge $charge, CarbonInterface $date): array
    {
        $rate = WaitingFeeRate::query()
            ->where('customer_id', $charge->customer_id)
            ->where('surcharge_type', $charge->surcharge_type)
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date->toDateString()))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString()))
            ->get()
            ->sortBy(fn (WaitingFeeRate $rate): array => [
                $rate->waiting_mode !== WaitingFeeRate::MODE_NORMAL,
                $rate->id,
            ])
            ->first(fn (WaitingFeeRate $rate): bool => $rate->tierFor($charge->chargeable_tonnage) !== null);
        $tier = $rate?->tierFor($charge->chargeable_tonnage);

        $row = $order->trip_id === null ? [] : ($this->tripSummary
            ->getTripSummaryRowsFromOrders(Order::query()->whereKey($order->id))
            ->firstWhere('order_id', $order->id) ?? []);

        $loadingMinutes = (int) ($row['time_loading'] ?? 0);
        $unloadingMinutes = (int) ($row['time_unloading'] ?? 0);
        $freeMinutes = (int) ($tier['free_minutes'] ?? 0);
        $billableMinutes = max(0, $loadingMinutes - $freeMinutes) + max(0, $unloadingMinutes - $freeMinutes);

        return [
            'minutes' => $loadingMinutes + $unloadingMinutes,
            'fee' => $rate === null ? 0.0 : (float) self::waitingFee($billableMinutes, (float) $tier['fee'], $rate->fee_block_minutes, $rate->grace_minutes),
            'loading' => $loadingMinutes,
            'unloading' => $unloadingMinutes,
            'rate' => $rate,
            'tier' => $tier,
            'billable' => $billableMinutes,
        ];
    }

    /**
     * Diễn giải phí giờ chờ tính tự động (hiện trong modal cước).
     */
    public function explainWaiting(Order $order, OrderCharge $charge): string
    {
        $waiting = $this->waiting($order, $charge, $order->planned_loading_at ?? $order->created_at ?? now());
        $legs = "Đóng hàng {$waiting['loading']}' + hạ hàng {$waiting['unloading']}'";

        if ($waiting['rate'] === null) {
            return $legs.' · chưa có phí giờ chờ cho khách '.($charge->customer?->code ?? '—')
                .', loại hình phụ phí "'.($charge->surcharge_type ?: 'Chuẩn').'", bậc '.FreightRate::tonnageKey($charge->chargeable_tonnage).'T';
        }

        $rate = $waiting['rate'];
        $blocks = intdiv($waiting['billable'], max(1, $rate->fee_block_minutes))
            + ($rate->grace_minutes < $waiting['billable'] % max(1, $rate->fee_block_minutes) ? 1 : 0);

        return "{$legs} · miễn {$waiting['tier']['free_minutes']}'/chặng → tính {$waiting['billable']}'"
            ." = {$blocks} × ".number_format((float) $waiting['tier']['fee'], 0, ',', '.')." đ (block {$rate->fee_block_minutes}', lẻ quá {$rate->grace_minutes}' tính thêm 1 block)";
    }

    /**
     * Diễn giải đơn giá gốc theo các giá trị đang có trên form cước (hiện trong modal).
     */
    public function explainPrice(Order $order, OrderCharge $charge, ?int $freightRateId): string
    {
        $tonnage = FreightRate::tonnageKey($charge->chargeable_tonnage);
        $rate = $freightRateId === null ? null : FreightRate::with(['originZone', 'destinationZone'])->find($freightRateId);

        if ($rate !== null) {
            return "{$rate->originZone?->name} → {$rate->destinationZone?->name} · {$tonnage}T: "
                .number_format((float) $rate->priceFor($charge->chargeable_tonnage), 0, ',', '.').' đ'
                .($charge->is_return_trip ? ' × '.(float) $charge->return_trip_percent.'% quay đầu' : '');
        }

        $order->loadMissing(['pickupLocation.billingZone', 'deliveryPoints.location.billingZone']);
        $pickup = $order->pickupLocation;
        $delivery = $order->deliveryPoints->sortBy('sequence')->first()?->location;

        return match (true) {
            $pickup?->billingZone === null => 'Điểm lấy hàng '.($pickup?->code ?? '—').' chưa thuộc khu vực tính cước',
            $delivery?->billingZone === null => 'Điểm trả '.($delivery?->code ?? '—').' chưa thuộc khu vực tính cước',
            $tonnage === '' => 'Chưa có trọng tải tính cước',
            default => 'Bảng giá chưa có tuyến '.$pickup->billingZone->name.' → '.$delivery->billingZone->name
                .' (khách '.($charge->customer?->code ?? '—').', '.($charge->cargo_type?->value ?? '—')
                .', loại hình "'.($charge->rate_type ?: 'Chuẩn').'") bậc '.$tonnage.'T',
        };
    }
}
