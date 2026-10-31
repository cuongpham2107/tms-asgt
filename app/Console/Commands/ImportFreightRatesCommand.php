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

    protected $description = 'Tạo khu vực tính cước, gán địa điểm và nhập đơn giá vận chuyển, phí giờ chờ từ file Excel bảng giá (sheet 3.1–3.4, 4, 6.1, 6.2)';

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
            ['Khu vực tính cước (tạo mới)', "{$report['zones']} ({$report['zones_created']})"],
            ['Địa điểm được gán khu vực (sheet 5)', $report['locations_assigned']],
            ['Đơn giá vận chuyển', $report['freight_rates']],
            ['Phí giờ chờ', $report['waiting_fees']],
            ['Khu vực có giá nhưng chưa có địa điểm nào', count($report['zones_without_locations'])],
        ]);

        if ($report['zones_without_locations'] !== []) {
            $this->warn('Các khu vực dưới đây đã có giá nhưng chưa địa điểm nào thuộc khu vực — thêm địa điểm vào khu vực thì đơn mới tra được giá:');
            $this->table(
                ['Khu vực', 'Số dòng giá', 'Ví dụ'],
                collect($report['zones_without_locations'])
                    ->sortByDesc(fn (array $sources): int => count($sources))
                    ->map(fn (array $sources, string $zone): array => [$zone, count($sources), $sources[0]])
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
