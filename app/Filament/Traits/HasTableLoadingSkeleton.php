<?php

namespace App\Filament\Traits;

use Illuminate\Contracts\View\View;

/**
 * Widget bảng React nhúng với `defer`: thay khung chờ trống của Filament
 * bằng bảng skeleton (tiêu đề cột thật + vạch xám) giống loadingSkeleton của bảng Filament.
 */
trait HasTableLoadingSkeleton
{
    /**
     * @return array<int, string>
     */
    abstract protected function getSkeletonColumns(): array;

    public function placeholder(): View
    {
        return view('filament.widgets.table-loading-skeleton', [
            'columns' => $this->getSkeletonColumns(),
            'rows' => 10,
        ]);
    }
}
