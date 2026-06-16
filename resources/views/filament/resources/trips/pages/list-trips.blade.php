<x-filament-panels::page>
    <div class="space-y-4">
        {{-- Filters Bar --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                {{-- Date From --}}
            <div class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900">
                <x-filament::icon
                    icon="heroicon-o-calendar-days"
                    class="h-4 w-4 text-gray-400"
                />
                <input
                    type="date"
                    wire:model.live="dateFrom"
                    class="border-0 bg-transparent text-sm text-gray-700 outline-none dark:text-gray-300"
                />
            </div>

            <span class="text-gray-400 dark:text-gray-500">—</span>

            {{-- Date To --}}
            <div class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900">
                <x-filament::icon
                    icon="heroicon-o-calendar-days"
                    class="h-4 w-4 text-gray-400"
                />
                <input
                    type="date"
                    wire:model.live="dateTo"
                    class="border-0 bg-transparent text-sm text-gray-700 outline-none dark:text-gray-300"
                />
            </div>
            </div>

            {{-- Search --}}
            <div class="relative flex-1 max-w-92">
                <x-filament::icon
                    icon="heroicon-o-magnifying-glass"
                    class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400 dark:text-gray-500"
                />
                <input
                    type="search"
                    wire:model.live.debounce.400ms="tripSearch"
                    placeholder="Tìm chuyến đi, lái xe, BSX, khu vực..."
                    class="h-10 w-full rounded-lg border border-gray-200 bg-white pl-9 pr-3 text-sm text-gray-700 outline-none transition-all placeholder:text-gray-400 focus:border-[#008fd5] focus:ring-2 focus:ring-[#008fd5]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:placeholder:text-gray-500"
                />
            </div>
        </div>
        {{-- Table --}}
        {{ $this->table }}
    </div>
</x-filament-panels::page>
