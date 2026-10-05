<x-filament-panels::page>
    <div class="space-y-3">
        {{-- Một hàng: bộ lọc phụ (dropdown) + khoảng ngày + tìm kiếm + xuất Excel theo bộ lọc --}}
        <div class="flex flex-wrap items-center gap-2">
            {{ $this->filtersForm }}

            <div class="w-full sm:w-76">
                {{ $this->dateRangeForm }}
            </div>

            <div class="min-w-40 flex-1">
                {{ $this->searchForm }}
            </div>

            <x-filament::icon-button
                icon="heroicon-o-arrow-down-tray"
                color="gray"
                label="Xuất Excel theo bộ lọc"
                tooltip="Xuất Excel theo bộ lọc"
                wire:click="exportExcel"
                wire:loading.attr="disabled"
            />
        </div>

        {{-- Trạng thái chuyến (bộ lọc chính) --}}
        {{ $this->statusFilterForm }}

        {{ $this->table }}
    </div>
</x-filament-panels::page>
