<x-filament-panels::page>
    <div
        x-data="{
            isFiltersOpen: localStorage.getItem('list_orders_filters_open') !== 'false'
        }"
        x-effect="localStorage.setItem('list_orders_filters_open', isFiltersOpen)"
        class="space-y-3"
    >
        {{-- Toolbar: Toggle Filters Button + Date Range + Mine Only + Search --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::button
                    type="button"
                    color="gray"
                    size="sm"
                    icon="heroicon-o-funnel"
                    x-on:click="isFiltersOpen = !isFiltersOpen"
                    x-bind:aria-expanded="isFiltersOpen"
                >
                    <span class="inline-flex items-center gap-1">
                        Bộ lọc
                        <x-filament::icon
                            icon="heroicon-m-chevron-down"
                            class="h-4 w-4 text-gray-400 transition-transform duration-200 motion-reduce:transition-none dark:text-gray-500"
                            x-bind:class="{ 'rotate-180': isFiltersOpen }"
                        />
                    </span>
                </x-filament::button>

                <div class="w-full max-w-[420px] sm:w-[420px]">
                    {{ $this->dateRangeForm }}
                </div>

                <x-filament::button
                    wire:click="$toggle('showMineOnly')"
                    :color="$showMineOnly ? 'primary' : 'gray'"
                    size="sm"
                    :icon="$showMineOnly ? 'heroicon-s-user' : 'heroicon-o-user'"
                >
                    Đơn của tôi
                </x-filament::button>

                <x-filament::button
                    wire:click="exportExcel"
                    wire:loading.attr="disabled"
                    color="gray"
                    size="sm"
                    icon="heroicon-o-arrow-down-tray"
                >
                    Xuất Excel
                </x-filament::button>
            </div>

            <div class="min-w-0 flex-1 sm:max-w-md">
                {{ $this->searchForm }}
            </div>
        </div>

        {{-- Collapsible filter bar container --}}
        <div
            x-show="isFiltersOpen"
            x-collapse
            x-cloak
            class="flex flex-col divide-y divide-gray-200 rounded-xl bg-white p-2 shadow-sm ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10"
        >
            {{ $this->filtersForm }}
        </div>

        {{-- Active filters summary --}}
        @if ($activeOrderTypeFilter !== 'all' || $activeStatusFilter !== 'all' || $activePlaceFilter !== 'all')
            <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <span>Đang lọc:</span>
                @if ($activeOrderTypeFilter !== 'all')
                    <x-filament::badge color="primary">
                        {{ $orderTypeFilters[$activeOrderTypeFilter]['label'] ?? $activeOrderTypeFilter }}
                        <x-slot name="deleteButton" label="Bỏ lọc" wire:click="filterOrderType('all')"></x-slot>
                    </x-filament::badge>
                @endif
                @if ($activeStatusFilter !== 'all')
                    <x-filament::badge color="warning">
                        {{ $orderStatusFilters[$activeStatusFilter]['label'] ?? $activeStatusFilter }}
                        <x-slot name="deleteButton" label="Bỏ lọc" wire:click="filterStatus('all')"></x-slot>
                    </x-filament::badge>
                @endif
                @if ($activePlaceFilter !== 'all')
                    <x-filament::badge color="success">
                        {{ $activePlaceFilter ? ($orderPlaceFilters[(string) $activePlaceFilter] ?? $activePlaceFilter) : '' }}
                        <x-slot name="deleteButton" label="Bỏ lọc" wire:click="filterPlace('all')"></x-slot>
                    </x-filament::badge>
                @endif
            </div>
        @endif
    </div>

    <div>
        {{ $this->table }}
    </div>
</x-filament-panels::page>
