<?php

use App\Enums\CargoType;
use App\Models\BillingZone;
use App\Models\Customer;
use App\Models\FreightRate;
use App\Models\Location;
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

        $vfcId = $vfc->id;

        // 1. Chuyển đổi toàn bộ các bảng giá hiện có của VFC từ bậc 2, 3, 4, 7 sang 2.5, 3.5, 5, 8
        $existingRates = FreightRate::where('customer_id', $vfcId)->get();
        foreach ($existingRates as $rate) {
            $prices = $rate->prices ?? [];
            if (array_key_exists('2', $prices) || array_key_exists('3', $prices) || array_key_exists('4', $prices) || array_key_exists('7', $prices)) {
                $newPrices = [];
                foreach ($prices as $k => $v) {
                    $newKey = match ((string) $k) {
                        '2' => '2.5',
                        '3' => '3.5',
                        '4' => '5',
                        '7' => '8',
                        default => (string) $k,
                    };
                    $newPrices[$newKey] = $v;
                }
                $rate->prices = $newPrices;
                $rate->save();
            }
        }

        // 2. Đảm bảo các BillingZone cần thiết tồn tại
        $zoneYenPhong = BillingZone::firstOrCreate(['code' => 'KCN-YEN-PHONG'], ['name' => 'KCN YÊN PHONG']);
        $zoneQueVo1 = BillingZone::firstOrCreate(['code' => 'KCN-QUE-VO-1'], ['name' => 'KCN QUẾ VÕ 1']);
        $zoneHaiPhong = BillingZone::firstOrCreate(['code' => 'KCN-HAI-PHONG'], ['name' => 'KCN HẢI PHÒNG']);
        $zoneCamGiang = BillingZone::firstOrCreate(['code' => 'CAM-GIANG'], ['name' => 'CẨM GIÀNG']);
        $zoneHungYen = BillingZone::firstOrCreate(['code' => 'KCN-HUNG-YEN'], ['name' => 'KCN HƯNG YÊN']);
        $zoneBacNinh = BillingZone::firstOrCreate(['code' => 'BAC-NINH'], ['name' => 'BẮC NINH']);
        $zoneHaNoi = BillingZone::firstOrCreate(['code' => 'HA-NOI'], ['name' => 'HÀ NỘI']);
        $zoneThangLong = BillingZone::firstOrCreate(['code' => 'KCN-THANG-LONG'], ['name' => 'KCN THĂNG LONG']);
        $zonePhucYen = BillingZone::firstOrCreate(['code' => 'PHUC-YEN'], ['name' => 'PHÚC YÊN']);
        $zoneThaiBinh = BillingZone::firstOrCreate(['code' => 'THAI-BINH'], ['name' => 'CÁC KCN THÁI BÌNH']);
        $zoneChiLinh = BillingZone::firstOrCreate(['code' => 'CHI-LINH'], ['name' => 'CÁC KCN CHÍ LINH']);
        $zonePhuLy = BillingZone::firstOrCreate(['code' => 'PHU-LY'], ['name' => 'PHỦ LÝ']);
        $zoneBacGiang = BillingZone::firstOrCreate(['code' => 'BAC-GIANG'], ['name' => 'CÁC KCN BẮC GIANG']);
        $zoneNinhBinh = BillingZone::firstOrCreate(['code' => 'NINH-BINH'], ['name' => 'NINH BÌNH']);
        $zoneHoaBinh = BillingZone::firstOrCreate(['code' => 'HOA-BINH'], ['name' => 'KCN BỜ TRÁI SÔNG ĐÀ - HÒA BÌNH']);
        $zoneThaiNguyen = BillingZone::firstOrCreate(['code' => 'THAI-NGUYEN'], ['name' => 'CÁC KCN THÁI NGUYÊN']);
        $zoneNamDinh = BillingZone::firstOrCreate(['code' => 'NAM-DINH'], ['name' => 'CÁC KCN NAM ĐỊNH']);
        $zoneTamNong = BillingZone::firstOrCreate(['code' => 'KCN-TAM-NONG'], ['name' => 'KCN TAM NÔNG']);
        $zoneYenBai = BillingZone::firstOrCreate(['code' => 'TRAN-YEN'], ['name' => 'TRẤN YÊN']);
        $zoneCaoThang = BillingZone::firstOrCreate(['code' => 'CUM-CN-CAO-THANG'], ['name' => 'CỤM CN CAO THẮNG']);
        $zoneKimThanh = BillingZone::firstOrCreate(['code' => 'KIM-THANH'], ['name' => 'KIM THÀNH']);
        $zoneBacTienPhong = BillingZone::firstOrCreate(['code' => 'KCN-BAC-TIEN-PHONG'], ['name' => 'KCN BẮC TIỀN PHONG']);
        $zoneNoiBai = BillingZone::firstOrCreate(['code' => 'NOI-BAI'], ['name' => 'NỘI BÀI']);
        $zoneSocSon = BillingZone::firstOrCreate(['code' => 'SOC-SON'], ['name' => 'SÓC SƠN']);
        $zoneLaiVu = BillingZone::firstOrCreate(['code' => 'KCN-LAI-VU'], ['name' => 'KCN LAI VŨ']);
        $zoneHoangMai = BillingZone::firstOrCreate(['code' => 'KCN-HOANG-MAI-I'], ['name' => 'KCN HOÀNG MAI I']);
        $zoneKinhMon = BillingZone::firstOrCreate(['code' => 'CUM-CN-PHU-THU'], ['name' => 'CỤM CN PHÚ THỨ']);
        $zoneQuangNinh = BillingZone::firstOrCreate(['code' => 'KCN-DONG-MAI'], ['name' => 'KCN ĐÔNG MAI']);
        $zoneDaiAn = BillingZone::firstOrCreate(['code' => 'KCN-DAI-AN'], ['name' => 'KCN ĐẠI AN']);

        // 3. Cập nhật / tạo 43 tuyến chi tiết theo bảng giá mới
        $routes = [
            // 14 Tuyến từ KCN Yên Phong
            ['from' => $zoneYenPhong->id, 'to' => $zoneHaiPhong->id, 'prices' => ['2.5' => 1271768, '3.5' => 1447740, '5' => 2062898, '8' => 2682969]],
            ['from' => $zoneYenPhong->id, 'to' => $zoneCamGiang->id, 'prices' => ['2.5' => 778351, '3.5' => 885990, '5' => 1262484, '8' => 1642007]],
            ['from' => $zoneYenPhong->id, 'to' => $zoneHungYen->id, 'prices' => ['2.5' => 896532, '3.5' => 1047663, '5' => 1552507, '8' => 1930521]],
            ['from' => $zoneYenPhong->id, 'to' => $zoneBacNinh->id, 'prices' => ['2.5' => 741281, '3.5' => 843831, '5' => 1202364, '8' => 1563851]],
            ['from' => $zoneYenPhong->id, 'to' => $zoneHaNoi->id, 'prices' => ['2.5' => 807209, '3.5' => 918772, '5' => 1309285, '8' => 1702753]],
            ['from' => $zoneYenPhong->id, 'to' => $zoneThangLong->id, 'prices' => ['2.5' => 704221, '3.5' => 801662, '5' => 1142253, '8' => 1485695]],
            ['from' => $zoneYenPhong->id, 'to' => $zonePhucYen->id, 'prices' => ['2.5' => 920552, '3.5' => 1047840, '5' => 1493198, '8' => 1942042]],
            ['from' => $zoneYenPhong->id, 'to' => $zoneThaiBinh->id, 'prices' => ['2.5' => 1491501, '3.5' => 1697925, '5' => 2419473, '8' => 3146735]],
            ['from' => $zoneYenPhong->id, 'to' => $zoneChiLinh->id, 'prices' => ['2.5' => 747181, '3.5' => 850701, '5' => 1211927, '8' => 1576266]],
            ['from' => $zoneYenPhong->id, 'to' => $zonePhuLy->id, 'prices' => ['2.5' => 1318401, '3.5' => 1540539, '5' => 2283079, '8' => 2839198]],
            ['from' => $zoneYenPhong->id, 'to' => $zoneBacGiang->id, 'prices' => ['2.5' => 711631, '3.5' => 810155, '5' => 1154220, '8' => 1501242]],
            ['from' => $zoneYenPhong->id, 'to' => $zoneNinhBinh->id, 'prices' => ['2.5' => 1570022, '3.5' => 1787425, '5' => 2546845, '8' => 3312333]],
            ['from' => $zoneYenPhong->id, 'to' => $zoneHoaBinh->id, 'prices' => ['2.5' => 1345730, '3.5' => 1532057, '5' => 2182943, '8' => 2839198]],
            ['from' => $zoneYenPhong->id, 'to' => $zoneThaiNguyen->id, 'prices' => ['2.5' => 1187003, '3.5' => 1351173, '5' => 1925338, '8' => 2504145]],

            // 29 Tuyến từ KCN Quế Võ 1
            ['from' => $zoneQueVo1->id, 'to' => $zoneHaiPhong->id, 'prices' => ['2.5' => 1211209, '3.5' => 1378782, '5' => 1964645, '8' => 2555243]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneCamGiang->id, 'prices' => ['2.5' => 741281, '3.5' => 843831, '5' => 1202364, '8' => 1563851]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneHungYen->id, 'prices' => ['2.5' => 853833, '3.5' => 997730, '5' => 1478555, '8' => 1838607]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneBacNinh->id, 'prices' => ['2.5' => 741281, '3.5' => 843831, '5' => 1202364, '8' => 1563851]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneHaNoi->id, 'prices' => ['2.5' => 823642, '3.5' => 937525, '5' => 1335990, '8' => 1737492]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneThangLong->id, 'prices' => ['2.5' => 741281, '3.5' => 843831, '5' => 1202364, '8' => 1563851]],
            ['from' => $zoneQueVo1->id, 'to' => $zonePhucYen->id, 'prices' => ['2.5' => 968966, '3.5' => 1102955, '5' => 1571802, '8' => 2044227]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneThaiBinh->id, 'prices' => ['2.5' => 1420494, '3.5' => 1617093, '5' => 2304247, '8' => 2996854]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneChiLinh->id, 'prices' => ['2.5' => 711631, '3.5' => 810155, '5' => 1154220, '8' => 1501242]],
            ['from' => $zoneQueVo1->id, 'to' => $zonePhuLy->id, 'prices' => ['2.5' => 1255605, '3.5' => 1467210, '5' => 2174368, '8' => 2703959]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneBacGiang->id, 'prices' => ['2.5' => 711631, '3.5' => 810155, '5' => 1154220, '8' => 1501242]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneNinhBinh->id, 'prices' => ['2.5' => 1495258, '3.5' => 1702305, '5' => 2425541, '8' => 3154593]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneNamDinh->id, 'prices' => ['2.5' => 1495258, '3.5' => 1702305, '5' => 2425541, '8' => 3154593]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneHoaBinh->id, 'prices' => ['2.5' => 1345730, '3.5' => 1532057, '5' => 2182943, '8' => 2839198]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneThaiNguyen->id, 'prices' => ['2.5' => 1211209, '3.5' => 1378782, '5' => 1964645, '8' => 2555243]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneTamNong->id, 'prices' => ['2.5' => 1271768, '3.5' => 1447740, '5' => 2062898, '8' => 2682969]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneYenBai->id, 'prices' => ['2.5' => 2328000, '3.5' => 2861500, '5' => 3206055, '8' => 4169755]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneCaoThang->id, 'prices' => ['2.5' => 968966, '3.5' => 1102955, '5' => 1571802, '8' => 2044227]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneKimThanh->id, 'prices' => ['2.5' => 1211209, '3.5' => 1378782, '5' => 1964645, '8' => 2555243]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneBacTienPhong->id, 'prices' => ['2.5' => 1211209, '3.5' => 1378782, '5' => 1964645, '8' => 2555243]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneNoiBai->id, 'prices' => ['2.5' => 741281, '3.5' => 843831, '5' => 1202364, '8' => 1563851]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneSocSon->id, 'prices' => ['2.5' => 741281, '3.5' => 843831, '5' => 1202364, '8' => 1563851]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneLaiVu->id, 'prices' => ['2.5' => 741281, '3.5' => 843831, '5' => 1202364, '8' => 1563851]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneHoangMai->id, 'prices' => ['2.5' => 3552000, '3.5' => 5044970, '5' => 5852010, '8' => 7112040]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneKinhMon->id, 'prices' => ['2.5' => 719042, '3.5' => 818516, '5' => 1166293, '8' => 1516936]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneQuangNinh->id, 'prices' => ['2.5' => 1259060, '3.5' => 1445300, '5' => 2061250, '8' => 2630640]],
            ['from' => $zoneQueVo1->id, 'to' => $zoneDaiAn->id, 'prices' => ['2.5' => 741281, '3.5' => 843831, '5' => 1202364, '8' => 1563851]],
        ];

        foreach ($routes as $item) {
            $rate = FreightRate::query()->firstOrNew([
                'customer_id' => $vfcId,
                'origin_zone_id' => $item['from'],
                'destination_zone_id' => $item['to'],
                'cargo_type' => CargoType::Gcr,
                'rate_type' => '',
                'vehicle_type' => null,
            ]);
            $rate->prices = $item['prices'];
            $rate->save();
        }

        // 4. Gán BillingZone cho các địa điểm của VFC nếu đang để trống hoặc chưa chuẩn
        $locationMappings = [
            'CÔNG TY CP SX VÀ TM THÀNH CÔNG' => $zoneYenPhong->id,
            'AKZO' => $zoneQueVo1->id,
            'GLOBAL XINGFA' => $zoneCaoThang->id,
            'DAIKIN' => BillingZone::where('code', 'KCN-THANG-LONG-II')->value('id'),
            'T&C' => $zoneTamNong->id,
            'NHÔM ĐÔNG Á' => BillingZone::where('code', 'CUM-CN-HAI-PHONG')->value('id') ?? $zoneHaiPhong->id,
            'Phố nối' => $zoneHungYen->id,
            'Đồng Văn 1' => BillingZone::where('code', 'KCN-DONG-VAN-1')->value('id'),
            'Eurowindow' => BillingZone::where('code', 'KCN-QUANG-MINH')->value('id'),
            'TOP RUN' => $zoneHaiPhong->id,
            'CHÍNH XÁC' => BillingZone::where('code', 'KCN-KHAI-QUANG')->value('id'),
        ];

        foreach ($locationMappings as $nameKeyword => $zoneId) {
            if ($zoneId !== null) {
                Location::query()
                    ->where(function ($q) use ($nameKeyword) {
                        $q->where('code', 'like', "%{$nameKeyword}%")
                            ->orWhere('name', 'like', "%{$nameKeyword}%");
                    })
                    ->update(['billing_zone_id' => $zoneId]);
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

        $rates = FreightRate::where('customer_id', $vfc->id)->get();
        foreach ($rates as $rate) {
            $prices = $rate->prices ?? [];
            if (array_key_exists('2.5', $prices) || array_key_exists('3.5', $prices) || array_key_exists('5', $prices) || array_key_exists('8', $prices)) {
                $oldPrices = [];
                foreach ($prices as $k => $v) {
                    $oldKey = match ((string) $k) {
                        '2.5' => '2',
                        '3.5' => '3',
                        '5' => '4',
                        '8' => '7',
                        default => (string) $k,
                    };
                    $oldPrices[$oldKey] = $v;
                }
                $rate->prices = $oldPrices;
                $rate->save();
            }
        }
    }
};

