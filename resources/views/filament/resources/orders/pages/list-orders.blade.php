<x-filament-panels::page>
    <style>
        html.dark .collapsible-filter-bar {
            background-color: rgb(15 23 42 / 0.75) !important;
            border-color: rgb(30 41 59 / 0.8) !important;
        }
        html.dark .toolbar-toggle-btn {
            background-color: rgb(30 41 59 / 0.85) !important;
            border-color: rgb(51 65 85 / 0.8) !important;
            color: rgb(226 232 240) !important;
        }
        html.dark .toolbar-toggle-btn:hover {
            background-color: rgb(51 65 85 / 0.95) !important;
        }
    </style>
    <div
        x-data="{
            isFiltersOpen: localStorage.getItem('list_orders_filters_open') !== 'false'
        }"
        x-effect="localStorage.setItem('list_orders_filters_open', isFiltersOpen)"
        class="space-y-3"
    >
        {{-- Toolbar: Toggle Filters Button + Date Range + Mine Only + Search --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    x-on:click="isFiltersOpen = !isFiltersOpen"
                    class="toolbar-toggle-btn inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold shadow-xs transition cursor-pointer"
                >
                    <x-filament::icon icon="heroicon-o-funnel" class="h-4 w-4 text-primary-500" />
                    <span>Bộ lọc</span>
                    @php
                        $activeCount = ($activeOrderTypeFilter !== 'all' ? 1 : 0) + ($activeStatusFilter !== 'all' ? 1 : 0) + ($activePlaceFilter !== 'all' ? 1 : 0);
                    @endphp
                    @if ($activeCount > 0)
                        <span class="rounded-full bg-primary-500 px-1.5 py-0.5 text-[10px] font-bold text-white">
                            {{ $activeCount }}
                        </span>
                    @endif
                    <x-filament::icon
                        icon="heroicon-m-chevron-down"
                        class="h-3.5 w-3.5 text-gray-400 transition-transform duration-200"
                        x-bind:class="{ 'rotate-180': isFiltersOpen }"
                    />
                </button>

                <div class="w-[380px] sm:w-[420px]">
                    {{ $this->dateRangeForm }}
                </div>

                <x-filament::button
                    wire:click="$toggle('showMineOnly')"
                    :color="$showMineOnly ? 'primary' : 'gray'"
                    size="sm"
                    :icon="$showMineOnly ? 'heroicon-s-user' : 'heroicon-o-user'"
                    class="toolbar-mine-btn"
                >
                    Đơn của tôi
                </x-filament::button>
            </div>

            <div class="flex-1 min-w-0 sm:max-w-md">
                {{ $this->searchForm }}
            </div>
        </div>

        {{-- Collapsible filter bar container --}}
        <div
            x-show="isFiltersOpen"
            x-collapse
            x-cloak
            class="collapsible-filter-bar rounded-xl flex flex-col divide-y divide-gray-100 dark:divide-gray-800 p-2 shadow-2xs"
        >
            {{ $this->filtersForm }}
        </div>

        {{-- Active filters summary --}}
        @if ($activeOrderTypeFilter !== 'all' || $activeStatusFilter !== 'all' || $activePlaceFilter !== 'all')
            <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <span>Đang lọc:</span>
                @if ($activeOrderTypeFilter !== 'all')
                    <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-0.5 font-medium text-blue-800 dark:bg-blue-950/60 dark:text-blue-700 dark:border dark:border-blue-800/50">
                        {{ $orderTypeFilters[$activeOrderTypeFilter]['label'] ?? $activeOrderTypeFilter }}
                        <button wire:click="filterOrderType('all')" class="ml-0.5 hover:text-red-500 cursor-pointer">&times;</button>
                    </span>
                @endif
                @if ($activeStatusFilter !== 'all')
                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 font-medium text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 dark:border dark:border-amber-800/50">
                        {{ $orderStatusFilters[$activeStatusFilter]['label'] ?? $activeStatusFilter }}
                        <button wire:click="filterStatus('all')" class="ml-0.5 hover:text-red-500 cursor-pointer">&times;</button>
                    </span>
                @endif
                @if ($activePlaceFilter !== 'all')
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 font-medium text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border dark:border-emerald-800/50">
                        {{ $activePlaceFilter ? ($orderPlaceFilters[(string) $activePlaceFilter] ?? $activePlaceFilter) : '' }}
                        <button wire:click="filterPlace('all')" class="ml-0.5 hover:text-red-500 cursor-pointer">&times;</button>
                    </span>
                @endif
            </div>
        @endif
    </div>

    <div>
        {{ $this->table }}
    </div>
</x-filament-panels::page>
