<?php

namespace App\Console\Commands;

use App\Enums\FeeCalcMethod;
use App\Models\Customer;
use App\Models\FeeType;
use Illuminate\Console\Command;

/**
 * Tạo phí riêng mẫu cho khách theo bảng phụ phí sếp gửi (CLC, SDS HN, SDS HCM, SAFO).
 * Chạy lại được: tìm theo khách + tên phí rồi cập nhật, không tạo trùng.
 */
class SeedSampleFeeRulesCommand extends Command
{
    protected $signature = 'fee-types:seed-samples {--dry-run : Chỉ in ra, không ghi DB}';

    protected $description = 'Tạo phí riêng mẫu (tự tính) cho CLC, SDS HN, SDS HCM, SAFO';

    public function handle(): int
    {
        $rows = [];

        foreach ($this->samples() as $sample) {
            $customer = Customer::where('code', $sample['customer'])->first();
            if ($customer === null) {
                $rows[] = [$sample['customer'], $sample['name'], '—', 'Bỏ qua: không có khách '.$sample['customer']];

                continue;
            }

            $exists = FeeType::where('customer_id', $customer->id)->where('name', $sample['name'])->exists();
            if (! $this->option('dry-run')) {
                FeeType::updateOrCreate(['customer_id' => $customer->id, 'name' => $sample['name']], [
                    'code' => $sample['code'],
                    'calc_method' => $sample['method'],
                    'amount' => $sample['amount'],
                    'auto_apply' => $sample['auto_apply'] ?? false,
                    'description' => $sample['description'] ?? null,
                    'sort' => $sample['sort'],
                    'is_active' => true,
                ]);
            }

            $price = $sample['method']->isAuto() ? number_format($sample['amount'], 0, ',', '.').' '.$sample['method']->unit() : 'nhập tay';
            $rows[] = [$customer->code, $sample['name'], $price.(($sample['auto_apply'] ?? false) ? ' · tự cộng mọi đơn' : ''), $exists ? 'Cập nhật' : 'Tạo mới'];
        }

        $this->table(['Khách', 'Phí', 'Đơn giá', 'Kết quả'], $rows);

        if ($this->option('dry-run')) {
            $this->warn('Chạy thử (--dry-run): chưa ghi gì vào DB.');
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{customer: string, code: string, name: string, method: FeeCalcMethod, amount: float, sort: int, auto_apply?: bool, description?: string}>
     */
    private function samples(): array
    {
        return [
            ['customer' => 'CLC', 'code' => 'inspection', 'name' => 'Chi phí kiểm hóa', 'method' => FeeCalcMethod::Fixed, 'amount' => 300_000, 'sort' => 101,
                'description' => 'Hàng kiểm hóa'],
            ['customer' => 'CLC', 'code' => 'dunnage', 'name' => 'Chi phí thuê chèn (Fukang)', 'method' => FeeCalcMethod::Fixed, 'amount' => 80_000, 'sort' => 102,
                'description' => 'Đóng hoặc trả hàng tại nhà máy Fukang'],
            ['customer' => 'SDS HN', 'code' => 'loading_unloading', 'name' => 'Chi phí bốc xếp', 'method' => FeeCalcMethod::PerPackage, 'amount' => 3_750, 'sort' => 111,
                'auto_apply' => true],
            ['customer' => 'SDS HCM', 'code' => 'hauling', 'name' => 'Chi phí kéo hàng', 'method' => FeeCalcMethod::PerPallet, 'amount' => 30_000, 'sort' => 121],
            ['customer' => 'SDS HCM', 'code' => 'forklift', 'name' => 'Chi phí nâng xe', 'method' => FeeCalcMethod::PerPallet, 'amount' => 10_000, 'sort' => 122],
            ['customer' => 'ASGS (SAFO)', 'code' => 'extra_drop', 'name' => 'Rớt điểm trong khu Sân bay Nội Bài', 'method' => FeeCalcMethod::PerExtraDrop, 'amount' => 50_000, 'sort' => 131,
                'description' => 'Block 5 km trong khu sân bay'],
            ['customer' => 'ASGS (SAFO)', 'code' => 'extra_drop', 'name' => 'Rớt điểm ngoài khu Sân bay Nội Bài', 'method' => FeeCalcMethod::PerExtraDrop, 'amount' => 100_000, 'sort' => 132,
                'description' => 'Block 5 km ngoài khu sân bay'],
            ['customer' => 'ASGS (SAFO)', 'code' => 'extra_drop', 'name' => 'Rớt điểm ALSE', 'method' => FeeCalcMethod::PerExtraDrop, 'amount' => 400_000, 'sort' => 133,
                'description' => 'Fukang/Newing đến ALSE sau đó trả NBA'],
            ['customer' => 'ASGS (SAFO)', 'code' => 'extra_drop', 'name' => 'Rớt điểm SUPERPORT', 'method' => FeeCalcMethod::PerExtraDrop, 'amount' => 500_000, 'sort' => 134,
                'description' => 'Fukang/Newing về SUPERPORT về NBA'],
            ['customer' => 'ASGS (SAFO)', 'code' => 'other', 'name' => 'Quay lại ALSE (50% cước chính)', 'method' => FeeCalcMethod::PercentOfBase, 'amount' => 50, 'sort' => 135,
                'description' => 'Từ Fukang/Newing về NBA hoặc SUPERPORT rồi quay lại ALSE'],
        ];
    }
}
