<div class="flex flex-col h-full p-2">
    <!-- Top Section -->
    <div class="flex items-start gap-4 mb-5">
        <div
            class="w-10 h-10 flex items-center justify-center rounded-lg bg-orange-50 dark:bg-orange-950 text-orange-500 shrink-0 border border-orange-100/50 dark:border-orange-900/50">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                </path>
            </svg>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-lg! font-bold text-gray-900 dark:text-white truncate" title="{{ $getRecord()->name }}">
                {{ $getRecord()->name }}
            </h2>
            <p class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5 font-medium tracking-wider">
                {{ $getRecord()->orders_count ?? 0 }} đơn hàng
            </p>
        </div>
    </div>

    <!-- Info Section -->
    <div class="space-y-3 mb-6 flex-1">
        <div class="flex items-center gap-3 text-[13px] text-gray-500 dark:text-gray-400">
            <div class="w-4 h-4 flex items-center justify-center shrink-0">
                <x-heroicon-m-phone class="w-4 h-4 text-gray-300 dark:text-gray-600" />
            </div>
            <span class="truncate font-semibold">{{ $getRecord()->phone ?? '-' }}</span>
        </div>
        <div class="flex items-center gap-3 text-[13px] text-gray-500 dark:text-gray-400">
            <div class="w-4 h-4 flex items-center justify-center shrink-0">
                <x-heroicon-m-envelope class="w-4 h-4 text-gray-300 dark:text-gray-600" />
            </div>
            <span class="truncate font-semibold">{{ $getRecord()->email ?? '-' }}</span>
        </div>
        <div class="flex items-start gap-3 text-[13px] text-gray-500 dark:text-gray-400 leading-tight">
            <div class="w-4 h-4 flex items-center justify-center shrink-0 mt-0.5">
                <x-heroicon-m-map-pin class="w-4 h-4 text-gray-300 dark:text-gray-600" />
            </div>
            <span class="line-clamp-2 font-semibold">{{ $getRecord()->address ?? '-' }}</span>
        </div>
    </div>


</div>
