<x-filament-panels::page>
    @php
        $stats = $this->getStats();
    @endphp

    <div class="space-y-4">
        {{-- Stats bar --}}
        <div class="flex gap-3 overflow-x-auto pb-2">
            <div class="min-w-[120px] shrink-0 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
                <span class="text-xs text-gray-500 dark:text-gray-400">Tổng xe</span>
                <p class="text-xl font-bold text-gray-900 dark:text-white">{{ $stats['total'] }}</p>
            </div>
            <div class="min-w-[120px] shrink-0 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-950/30">
                <span class="text-xs text-amber-600 dark:text-amber-400">Đang chạy</span>
                <p class="text-xl font-bold text-amber-700 dark:text-amber-300">{{ $stats['running'] }}</p>
            </div>
            <div class="min-w-[120px] shrink-0 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-800 dark:bg-emerald-950/30">
                <span class="text-xs text-emerald-600 dark:text-emerald-400">Sẵn sàng</span>
                <p class="text-xl font-bold text-emerald-700 dark:text-emerald-300">{{ $stats['on'] }}</p>
            </div>
            <div class="min-w-[120px] shrink-0 rounded-lg border border-red-200 bg-red-50 px-4 py-3 dark:border-red-800 dark:bg-red-950/30">
                <span class="text-xs text-red-600 dark:text-red-400">BDSC</span>
                <p class="text-xl font-bold text-red-700 dark:text-red-300">{{ $stats['bdsc'] }}</p>
            </div>
            <div class="min-w-[120px] shrink-0 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-900/50">
                <span class="text-xs text-gray-500 dark:text-gray-400">Tắt</span>
                <p class="text-xl font-bold text-gray-600 dark:text-gray-300">{{ $stats['off'] }}</p>
            </div>
        </div>

        {{-- Leaflet Map --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <x-filament-leaflet::map
                :config="$this->getMapData()"
                widget
            />
        </div>

        {{-- Info bar --}}
        <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-700 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-300">
            <strong>🗺️ Leaflet Tracking</strong> — dùng thư viện <code>eduardoribeirodev/filament-leaflet</code> (OpenStreetMap miễn phí).
            Marker màu theo trạng thái xe, polyline từ GPS checkpoint, popup hiển thị chi tiết đơn hàng.
        </div>
    </div>
</x-filament-panels::page>
