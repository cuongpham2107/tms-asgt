<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class SyncEmployeeCodeCommand extends Command
{
    protected $signature = 'users:sync-employee-code
        {--file= : Đường dẫn file Excel (mặc định "data/8.10 DS Nhân sự.xlsx")}
        {--dry-run : Chỉ hiển thị đối soát, không cập nhật vào database}';

    protected $description = 'Đồng bộ mã nhân viên từ file Excel vào bảng users mà không xoá hay thay đổi tài khoản hiện có';

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
            ];

            if (class_exists(\Normalizer::class)) {
                $candidates[] = base_path(\Normalizer::normalize('data/8.10 DS Nhân sự.xlsx', \Normalizer::FORM_D));
                $candidates[] = base_path(\Normalizer::normalize('data/8.10 DS Nhân sự.xlsx', \Normalizer::FORM_C));
            }

            foreach (array_filter($candidates) as $candidate) {
                if (file_exists($candidate)) {
                    $filePath = $candidate;
                    break;
                }
            }

            if (! $filePath) {
                $globbed = glob(base_path('data/*8.10*Nha*su*.xlsx'))
                    ?: glob(base_path('data/*Nha*su*.xlsx'))
                    ?: glob(base_path('*Nha*su*.xlsx'));
                if (! empty($globbed)) {
                    $filePath = $globbed[0];
                }
            }

            $filePath = $filePath ?: base_path('data/8.10 DS Nhân sự.xlsx');
        }

        if (! file_exists($filePath)) {
            $this->error("Không tìm thấy file Excel tại đường dẫn: {$filePath}");

            return self::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');

        $this->info("Bắt đầu đọc file Excel: {$filePath}");
        if ($isDryRun) {
            $this->warn('--- CHẾ ĐỘ DRY-RUN: Không cập nhật dữ liệu vào database ---');
        }

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheetByName('Gốc')
            ?? $spreadsheet->getSheetByName('Sheet1')
            ?? $spreadsheet->getSheet(0);

        if (! $sheet) {
            $this->error('Không tìm thấy sheet dữ liệu nhân sự (Gốc hoặc Sheet1).');

            return self::FAILURE;
        }

        $highestRow = $sheet->getHighestRow();
        $this->info("Đang xử lý từ dòng 2 đến dòng {$highestRow}...");

        $users = User::all();
        $updatedCount = 0;
        $unchangedCount = 0;
        $unmatchedCount = 0;
        $rowsData = [];

        for ($r = 2; $r <= $highestRow; $r++) {
            $code = trim((string) $sheet->getCell("B{$r}")->getValue());
            $name = trim((string) $sheet->getCell("C{$r}")->getValue());
            $cccd = preg_replace('/\D/', '', trim((string) $sheet->getCell("K{$r}")->getValue()));
            $phone = preg_replace('/\D/', '', trim((string) $sheet->getCell("O{$r}")->getValue()));

            if (str_starts_with($phone, '84') && strlen($phone) >= 11) {
                $phone = '0'.substr($phone, 2);
            }

            if ($code === '' || $name === '') {
                continue;
            }

            // Tìm user tương ứng trong DB
            $matchedUser = null;
            $matchedBy = '';

            if ($phone !== '') {
                $matchedUser = $users->first(function ($u) use ($phone) {
                    $userPhone = preg_replace('/\D/', '', (string) $u->phone);
                    if (str_starts_with($userPhone, '84') && strlen($userPhone) >= 11) {
                        $userPhone = '0'.substr($userPhone, 2);
                    }

                    return $userPhone !== '' && $userPhone === $phone;
                });
                if ($matchedUser) {
                    $matchedBy = 'SĐT';
                }
            }

            if (! $matchedUser && $cccd !== '') {
                $matchedUser = $users->first(function ($u) use ($cccd) {
                    $userCccd = preg_replace('/\D/', '', (string) $u->cccd);

                    return $userCccd !== '' && $userCccd === $cccd;
                });
                if ($matchedUser) {
                    $matchedBy = 'CCCD';
                }
            }

            if (! $matchedUser && $name !== '') {
                $matchedUser = $users->first(function ($u) use ($name) {
                    return mb_strtolower(trim((string) $u->name)) === mb_strtolower($name);
                });
                if ($matchedUser) {
                    $matchedBy = 'Họ tên';
                }
            }

            if ($matchedUser) {
                $status = '';
                if ($matchedUser->employee_code === $code) {
                    $unchangedCount++;
                    $status = 'Đã khớp (không đổi)';
                } else {
                    if (! $isDryRun) {
                        User::query()->where('id', $matchedUser->id)->update([
                            'employee_code' => $code,
                        ]);
                        $matchedUser->employee_code = $code;
                    }
                    $updatedCount++;
                    $status = $isDryRun ? 'Sẽ cập nhật' : 'Đã cập nhật';
                }

                $rowsData[] = [
                    'Mã NV' => $code,
                    'Tên Excel' => $name,
                    'User ID' => $matchedUser->id,
                    'Tên DB' => $matchedUser->name,
                    'Khớp theo' => $matchedBy,
                    'Trạng thái' => $status,
                ];
            } else {
                $unmatchedCount++;
                $rowsData[] = [
                    'Mã NV' => $code,
                    'Tên Excel' => $name,
                    'User ID' => '—',
                    'Tên DB' => '—',
                    'Khớp theo' => 'Không tìm thấy',
                    'Trạng thái' => 'Bỏ qua',
                ];
            }
        }

        $this->table(
            ['Mã NV', 'Tên Excel', 'User ID', 'Tên DB', 'Khớp theo', 'Trạng thái'],
            array_slice($rowsData, 0, 15)
        );

        if (count($rowsData) > 15) {
            $this->line(sprintf('... và %d dòng khác đã được xử lý.', count($rowsData) - 15));
        }

        $this->newLine();
        $this->info('=== KẾT QUẢ ĐỒNG BỘ MÃ NHÂN VIÊN ===');
        $this->info('Tổng số hồ sơ trong Excel: '.count($rowsData));
        $this->info("Số user được cập nhật mã: {$updatedCount}");
        $this->info("Số user đã có mã trùng khớp: {$unchangedCount}");
        if ($unmatchedCount > 0) {
            $this->warn("Số hồ sơ không khớp được user trong DB: {$unmatchedCount}");
        } else {
            $this->info('Không có hồ sơ nào bị sót (100% khớp).');
        }

        return self::SUCCESS;
    }
}
