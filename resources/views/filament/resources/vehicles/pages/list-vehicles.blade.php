<x-filament-panels::page>
    {{-- Filters Bar --}}
    <div class="rounded-xl px-2 flex flex-col divide-y divide-gray-100 dark:divide-gray-800">
        {{ $this->filtersForm }}
    </div>
    <!-- Search & Toggle Section -->
    <!-- <div class="flex items-center gap-1">
        <div class="relative max-w-75 flex-1">
            <x-filament::icon icon="heroicon-o-magnifying-glass"
                class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 text-[13px]" />
            <input placeholder="Tìm biển số, loại xe..."
                class="w-full h-9 bg-white border border-gray-200 rounded-lg pl-9 pr-3 text-[12.5px] text-gray-700 placeholder:text-gray-400 focus:outline-none focus:border-[#008fd5] transition-all dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200 dark:placeholder:text-gray-500 dark:focus:border-[#008fd5]"
                type="text" value="">
        </div>
    </div> -->
    {{ $this->table }}
</x-filament-panels::page>
