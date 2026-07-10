<div class="flex items-center gap-2">
    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-red-50 text-red-700 border border-red-200">
        🏁 Kết thúc chuyến
    </span>
    <span class="text-sm text-gray-600">
        {{ $trip_code }}
        @if($km)
            <span class="text-gray-400">· {{ $km }}</span>
        @endif
        @if($total_km)
            <span class="text-gray-400">· Tổng: {{ number_format((float) $total_km, 1) }} km</span>
        @endif
    </span>
</div>
