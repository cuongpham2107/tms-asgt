<?php

namespace App\Jobs;

use App\Services\EupGpsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Lấy vị trí toàn đội xe từ hộp đen EUP (một request cho tất cả xe).
 */
class SyncEupGpsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(EupGpsService $service): void
    {
        $result = $service->sync();

        if (! $result['success']) {
            Log::warning('Đồng bộ GPS EUP thất bại', ['message' => $result['message']]);
        }
    }
}
