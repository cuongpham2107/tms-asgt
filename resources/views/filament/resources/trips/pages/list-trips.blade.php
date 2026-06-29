<x-filament-panels::page>
    {{-- Filter bar container --}}
    <div class="rounded-xl flex flex-col divide-y divide-gray-100 dark:divide-gray-800">
        {{ $this->filtersForm }}
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="w-[420px]">
            {{ $this->dateRangeForm }}
        </div>

        <div class="flex-1 min-w-0 sm:max-w-md">
            {{ $this->searchForm }}
        </div>
    </div>

    {{-- Active filter summary --}}
    @if ($activeStatusFilter !== 'all')
        <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
            <span>Đang lọc:</span>
            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                {{ $tripStatusFilters[$activeStatusFilter]['label'] ?? $activeStatusFilter }}
                <button wire:click="filterStatus('all')" class="ml-0.5 hover:text-red-500">&times;</button>
            </span>
        </div>
    @endif

    <div>
        {{ $this->table }}
    </div>
</x-filament-panels::page>