<x-filament-panels::page>
    <div class="space-y-3">
        {{-- Một hàng: bộ lọc phụ (dropdown) + khoảng ngày bên trái, tìm kiếm bên phải --}}
        <div class="flex flex-wrap items-center gap-2">
            {{ $this->filtersForm }}

            <div class="w-full sm:w-76">
                {{ $this->dateRangeForm }}
            </div>

            <div class="w-full sm:ml-auto sm:w-80">
                {{ $this->searchForm }}
            </div>
        </div>

        {{-- Trạng thái chuyến (bộ lọc chính) --}}
        {{ $this->statusFilterForm }}

        {{ $this->table }}
    </div>
</x-filament-panels::page>
