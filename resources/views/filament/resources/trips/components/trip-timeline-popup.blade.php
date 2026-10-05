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
        'started'          => 'heroicon-o-play-circle',
        'arrived_pickup'   => 'heroicon-o-truck',
        'left_pickup'      => 'heroicon-o-arrow-right-circle',
        'arrived_delivery' => 'heroicon-o-map-pin',
        'driver_swap'      => 'heroicon-o-arrow-path',
        'completed'        => 'heroicon-o-check-circle',
        'end'              => 'heroicon-o-flag',
        'cancelled'        => 'heroicon-o-x-circle',
    ];

    $semanticColors = ['primary', 'gray', 'success', 'warning', 'danger', 'info'];

    $orderCodes = $orders->pluck('order_code')->implode(', ');

    $renderIcon = fn (string $icon, string $classes): string => Blade::render(
        '<x-filament::icon icon="'.$icon.'" class="'.$classes.'" />'
    );

    $renderCheckpoint = function ($cp, $loop) use ($iconMap, $semanticColors, $photoUrl, $renderIcon) {
        $icon = $iconMap[$cp->checkpoint_type->value] ?? 'heroicon-o-question-mark-circle';
        $color = $cp->checkpoint_type->getColor();
        $color = in_array($color, $semanticColors, true) ? $color : 'gray';

        $endAfter = !$loop->last;
        $lastCls = $loop->last ? 'mb-0' : 'mb-5';

        $html = '<div class="flex gap-x-3">';
        $html .= '<div class="relative flex flex-col items-center'.($endAfter ? ' after:absolute after:top-8 after:bottom-0 after:w-px after:bg-gray-200 dark:after:bg-white/10' : '').'">';
        $html .= '<div class="fi-color-'.$color.' relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-(--color-50) text-(--color-600) ring-1 ring-(--color-600)/20 dark:bg-(--color-400)/10 dark:text-(--color-400) dark:ring-(--color-400)/30">';
        $html .= $renderIcon($icon, 'h-4 w-4');
        $html .= '</div></div>';
        $html .= '<div class="min-w-0 grow pt-1 '.$lastCls.'">';
        $html .= '<div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5">';
        $html .= '<p class="text-sm font-semibold text-gray-950 dark:text-white">';
        $html .= e($cp->checkpoint_type->getLabel());
        if ($cp->driver?->name) {
            $html .= ' <span class="font-normal text-gray-500 dark:text-gray-400">— '.e($cp->driver->name).'</span>';
        }
        $html .= '</p>';
        $html .= '<span class="shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400">'.e($cp->occurred_at?->format('H:i d/m/Y') ?? '—').'</span>';
        $html .= '</div>';
        $html .= '<p class="text-sm text-gray-500 dark:text-gray-400">'.e($cp->deliveryPoint?->location?->code ?? $cp->deliveryPoint?->location?->code ?? '—').'</p>';

        $hasExtra = ($cp->gps_lat && $cp->gps_lng) || $cp->voice_note;
        if ($hasExtra) {
            $html .= '<div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">';
            if ($cp->gps_lat && $cp->gps_lng) {
                $html .= '<span class="inline-flex items-center gap-1 tabular-nums">'.$renderIcon('heroicon-m-map-pin', 'h-3.5 w-3.5 text-gray-400 dark:text-gray-500').e(number_format((float) $cp->gps_lat, 4, ',', '.')).', '.e(number_format((float) $cp->gps_lng, 4, ',', '.')).'</span>';
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
                $html .= ' class="group block overflow-hidden rounded-lg bg-gray-50 ring-1 ring-gray-950/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 dark:bg-white/5 dark:ring-white/10">';
                $html .= '<img src="'.e($url).'" alt="Ảnh hành trình '.$label.'" loading="lazy"';
                $html .= ' class="h-24 w-full object-cover transition duration-150 group-hover:scale-105 motion-reduce:transition-none motion-reduce:group-hover:scale-100" />';
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
    <dl class="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl bg-gray-50 px-4 py-3 dark:bg-white/5">
        <div class="flex min-w-0 items-baseline gap-2">
            <dt class="text-xs text-gray-500 dark:text-gray-400">Đơn hàng</dt>
            <dd class="truncate text-sm font-semibold text-gray-950 dark:text-white" title="{{ $orderCodes }}">{{ $orderCodes ?: '—' }}</dd>
        </div>
        <div class="flex items-baseline gap-2">
            <dt class="text-xs text-gray-500 dark:text-gray-400">Xe</dt>
            <dd class="text-sm font-semibold text-gray-950 dark:text-white">{{ $trip->vehicle?->plate_number ?? '—' }}</dd>
        </div>
        <div class="flex items-baseline gap-2">
            <dt class="text-xs text-gray-500 dark:text-gray-400">Lái xe</dt>
            <dd class="text-sm font-semibold text-gray-950 dark:text-white">{{ $trip->driver?->name ?? '—' }}</dd>
        </div>
    </dl>

    {{-- Single order: flat timeline --}}
    @if ($orders->count() === 1)
        @php $order = $orders->first(); @endphp
        @php $checkpoints = $order->tripCheckpoints; @endphp

        @if ($checkpoints->isEmpty())
            <div class="flex flex-col items-center justify-center py-10 text-center">
                <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-500/20">
                    <x-filament::icon icon="heroicon-o-map-pin" class="h-6 w-6 text-gray-500 dark:text-gray-400" />
                </div>
                <p class="text-base font-semibold text-gray-950 dark:text-white">Chưa có mốc hành trình</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Chuyến đi này chưa có dữ liệu được ghi nhận.</p>
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
                    <div class="mt-3 border-t border-gray-200 pt-3 dark:border-white/10">
                        <x-filament::link tag="button" type="button" x-on:click="expanded = !expanded" x-show="!expanded" icon="heroicon-m-chevron-down" size="sm">
                            Xem thêm {{ $checkpoints->count() - 5 }} mốc
                        </x-filament::link>
                        <x-filament::link tag="button" type="button" x-on:click="expanded = false" x-show="expanded" icon="heroicon-m-chevron-up" size="sm">
                            Thu gọn
                        </x-filament::link>
                    </div>
                @endif
            </div>
        @endif
    @else
        {{-- Multiple orders: grouped by order --}}
        <div x-data="{ expanded: false }" class="space-y-4">
            @foreach ($orders as $orderIdx => $order)
                @php $checkpoints = $order->tripCheckpoints; @endphp

                <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    {{-- Order header --}}
                    <header class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-gray-200 px-4 py-3 dark:border-white/10">
                        <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                        <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ $order->order_code }}</span>
                        @if ($order->customer)
                            <span class="text-xs text-gray-500 dark:text-gray-400">— {{ $order->customer->name }}</span>
                        @endif
                        @if ($order->pickupLocation)
                            <span class="ml-auto truncate text-xs text-gray-500 dark:text-gray-400" title="{{ $order->pickupLocation->name }}">{{ $order->pickupLocation->name }}</span>
                        @endif
                    </header>

                    {{-- Order timeline --}}
                    <div class="p-4">
                        @if ($checkpoints->isEmpty())
                            <p class="text-center text-sm text-gray-500 dark:text-gray-400">Chưa có mốc hành trình</p>
                        @else
                            <div x-data="{ expandedOrder: {{ $orderIdx === 0 ? 'true' : 'false' }} }">
                                @foreach ($checkpoints as $i => $cp)
                                    @php $hidden = $i >= 5; @endphp
                                    <div @if ($hidden) x-show="expandedOrder" x-collapse @endif>
                                        {{ $renderCheckpoint($cp, $loop) }}
                                    </div>
                                @endforeach

                                @if ($checkpoints->count() > 5)
                                    <div class="mt-3 border-t border-gray-200 pt-3 dark:border-white/10">
                                        <x-filament::link tag="button" type="button" x-on:click="expandedOrder = !expandedOrder" x-show="!expandedOrder" icon="heroicon-m-chevron-down" size="sm">
                                            Xem thêm {{ $checkpoints->count() - 5 }} mốc
                                        </x-filament::link>
                                        <x-filament::link tag="button" type="button" x-on:click="expandedOrder = false" x-show="expandedOrder" icon="heroicon-m-chevron-up" size="sm">
                                            Thu gọn
                                        </x-filament::link>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                </section>
            @endforeach

            {{-- Trip-level checkpoints (no order_id) --}}
            @if ($tripCheckpoints->isNotEmpty())
                <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <header class="flex items-center gap-3 border-b border-gray-200 px-4 py-3 dark:border-white/10">
                        <x-filament::icon icon="heroicon-o-truck" class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                        <span class="text-sm font-semibold text-gray-950 dark:text-white">Chuyến xe</span>
                    </header>
                    <div class="p-4">
                        @foreach ($tripCheckpoints as $cp)
                            {{ $renderCheckpoint($cp, $loop) }}
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    @endif
</div>
