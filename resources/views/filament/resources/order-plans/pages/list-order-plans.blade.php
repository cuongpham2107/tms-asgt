<x-filament-panels::page>
    <div class="space-y-3">
        <div class="rounded-xl flex flex-col divide-y divide-gray-100 dark:divide-gray-800">
            {{ $this->filtersForm }}
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 pb-1">
            <div class="w-105">
                {{ $this->dateRangeForm }}
            </div>
            <div class="flex-1 min-w-0 sm:max-w-md">
                {{ $this->searchForm }}
            </div>
        </div>
    </div>

    {{ $this->table }}
</x-filament-panels::page>