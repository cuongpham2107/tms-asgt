<?php

namespace App\Filament\Traits;

/**
 * Widget bảng React hiện ngay, dữ liệu tải sau:
 * render đầu tiên chỉ gửi tiêu đề cột để React vẽ skeleton (resources/js/filament/widgets/table-skeleton.tsx),
 * React gọi `$wire.$set('isReady', true)` và Livewire render lại kèm dữ liệu.
 */
trait HasTableLoadingSkeleton
{
    public bool $isReady = false;

    /**
     * @return array<int, string>
     */
    abstract protected function getSkeletonColumns(): array;

    /**
     * @return array{loaded: false, skeletonColumns: array<int, string>}
     */
    protected function getPendingRendererConfiguration(): array
    {
        return [
            'loaded' => false,
            'skeletonColumns' => $this->getSkeletonColumns(),
        ];
    }
}
