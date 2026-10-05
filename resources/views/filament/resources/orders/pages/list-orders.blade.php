<x-filament-panels::page>
    <div class="space-y-3">
        {{-- Một hàng: bộ lọc phụ (dropdown) + khoảng ngày + đơn của tôi + tìm kiếm + xuất Excel theo bộ lọc --}}
        <div class="flex flex-wrap items-center gap-2">
            {{ $this->filtersForm }}

            <div class="w-full sm:w-76">
                {{ $this->dateRangeForm }}
            </div>

            <x-filament::button
                wire:click="$toggle('showMineOnly')"
                :color="$showMineOnly ? 'primary' : 'gray'"
                :outlined="$showMineOnly"
                size="sm"
                :icon="$showMineOnly ? 'heroicon-s-user' : 'heroicon-o-user'"
            >
                Đơn của tôi
            </x-filament::button>

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

        {{-- Trạng thái đơn (bộ lọc chính) --}}
        {{ $this->statusFilterForm }}

        {{ $this->table }}
    </div>
</x-filament-panels::page>
