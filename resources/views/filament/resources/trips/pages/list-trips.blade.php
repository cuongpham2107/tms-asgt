<x-filament-panels::page>
    {{-- Custom Stats Bar --}}
    @php
        $stats = $this->getTripStats();

        // Semantic Filament palette per stat key (literal class strings so Tailwind picks them up).
        $statIconClasses = [
            'all' => 'bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400',
            'unsent' => 'bg-warning-50 text-warning-600 dark:bg-warning-400/10 dark:text-warning-400',
            'running' => 'bg-info-50 text-info-600 dark:bg-info-400/10 dark:text-info-400',
            'completed' => 'bg-success-50 text-success-600 dark:bg-success-400/10 dark:text-success-400',
            'delayed' => 'bg-danger-50 text-danger-600 dark:bg-danger-400/10 dark:text-danger-400',
        ];
    @endphp
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-5">
        @foreach ($stats as $stat)
            @php
                $isClickable = ! empty($stat['filter']);
                $isActive = $isClickable && $activeStatusFilter === $stat['filter'];
            @endphp
            <{{ $isClickable ? 'button' : 'div' }}
                @if ($isClickable)
                    type="button"
                    wire:click="filterStatus('{{ $stat['filter'] }}')"
                    aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                @endif
                @class([
                    'relative flex items-center justify-between gap-3 rounded-xl bg-white p-3 text-start shadow-sm ring-1 dark:bg-gray-900',
                    'cursor-pointer transition duration-75 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 motion-reduce:transition-none dark:hover:bg-white/5 dark:focus-visible:ring-primary-500' => $isClickable,
                    'ring-2 ring-primary-600 dark:ring-primary-500' => $isActive,
                    'ring-gray-950/5 dark:ring-white/10' => ! $isActive,
                ])
            >
                <div class="min-w-0 flex-1">
                    <p class="truncate text-xs font-medium text-gray-500 dark:text-gray-400" title="{{ $stat['label'] }}">
                        {{ $stat['label'] }}
                    </p>
                    <p class="mt-0.5 text-xl font-semibold tracking-tight tabular-nums text-gray-950 dark:text-white">
                        {{ number_format($stat['value']) }}
                    </p>
                </div>

                <div @class([
                    'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg',
                    $statIconClasses[$stat['key'] ?? ''] ?? 'bg-gray-50 text-gray-600 dark:bg-white/5 dark:text-gray-400',
                ])>
                    <x-filament::icon :icon="$stat['icon']" class="h-5 w-5" />
                </div>
            </{{ $isClickable ? 'button' : 'div' }}>
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

        {{-- Active filter summary --}}
        @if ($activeStatusFilter !== 'all' || $vehicleOwner !== 'all' || $orderType !== 'all' || $activePlaceFilter !== 'all')
            <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <span>Đang lọc:</span>
                @if ($activeStatusFilter !== 'all')
                    <x-filament::badge color="warning">
                        {{ $tripStatusFilters[$activeStatusFilter]['label'] ?? $activeStatusFilter }}
                        <x-slot name="deleteButton" label="Bỏ lọc" wire:click="filterStatus('all')"></x-slot>
                    </x-filament::badge>
                @endif
                @if ($vehicleOwner !== 'all')
                    <x-filament::badge color="primary">
                        {{ $vehicleOwnerFilters[$vehicleOwner]['label'] ?? $vehicleOwner }}
                        <x-slot name="deleteButton" label="Bỏ lọc" wire:click="filterVehicleOwner('all')"></x-slot>
                    </x-filament::badge>
                @endif
                @if ($orderType !== 'all')
                    <x-filament::badge color="primary">
                        {{ $orderTypeFilters[$orderType]['label'] ?? $orderType }}
                        <x-slot name="deleteButton" label="Bỏ lọc" wire:click="filterOrderType('all')"></x-slot>
                    </x-filament::badge>
                @endif
                @if ($activePlaceFilter !== 'all')
                    <x-filament::badge color="success">
                        Khu vực: {{ $activePlaceFilter ? ($orderPlaceFilters[(string) $activePlaceFilter] ?? $activePlaceFilter) : '' }}
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
