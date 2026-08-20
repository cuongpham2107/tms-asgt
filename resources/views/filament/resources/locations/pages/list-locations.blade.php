<x-filament-panels::page>
    <div class="rounded-xl flex flex-col divide-y divide-gray-100 dark:divide-gray-800 p-2 border border-gray-200 bg-white/70 dark:border-gray-800 dark:bg-gray-900/60 shadow-2xs">
        {{ $this->filtersForm }}
    </div>

    @if ($orderType !== 'all' || $areaFilter !== 'all')
        <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mt-3">
            <span>Đang lọc:</span>
            @if ($orderType !== 'all')
            <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-0.5 font-medium text-blue-800 dark:bg-blue-950/60 dark:text-blue-300 dark:border dark:border-blue-800/50">
                {{ $orderTypeFilters[$orderType]['label'] ?? $orderType }}
                <button wire:click="filterOrderType('all')" class="ml-0.5 hover:text-red-500 cursor-pointer">&times;</button>
            </span>
            @endif
            @if ($areaFilter !== 'all')
            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 font-medium text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 dark:border dark:border-amber-800/50">
                KV: {{ $areaFilter }}
                <button wire:click="filterArea('all')" class="ml-0.5 hover:text-red-500 cursor-pointer">&times;</button>
            </span>
            @endif
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
