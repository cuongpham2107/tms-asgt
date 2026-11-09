<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

#[Signature('db:backup {--keep=3 : Số lượng bản sao lưu gần nhất được giữ lại}')]
#[Description('Tạo bản sao lưu nén gzip cho cơ sở dữ liệu PostgreSQL và tự động dọn dẹp các bản cũ')]
class BackupDatabaseCommand extends Command
{
    public function handle(): int
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if ($driver !== 'pgsql') {
            $this->error("Lệnh db:backup hiện tại chỉ hỗ trợ PostgreSQL (driver hiện tại: {$driver}).");

            return self::FAILURE;
        }

        $host = config("database.connections.{$connection}.host", '127.0.0.1');
        $port = config("database.connections.{$connection}.port", 5432);
        $database = config("database.connections.{$connection}.database");
        $username = config("database.connections.{$connection}.username");
        $password = (string) config("database.connections.{$connection}.password");

        $backupDir = storage_path('app/backups');
        File::ensureDirectoryExists($backupDir);

        $timestamp = now()->format('Ymd_His');
        $fileName = "tms_asgt_backup_{$timestamp}.sql.gz";
        $filePath = "{$backupDir}/{$fileName}";

        $this->info("Bắt đầu sao lưu cơ sở dữ liệu [{$database}] từ {$host}:{$port}...");

        $command = sprintf(
            'pg_dump -h %s -p %s -U %s %s | gzip > %s',
            escapeshellarg((string) $host),
            escapeshellarg((string) $port),
            escapeshellarg((string) $username),
            escapeshellarg((string) $database),
            escapeshellarg($filePath)
        );

        $process = Process::fromShellCommandline(
            $command,
            null,
            ['PGPASSWORD' => $password],
            null,
            600
        );

        $process->run();

        if (! $process->isSuccessful() || ! file_exists($filePath) || filesize($filePath) === 0) {
            $errorOutput = $process->getErrorOutput();
            $this->error("Sao lưu cơ sở dữ liệu thất bại: {$errorOutput}");
            Log::error("Database backup failed: {$errorOutput}");

            if (file_exists($filePath)) {
                @unlink($filePath);
            }

            return self::FAILURE;
        }

        $fileSizeBytes = (int) filesize($filePath);
        $fileSizeFormatted = $this->formatBytes($fileSizeBytes);

        $this->info("✓ Sao lưu thành công: {$fileName} ({$fileSizeFormatted})");
        Log::info("Database backup created successfully: {$fileName} ({$fileSizeFormatted})", [
            'path' => $filePath,
            'size_bytes' => $fileSizeBytes,
        ]);

        $this->pruneOldBackups($backupDir, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    /**
     * Tự động dọn dẹp các bản sao lưu cũ vượt quá số lượng cho phép.
     */
    protected function pruneOldBackups(string $backupDir, int $keepCount): void
    {
        if ($keepCount <= 0) {
            return;
        }

        $files = collect(File::files($backupDir))
            ->filter(fn (\SplFileInfo $file) => str_starts_with($file->getFilename(), 'tms_asgt_backup_') && str_ends_with($file->getFilename(), '.sql.gz'))
            ->sortByDesc(fn (\SplFileInfo $file) => $file->getMTime())
            ->values();

        if ($files->count() <= $keepCount) {
            return;
        }

        $filesToDelete = $files->slice($keepCount);
        if ($this->output) {
            $this->info("Đang dọn dẹp {$filesToDelete->count()} bản sao lưu cũ (giữ lại {$keepCount} bản mới nhất)...");
        }

        foreach ($filesToDelete as $file) {
            $name = $file->getFilename();
            @unlink($file->getRealPath());
            if ($this->output) {
                $this->line("  - Đã xoá bản cũ: {$name}");
            }
            Log::info("Pruned old database backup: {$name}");
        }
    }

    /**
     * Chuyển đổi dung lượng byte sang định dạng dễ đọc.
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    }
}
