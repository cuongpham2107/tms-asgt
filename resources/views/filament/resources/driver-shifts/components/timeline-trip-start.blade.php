<div class="flex items-center gap-2">
    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-green-50 text-green-700 border border-green-200">
        🚀 Bắt đầu chuyến
    </span>
    <span class="text-sm text-gray-600">
        {{ $trip_code }}
        @if($km)
            <span class="text-gray-400">· {{ $km }}</span>
        @endif
    </span>
</div>
