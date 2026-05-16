@php
    use App\Models\Order;
    /** @var Order $order */
    $checkpoints = $order->tripCheckpoints()
        ->with(['driver', 'deliveryPoint.location', 'photos'])
        ->orderBy('occurred_at', 'desc')
        ->get();

    $iconMap = [
        'started'          => ['icon' => 'heroicon-o-play-circle',     'color' => 'blue'],
        'arrived_pickup'   => ['icon' => 'heroicon-o-truck',           'color' => 'amber'],
        'left_pickup'      => ['icon' => 'heroicon-o-arrow-right-circle', 'color' => 'amber'],
        'arrived_delivery' => ['icon' => 'heroicon-o-map-pin',         'color' => 'orange'],
        'driver_swap'      => ['icon' => 'heroicon-o-arrow-path',      'color' => 'purple'],
        'completed'        => ['icon' => 'heroicon-o-check-circle',    'color' => 'emerald'],
    ];

    $colorRing = [
        'blue'    => 'ring-blue-200 dark:ring-blue-800',
        'amber'   => 'ring-amber-200 dark:ring-amber-800',
        'orange'  => 'ring-orange-200 dark:ring-orange-800',
        'purple'  => 'ring-purple-200 dark:ring-purple-800',
        'emerald' => 'ring-emerald-200 dark:ring-emerald-800',
    ];

    $colorIcon = [
        'blue'    => 'text-blue-600 dark:text-blue-400',
        'amber'   => 'text-amber-600 dark:text-amber-400',
        'orange'  => 'text-orange-600 dark:text-orange-400',
        'purple'  => 'text-purple-600 dark:text-purple-400',
        'emerald' => 'text-emerald-600 dark:text-emerald-400',
    ];
@endphp

<div class="space-y-4">
    {{-- Header --}}
    <div class="flex flex-wrap items-center gap-x-6 gap-y-1 rounded-lg bg-gray-50 px-4 py-3 dark:bg-gray-800">
        <div>
            <span class="text-xs text-gray-500 dark:text-gray-400">Mã chuyến</span>
            <span class="ml-2 text-sm font-bold text-gray-900 dark:text-white">{{ $order->order_code }}</span>
        </div>
        <div>
            <span class="text-xs text-gray-500 dark:text-gray-400">Xe</span>
            <span class="ml-2 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $order->vehicle?->plate_number ?? '—' }}</span>
        </div>
        <div>
            <span class="text-xs text-gray-500 dark:text-gray-400">Lái xe</span>
            <span class="ml-2 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $order->driver?->name ?? '—' }}</span>
        </div>
    </div>

    {{-- Timeline --}}
    @if ($checkpoints->isEmpty())
        <div class="flex flex-col items-center justify-center py-10 text-center">
            <x-filament::icon icon="heroicon-o-map-pin" class="mb-3 h-10 w-10 text-gray-300 dark:text-gray-600" />
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Chưa có mốc hành trình</p>
            <p class="text-xs text-gray-400 dark:text-gray-500">Chuyến đi này chưa có dữ liệu được ghi nhận.</p>
        </div>
    @else
        <div x-data="{ expanded: false, limit: 5 }" class="relative">
            @foreach ($checkpoints as $i => $cp)
                @php
                    $meta = $iconMap[$cp->checkpoint_type->value] ?? ['icon' => 'heroicon-o-question-mark-circle', 'color' => 'gray'];
                    $ring = $colorRing[$meta['color']] ?? 'ring-gray-200 dark:ring-gray-700';
                    $iconCls = $colorIcon[$meta['color']] ?? 'text-gray-500 dark:text-gray-400';
                    $hidden = $i >= 5;
                @endphp

                <div @if ($hidden) x-show="expanded" x-collapse @endif
                     class="flex gap-x-3">
                    {{-- Icon + line --}}
                    <div class="relative flex flex-col items-center @if (!$loop->last) after:absolute after:top-10 after:bottom-0 after:w-px after:bg-gray-200 dark:after:bg-gray-700 @endif">
                        <div class="relative z-10 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-white ring-1 {{ $ring }} dark:bg-gray-900">
                            <x-filament::icon icon="{{ $meta['icon'] }}" class="h-4 w-4 {{ $iconCls }}" />
                        </div>
                    </div>

                    {{-- Content --}}
                    <div class="grow pt-1 {{ $loop->last ? 'mb-0' : 'mb-5' }}">
                        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">
                                {{ $cp->checkpoint_type->getLabel() }}
                                @if ($cp->driver?->name)
                                    <span class="font-normal text-gray-400 dark:text-gray-500">— {{ $cp->driver->name }}</span>
                                @endif
                            </p>
                            <span class="flex-shrink-0 text-xs text-gray-400 dark:text-gray-500">
                                {{ $cp->occurred_at?->format('H:i d/m/Y') ?? '—' }}
                            </span>
                        </div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $cp->deliveryPoint?->address ?? $cp->deliveryPoint?->location?->name ?? '—' }}
                        </p>
                        @if ($cp->km_reading || ($cp->gps_lat && $cp->gps_lng) || $cp->voice_note)
                            <div class="mt-1 flex flex-wrap gap-x-3 text-xs text-gray-400 dark:text-gray-500">
                                @if ($cp->km_reading) <span>{{ number_format((float) $cp->km_reading, 1, ',', '.') }} km</span> @endif
                                @if ($cp->gps_lat && $cp->gps_lng) <span>{{ number_format((float) $cp->gps_lat, 4, ',', '.') }}, {{ number_format((float) $cp->gps_lng, 4, ',', '.') }}</span> @endif
                                @if ($cp->voice_note) <span class="italic">{{ $cp->voice_note }}</span> @endif
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach

            @if ($checkpoints->count() > 5)
                <div class="mt-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                    <button type="button" x-on:click="expanded = !expanded" x-show="!expanded"
                            class="inline-flex cursor-pointer items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">
                        <x-filament::icon icon="heroicon-o-chevron-down" class="h-4 w-4" />
                        Xem thêm {{ $checkpoints->count() - 5 }} mốc
                    </button>
                    <button type="button" x-on:click="expanded = false" x-show="expanded"
                            class="inline-flex cursor-pointer items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">
                        <x-filament::icon icon="heroicon-o-chevron-up" class="h-4 w-4" />
                        Thu gọn
                    </button>
                </div>
            @endif
        </div>
    @endif
</div>
