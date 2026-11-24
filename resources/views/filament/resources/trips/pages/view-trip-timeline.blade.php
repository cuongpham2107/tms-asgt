<x-filament-panels::page>
    @php
        $timeline = $this->getTimelineData();
        $order = $timeline['order'];
        $osmValidation = $timeline['osm_validation'] ?? null;
        $checkpoints = $timeline['checkpoints'];

        $iconMap = [
            'started'          => ['icon' => 'heroicon-o-play-circle',         'color' => 'blue'],
            'arrived_pickup'   => ['icon' => 'heroicon-o-truck',               'color' => 'amber'],
            'left_pickup'      => ['icon' => 'heroicon-o-arrow-right-circle',  'color' => 'amber'],
            'arrived_delivery' => ['icon' => 'heroicon-o-map-pin',             'color' => 'orange'],
            'driver_swap'      => ['icon' => 'heroicon-o-arrow-path',          'color' => 'purple'],
            'completed'        => ['icon' => 'heroicon-o-check-circle',        'color' => 'emerald'],
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

    <div class="space-y-6">
        {{-- Header: Thông tin chuyến đi --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-center gap-x-8 gap-y-2">
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Mã chuyến</span>
                    <p class="text-lg font-bold text-gray-900 dark:text-white">{{ $order['order_code'] }}</p>
                </div>
                @if (! empty($order['customer_code']) && $order['customer_code'] !== '—')
                    <div>
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Khách hàng</span>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $order['customer_code'] }}</p>
                    </div>
                @endif
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Trạng thái</span>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $order['status_label'] }}</p>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Xe</span>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $order['vehicle_plate'] }}</p>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Lái xe</span>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $order['driver_name'] }}</p>
                </div>
            </div>
        </div>

        {{-- Thẻ đối soát Km qua OSM --}}
        @if ($osmValidation)
            @if ($osmValidation['overall_status'] === 'valid')
                <div class="rounded-xl border border-emerald-200 bg-emerald-50/70 p-4 shadow-sm dark:border-emerald-900/60 dark:bg-emerald-950/30">
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
                <div class="rounded-xl border border-amber-300 bg-amber-50/80 p-4 shadow-sm dark:border-amber-800/80 dark:bg-amber-950/30">
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
                <div class="rounded-xl border border-rose-300 bg-rose-50/90 p-4 shadow-sm dark:border-rose-800/80 dark:bg-rose-950/40">
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
            @endif
        @endif

        {{-- Timeline Section --}}
        <div x-data="{ showAll: false, limit: 5 }" class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            {{-- Section header --}}
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">Mốc hành trình</h3>
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ count($checkpoints) }} mốc đã ghi nhận</p>
                </div>
            </div>

            {{-- Timeline body --}}
            <div class="px-5 py-4">
                @if (empty($checkpoints))
                    {{-- Empty state --}}
                    <div class="flex flex-col items-center justify-center py-12 text-center">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                            <x-filament::icon icon="heroicon-o-map-pin" class="h-6 w-6 text-gray-400 dark:text-gray-500" />
                        </div>
                        <h4 class="text-base font-semibold text-gray-950 dark:text-white">Chưa có mốc hành trình</h4>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Chuyến đi này chưa có dữ liệu hành trình được ghi nhận.</p>
                    </div>
                @else
                    <div class="relative">
                        @foreach ($checkpoints as $index => $cp)
                            @php
                                $meta = $iconMap[$cp['type_value']] ?? ['icon' => 'heroicon-o-question-mark-circle', 'color' => 'gray'];
                                $ring = $colorRing[$meta['color']] ?? 'ring-gray-200 dark:ring-gray-700';
                                $iconCls = $colorIcon[$meta['color']] ?? 'text-gray-500 dark:text-gray-400';
                                $isLast = $loop->last;
                                $isHidden = !$loop->first && $index >= 5;
                                $seg = $cp['osm_segment'] ?? null;
                                $geoWarnings = $cp['geo_warnings'] ?? [];
                                $photos = $cp['photos'] ?? [];
                            @endphp

                            <div @if ($isHidden) x-show="showAll" x-collapse @endif
                                 class="flex gap-x-4">
                                {{-- Icon + line --}}
                                <div class="relative flex flex-col items-center @if (!$isLast) after:absolute after:top-10 after:bottom-0 after:w-px after:bg-gray-200 dark:after:bg-gray-700 @endif">
                                    <div class="relative z-10 flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-white ring-1 {{ $ring }} dark:bg-gray-900">
                                        <x-filament::icon icon="{{ $meta['icon'] }}" class="h-4 w-4 {{ $iconCls }}" />
                                    </div>
                                </div>

                                {{-- Content --}}
                                <div class="grow pt-1 {{ $isLast ? 'mb-0' : 'mb-6' }}">
                                    {{-- Header row: type + time --}}
                                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                                            {{ $cp['type_label'] }}
                                            @if ($cp['driver_name'])
                                                <span class="font-normal text-gray-500 dark:text-gray-400">— {{ $cp['driver_name'] }}</span>
                                            @endif
                                        </h4>
                                        <span class="flex-shrink-0 text-xs font-medium text-gray-400 dark:text-gray-500">
                                            {{ $cp['occurred_at'] }}
                                        </span>
                                    </div>

                                    {{-- Address / Location code --}}
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $cp['address'] }}</p>

                                    {{-- Extra info row --}}
                                    @if ($cp['km_reading'] || $cp['gps'] || $cp['voice_note'])
                                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1">
                                            @if ($cp['km_reading'])
                                                <span class="inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                                    <x-filament::icon icon="heroicon-o-truck" class="h-3.5 w-3.5" />
                                                    {{ $cp['km_reading'] }}
                                                </span>
                                            @endif
                                            @if ($cp['gps'])
                                                <span class="inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                                    <x-filament::icon icon="heroicon-o-map-pin" class="h-3.5 w-3.5" />
                                                    {{ $cp['gps'] }}
                                                </span>
                                            @endif
                                        </div>
                                    @endif

                                    @if ($cp['voice_note'])
                                        <p class="mt-1 text-xs italic text-gray-400 dark:text-gray-500">{{ $cp['voice_note'] }}</p>
                                    @endif

                                    {{-- OSM Segment Badge --}}
                                    @if ($seg !== null)
                                        @php
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
                                        @endphp
                                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                            <span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs ring-1 ring-inset {{ $badgeCls }}">
                                                <x-filament::icon icon="{{ $iconName }}" class="h-3.5 w-3.5 shrink-0" />
                                                <span>{{ $seg['message'] }}</span>
                                            </span>
                                            @if ($seg['has_gps'] && $seg['osm_km'] !== null && $seg['osm_km'] >= 0.3)
                                                <span class="text-xs text-gray-400 dark:text-gray-500">
                                                    (Chặng: +{{ number_format((float) $seg['driver_delta_km'], 1, ',', '.') }} km | OSM: ~{{ number_format((float) $seg['osm_km'], 1, ',', '.') }} km)
                                                </span>
                                            @endif
                                        </div>
                                    @endif

                                    {{-- Geofence warnings --}}
                                    @if (! empty($geoWarnings))
                                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                            @foreach ($geoWarnings as $warning)
                                                <span class="inline-flex items-center gap-1.5 rounded-md bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-700 ring-1 ring-inset ring-rose-600/30 dark:bg-rose-950/50 dark:text-rose-300 dark:ring-rose-500/30">
                                                    <x-filament::icon icon="heroicon-m-map-pin" class="h-3.5 w-3.5 shrink-0" />
                                                    <span>Sai vị trí: {{ $warning }}</span>
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif

                                    {{-- Photos --}}
                                    @if (! empty($photos))
                                        <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
                                            @foreach ($photos as $photo)
                                                @if ($photo['is_valid'])
                                                    <a href="{{ $photo['url'] }}" target="_blank" rel="noopener noreferrer"
                                                       class="group relative block overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800">
                                                        <img src="{{ $photo['url'] }}" alt="Ảnh hành trình {{ $cp['type_label'] }}" loading="lazy"
                                                             class="h-24 w-full object-cover transition duration-150 group-hover:scale-105" />
                                                    </a>
                                                @else
                                                    <div class="flex h-24 flex-col items-center justify-center rounded-lg border border-dashed border-amber-300 bg-amber-50/50 p-2 text-center dark:border-amber-700 dark:bg-amber-950/30">
                                                        <span class="text-[11px] font-medium text-amber-700 dark:text-amber-300">Ảnh lỗi tải lên (0 KB)</span>
                                                        <span class="text-[10px] text-gray-400">File rỗng từ thiết bị</span>
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Show more button --}}
                    @if (count($checkpoints) > 5)
                        <div class="mt-4 border-t border-gray-100 pt-3 dark:border-gray-800">
                            <button type="button" x-on:click="showAll = !showAll"
                                    x-show="!showAll"
                                    class="inline-flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">
                                <x-filament::icon icon="heroicon-o-chevron-down" class="h-4 w-4" />
                                <span>Xem thêm {{ count($checkpoints) - 5 }} mốc</span>
                            </button>
                            <button type="button" x-on:click="showAll = false"
                                    x-show="showAll"
                                    class="inline-flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">
                                <x-filament::icon icon="heroicon-o-chevron-up" class="h-4 w-4" />
                                <span>Thu gọn</span>
                            </button>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
