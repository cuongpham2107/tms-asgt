<x-filament-panels::page>
    <style>
        html.dark .trip-stat-card {
            background-color: rgb(15 23 42 / 0.85) !important;
            border-color: rgb(30 41 59 / 0.9) !important;
        }
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
    {{-- Custom Stats Bar --}}
    @php
        $stats = $this->getTripStats();
    @endphp
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-5">
        @foreach ($stats as $stat)
            <div
                @if (!empty($stat['filter']))
                    wire:click="filterStatus('{{ $stat['filter'] }}')"
                    role="button"
                    class="trip-stat-card group relative flex items-center justify-between rounded-xl p-3 shadow-2xs transition-all duration-150 hover:shadow-xs hover:border-primary-400 cursor-pointer"
                @else
                    class="trip-stat-card relative flex items-center justify-between rounded-xl p-3 shadow-2xs"
                @endif
            >
                <div class="min-w-0 flex-1">
                    <p class="truncate text-xs font-medium text-gray-500 dark:text-gray-400">
                        {{ $stat['label'] }}
                    </p>
                    <p class="mt-0.5 text-xl font-bold tracking-tight text-gray-900 dark:text-gray-100">
                        {{ number_format($stat['value']) }}
                    </p>
                </div>

                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $stat['bg'] }} {{ $stat['color'] }}">
                    <x-filament::icon :icon="$stat['icon']" class="h-5 w-5" />
                </div>
            </div>
        @endforeach
    </div>

    <div
        x-data="{
            isFiltersOpen: localStorage.getItem('list_trips_filters_open') !== 'false'
        }"
        x-effect="localStorage.setItem('list_trips_filters_open', isFiltersOpen)"
        class="space-y-3"
    >
        {{-- Toolbar: Toggle Filters Button + Date Range + Search --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    x-on:click="isFiltersOpen = !isFiltersOpen"
                    class="toolbar-toggle-btn inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold shadow-xs transition cursor-pointer"
                >
                    <x-filament::icon icon="heroicon-o-funnel" class="h-4 w-4 text-primary-500" />
                    <span>Bộ lọc</span>
                    <x-filament::icon
                        icon="heroicon-m-chevron-down"
                        class="h-3.5 w-3.5 text-gray-400 transition-transform duration-200"
                        x-bind:class="{ 'rotate-180': isFiltersOpen }"
                    />
                </button>

                <div class="w-[380px] sm:w-[420px]">
                    {{ $this->dateRangeForm }}
                </div>
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

        {{-- Active filter summary --}}
        @if ($activeStatusFilter !== 'all' || $vehicleOwner !== 'all' || $orderType !== 'all' || $activePlaceFilter !== 'all')
            <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <span>Đang lọc:</span>
                @if ($activeStatusFilter !== 'all')
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 font-medium text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 dark:border dark:border-amber-800/50">
                    {{ $tripStatusFilters[$activeStatusFilter]['label'] ?? $activeStatusFilter }}
                    <button wire:click="filterStatus('all')" class="ml-0.5 hover:text-red-500 cursor-pointer">&times;</button>
                </span>
                @endif
                @if ($vehicleOwner !== 'all')
                <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-0.5 font-medium text-blue-800 dark:bg-blue-950/60 dark:text-blue-700 dark:border dark:border-blue-800/50">
                    {{ $vehicleOwnerFilters[$vehicleOwner]['label'] ?? $vehicleOwner }}
                    <button wire:click="filterVehicleOwner('all')" class="ml-0.5 hover:text-red-500 cursor-pointer">&times;</button>
                </span>
                @endif
                @if ($orderType !== 'all')
                <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-0.5 font-medium text-blue-800 dark:bg-blue-950/60 dark:text-blue-700 dark:border dark:border-blue-800/50">
                    {{ $orderTypeFilters[$orderType]['label'] ?? $orderType }}
                    <button wire:click="filterOrderType('all')" class="ml-0.5 hover:text-red-500 cursor-pointer">&times;</button>
                </span>
                @endif
                @if ($activePlaceFilter !== 'all')
                <span class="inline-flex items-center gap-1 rounded-full bg-purple-100 px-2.5 py-0.5 font-medium text-purple-800 dark:bg-purple-950/60 dark:text-purple-300 dark:border dark:border-purple-800/50">
                    Khu vực: {{ $activePlaceFilter ? ($orderPlaceFilters[(string) $activePlaceFilter] ?? $activePlaceFilter) : '' }}
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