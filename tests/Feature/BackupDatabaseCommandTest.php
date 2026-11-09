<?php

use App\Console\Commands\BackupDatabaseCommand;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->backupDir = storage_path('app/backups');
    File::ensureDirectoryExists($this->backupDir);
});

afterEach(function () {
    // Dọn dẹp các file test backup
    if (File::isDirectory($this->backupDir)) {
        $testFiles = collect(File::files($this->backupDir))
            ->filter(fn (SplFileInfo $file) => str_starts_with($file->getFilename(), 'tms_asgt_backup_test_'));

        foreach ($testFiles as $file) {
            @unlink($file->getRealPath());
        }
    }
});

test('db:backup rejects unsupported database drivers', function () {
    Config::set('database.default', 'sqlite');

    $this->artisan('db:backup')
        ->expectsOutputToContain('Lệnh db:backup hiện tại chỉ hỗ trợ PostgreSQL')
        ->assertExitCode(1);
});

test('db:backup prunes old backup files according to keep option', function () {
    // Tạo giả 4 file backup với các mtime khác nhau
    $now = time();
    for ($i = 1; $i <= 4; $i++) {
        $fakeFile = "{$this->backupDir}/tms_asgt_backup_test_{$i}.sql.gz";
        file_put_contents($fakeFile, "dummy content {$i}");
        touch($fakeFile, $now - ($i * 100));
    }

    // Command khi chạy giữ lại 2 file
    $command = new BackupDatabaseCommand;
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('pruneOldBackups');
    $method->setAccessible(true);

    // Chạy prune với keep = 2 cho các file test
    // Đổi tên các file test thành chuẩn tms_asgt_backup_
    $activeTestFiles = [];
    for ($i = 1; $i <= 4; $i++) {
        $realTestFile = "{$this->backupDir}/tms_asgt_backup_2026010{$i}_000000.sql.gz";
        rename("{$this->backupDir}/tms_asgt_backup_test_{$i}.sql.gz", $realTestFile);
        touch($realTestFile, $now - ($i * 100));
        $activeTestFiles[] = $realTestFile;
    }

    $method->invoke($command, $this->backupDir, 2);

    // File 1 và 2 mới nhất phải còn, file 3 và 4 phải bị xoá
    expect(file_exists($activeTestFiles[0]))->toBeTrue()
        ->and(file_exists($activeTestFiles[1]))->toBeTrue()
        ->and(file_exists($activeTestFiles[2]))->toBeFalse()
        ->and(file_exists($activeTestFiles[3]))->toBeFalse();

    // Dọn dẹp nốt 2 file còn lại
    @unlink($activeTestFiles[0]);
    @unlink($activeTestFiles[1]);
});
