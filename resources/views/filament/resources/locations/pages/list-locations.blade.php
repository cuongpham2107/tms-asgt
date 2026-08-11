<x-filament-panels::page>
    <div class="rounded-xl flex flex-col divide-y divide-gray-100 dark:divide-gray-800">
        {{ $this->filtersForm }}
    </div>

    @if ($orderType !== 'all' || $areaFilter !== 'all')
        <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mt-3">
            <span>Đang lọc:</span>
            @if ($orderType !== 'all')
            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                {{ $orderTypeFilters[$orderType]['label'] ?? $orderType }}
                <button wire:click="filterOrderType('all')" class="ml-0.5 hover:text-red-500">&times;</button>
            </span>
            @endif
            @if ($areaFilter !== 'all')
            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                KV: {{ $areaFilter }}
                <button wire:click="filterArea('all')" class="ml-0.5 hover:text-red-500">&times;</button>
            </span>
            @endif
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
