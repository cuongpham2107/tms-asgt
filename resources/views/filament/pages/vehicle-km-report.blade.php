<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Km lấy từ số đồng hồ lái xe nhập ở các mốc chuyến (bỏ chuyến đã huỷ). Km đầu kỳ là số mới nhất tại hoặc trước thời điểm "Từ";
        xe chưa có số nào trước đó thì lấy số đầu tiên trong kỳ.
    </p>

    {{ $this->table }}
</x-filament-panels::page>
