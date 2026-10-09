<?php

namespace App\Console\Commands;

use App\Enums\DriverWorkShift;
use App\Models\DriverShift;
use App\Models\DriverSwap;
use App\Models\OvertimeRegistration;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Permission\Models\Role;

class ImportDriversCommand extends Command
{
    protected $signature = 'drivers:import
        {--file= : Đường dẫn file Excel (mặc định "DS Nhân sự.xlsx")}
        {--dry-run : Chỉ phân tích và hiển thị kết quả, không ghi vào database}
        {--keep-old : Không xoá tài xế cũ, chỉ cập nhật/thêm mới}';

    protected $description = 'Xoá danh sách tài xế cũ và import danh sách tài xế mới từ file Excel';

    /**
     * Danh sách email đặc biệt tuyệt đối không được xoá
     *
     * @var array<int, string>
     */
    protected array $protectedEmails = [
        'admin@admin.com',
        'hkt@asgt.vn', // cuongpham
    ];

    public function handle(): int
    {
        $filePath = $this->option('file');

        if (! $filePath) {
            $candidates = [
                base_path('data/8.10 DS Nhân sự.xlsx'),
                base_path('data/8.10 DS Nhân sự.xlsx'),
                base_path('data/DS Nhân sự.xlsx'),
                base_path('data/DS Nhân sự.xlsx'),
                base_path('DS Nhân sự.xlsx'),
                class_exists(\Normalizer::class) ? base_path(\Normalizer::normalize('data/8.10 DS Nhân sự.xlsx', \Normalizer::FORM_D)) : '',
                class_exists(\Normalizer::class) ? base_path(\Normalizer::normalize('data/8.10 DS Nhân sự.xlsx', \Normalizer::FORM_C)) : '',
                class_exists(\Normalizer::class) ? base_path(\Normalizer::normalize('DS Nhân sự.xlsx', \Normalizer::FORM_D)) : '',
            ];
            foreach (array_filter($candidates) as $candidate) {
                if (file_exists($candidate)) {
                    $filePath = $candidate;
                    break;
                }
            }
            if (! $filePath) {
                $globbed = glob(base_path('data/*8.10*Nha*su*.xlsx'))
                    ?: glob(base_path('data/*Nha*su*.xlsx'))
                    ?: glob(base_path('*Nha*su*.xlsx'))
                    ?: glob(base_path('*Nhan*su*.xlsx'));
                if (! empty($globbed)) {
                    $filePath = $globbed[0];
                }
            }
            $filePath = $filePath ?: base_path('data/8.10 DS Nhân sự.xlsx');
        }
        $isDryRun = (bool) $this->option('dry-run');
        $keepOld = (bool) $this->option('keep-old');

        if (! file_exists($filePath)) {
            $this->error("Không tìm thấy file Excel tại đường dẫn: {$filePath}");

            return self::FAILURE;
        }

        $this->info("Bắt đầu xử lý file Excel: {$filePath}");
        if ($isDryRun) {
            $this->warn('--- CHẾ ĐỘ DRY-RUN: Không có dữ liệu nào bị thay đổi ---');
        }

        // 1. Đọc file Excel
        $spreadsheet = IOFactory::load($filePath);
        $sheet1 = $spreadsheet->getSheetByName('Gốc')
            ?? $spreadsheet->getSheetByName('Sheet1')
            ?? $spreadsheet->getSheet(0);
        $sheet2 = $spreadsheet->getSheetByName('Sheet2')
            ?? ($spreadsheet->getSheetCount() > 1 ? $spreadsheet->getSheet(1) : null);

        if (! $sheet1) {
            $this->error('File Excel không tìm thấy Sheet hồ sơ (Gốc hoặc Sheet1).');

            return self::FAILURE;
        }

        // 2. Parse Sheet2 (Phân xe & Ca trực)
        $sheet2Data = $this->parseSheet2($sheet2);
        $this->info(sprintf('Sheet2: Đã tải thông tin phân xe của %d tài xế.', count($sheet2Data['by_code'])));

        // 3. Parse Sheet1 (Thông tin hồ sơ tài xế)
        $drivers = $this->parseSheet1($sheet1, $sheet2Data);
        $this->info(sprintf('Sheet1: Đã bóc tách thành công %d hồ sơ tài xế.', count($drivers)));

        if (empty($drivers)) {
            $this->error('Không tìm thấy dữ liệu tài xế nào để import.');

            return self::FAILURE;
        }

        // 4. Kiểm tra xe chưa có trong hệ thống và chuẩn bị
        $missingVehicles = $this->findMissingVehicles($drivers);
        if (! empty($missingVehicles)) {
            $this->warn(sprintf('Phát hiện %d xe chưa có trong bảng vehicles: %s', count($missingVehicles), implode(', ', array_keys($missingVehicles))));
        }

        if ($isDryRun) {
            $this->displaySummary($drivers, $missingVehicles, $keepOld);

            return self::SUCCESS;
        }

        // 5. Thực thi Transaction
        DB::beginTransaction();
        try {
            // A. Tạo các xe còn thiếu trong bảng vehicles
            foreach ($missingVehicles as $cleanPlate => $vInfo) {
                Vehicle::query()->firstOrCreate(
                    ['plate_number' => $cleanPlate],
                    [
                        'owner' => 'ASGT',
                        'vehicle_type' => 'normal',
                        'load_capacity' => $vInfo['capacity'],
                        'is_active' => true,
                        'status' => 'on',
                        'type' => 'company',
                    ]
                );
                $this->info("Đã tạo mới xe: {$cleanPlate} (Tải trọng: {$vInfo['capacity']}T)");
            }

            // B. Xoá tài xế cũ (nếu không bật keep-old)
            if (! $keepOld) {
                $this->cleanupOldDrivers();
            }

            // C. Đảm bảo role 'driver' tồn tại
            $driverRole = Role::findOrCreate('driver', 'web');

            // D. Insert các tài xế mới
            $plateEvenDrivers = [];    // Map plate_number => driver_id
            $plateOddDrivers = [];     // Map plate_number => driver_id
            $platePrimaryDrivers = []; // Map plate_number => driver_id (ưu tiên ca chẵn hoặc tài xế đầu tiên)
            $now = now();
            $defaultPassword = Hash::make('66668888');

            $importedCount = 0;
            foreach ($drivers as $d) {
                $workShift = null;
                if (! empty($d['is_even_shift'])) {
                    $workShift = DriverWorkShift::Even->value;
                } elseif (! empty($d['is_odd_shift'])) {
                    $workShift = DriverWorkShift::Odd->value;
                }

                $cleanPlate = $d['plate_clean'] ?? null;
                $vehicle = $cleanPlate ? Vehicle::query()->where('plate_number', $cleanPlate)->first() : null;

                $user = User::query()->create([
                    'name' => $d['name'],
                    'email' => $d['email'],
                    'password' => $defaultPassword,
                    'phone' => $d['phone'],
                    'date_of_birth' => $d['dob'],
                    'cccd' => $d['cccd'],
                    'cccd_issue_date' => $d['cccd_issue_date'],
                    'address' => $d['address'],
                    'station' => $d['station'],
                    'license_class' => $d['license_class'],
                    'license_number' => $d['license_number'],
                    'license_issue_date' => $d['license_issue_date'],
                    'license_expiry_date' => $d['license_expiry_date'],
                    'work_shift' => $workShift,
                    'vehicle_id' => $vehicle?->id,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // Gán role 'driver'
                $user->assignRole($driverRole);

                // Lưu tài xế gán xe
                if (! empty($cleanPlate)) {
                    if (! empty($d['is_even_shift'])) {
                        $plateEvenDrivers[$cleanPlate] = $user->id;
                    }
                    if (! empty($d['is_odd_shift'])) {
                        $plateOddDrivers[$cleanPlate] = $user->id;
                    }

                    // Ưu tiên ca chẵn hoặc tài xế đầu tiên làm current_driver_id
                    if (! isset($platePrimaryDrivers[$cleanPlate]) || $d['is_even_shift']) {
                        $platePrimaryDrivers[$cleanPlate] = $user->id;
                    }
                }

                $importedCount++;
            }

            // E. Cập nhật even_driver_id, odd_driver_id, current_driver_id trên bảng vehicles
            $vehicleAssignedCount = 0;
            $allPlates = array_unique(array_merge(
                array_keys($plateEvenDrivers),
                array_keys($plateOddDrivers),
                array_keys($platePrimaryDrivers)
            ));

            foreach ($allPlates as $cleanPlate) {
                $evenId = $plateEvenDrivers[$cleanPlate] ?? null;
                $oddId = $plateOddDrivers[$cleanPlate] ?? null;
                $primaryId = $platePrimaryDrivers[$cleanPlate] ?? $evenId ?? $oddId;

                $affected = Vehicle::query()
                    ->where('plate_number', $cleanPlate)
                    ->update([
                        'even_driver_id' => $evenId,
                        'odd_driver_id' => $oddId,
                        'current_driver_id' => $primaryId,
                    ]);

                if ($affected > 0) {
                    $vehicleAssignedCount++;
                }
            }

            DB::commit();

            $this->newLine();
            $this->info('====================================================');
            $this->info('   IMPORT TÀI XẾ MỚI THÀNH CÔNG VÀO DATABASE!       ');
            $this->info('====================================================');
            $this->table(
                ['Hạng mục', 'Số lượng'],
                [
                    ['Số tài xế mới đã tạo', $importedCount],
                    ['Số xe được gán tài xế (2 lái / xe)', $vehicleAssignedCount],
                    ['Mật khẩu mặc định', '66668888'],
                    ['Định dạng email', '[chữ_cái_đầu][ngày_tháng_sinh]@tms.local'],
                ]
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Lỗi trong quá trình import: '.$e->getMessage());
            $this->error($e->getTraceAsString());

            return self::FAILURE;
        }
    }

    /**
     * Bóc tách dữ liệu Sheet 2 (Phân xe & Ca trực)
     *
     * @return array{by_code: array<string, array>, by_name: array<string, array>}
     */
    private function parseSheet2(?Worksheet $sheet): array
    {
        $byCode = [];
        $byName = [];

        if (! $sheet) {
            return ['by_code' => $byCode, 'by_name' => $byName];
        }

        $highestRow = $sheet->getHighestRow();

        for ($r = 2; $r <= $highestRow; $r++) {
            $code = trim((string) $sheet->getCell("C{$r}")->getValue());
            $name = trim((string) $sheet->getCell("D{$r}")->getValue());
            $plate = trim((string) $sheet->getCell("E{$r}")->getValue());
            $capacity = trim((string) $sheet->getCell("F{$r}")->getValue());
            $station = trim((string) $sheet->getCell("G{$r}")->getValue());
            $evenShift = strtoupper(trim((string) $sheet->getCell("H{$r}")->getValue())) === 'X';
            $oddShift = strtoupper(trim((string) $sheet->getCell("I{$r}")->getValue())) === 'X';

            if ($code === '' && $name === '') {
                continue;
            }

            // Chuẩn hoá biển số (bỏ 'Lái mới')
            $cleanPlate = null;
            if ($plate !== '' && ! str_contains(mb_strtolower($plate), 'lái mới')) {
                $cleanPlate = preg_replace('/[^A-Za-z0-9]/', '', $plate);
            }

            // Chuẩn hoá trạm trực
            $stationMapped = match ($station) {
                'NB', 'NBA' => 'NBA',
                'BN' => 'BN',
                'TN' => 'TN',
                default => null,
            };

            $record = [
                'code' => $code,
                'name' => $name,
                'plate_raw' => $plate,
                'plate_clean' => $cleanPlate,
                'capacity' => $capacity,
                'station' => $stationMapped,
                'is_even_shift' => $evenShift,
                'is_odd_shift' => $oddShift,
            ];

            if ($code !== '') {
                $byCode[$code] = $record;
            }
            if ($name !== '') {
                $byName[mb_strtolower($name)] = $record;
            }
        }

        return ['by_code' => $byCode, 'by_name' => $byName];
    }

    /**
     * Bóc tách dữ liệu Sheet 1 (Hồ sơ tài xế)
     *
     * @param  array{by_code: array<string, array>, by_name: array<string, array>}  $sheet2Data
     * @return array<int, array<string, mixed>>
     */
    private function parseSheet1(Worksheet $sheet, array $sheet2Data): array
    {
        $drivers = [];
        $seenEmails = [];
        $highestRow = $sheet->getHighestRow();

        for ($r = 2; $r <= $highestRow; $r++) {
            $code = trim((string) $sheet->getCell("B{$r}")->getValue());
            $name = trim((string) $sheet->getCell("C{$r}")->getValue());

            if ($name === '') {
                continue;
            }

            // Khớp với Sheet2 để lấy phân xe / ca trực
            $s2Info = $sheet2Data['by_code'][$code] ?? $sheet2Data['by_name'][mb_strtolower($name)] ?? null;

            // Điểm trực
            $calcStation = trim((string) $sheet->getCell("F{$r}")->getCalculatedValue());
            $station = match ($calcStation) {
                'NB', 'NBA' => 'NBA',
                'BN' => 'BN',
                'TN' => 'TN',
                default => $s2Info['station'] ?? null,
            };

            // Phân xe
            $calcPlate = trim((string) $sheet->getCell("D{$r}")->getCalculatedValue());
            $cleanPlate = null;
            if ($calcPlate !== '' && $calcPlate !== '#N/A' && ! str_contains(mb_strtolower($calcPlate), 'lái mới')) {
                $cleanPlate = preg_replace('/[^A-Za-z0-9]/', '', $calcPlate);
            } elseif ($s2Info && ! empty($s2Info['plate_clean'])) {
                $cleanPlate = $s2Info['plate_clean'];
            }

            // Ca chẵn / lẻ
            $evenShift = $s2Info['is_even_shift'] ?? (strtoupper(trim((string) $sheet->getCell("G{$r}")->getCalculatedValue())) === 'X');
            $oddShift = $s2Info['is_odd_shift'] ?? (strtoupper(trim((string) $sheet->getCell("H{$r}")->getCalculatedValue())) === 'X');

            // Ngày tháng
            $dob = $this->parseExcelDate($sheet->getCell("J{$r}")->getValue());
            $cccd = preg_replace('/\D/', '', trim((string) $sheet->getCell("K{$r}")->getValue()));
            $cccdIssue = $this->parseExcelDate($sheet->getCell("L{$r}")->getValue());
            $address = trim((string) $sheet->getCell("N{$r}")->getValue()) ?: null;

            // Số điện thoại
            $phone = preg_replace('/\D/', '', trim((string) $sheet->getCell("O{$r}")->getValue()));
            if (str_starts_with($phone, '84') && strlen($phone) >= 11) {
                $phone = '0'.substr($phone, 2);
            }

            // Hạng GPLX
            $rawLicenseClass = trim((string) $sheet->getCell("P{$r}")->getValue());
            $licenseClass = $this->parseLicenseClass($rawLicenseClass);

            // Số GPLX & Ngày cấp / hết hạn
            $licenseNumber = trim((string) $sheet->getCell("Q{$r}")->getValue()) ?: null;
            if ($licenseNumber !== null) {
                // Xử lý khoa học nếu có
                if (stripos($licenseNumber, 'E') !== false) {
                    $licenseNumber = number_format((float) $licenseNumber, 0, '', '');
                }
            }
            $licenseIssue = $this->parseExcelDate($sheet->getCell("R{$r}")->getValue());
            $licenseExpiry = $this->parseExcelDate($sheet->getCell("S{$r}")->getValue());

            // Sinh email duy nhất
            $email = $this->generateUniqueEmail($name, $dob, $seenEmails);
            $seenEmails[$email] = true;

            $drivers[] = [
                'row' => $r,
                'code' => $code,
                'name' => $name,
                'email' => $email,
                'phone' => $phone ?: null,
                'station' => $station,
                'plate_clean' => $cleanPlate,
                'capacity_raw' => $s2Info['capacity'] ?? trim((string) $sheet->getCell("E{$r}")->getCalculatedValue()),
                'is_even_shift' => $evenShift,
                'is_odd_shift' => $oddShift,
                'dob' => $dob,
                'cccd' => $cccd ?: null,
                'cccd_issue_date' => $cccdIssue,
                'address' => $address,
                'license_class' => $licenseClass,
                'license_number' => $licenseNumber,
                'license_issue_date' => $licenseIssue,
                'license_expiry_date' => $licenseExpiry,
            ];
        }

        return $drivers;
    }

    /**
     * Tìm các xe chưa tồn tại trong bảng vehicles
     *
     * @param  array<int, array<string, mixed>>  $drivers
     * @return array<string, array{capacity: float}>
     */
    private function findMissingVehicles(array $drivers): array
    {
        $plates = [];
        foreach ($drivers as $d) {
            if (! empty($d['plate_clean'])) {
                $cap = 7.0;
                if (! empty($d['capacity_raw'])) {
                    if (preg_match('/(\d+(\.\d+)?)/', $d['capacity_raw'], $m)) {
                        $cap = (float) $m[1];
                    }
                }
                $plates[$d['plate_clean']] = ['capacity' => $cap];
            }
        }

        $existingPlates = Vehicle::query()
            ->whereIn('plate_number', array_keys($plates))
            ->pluck('plate_number')
            ->all();

        $missing = [];
        foreach ($plates as $plate => $info) {
            if (! in_array($plate, $existingPlates, true)) {
                $missing[$plate] = $info;
            }
        }

        return $missing;
    }

    /**
     * Dọn dẹp tài xế cũ và xử lý khoá ngoại
     */
    private function cleanupOldDrivers(): void
    {
        // 1. Lấy danh sách ID tài xế cần xoá
        // Loại trừ tuyệt đối các tài khoản trong protectedEmails và các tài khoản có role khác driver
        $driversToDeleteQuery = User::query()
            ->where(function ($q) {
                $q->where('email', 'like', '%@tms.local')
                    ->orWhereHas('roles', fn ($rq) => $rq->where('name', 'driver'));
            })
            ->whereNotIn('email', $this->protectedEmails);

        $driverIds = $driversToDeleteQuery->pluck('id')->all();

        if (empty($driverIds)) {
            $this->info('Không có tài xế cũ nào cần xoá.');

            return;
        }

        $this->warn(sprintf('Đang gỡ bỏ liên kết và xoá %d tài xế cũ...', count($driverIds)));

        // 2. Gỡ liên kết driver trên bảng vehicles
        Vehicle::query()->whereIn('current_driver_id', $driverIds)->update(['current_driver_id' => null]);
        Vehicle::query()->whereIn('even_driver_id', $driverIds)->update(['even_driver_id' => null]);
        Vehicle::query()->whereIn('odd_driver_id', $driverIds)->update(['odd_driver_id' => null]);

        // 3. Xử lý trips và check-in mốc
        Trip::query()->whereIn('driver_id', $driverIds)->update(['driver_id' => null]);
        TripCheckpoint::query()->whereIn('driver_id', $driverIds)->update(['driver_id' => null]);

        // 4. Xoá các bản ghi shift / swap / overtime test gắn với tài xế cũ
        DriverShift::query()->whereIn('driver_id', $driverIds)->delete();
        DriverSwap::query()
            ->whereIn('from_driver_id', $driverIds)
            ->orWhereIn('to_driver_id', $driverIds)
            ->orWhereIn('created_by', $driverIds)
            ->delete();
        OvertimeRegistration::query()->whereIn('driver_id', $driverIds)->delete();

        // 5. Gỡ bỏ quyền trong model_has_roles
        DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->whereIn('model_id', $driverIds)
            ->delete();

        // 6. Xoá người dùng trong bảng users
        User::query()->whereIn('id', $driverIds)->delete();

        $this->info(sprintf('Đã xoá an toàn %d tài khoản tài xế cũ.', count($driverIds)));
    }

    /**
     * Parse ngày tháng từ ô Excel
     */
    private function parseExcelDate(mixed $val): ?string
    {
        if ($val === null || $val === '') {
            return null;
        }

        if (is_numeric($val)) {
            try {
                return ExcelDate::excelToDateTimeObject($val)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        $val = trim((string) $val);
        $parts = preg_split('/[\/\-\.]/', $val);
        if (count($parts) === 3) {
            $day = (int) $parts[0];
            $month = (int) $parts[1];
            $year = (int) $parts[2];
            if ($year < 100) {
                $year += $year >= 70 ? 1900 : 2000;
            }

            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        return null;
    }

    /**
     * Chuẩn hoá hạng GPLX khớp enum ['B', 'B1', 'C1', 'C', 'FC', 'D', 'E']
     */
    private function parseLicenseClass(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }

        $cleaned = trim(preg_replace('/gplx\s+hạng/iu', '', $raw));
        $cleaned = trim(preg_replace('/hạng/iu', '', $cleaned));

        // Tách dấu phẩy nếu có nhiều hạng: e.g. "A1, D", "E, FC"
        $parts = array_map('trim', explode(',', $cleaned));
        $filtered = array_filter($parts, fn ($c) => ! in_array(strtoupper($c), ['A1', 'A2', 'A3', 'A']));
        $target = ! empty($filtered) ? end($filtered) : ($parts[0] ?? '');
        $target = strtoupper(trim($target));

        // Ánh xạ D2 về D
        if ($target === 'D2') {
            return 'D';
        }

        $validEnums = ['B', 'B1', 'C1', 'C', 'FC', 'D', 'E'];

        return in_array($target, $validEnums, true) ? $target : null;
    }

    /**
     * Sinh email duy nhất theo quy tắc tên + ngày sinh
     *
     * @param  array<string, bool>  $seen
     */
    private function generateUniqueEmail(string $name, ?string $dob, array $seen): string
    {
        $words = explode(' ', trim($name));
        $initials = '';
        foreach ($words as $w) {
            if ($w !== '') {
                $initials .= Str::ascii(mb_substr($w, 0, 1));
            }
        }
        $initials = strtolower($initials);

        $suffix = '0000';
        if ($dob) {
            $suffix = substr($dob, 8, 2).substr($dob, 5, 2);
        }

        $baseEmail = "{$initials}{$suffix}@tms.local";
        $email = $baseEmail;
        $counter = 1;

        while (isset($seen[$email])) {
            $email = "{$initials}{$suffix}{$counter}@tms.local";
            $counter++;
        }

        return $email;
    }

    /**
     * Hiển thị bảng tổng hợp chế độ Dry Run
     *
     * @param  array<int, array<string, mixed>>  $drivers
     * @param  array<string, array>  $missingVehicles
     */
    private function displaySummary(array $drivers, array $missingVehicles, bool $keepOld): void
    {
        $this->newLine();
        $this->info('TỔNG HỢP KẾT QUẢ PHÂN TÍCH (DRY-RUN):');
        $this->table(
            ['Chỉ số', 'Giá trị'],
            [
                ['Tổng số tài xế mới bóc tách được', count($drivers)],
                ['Số xe cần bổ sung vào hệ thống', count($missingVehicles).' xe ('.implode(', ', array_keys($missingVehicles)).')'],
                ['Chế độ xóa tài xế cũ', $keepOld ? 'Giữ lại (Không xóa)' : 'Xóa tài xế cũ (@tms.local)'],
                ['Tài khoản quản trị viên & Dev', 'Bảo vệ an toàn (admin@admin.com, hkt@asgt.vn)'],
            ]
        );

        $this->info('Ví dụ 5 tài xế đầu tiên chuẩn bị import:');
        $sample = [];
        foreach (array_slice($drivers, 0, 5) as $d) {
            $sample[] = [
                $d['code'],
                $d['name'],
                $d['email'],
                $d['phone'],
                $d['station'],
                $d['plate_clean'] ?: '(Chưa có xe)',
                $d['license_class'],
                $d['dob'],
            ];
        }
        $this->table(['Mã NV', 'Họ tên', 'Email', 'SĐT', 'Trạm', 'Biển số xe', 'Hạng GPLX', 'Ngày sinh'], $sample);
    }
}
