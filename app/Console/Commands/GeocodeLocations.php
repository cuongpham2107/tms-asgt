<?php

namespace App\Console\Commands;

use App\Models\Location;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class GeocodeLocations extends Command
{
    protected $signature = 'locations:geocode
        {--force : Geocode lại tất cả, kể cả đã có toạ độ}
        {--dry-run : Chỉ hiển thị địa chỉ sẽ geocode, không gửi request}';

    protected $description = 'Geocode địa chỉ locations sang lat/lng dùng Nominatim';

    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    private const RATE_LIMIT_US = 1_100_000;

    private array $cache = [];

    public function handle(): int
    {
        $query = Location::query();

        if (! $this->option('force')) {
            $query->whereNull('lat')->orWhereNull('lng');
        }

        $locations = $query->get();

        if ($locations->isEmpty()) {
            $this->info('Không có location nào cần geocode.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Đang geocode %d locations...', $locations->count()));
        $bar = $this->output->createProgressBar($locations->count());
        $bar->start();

        $success = 0;
        $failed = 0;

        foreach ($locations as $location) {
            $coords = null;
            $candidates = $this->getGeocodeCandidates($location->address);

            foreach ($candidates as $candidate) {
                $coords = $this->geocodeWithCache($candidate);
                if ($coords !== null) {
                    break;
                }
            }

            if ($coords !== null) {
                $location->updateQuietly([
                    'lat' => $coords['lat'],
                    'lng' => $coords['lng'],
                ]);
                $success++;
            } else {
                $this->newLine();
                $this->warn("Không tìm thấy toạ độ cho: {$location->name} — {$location->address}");
                $failed++;
            }

            $bar->advance();

            if (! $this->option('dry-run')) {
                usleep(self::RATE_LIMIT_US);
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Hoàn thành: {$success} thành công, {$failed} thất bại.");

        return self::SUCCESS;
    }

    private function geocodeWithCache(string $query): ?array
    {
        $key = md5($query);

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $result = $this->geocode($query);
        $this->cache[$key] = $result;

        return $result;
    }

    private function geocode(?string $query): ?array
    {
        if (empty($query)) {
            return null;
        }

        if ($this->option('dry-run')) {
            $this->line(" [DRY-RUN] {$query}");

            return null;
        }

        $response = Http::withHeaders([
            'User-Agent' => 'TMS-ASGT/1.0 (admin@tms-asgt.local)',
            'Accept' => 'application/json',
        ])->get(self::NOMINATIM_URL, [
            'q' => $query.', Vietnam',
            'format' => 'json',
            'limit' => 1,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        if (empty($data) || ! isset($data[0]['lat'], $data[0]['lon'])) {
            return null;
        }

        return [
            'lat' => (float) $data[0]['lat'],
            'lng' => (float) $data[0]['lon'],
        ];
    }

    private function getGeocodeCandidates(?string $address): array
    {
        if (empty($address)) {
            return [];
        }

        $candidates = [$address];

        // 1. Nếu có tên KCN/VSIP/Cụm CN, thêm phương án chỉ tìm theo tên KCN đó (không kèm tỉnh để tránh sai tỉnh trong data)
        $parkName = '';
        if (preg_match('/(?:KCN|Khu công nghiệp|Cụm CN|Cụm công nghiệp)\s+([^,]+)/ui', $address, $m)) {
            $parkName = trim($m[0]);
        }
        if (empty($parkName) && preg_match('/VSIP\s+([^,]+)/ui', $address, $m)) {
            $parkName = 'Khu công nghiệp '.trim($m[0]);
        }
        if (! empty($parkName)) {
            $parkNameClean = preg_replace('/\s+mở\s+rộng/i', '', $parkName);
            $candidates[] = $parkNameClean;
        }

        // 2. Làm sạch địa chỉ bằng cách loại bỏ chi tiết nhỏ (số nhà, ngõ, tổ...)
        $clean = $address;
        $clean = preg_replace('/^(?:số|no\.?|ki\s*ốt|kốt|cửa\s*hàng|quầy|phòng|tầng|nhà|lô|kho|xưởng|nhà\s*xưởng)\s+\w+(?:\/\w+)*\s*,?\s*/ui', '', $clean);
        $clean = preg_replace('/\b(?:ngõ|ngách|hẻm|kiệt)\s+\w+(?:\/\w+)*\s*,?\s*/ui', '', $clean);
        $clean = preg_replace('/\b(?:tổ|xóm|ấp|thôn|tổ\s*dân\s*phố)\s+\w+\s*,?\s*/ui', '', $clean);
        $clean = preg_replace('/\b(?:xã|phường|thị\s*trấn)\s+/ui', '', $clean); // Bỏ từ "Xã", "Phường" để dễ khớp
        $clean = trim($clean);

        if ($clean !== $address && ! empty($clean)) {
            $candidates[] = $clean;
        }

        // 3. Rút gọn bằng cách loại bỏ dần phần đầu trước dấu phẩy
        $parts = array_map('trim', explode(',', $address));
        while (count($parts) > 1) {
            array_shift($parts);
            $candidates[] = implode(', ', $parts);
        }

        // 4. Thử tương tự với địa chỉ đã làm sạch (bỏ dần phần đầu trước dấu phẩy)
        if ($clean !== $address && ! empty($clean)) {
            $cleanParts = array_map('trim', explode(',', $clean));
            while (count($cleanParts) > 1) {
                array_shift($cleanParts);
                $candidates[] = implode(', ', $cleanParts);
            }
        }

        // 5. Thêm các thành phần riêng lẻ có chứa tên đường/phố/xã/huyện/tỉnh
        $allParts = array_map('trim', explode(',', $address));
        foreach ($allParts as $part) {
            if (preg_match('/^(?:đường|phố|xã|phường|thị\s*trấn|quận|huyện|thị\s*xã)\s+/ui', $part)) {
                $candidates[] = $part;
                $candidates[] = preg_replace('/^(?:đường|phố|xã|phường|thị\s*trấn|quận|huyện|thị\s*xã)\s+/ui', '', $part);
            }
        }

        // Thêm fallback tỉnh/thành phố cuối cùng
        $province = $this->extractProvince($address);
        if ($province) {
            $candidates[] = $province;
        }

        // Loại bỏ trùng lặp và giá trị trống
        return array_values(array_unique(array_filter($candidates)));
    }

    private function extractProvince(string $address): ?string
    {
        if (preg_match('/(Tỉnh\s+\S+(?:\s+\S+)?)$/ui', $address, $m)) {
            $province = trim($m[1]);

            return str_replace('Tỉnh ', '', $province);
        }

        $provinceNames = [
            '/\bHà Nội\b/' => 'Hà Nội',
            '/\bHải Phòng\b/' => 'Hải Phòng',
            '/\bĐà Nẵng\b/' => 'Đà Nẵng',
            '/\bBắc Ninh\b/' => 'Bắc Ninh',
            '/\bThái Nguyên\b/' => 'Thái Nguyên',
            '/\bHưng Yên\b/' => 'Hưng Yên',
            '/\bNinh Bình\b/' => 'Ninh Bình',
            '/\bPhú Thọ\b/' => 'Phú Thọ',
            '/\bQuảng Ninh\b/' => 'Quảng Ninh',
            '/\bVĩnh Phúc\b/' => 'Vĩnh Phúc',
            '/\bBắc Giang\b/' => 'Bắc Giang',
            '/\bHà Nam\b/' => 'Hà Nam',
            '/\bHải Dương\b/' => 'Hải Dương',
        ];

        foreach ($provinceNames as $pattern => $name) {
            if (preg_match($pattern, $address)) {
                return $name;
            }
        }

        return null;
    }
}
