@php
    use App\Models\Trip;
    use Illuminate\Support\Facades\Blade;
    use Illuminate\Support\Facades\Storage;

    /** @var Trip $trip */
    $orders = $trip->orders()
        ->with([
            'customer',
            'pickupLocation',
            'tripCheckpoints' => fn ($q) => $q
                ->with(['driver', 'deliveryPoint.location', 'photos'])
                ->orderBy('occurred_at', 'desc'),
        ])
        ->orderBy('planned_loading_at')
        ->get();

    $tripCheckpoints = $trip->checkpoints()
        ->with(['driver', 'deliveryPoint.location', 'photos'])
        ->whereNull('order_id')
        ->orderBy('occurred_at', 'desc')
        ->get();

    $photoUrl = static fn ($photo): string => $photo->photo_url ?: Storage::disk('public')->url($photo->photo_path);

    $iconMap = [
        'started'          => ['icon' => 'heroicon-o-play-circle',       'color' => 'blue'],
        'arrived_pickup'   => ['icon' => 'heroicon-o-truck',             'color' => 'amber'],
        'left_pickup'      => ['icon' => 'heroicon-o-arrow-right-circle','color' => 'amber'],
        'arrived_delivery' => ['icon' => 'heroicon-o-map-pin',           'color' => 'orange'],
        'driver_swap'      => ['icon' => 'heroicon-o-arrow-path',        'color' => 'purple'],
        'completed'        => ['icon' => 'heroicon-o-check-circle',      'color' => 'emerald'],
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

    $orderCodes = $orders->pluck('order_code')->implode(', ');

    $renderIcon = fn (string $icon, string $classes): string => Blade::render(
        '<x-filament::icon icon="'.$icon.'" class="'.$classes.'" />'
    );

    $renderCheckpoint = function ($cp, $loop) use ($iconMap, $colorRing, $colorIcon, $photoUrl, $renderIcon) {
        $meta = $iconMap[$cp->checkpoint_type->value] ?? ['icon' => 'heroicon-o-question-mark-circle', 'color' => 'gray'];
        $ring = $colorRing[$meta['color']] ?? 'ring-gray-200 dark:ring-gray-700';
        $iconCls = $colorIcon[$meta['color']] ?? 'text-gray-500 dark:text-gray-400';

        $endAfter = !$loop->last;
        $lastCls = $loop->last ? 'mb-0' : 'mb-5';

        $html = '<div class="flex gap-x-3">';
        $html .= '<div class="relative flex flex-col items-center'.($endAfter ? ' after:absolute after:top-10 after:bottom-0 after:w-px after:bg-gray-200 dark:after:bg-gray-700' : '').'">';
        $html .= '<div class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white ring-1 '.$ring.' dark:bg-gray-900">';
        $html .= $renderIcon($meta['icon'], 'h-4 w-4 '.$iconCls);
        $html .= '</div></div>';
        $html .= '<div class="grow pt-1 '.$lastCls.'">';
        $html .= '<div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5">';
        $html .= '<p class="text-sm font-semibold text-gray-900 dark:text-white">';
        $html .= e($cp->checkpoint_type->getLabel());
        if ($cp->driver?->name) {
            $html .= '<span class="font-normal text-gray-400 dark:text-gray-500">— '.e($cp->driver->name).'</span>';
        }
        $html .= '</p>';
        $html .= '<span class="shrink-0 text-xs text-gray-400 dark:text-gray-500">'.e($cp->occurred_at?->format('H:i d/m/Y') ?? '—').'</span>';
        $html .= '</div>';
        $html .= '<p class="text-sm text-gray-500 dark:text-gray-400">'.e($cp->deliveryPoint?->address ?? $cp->deliveryPoint?->location?->name ?? '—').'</p>';

        $hasExtra = $cp->km_reading || ($cp->gps_lat && $cp->gps_lng) || $cp->voice_note;
        if ($hasExtra) {
            $html .= '<div class="mt-1 flex flex-wrap gap-x-3 text-xs text-gray-400 dark:text-gray-500">';
            if ($cp->km_reading) {
                $html .= '<span>'.e(number_format((float) $cp->km_reading, 1, ',', '.')).' km</span>';
            }
            if ($cp->gps_lat && $cp->gps_lng) {
                $html .= '<span>'.e(number_format((float) $cp->gps_lat, 4, ',', '.')).', '.e(number_format((float) $cp->gps_lng, 4, ',', '.')).'</span>';
            }
            if ($cp->voice_note) {
                $html .= '<span class="italic">'.e($cp->voice_note).'</span>';
            }
            $html .= '</div>';
        }

        if ($cp->photos->isNotEmpty()) {
            $html .= '<div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">';
            foreach ($cp->photos as $photo) {
                $url = $photoUrl($photo);
                $label = e($cp->checkpoint_type->getLabel());
                $html .= '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer"';
                $html .= ' class="group block overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800">';
                $html .= '<img src="'.e($url).'" alt="Ảnh hành trình '.$label.'" loading="lazy"';
                $html .= ' class="h-24 w-full object-cover transition duration-150 group-hover:scale-105" />';
                $html .= '</a>';
            }
            $html .= '</div>';
        }

        $html .= '</div></div>';

        return new Illuminate\Support\HtmlString($html);
    };
@endphp

<div class="space-y-4">
    {{-- Header --}}
    <div class="flex flex-wrap items-center gap-x-6 gap-y-1 rounded-lg bg-gray-50 px-4 py-3 dark:bg-gray-800">
        <div>
            <span class="text-xs text-gray-500 dark:text-gray-400">Đơn hàng</span>
            <span class="ml-2 text-sm font-bold text-gray-900 dark:text-white">{{ $orderCodes ?: '—' }}</span>
        </div>
        <div>
            <span class="text-xs text-gray-500 dark:text-gray-400">Xe</span>
            <span class="ml-2 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $trip->vehicle?->plate_number ?? '—' }}</span>
        </div>
        <div>
            <span class="text-xs text-gray-500 dark:text-gray-400">Lái xe</span>
            <span class="ml-2 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $trip->driver?->name ?? '—' }}</span>
        </div>
    </div>

    {{-- Single order: flat timeline --}}
    @if ($orders->count() === 1)
        @php $order = $orders->first(); @endphp
        @php $checkpoints = $order->tripCheckpoints; @endphp

        @if ($checkpoints->isEmpty())
            <div class="flex flex-col items-center justify-center py-10 text-center">
                <x-filament::icon icon="heroicon-o-map-pin" class="mb-3 h-10 w-10 text-gray-300 dark:text-gray-600" />
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Chưa có mốc hành trình</p>
                <p class="text-xs text-gray-400 dark:text-gray-500">Chuyến đi này chưa có dữ liệu được ghi nhận.</p>
            </div>
        @else
            <div x-data="{ expanded: false }" class="relative">
                @foreach ($checkpoints as $i => $cp)
                    @php $hidden = $i >= 5; @endphp
                    <div @if ($hidden) x-show="expanded" x-collapse @endif>
                        {{ $renderCheckpoint($cp, $loop) }}
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
    @else
        {{-- Multiple orders: grouped by order --}}
        <div x-data="{ expanded: false }" class="space-y-6">
            @foreach ($orders as $orderIdx => $order)
                @php $checkpoints = $order->tripCheckpoints; @endphp

                <div class="rounded-lg border border-gray-200 dark:border-gray-700">
                    {{-- Order header --}}
                    <div class="flex items-center gap-3 rounded-t-lg bg-gray-50 px-4 py-2.5 dark:bg-gray-800">
                        <x-filament::icon icon="heroicon-o-document-text" class="h-4 w-4 text-gray-400 dark:text-gray-500" />
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $order->order_code }}</span>
                        @if ($order->customer)
                            <span class="text-xs text-gray-400 dark:text-gray-500">— {{ $order->customer->name }}</span>
                        @endif
                        @if ($order->pickupLocation)
                            <span class="ml-auto text-xs text-gray-400 dark:text-gray-500">{{ $order->pickupLocation->name }}</span>
                        @endif
                    </div>

                    {{-- Order timeline --}}
                    <div class="px-4 py-3">
                        @if ($checkpoints->isEmpty())
                            <p class="text-center text-sm text-gray-400 dark:text-gray-500">Chưa có mốc hành trình</p>
                        @else
                            <div x-data="{ expandedOrder: {{ $orderIdx === 0 ? 'true' : 'false' }} }">
                                @foreach ($checkpoints as $i => $cp)
                                    @php $hidden = $i >= 5; @endphp
                                    <div @if ($hidden) x-show="expandedOrder" x-collapse @endif>
                                        {{ $renderCheckpoint($cp, $loop) }}
                                    </div>
                                @endforeach

                                @if ($checkpoints->count() > 5)
                                    <div class="mt-2 border-t border-gray-100 pt-2 dark:border-gray-800">
                                        <button type="button" x-on:click="expandedOrder = !expandedOrder" x-show="!expandedOrder"
                                                class="inline-flex cursor-pointer items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">
                                            <x-filament::icon icon="heroicon-o-chevron-down" class="h-4 w-4" />
                                            Xem thêm {{ $checkpoints->count() - 5 }} mốc
                                        </button>
                                        <button type="button" x-on:click="expandedOrder = false" x-show="expandedOrder"
                                                class="inline-flex cursor-pointer items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">
                                            <x-filament::icon icon="heroicon-o-chevron-up" class="h-4 w-4" />
                                            Thu gọn
                                        </button>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach

            {{-- Trip-level checkpoints (no order_id) --}}
            @if ($tripCheckpoints->isNotEmpty())
                <div class="rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center gap-3 rounded-t-lg bg-gray-50 px-4 py-2.5 dark:bg-gray-800">
                        <x-filament::icon icon="heroicon-o-truck" class="h-4 w-4 text-gray-400 dark:text-gray-500" />
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">Chuyến xe</span>
                    </div>
                    <div class="px-4 py-3">
                        @foreach ($tripCheckpoints as $cp)
                            {{ $renderCheckpoint($cp, $loop) }}
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
