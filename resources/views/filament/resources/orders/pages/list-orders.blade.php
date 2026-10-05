<x-filament-panels::page>
    <div class="space-y-3">
        {{-- Một hàng: bộ lọc phụ (dropdown) + khoảng ngày + đơn của tôi bên trái, tìm kiếm bên phải --}}
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

            <div class="w-full sm:ml-auto sm:w-80">
                {{ $this->searchForm }}
            </div>
        </div>

        {{-- Trạng thái đơn (bộ lọc chính) --}}
        {{ $this->statusFilterForm }}

        {{ $this->table }}
    </div>
</x-filament-panels::page>
