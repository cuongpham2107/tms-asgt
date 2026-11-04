<?php

namespace App\Services\Billing;

use App\Enums\CargoType;
use App\Enums\CheckpointType;
use App\Enums\FreightPricingMethod;
use App\Models\FreightRate;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\WaitingFeeRate;
use App\Services\Export\TripExcelExportService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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

    public function calculate(Order $order, ?OrderCharge $charge = null): OrderCharge
    {
        $charge ??= OrderCharge::firstOrNew(['order_id' => $order->id]);

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

        $charge->chargeable_tonnage ??= $order->chargeable_weight;
        $charge->trip_id = $order->trip_id;

        $this->applyFreightRate($order, $charge, $chargeDate);

        $waiting = $this->waiting($order, $charge, $chargeDate);
        [$charge->waiting_minutes, $charge->waiting_fee] = [$waiting['minutes'], $waiting['fee']];

        $charge->recalculateTotals()->save();

        return $charge;
    }

    public static function parseKgToTons(mixed $weight): ?float
    {
        if (blank($weight)) {
            return null;
        }
        $clean = str_replace(',', '.', trim((string) $weight));
        if (! is_numeric($clean)) {
            return null;
        }
        $val = (float) $clean;

        return $val > 0 ? $val / 1000 : null;
    }

    /**
     * Tra lại đơn giá theo các giá trị đang sửa trên form (chưa lưu) — dùng để xem trước.
     *
     * @param  array{cargo_type?: mixed, rate_type?: ?string, chargeable_tonnage?: mixed, delivery_notes?: ?array, is_return_trip?: mixed, return_trip_percent?: mixed}  $attributes
     */
    public function previewFreightRate(Order $order, OrderCharge $charge, array $attributes): OrderCharge
    {
        $cargoType = $attributes['cargo_type'] ?? null;
        if (is_string($cargoType)) {
            $cargoType = CargoType::tryFrom($cargoType);
        }

        $draft = (clone $charge)->fill([
            'cargo_type' => $cargoType ?? $charge->cargo_type ?? $order->cargo_type ?? CargoType::Gcr,
            'rate_type' => (string) ($attributes['rate_type'] ?? ''),
            'chargeable_tonnage' => is_numeric($attributes['chargeable_tonnage'] ?? null) ? $attributes['chargeable_tonnage'] : null,
            'delivery_notes' => $attributes['delivery_notes'] ?? $charge->delivery_notes ?? [],
            'is_return_trip' => (bool) ($attributes['is_return_trip'] ?? false),
            'return_trip_percent' => (float) ($attributes['return_trip_percent'] ?? 0),
        ]);

        $order->loadMissing(['pickupLocation', 'deliveryPoints.location', 'trip.vehicle']);
        $this->applyFreightRate($order, $draft, $order->planned_loading_at ?? $order->created_at ?? now());

        return $draft;
    }

    public function applyFreightRate(Order $order, OrderCharge $charge, CarbonInterface $date): void
    {
        $rate = $this->findFreightRate($order, $charge, $date);
        $charge->freight_rate_id = $rate?->id;
        $charge->price_code = $rate?->priceCode($charge->chargeable_tonnage);

        if ($rate === null) {
            $charge->base_price = null;

            return;
        }

        $multiplier = $charge->is_return_trip ? (float) $charge->return_trip_percent / 100 : 1;

        $validNotes = collect($charge->delivery_notes ?? [])
            ->filter(fn ($n) => filled($n['chargeable_weight'] ?? null));

        if ($rate->pricing_method === FreightPricingMethod::ByWeightRange && $validNotes->isNotEmpty()) {
            $totalBase = 0;
            $allMatched = true;

            foreach ($validNotes as $note) {
                $ton = self::parseKgToTons($note['chargeable_weight']);
                $price = $ton !== null ? $rate->priceFor($ton) : null;
                if ($price !== null) {
                    $totalBase += $price;
                } else {
                    $allMatched = false;
                }
            }

            $charge->base_price = $allMatched ? round($totalBase * $multiplier) : null;

            return;
        }

        $charge->base_price = $rate->priceFor($charge->chargeable_tonnage) !== null
            ? round($rate->priceFor($charge->chargeable_tonnage) * $multiplier)
            : null;
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
    public function findFreightRate(Order $order, OrderCharge $charge, CarbonInterface $date): ?FreightRate
    {
        $origin = $order->pickupLocation?->billing_zone_id;
        $destination = $order->deliveryPoints->sortBy('sequence')->first()?->location?->billing_zone_id;

        $validNotes = collect($charge->delivery_notes ?? [])
            ->filter(fn ($n) => filled($n['chargeable_weight'] ?? null));

        if ($origin === null || $destination === null || ($charge->chargeable_tonnage === null && $validNotes->isEmpty())) {
            return null;
        }

        $vehicleType = $order->trip?->vehicle?->vehicle_type;
        $isStandardRate = blank($charge->rate_type) || $charge->rate_type === 'Chuẩn';

        $primaryCargoType = $charge->cargo_type ?? $order->cargo_type ?? CargoType::Gcr;
        $cargoTypes = [$primaryCargoType];
        if ($primaryCargoType !== CargoType::Gcr) {
            $cargoTypes[] = CargoType::Gcr;
        }

        foreach ($cargoTypes as $cargoType) {
            foreach ([[$origin, $destination], [$destination, $origin]] as [$from, $to]) {
                $rate = FreightRate::query()
                    ->with(['customer', 'originZone', 'destinationZone'])
                    ->where('customer_id', $charge->customer_id)
                    ->where('origin_zone_id', $from)
                    ->where('destination_zone_id', $to)
                    ->where('cargo_type', $cargoType)
                    ->when(
                        $isStandardRate,
                        fn (Builder $q) => $q->where(fn (Builder $sq) => $sq->whereNull('rate_type')->orWhere('rate_type', '')->orWhere('rate_type', 'Chuẩn')),
                        fn (Builder $q) => $q->where('rate_type', $charge->rate_type)
                    )
                    ->where(fn (Builder $q) => $q->whereNull('vehicle_type')->orWhere('vehicle_type', $vehicleType))
                    ->effectiveOn($date)
                    ->orderByDesc('vehicle_type')
                    ->get()
                    ->first(fn (FreightRate $r): bool => $this->rateCanPrice($r, $charge, $validNotes));

                if ($rate !== null) {
                    return $rate;
                }
            }
        }

        return null;
    }

    private function rateCanPrice(FreightRate $rate, OrderCharge $charge, Collection $validNotes): bool
    {
        if ($rate->pricing_method === FreightPricingMethod::ByWeightRange && $validNotes->isNotEmpty()) {
            return $validNotes->every(function ($note) use ($rate): bool {
                $ton = self::parseKgToTons($note['chargeable_weight']);

                return $ton !== null && $rate->priceFor($ton) !== null;
            });
        }

        return $charge->chargeable_tonnage !== null && $rate->priceFor($charge->chargeable_tonnage) !== null;
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
        return $this->previewWaiting($order, $charge, null, null, $date);
    }

    /**
     * Tra trước phí giờ chờ theo thời lượng đóng/hạ hàng truyền vào (hoặc tự lấy theo đơn).
     * Với đơn nhiều điểm trả (hàng rớt điểm): miễn phí được trừ riêng cho từng điểm hạ hàng.
     * "minutes" trả về là tổng thời gian chờ tính tiền (đã trừ thời gian miễn phí từng chặng).
     *
     * @return array{minutes: int, fee: float, loading: int, unloading: int, unloading_legs: list<int>, billable_loading: int, billable_unloading: int, rate: ?WaitingFeeRate, tier: ?array{tonnage: mixed, fee: mixed, free_minutes: mixed}, billable: int}
     */
    public function previewWaiting(
        Order $order,
        OrderCharge $charge,
        ?int $loadingMinutes = null,
        ?int $unloadingMinutes = null,
        ?CarbonInterface $date = null
    ): array {
        $date ??= $order->planned_loading_at ?? $order->created_at ?? now();

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

        $order->loadMissing(['deliveryPoints', 'tripCheckpoints', 'trip.checkpoints']);

        $deliveryPoints = $order->deliveryPoints->sortBy('sequence')->values();
        $isMultiPoint = $deliveryPoints->count() > 1;

        if ($loadingMinutes === null) {
            $row = $order->trip_id === null ? [] : ($this->tripSummary
                ->getTripSummaryRowsFromOrders(Order::query()->whereKey($order->id))
                ->firstWhere('order_id', $order->id) ?? []);
            $loadingMinutes = (int) ($row['time_loading'] ?? 0);
        }

        $freeMinutes = (int) ($tier['free_minutes'] ?? 0);

        if ($isMultiPoint && $unloadingMinutes === null) {
            $checkpoints = $order->tripCheckpoints->isNotEmpty()
                ? $order->tripCheckpoints
                : ($order->trip?->checkpoints ?? collect());

            $unloadingLegs = [];
            foreach ($deliveryPoints as $dp) {
                $dpCheckpoints = $checkpoints->where('delivery_point_id', $dp->id);
                $tC = $dpCheckpoints->firstWhere('checkpoint_type', CheckpointType::ArrivedDelivery)?->occurred_at
                    ?? $dp->arrived_at;
                $tD = $dpCheckpoints->where('checkpoint_type', CheckpointType::Completed)->last()?->occurred_at
                    ?? $dp->delivered_at;

                $dur = ($tC && $tD) ? (int) abs(Carbon::parse($tD)->diffInMinutes(Carbon::parse($tC))) : 0;
                $unloadingLegs[] = $dur;
            }

            $unloadingMinutes = array_sum($unloadingLegs);
            $billableUnloading = 0;
            foreach ($unloadingLegs as $dur) {
                $billableUnloading += max(0, $dur - $freeMinutes);
            }
        } else {
            if ($unloadingMinutes === null) {
                $row ??= $order->trip_id === null ? [] : ($this->tripSummary
                    ->getTripSummaryRowsFromOrders(Order::query()->whereKey($order->id))
                    ->firstWhere('order_id', $order->id) ?? []);
                $unloadingMinutes = (int) ($row['time_unloading'] ?? 0);
            }
            $unloadingLegs = [$unloadingMinutes];
            $billableUnloading = max(0, $unloadingMinutes - $freeMinutes);
        }

        $billableLoading = max(0, $loadingMinutes - $freeMinutes);
        $billableMinutes = $billableLoading + $billableUnloading;

        return [
            'minutes' => $billableMinutes,
            'fee' => $rate === null ? 0.0 : (float) self::waitingFee($billableMinutes, (float) $tier['fee'], $rate->fee_block_minutes, $rate->grace_minutes),
            'loading' => $loadingMinutes,
            'unloading' => $unloadingMinutes,
            'unloading_legs' => $unloadingLegs,
            'billable_loading' => $billableLoading,
            'billable_unloading' => $billableUnloading,
            'rate' => $rate,
            'tier' => $tier,
            'billable' => $billableMinutes,
        ];
    }

    /**
     * Diễn giải phí giờ chờ tính tự động (hiện trong modal cước).
     */
    public function explainWaiting(
        Order $order,
        OrderCharge $charge,
        ?int $loadingMinutes = null,
        ?int $unloadingMinutes = null
    ): string {
        $waiting = $this->previewWaiting($order, $charge, $loadingMinutes, $unloadingMinutes, $order->planned_loading_at ?? $order->created_at ?? now());

        $free = (int) ($waiting['tier']['free_minutes'] ?? 0);

        if (count($waiting['unloading_legs']) > 1) {
            $legParts = ["Đóng hàng {$waiting['loading']}' (tính {$waiting['billable_loading']}')"];
            foreach ($waiting['unloading_legs'] as $idx => $dur) {
                $pt = $idx + 1;
                $billablePt = max(0, $dur - $free);
                $legParts[] = "Điểm {$pt}: {$dur}' (tính {$billablePt}')";
            }
            $legs = implode(' + ', $legParts);
        } else {
            $legs = "Đóng hàng {$waiting['loading']}' + hạ hàng {$waiting['unloading']}'";
        }

        if ($waiting['rate'] === null) {
            return $legs.' · chưa có phí giờ chờ cho khách '.($charge->customer?->code ?? '—')
                .', loại hình phụ phí "'.($charge->surcharge_type ?: 'Chuẩn').'", bậc '.FreightRate::tonnageKey($charge->chargeable_tonnage).'T';
        }

        $rate = $waiting['rate'];
        $blocks = intdiv($waiting['billable'], max(1, $rate->fee_block_minutes))
            + ($rate->grace_minutes < $waiting['billable'] % max(1, $rate->fee_block_minutes) ? 1 : 0);

        return "{$legs} · miễn {$free}'/chặng → tính {$waiting['billable']}'"
            ." = {$blocks} × ".number_format((float) $waiting['tier']['fee'], 0, ',', '.')." đ (block {$rate->fee_block_minutes}', lẻ quá {$rate->grace_minutes}' tính thêm 1 block)";
    }

    /**
     * Diễn giải đơn giá gốc theo các giá trị đang có trên form cước (hiện trong modal).
     */
    public function explainPrice(Order $order, OrderCharge $charge, ?int $freightRateId): string
    {
        $tonnage = FreightRate::tonnageKey($charge->chargeable_tonnage);
        $rate = $freightRateId === null ? null : FreightRate::with(['originZone', 'destinationZone'])->find($freightRateId);

        $order->loadMissing(['pickupLocation.billingZone', 'deliveryPoints.location.billingZone']);
        $pickup = $order->pickupLocation;
        $delivery = $order->deliveryPoints->sortBy('sequence')->first()?->location;

        if ($rate !== null) {
            $isReverse = ($pickup?->billing_zone_id !== null && $rate->origin_zone_id !== $pickup->billing_zone_id);
            $direction = "{$rate->originZone?->name} → {$rate->destinationZone?->name}".($isReverse ? ' (chiều đối ứng)' : '');
            $cargoNote = ($charge->cargo_type !== null && $rate->cargo_type !== $charge->cargo_type)
                ? ' (áp dụng giá hàng thường)'
                : '';

            $validNotes = collect($charge->delivery_notes ?? [])
                ->filter(fn ($n) => filled($n['chargeable_weight'] ?? null));

            if ($validNotes->isNotEmpty() && $rate->pricing_method === FreightPricingMethod::ByWeightRange) {
                $parts = [];
                $totalBase = 0;
                foreach ($validNotes->values() as $idx => $n) {
                    $kgStr = str_replace(',', '.', trim((string) $n['chargeable_weight']));
                    $kg = (float) $kgStr;
                    $ton = $kg / 1000;
                    $p = $rate->priceFor($ton);
                    $tier = $rate->matchedTier($ton);
                    $label = $tier['label'] ?? ($rate->tonnageKey($ton).'T');
                    $noteNum = filled($n['number'] ?? null) ? "Phiếu {$n['number']}" : 'Phiếu '.($idx + 1);
                    $parts[] = "{$noteNum} (".number_format($kg, 0, ',', '.')." kg · {$label}: ".number_format((float) $p, 0, ',', '.').' đ)';
                    $totalBase += (float) $p;
                }

                $multiplier = $charge->is_return_trip ? (float) $charge->return_trip_percent / 100 : 1;
                $finalBase = round($totalBase * $multiplier);

                return "{$direction}{$cargoNote}: ".implode(' + ', $parts)
                    .($validNotes->count() > 1 ? ' = '.number_format($finalBase, 0, ',', '.').' đ' : '')
                    .($charge->is_return_trip ? ' × '.(float) $charge->return_trip_percent.'% quay đầu' : '');
            }

            return "{$direction} · {$tonnage}T{$cargoNote}: "
                .number_format((float) $rate->priceFor($charge->chargeable_tonnage), 0, ',', '.').' đ'
                .($charge->is_return_trip ? ' × '.(float) $charge->return_trip_percent.'% quay đầu' : '');
        }

        return match (true) {
            $pickup?->billingZone === null => 'Điểm lấy hàng '.($pickup?->code ?? '—').' chưa thuộc khu vực tính cước',
            $delivery?->billingZone === null => 'Điểm trả '.($delivery?->code ?? '—').' chưa thuộc khu vực tính cước',
            $tonnage === '' => 'Chưa có trọng tải tính cước',
            default => 'Bảng giá chưa có tuyến '.$pickup->billingZone->name.' ⇄ '.$delivery->billingZone->name
                .' (đã tìm cả 2 chiều đi và về cho khách '.($charge->customer?->code ?? '—').', '.($charge->cargo_type?->value ?? '—')
                .', loại hình "'.($charge->rate_type ?: 'Chuẩn').'") bậc '.$tonnage.'T',
        };
    }
}
