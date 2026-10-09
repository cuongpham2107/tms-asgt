@php
    use App\Models\Trip;
    use App\Services\Trip\TripCheckpointOsmValidationService;
    use Illuminate\Support\Facades\Blade;
    use Illuminate\Support\Facades\Storage;

    /** @var Trip $trip */
    $osmValidation = $osmValidation ?? app(TripCheckpointOsmValidationService::class)->validateTrip($trip);
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

    $photoUrl = static function ($photo): string {
        if (! empty($photo->photo_path)) {
            return asset('storage/'.ltrim($photo->photo_path, '/'));
        }

        if (! empty($photo->photo_url)) {
            if (preg_match('#/storage/(.+)$#', $photo->photo_url, $matches)) {
                return asset('storage/'.ltrim($matches[1], '/'));
            }

            return $photo->photo_url;
        }

        return '';
    };

    $isPhotoValid = static function ($photo): bool {
        if (empty($photo->photo_path)) {
            return false;
        }
        $fullPath = storage_path('app/public/'.ltrim($photo->photo_path, '/'));

        return file_exists($fullPath) && filesize($fullPath) > 0;
    };


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

    $renderCheckpoint = function ($cp, $loop) use ($iconMap, $colorRing, $colorIcon, $photoUrl, $isPhotoValid, $renderIcon, $osmValidation) {
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
        $html .= '<p class="text-sm text-gray-500 dark:text-gray-400">'.e($cp->deliveryPoint?->location?->code ?? $cp->deliveryPoint?->location?->code ?? '—').'</p>';

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

        $seg = $osmValidation['segments'][$cp->id] ?? null;
        if ($seg !== null) {
            $badgeCls = match ($seg['status']) {
                'valid'   => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-400 dark:ring-emerald-500/30',
                'warning' => 'bg-amber-50 text-amber-800 ring-amber-600/30 dark:bg-amber-950/50 dark:text-amber-300 dark:ring-amber-500/30 font-medium',
                'danger'  => 'bg-rose-50 text-rose-700 ring-rose-600/30 dark:bg-rose-950/50 dark:text-rose-300 dark:ring-rose-500/30 font-semibold',
                'no_gps'  => 'bg-gray-100 text-gray-600 ring-gray-500/20 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-700',
                default   => 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400',
            };

            $iconName = match ($seg['status']) {
                'valid'   => 'heroicon-m-check-circle',
                'warning' => 'heroicon-m-exclamation-triangle',
                'danger'  => 'heroicon-m-shield-exclamation',
                'no_gps'  => 'heroicon-m-signal-slash',
                default   => 'heroicon-m-information-circle',
            };

            $html .= '<div class="mt-1.5 flex flex-wrap items-center gap-1.5">';
            $html .= '<span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs ring-1 ring-inset '.$badgeCls.'">';
            $html .= $renderIcon($iconName, 'h-3.5 w-3.5 shrink-0');
            $html .= '<span>'.e($seg['message']).'</span>';
            $html .= '</span>';
            if ($seg['has_gps'] && $seg['osm_km'] !== null && $seg['osm_km'] >= 0.3) {
                $html .= '<span class="text-xs text-gray-400 dark:text-gray-500">';
                $html .= '(Chặng: +'.e(number_format((float) $seg['driver_delta_km'], 1, ',', '.')).' km | OSM: ~'.e(number_format((float) $seg['osm_km'], 1, ',', '.')).' km)';
                $html .= '</span>';
            }
            $html .= '</div>';
        }

        if ($cp->photos->isNotEmpty()) {
            $html .= '<div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">';
            foreach ($cp->photos as $photo) {
                $url = $photoUrl($photo);
                $label = e($cp->checkpoint_type->getLabel());
                $valid = $isPhotoValid($photo);

                if ($valid) {
                    $html .= '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer"';
                    $html .= ' class="group relative block overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800">';
                    $html .= '<img src="'.e($url).'" alt="Ảnh hành trình '.$label.'" loading="lazy"';
                    $html .= ' class="h-24 w-full object-cover transition duration-150 group-hover:scale-105"';
                    $html .= ' onerror="this.onerror=null; this.parentElement.innerHTML=\'<div class=\\\'flex h-24 flex-col items-center justify-center p-2 text-center text-xs text-rose-500\\\'><span>Ảnh lỗi tải</span></div>\';" />';
                    $html .= '</a>';
                } else {
                    $html .= '<div class="flex h-24 flex-col items-center justify-center rounded-lg border border-dashed border-amber-300 bg-amber-50/50 p-2 text-center dark:border-amber-700 dark:bg-amber-950/30">';
                    $html .= '<svg class="mb-1 h-5 w-5 text-amber-500 dark:text-amber-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>';
                    $html .= '<span class="text-[11px] font-medium text-amber-700 dark:text-amber-300">Ảnh lỗi tải lên (0 KB)</span>';
                    $html .= '<span class="text-[10px] text-gray-400">File rỗng từ thiết bị</span>';
                    $html .= '</div>';
                }
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

    {{-- Thẻ đối soát Km qua OSM --}}
    @if ($osmValidation['overall_status'] === 'valid')
        <div class="rounded-lg border border-emerald-200 bg-emerald-50/70 p-3.5 dark:border-emerald-900/60 dark:bg-emerald-950/30">
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-s-check-badge" class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                <div class="grow space-y-1">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-sm font-semibold text-emerald-900 dark:text-emerald-200">
                            Đối soát Km qua OSM: Hợp lệ
                        </span>
                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-300">
                            Đạt chuẩn lộ trình
                        </span>
                    </div>
                    <p class="text-xs text-emerald-700 dark:text-emerald-300">
                        Số km tài xế ghi nhận giữa các mốc khớp với độ dài đường bộ tính toán từ bản đồ OSM.
                    </p>
                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-emerald-800 dark:text-emerald-300">
                        <span>Tổng Km tài xế: <strong>{{ number_format($osmValidation['total_driver_km'], 1, ',', '.') }} km</strong></span>
                        <span>Định tuyến OSM: <strong>~{{ number_format($osmValidation['total_osm_km'], 1, ',', '.') }} km</strong></span>
                        <span>Chênh lệch: <strong>{{ ($osmValidation['diff_km'] >= 0 ? '+' : '') . number_format($osmValidation['diff_km'], 1, ',', '.') }} km ({{ ($osmValidation['diff_percent'] >= 0 ? '+' : '') . number_format($osmValidation['diff_percent'], 1) }}%)</strong></span>
                    </div>
                </div>
            </div>
        </div>
    @elseif ($osmValidation['overall_status'] === 'warning')
        <div class="rounded-lg border border-amber-300 bg-amber-50/80 p-3.5 dark:border-amber-800/80 dark:bg-amber-950/30">
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-s-exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
                <div class="grow space-y-1">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-sm font-semibold text-amber-900 dark:text-amber-200">
                            Cảnh báo đối soát Km qua OSM ({{ $osmValidation['warning_count'] }} chặng lệch)
                        </span>
                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/60 dark:text-amber-300">
                            Cần lưu ý
                        </span>
                    </div>
                    <p class="text-xs text-amber-700 dark:text-amber-300">
                        Phát hiện chênh lệch giữa số km tài xế nhập và khoảng cách định tuyến đường bộ từ OSM.
                    </p>
                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-amber-800 dark:text-amber-300">
                        <span>Tổng Km tài xế: <strong>{{ number_format($osmValidation['total_driver_km'], 1, ',', '.') }} km</strong></span>
                        <span>Định tuyến OSM: <strong>~{{ number_format($osmValidation['total_osm_km'], 1, ',', '.') }} km</strong></span>
                        <span>Chênh lệch: <strong class="text-amber-900 dark:text-amber-100">{{ ($osmValidation['diff_km'] >= 0 ? '+' : '') . number_format($osmValidation['diff_km'], 1, ',', '.') }} km ({{ ($osmValidation['diff_percent'] >= 0 ? '+' : '') . number_format($osmValidation['diff_percent'], 1) }}%)</strong></span>
                    </div>
                </div>
            </div>
        </div>
    @elseif ($osmValidation['overall_status'] === 'danger')
        <div class="rounded-lg border border-rose-300 bg-rose-50/90 p-3.5 dark:border-rose-800/80 dark:bg-rose-950/40">
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-s-shield-exclamation" class="mt-0.5 h-5 w-5 shrink-0 text-rose-600 dark:text-rose-400" />
                <div class="grow space-y-1">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-sm font-semibold text-rose-900 dark:text-rose-200">
                            Bất thường số Km đối soát OSM ({{ $osmValidation['danger_count'] }} chặng sai lệch lớn)
                        </span>
                        <span class="inline-flex items-center rounded-full bg-rose-100 px-2 py-0.5 text-xs font-bold text-rose-800 dark:bg-rose-900/60 dark:text-rose-300">
                            Bất thường
                        </span>
                    </div>
                    <p class="text-xs text-rose-700 dark:text-rose-300">
                        Phát hiện mốc bị lùi số Km hoặc số Km khai báo vượt xa định tuyến bản đồ thực tế (>35%).
                    </p>
                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-rose-800 dark:text-rose-300">
                        <span>Tổng Km tài xế: <strong>{{ number_format($osmValidation['total_driver_km'], 1, ',', '.') }} km</strong></span>
                        <span>Định tuyến OSM: <strong>~{{ number_format($osmValidation['total_osm_km'], 1, ',', '.') }} km</strong></span>
                        <span>Chênh lệch: <strong class="text-rose-900 dark:text-rose-100">{{ ($osmValidation['diff_km'] >= 0 ? '+' : '') . number_format($osmValidation['diff_km'], 1, ',', '.') }} km ({{ ($osmValidation['diff_percent'] >= 0 ? '+' : '') . number_format($osmValidation['diff_percent'], 1) }}%)</strong></span>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="rounded-lg border border-gray-200 bg-gray-50/70 p-3 text-xs text-gray-500 dark:border-gray-700 dark:bg-gray-800/50 dark:text-gray-400">
            <div class="flex items-center gap-2">
                <x-filament::icon icon="heroicon-o-information-circle" class="h-4 w-4 text-gray-400" />
                <span>Chưa đủ tối thiểu 2 mốc hành trình có toạ độ GPS để thực hiện đối soát tự động qua OSM.</span>
            </div>
        </div>
    @endif

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
