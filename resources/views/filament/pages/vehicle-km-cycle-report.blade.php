<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Số liệu được lưu cố định lúc job chốt km chạy (12:00 ngày 23), cùng công thức cột KM over trên bảng điều xe:
        km cuối kỳ là km đầu kỳ mới; xe đang chạy chuyến lúc chốt lấy km bắt đầu của chuyến đó, nên cả chuyến được tính sang kỳ sau.
        "Km theo mốc chuyến" tính từ số đồng hồ lái xe nhập trong kỳ, dùng để đối chiếu.
    </p>

    {{ $this->table }}
</x-filament-panels::page>
