<div class="space-y-4 text-sm">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 bg-gray-50 dark:bg-gray-800/80 p-3.5 rounded-xl border border-gray-200 dark:border-gray-700">
        <div>
            <div class="text-xs text-gray-500 font-medium">Mã chuyến</div>
            <div class="font-bold text-primary-600 dark:text-primary-400 mt-0.5">{{ $trip->trip_code }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 font-medium">Trạng thái</div>
            <div class="mt-0.5">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-primary-100 text-primary-800 dark:bg-primary-900/50 dark:text-primary-300">
                    {{ $trip->getStatusLabel() }}
                </span>
            </div>
        </div>
        <div>
            <div class="text-xs text-gray-500 font-medium">Lái xe</div>
            <div class="font-bold text-gray-900 dark:text-white mt-0.5">{{ $trip->driver?->name ?? '—' }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 font-medium">Bắt đầu</div>
            <div class="text-gray-700 dark:text-gray-300 mt-0.5">{{ $trip->started_at ? $trip->started_at->format('H:i d/m') : 'Chưa bắt đầu' }}</div>
        </div>
    </div>

    @if ($trip->orders->isNotEmpty())
        <div>
            <h4 class="font-semibold text-gray-900 dark:text-white mb-2 text-xs uppercase tracking-wider">Danh sách đơn hàng ({{ $trip->orders->count() }} đơn)</h4>
            <div class="space-y-2 max-h-60 overflow-y-auto">
                @foreach ($trip->orders as $order)
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 bg-white dark:bg-gray-800">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="font-bold text-gray-900 dark:text-white">{{ $order->order_code }}</span>
                            <span class="text-xs text-gray-500">{{ $order->customer?->name ?? 'Khách hàng' }}</span>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-xs text-gray-600 dark:text-gray-300">
                            <div>
                                <span class="text-gray-400">Điểm đi:</span>
                                <span class="font-medium text-gray-800 dark:text-gray-200">{{ $order->pickupLocation?->name ?? $order->pickup_address ?? '—' }}</span>
                            </div>
                            <div>
                                <span class="text-gray-400">Điểm đến:</span>
                                <span class="font-medium text-gray-800 dark:text-gray-200">
                                    {{ $order->deliveryPoints->map(fn($dp) => $dp->location?->name ?? $dp->address)->join(', ') ?: '—' }}
                                </span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="text-xs text-gray-500 italic p-4 text-center border border-dashed rounded-lg">Chưa gán đơn hàng vào chuyến này.</div>
    @endif
</div>
