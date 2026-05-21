<x-filament-panels::page>
    <div class="space-y-4">
        {{-- Stats Section --}}
        <div>
            <p class="mb-3 text-sm font-semibold text-gray-500 dark:text-gray-400">Kiểm soát tổng thể</p>
            <div class="grid grid-cols-5 gap-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Tổng chuyến</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $this->getTripStats()['total'] }}</p>
                </div>
                <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800/50 dark:bg-blue-950/30">
                    <p class="text-xs font-medium text-blue-600 dark:text-blue-400">Đang chạy</p>
                    <p class="mt-1 text-2xl font-bold text-blue-700 dark:text-blue-300">{{ $this->getTripStats()['running'] }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Kế hoạch</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $this->getTripStats()['planned'] }}</p>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800/50 dark:bg-emerald-950/30">
                    <p class="text-xs font-medium text-emerald-600 dark:text-emerald-400">Hoàn thành</p>
                    <p class="mt-1 text-2xl font-bold text-emerald-700 dark:text-emerald-300">{{ $this->getTripStats()['completed'] }}</p>
                </div>
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-800/50 dark:bg-red-950/30">
                    <p class="text-xs font-medium text-red-600 dark:text-red-400">Trễ giờ</p>
                    <p class="mt-1 text-2xl font-bold text-red-700 dark:text-red-300">{{ $this->getTripStats()['delayed'] }}</p>
                </div>
            </div>
        </div>
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
