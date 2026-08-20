<x-filament-panels::page>
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
                    class="group relative flex items-center justify-between rounded-xl border {{ $stat['border'] }} bg-white p-3 shadow-2xs transition-all duration-150 hover:shadow-xs hover:border-primary-400 dark:bg-gray-900 cursor-pointer"
                @else
                    class="relative flex items-center justify-between rounded-xl border {{ $stat['border'] }} bg-white p-3 shadow-2xs dark:bg-gray-900"
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
                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 shadow-xs transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 cursor-pointer"
                >
                    <x-filament::icon icon="heroicon-o-funnel" class="h-4 w-4 text-primary-500" />
                    <span>Bộ lọc</span>
                    @php
                        $activeCount = ($activeStatusFilter !== 'all' ? 1 : 0) + ($vehicleOwner !== 'all' ? 1 : 0) + ($orderType !== 'all' ? 1 : 0);
                    @endphp
                    @if ($activeCount > 0)
                        <span class="rounded-full bg-primary-500 px-2 py-0.5 text-[10px] font-bold text-white">
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
            class="rounded-xl flex flex-col divide-y divide-gray-100 dark:divide-gray-800 bg-white/40 p-2 border border-gray-100 dark:border-gray-800 dark:bg-gray-900/30"
        >
            {{ $this->filtersForm }}
        </div>

        {{-- Active filter summary --}}
        @if ($activeStatusFilter !== 'all' || $vehicleOwner !== 'all' || $orderType !== 'all')
            <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <span>Đang lọc:</span>
                @if ($activeStatusFilter !== 'all')
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                    {{ $tripStatusFilters[$activeStatusFilter]['label'] ?? $activeStatusFilter }}
                    <button wire:click="filterStatus('all')" class="ml-0.5 hover:text-red-500 cursor-pointer">&times;</button>
                </span>
                @endif
                @if ($vehicleOwner !== 'all')
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                    {{ $vehicleOwnerFilters[$vehicleOwner]['label'] ?? $vehicleOwner }}
                    <button wire:click="filterVehicleOwner('all')" class="ml-0.5 hover:text-red-500 cursor-pointer">&times;</button>
                </span>
                @endif
                @if ($orderType !== 'all')
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                    {{ $orderTypeFilters[$orderType]['label'] ?? $orderType }}
                    <button wire:click="filterOrderType('all')" class="ml-0.5 hover:text-red-500 cursor-pointer">&times;</button>
                </span>
                @endif
            </div>
        @endif
    </div>

    <div>
        {{ $this->table }}
    </div>
</x-filament-panels::page>