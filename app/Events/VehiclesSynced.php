<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phát sau mỗi lần đồng bộ GPS từ EUP có cập nhật xe — các màn hình theo dõi xe làm mới ngay
 * thay vì chờ tới lượt tự tải lại.
 */
class VehiclesSynced implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $updated) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('vehicles')];
    }

    public function broadcastAs(): string
    {
        return 'VehiclesSynced';
    }

    /**
     * @return array{updated: int}
     */
    public function broadcastWith(): array
    {
        return ['updated' => $this->updated];
    }
}
