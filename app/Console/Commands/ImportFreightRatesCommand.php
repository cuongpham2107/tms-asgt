<?php

namespace App\Console\Commands;

use App\Services\Billing\FreightRateExcelImporter;
use Illuminate\Console\Command;
use Normalizer;

class ImportFreightRatesCommand extends Command
{
    private const DEFAULT_FILE = 'docs/QUY TRÌNH THỰC HIỆN SỐ LIỆU.xlsx';

    protected $signature = 'freight-rates:import
        {--file= : Đường dẫn file Excel bảng giá (mặc định "docs/QUY TRÌNH THỰC HIỆN SỐ LIỆU.xlsx")}
        {--dry-run : Chỉ phân tích và báo cáo, không ghi vào database}';

    protected $description = 'Nhập đơn giá vận chuyển và phí giờ chờ từ file Excel bảng giá (sheet 3.1–3.4, 4, 6.1, 6.2)';

    public function handle(FreightRateExcelImporter $importer): int
    {
        ini_set('memory_limit', '1024M');

        $path = $this->resolvePath();
        if ($path === null) {
            $this->error('Không tìm thấy file Excel: '.($this->option('file') ?: base_path(self::DEFAULT_FILE)));

            return self::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $this->info(($isDryRun ? '[DRY RUN] ' : '')."Đang đọc {$path}");

        $report = $importer->import($path, $isDryRun);

        $this->table(['Hạng mục', 'Số dòng'], [
            ['Đơn giá vận chuyển', $report['freight_rates']],
            ['Phí giờ chờ', $report['waiting_fees']],
            ['Địa chỉ chưa có trong danh mục địa điểm', count($report['missing_locations'])],
        ]);

        if ($report['missing_locations'] !== []) {
            $this->warn('Các địa chỉ dưới đây chưa khớp địa điểm nào (sheet 5 cột I → mã cột C → locations.code), dòng giá tương ứng đã bỏ qua:');
            $this->table(
                ['Địa chỉ trong bảng giá', 'Số dòng giá', 'Ví dụ'],
                collect($report['missing_locations'])
                    ->sortByDesc(fn (array $sources): int => count($sources))
                    ->map(fn (array $sources, string $address): array => [$address, count($sources), $sources[0]])
                    ->values()
                    ->all(),
            );
        }

        foreach ($report['warnings'] as $warning) {
            $this->line("  ⚠ {$warning}");
        }

        $this->info($isDryRun ? 'Dry run xong, chưa ghi gì vào database.' : 'Đã nhập xong bảng giá.');

        return self::SUCCESS;
    }

    private function resolvePath(): ?string
    {
        $path = $this->option('file') ?: base_path(self::DEFAULT_FILE);

        // Tên file tiếng Việt trên macOS có thể ở dạng NFD.
        foreach ([$path, Normalizer::normalize($path, Normalizer::FORM_C), Normalizer::normalize($path, Normalizer::FORM_D)] as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
