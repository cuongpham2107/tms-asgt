<div class="flex flex-wrap items-center gap-2">
    <x-filament::badge color="danger" icon="heroicon-m-flag">
        Kết thúc chuyến
    </x-filament::badge>
    <span class="text-sm font-medium text-gray-950 dark:text-white">
        {{ $trip_code }}
        @if($total_km)
            <span class="text-xs font-normal text-gray-500 dark:text-gray-400">· Tổng: <span class="tabular-nums">{{ number_format((float) $total_km, 1) }}</span> km</span>
        @endif
    </span>
</div>
