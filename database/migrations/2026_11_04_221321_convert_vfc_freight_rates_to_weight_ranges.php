<?php

use App\Enums\FreightPricingMethod;
use App\Models\Customer;
use App\Models\FreightRate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $vfc = Customer::where('code', 'like', '%VFC%')->orWhere('name', 'like', '%VFC%')->first();
        if ($vfc === null) {
            return;
        }

        $rates = FreightRate::where('customer_id', $vfc->id)->get();

        foreach ($rates as $rate) {
            $prices = $rate->prices ?? [];
            if (empty($prices)) {
                continue;
            }

            $ranges = [];

            // Bậc 1: Dưới 2T (key 2.5 hoặc 2)
            $p1 = $prices['2.5'] ?? $prices['2'] ?? null;
            if ($p1 !== null) {
                $ranges[] = ['from' => 0, 'to' => 2, 'label' => 'Dưới 2T', 'price' => $p1];
            }

            // Bậc 2: Từ 2T đến 3T (key 3.5 hoặc 3)
            $p2 = $prices['3.5'] ?? $prices['3'] ?? null;
            if ($p2 !== null) {
                $ranges[] = ['from' => 2, 'to' => 3, 'label' => 'Từ 2T đến 3T', 'price' => $p2];
            }

            // Bậc 3: Từ 3T đến 4T (key 5 hoặc 4)
            $p3 = $prices['5'] ?? $prices['4'] ?? null;
            if ($p3 !== null) {
                $ranges[] = ['from' => 3, 'to' => 4, 'label' => 'Từ 3T đến 4T', 'price' => $p3];
            }

            // Bậc 4: Từ 4T đến 7T (key 8 hoặc 7)
            $p4 = $prices['8'] ?? $prices['7'] ?? null;
            if ($p4 !== null) {
                $ranges[] = ['from' => 4, 'to' => 7, 'label' => 'Từ 4T đến 7T', 'price' => $p4];
            }

            // Bậc mở rộng nếu có: 10T
            $p10 = $prices['10'] ?? null;
            if ($p10 !== null) {
                $ranges[] = ['from' => 7, 'to' => 10, 'label' => 'Từ 7T đến 10T', 'price' => $p10];
            }

            // Bậc mở rộng nếu có: 15T
            $p15 = $prices['15'] ?? null;
            if ($p15 !== null) {
                $ranges[] = ['from' => 10, 'to' => 15, 'label' => 'Từ 10T đến 15T', 'price' => $p15];
            }

            if (! empty($ranges)) {
                $rate->pricing_method = FreightPricingMethod::ByWeightRange;
                $rate->range_prices = $ranges;
                $rate->save();
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $vfc = Customer::where('code', 'like', '%VFC%')->orWhere('name', 'like', '%VFC%')->first();
        if ($vfc === null) {
            return;
        }

        FreightRate::where('customer_id', $vfc->id)
            ->where('pricing_method', FreightPricingMethod::ByWeightRange)
            ->update([
                'pricing_method' => FreightPricingMethod::ByVehicle->value,
                'range_prices' => null,
            ]);
    }
};
