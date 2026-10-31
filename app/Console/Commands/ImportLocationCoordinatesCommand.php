<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Services\Trip\TripCheckpointOsmValidationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

#[Signature('locations:import-coordinates
    {file? : File Excel 2 sheet location (mặc định data/dia-diem-giao-nhan.xlsx)}
    {--apply : Ghi toạ độ vào bảng locations (mặc định chỉ xem trước)}
    {--skip= : Các mã bỏ qua, cách nhau bằng dấu phẩy (ví dụ: ITL,DHL)}')]
#[Description('Cập nhật toạ độ location từ link Google Maps trong 2 sheet DATA location HHHK và Data locationHàng ngoài')]
class ImportLocationCoordinatesCommand extends Command
{
    /**
     * Sheet → tiêu đề cột mã kho. Cột link là cột có tiêu đề bắt đầu bằng "Link".
     */
    private const DEFAULT_FILE = 'data/dia-diem-giao-nhan.xlsx';

    private const SHEETS = [
        'DATA location HHHK' => 'Địa điểm viết tắt',
        'Data locationHàng ngoài' => 'Mã viết tắt nhà máy',
    ];

    public function handle(TripCheckpointOsmValidationService $geo): int
    {
        $file = $this->argument('file') ?: base_path(self::DEFAULT_FILE);
        if (! is_file($file)) {
            $this->error("Không tìm thấy file: {$file}");

            return self::FAILURE;
        }

        $book = IOFactory::load($file);
        $skip = collect(explode(',', (string) $this->option('skip')))->map(fn (string $code): string => $this->normalize($code))->filter();

        $entries = collect(self::SHEETS)
            ->flatMap(fn (string $codeHeader, string $title): array => $book->getSheetByName($title) ? $this->readSheet($book->getSheetByName($title), $codeHeader) : [])
            ->reject(fn (array $entry): bool => $skip->contains($entry['key']));

        $problems = [];
        $sharedLinks = $entries->groupBy('link')->filter(fn (Collection $group): bool => $group->pluck('key')->unique()->count() > 1);
        foreach ($sharedLinks as $link => $group) {
            $problems[] = ['Nhiều mã dùng chung 1 link', $group->pluck('code')->unique()->implode(', '), $link];
        }

        $locationsByCode = Location::query()->whereNotNull('code')->get()->groupBy(fn (Location $location): string => $this->normalize($location->code));
        $rows = [];

        foreach ($entries->reject(fn (array $entry): bool => $sharedLinks->has($entry['link']))->groupBy('key') as $key => $group) {
            $code = $group->first()['code'];

            if ($group->pluck('link')->unique()->count() > 1) {
                $problems[] = ['1 mã có nhiều link khác nhau', $code, $group->pluck('link')->unique()->implode(' | ')];

                continue;
            }

            $locations = $locationsByCode->get($key);
            if ($locations === null) {
                $problems[] = ['Mã không có trong hệ thống', $code, $group->first()['link']];

                continue;
            }

            $coordinates = $this->coordinatesFromLink($group->first()['link']);
            if ($coordinates === null) {
                $problems[] = ['Không đọc được toạ độ từ link', $code, $group->first()['link']];

                continue;
            }

            foreach ($locations as $location) {
                $shiftM = $location->lat !== null && $location->lng !== null
                    ? (int) round($geo->calculateHaversineKm((float) $location->lat, (float) $location->lng, $coordinates[0], $coordinates[1]) * 1000)
                    : null;

                $rows[] = [
                    $location->id,
                    $location->code,
                    $location->lat !== null ? "{$location->lat}, {$location->lng}" : '—',
                    "{$coordinates[0]}, {$coordinates[1]}",
                    $shiftM !== null ? number_format($shiftM, 0, ',', '.').' m' : '—',
                ];

                if ($this->option('apply')) {
                    $location->update(['lat' => $coordinates[0], 'lng' => $coordinates[1]]);
                }
            }
        }

        $this->table(['ID', 'Mã', 'Toạ độ hiện tại', 'Toạ độ từ file', 'Lệch'], $rows);

        if ($problems !== []) {
            $this->warn('Bỏ qua '.count($problems).' trường hợp:');
            $this->table(['Lý do', 'Mã', 'Link'], $problems);
        }

        $this->info(($this->option('apply') ? 'Đã cập nhật ' : 'Sẽ cập nhật ').count($rows).' location.'
            .($this->option('apply') ? '' : ' Chạy lại với --apply để ghi.'));

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{code: string, key: string, link: string}>
     */
    private function readSheet(Worksheet $sheet, string $codeHeader): array
    {
        $header = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1', null, true, false)[0];
        $codeColumn = array_search($codeHeader, array_map(fn ($value): string => trim((string) $value), $header), true);
        $linkColumn = collect($header)->search(fn ($value): bool => str_starts_with(mb_strtolower(trim((string) $value)), 'link'));

        if ($codeColumn === false || $linkColumn === false) {
            $this->warn("Sheet \"{$sheet->getTitle()}\" thiếu cột \"{$codeHeader}\" hoặc cột link.");

            return [];
        }

        $entries = [];
        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $code = trim((string) $sheet->getCell([$codeColumn + 1, $row])->getValue());
            $cell = $sheet->getCell([$linkColumn + 1, $row]);
            $link = trim((string) ($cell->hasHyperlink() ? $cell->getHyperlink()->getUrl() : '') ?: (string) $cell->getValue());

            if ($code !== '' && $link !== '') {
                $entries[] = ['code' => $code, 'key' => $this->normalize($code), 'link' => $link];
            }
        }

        return $entries;
    }

    /**
     * Toạ độ trong link Google Maps; link rút gọn (maps.app.goo.gl) được mở để lấy link đầy đủ.
     *
     * @return array{0: float, 1: float}|null
     */
    private function coordinatesFromLink(string $link): ?array
    {
        $coordinates = $this->parseCoordinates($link);
        if ($coordinates !== null) {
            return $coordinates;
        }

        try {
            $location = Http::timeout(10)->withoutRedirecting()->get($link)->header('Location');
        } catch (\Throwable) {
            return null;
        }

        return $location !== '' ? $this->parseCoordinates(urldecode($location)) : null;
    }

    /**
     * Ưu tiên toạ độ chính xác của điểm (!3d…!4d…), rồi đến link tìm theo toạ độ, cuối cùng là tâm bản đồ (@lat,lng).
     *
     * @return array{0: float, 1: float}|null
     */
    private function parseCoordinates(string $url): ?array
    {
        foreach ([
            '/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/',
            '#/search/(-?\d+\.\d+),\s*\+?(-?\d+\.\d+)#',
            '/[?&]q=(-?\d+\.\d+),\s*\+?(-?\d+\.\d+)/',
            '/@(-?\d+\.\d+),(-?\d+\.\d+)/',
        ] as $pattern) {
            if (preg_match($pattern, $url, $match)) {
                return [round((float) $match[1], 7), round((float) $match[2], 7)];
            }
        }

        return null;
    }

    private function normalize(?string $code): string
    {
        return mb_strtoupper(trim((string) $code));
    }
}
