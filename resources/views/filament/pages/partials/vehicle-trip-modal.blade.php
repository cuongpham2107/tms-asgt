@php
    $trips = isset($trips) 
        ? $trips 
        : (isset($trip) ? collect([$trip]) : collect());
@endphp

<div class="space-y-4 text-sm">
    {{-- Header thông tin xe và thống kê trong ngày --}}
    <div class="bg-gray-50 dark:bg-gray-800/80 p-3.5 rounded-xl border border-gray-200 dark:border-gray-700 flex flex-wrap items-center justify-between gap-3 text-xs">
        <div class="flex flex-wrap items-center gap-2">
            <span class="font-bold text-sm text-gray-900 dark:text-white">{{ $vehicle->plate_number }}</span>
            @if ($vehicle->load_capacity)
                <span class="px-1.5 py-0.5 rounded bg-gray-200/80 dark:bg-gray-700 text-gray-700 dark:text-gray-300 font-medium text-[11px]">
                    {{ round((float) $vehicle->load_capacity, 1) }}T
                </span>
            @endif
            <span class="text-gray-400">|</span>
            <span class="text-gray-600 dark:text-gray-300">
                Lái xe: <strong class="text-gray-900 dark:text-white">{{ \App\Filament\Pages\VehicleDispatchBoard::getDriverName($vehicle) }}</strong>
            </span>
            <span class="text-gray-400">|</span>
            <span class="text-gray-500 dark:text-gray-400">
                Ngày: <strong class="text-gray-700 dark:text-gray-300">{{ today()->format('d/m/Y') }}</strong>
            </span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="px-2.5 py-0.5 rounded-full bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-300 font-semibold text-xs">
                Tổng: {{ $trips->count() }} chuyến
            </span>
            @php
                $activeCount = $trips->filter(fn($t) => in_array($t->status, \App\Enums\TripStatus::activeStatuses(), true))->count();
                $completedCount = $trips->where('status', \App\Enums\TripStatus::Completed)->count();
                $totalKmSum = $trips->sum('total_km');
            @endphp
            @if ($activeCount > 0)
                <span class="px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300 font-semibold text-xs">
                    {{ $activeCount }} đang chạy
                </span>
            @endif
            @if ($completedCount > 0)
                <span class="px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300 font-semibold text-xs">
                    {{ $completedCount }} hoàn thành
                </span>
            @endif
            @if ($totalKmSum > 0)
                <span class="px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700/60 text-slate-700 dark:text-slate-200 font-medium text-xs">
                    {{ number_format($totalKmSum, 1) }} km
                </span>
            @endif
        </div>
    </div>

    {{-- Danh sách chuyến xe trong ngày --}}
    @if ($trips->isEmpty())
        <div class="py-10 text-center border border-dashed border-gray-200 dark:border-gray-700 rounded-xl bg-gray-50/50 dark:bg-gray-800/30">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800 text-gray-400 mb-3">
                <x-filament::icon icon="heroicon-o-truck" class="w-6 h-6" />
            </div>
            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                Không có chuyến xe nào trong ngày hôm nay
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Xe {{ $vehicle->plate_number }} chưa thực hiện chuyến xe nào vào ngày {{ today()->format('d/m/Y') }}.
            </p>
        </div>
    @else
        <div class="space-y-4 max-h-[70vh] overflow-y-auto pr-1">
            @foreach ($trips as $index => $t)
                @php
                    $isActive = in_array($t->status, \App\Enums\TripStatus::activeStatuses(), true);
                    $isCompleted = $t->status === \App\Enums\TripStatus::Completed;
                    $cardBorder = $isActive 
                        ? 'border-amber-400 dark:border-amber-600 ring-1 ring-amber-400/50' 
                        : ($isCompleted ? 'border-emerald-300 dark:border-emerald-800' : 'border-gray-200 dark:border-gray-700');
                    $headerBg = $isActive 
                        ? 'bg-amber-50/70 dark:bg-amber-950/20' 
                        : ($isCompleted ? 'bg-emerald-50/50 dark:bg-emerald-950/20' : 'bg-gray-50 dark:bg-gray-800/50');
                    
                    $pickupName = $t->startLocation?->name ?? ($t->orders->first()?->pickupLocation?->name ?? $t->orders->first()?->pickup_address);
                    $deliveryNames = $t->endLocation?->name ?? $t->orders->flatMap->deliveryPoints->map(fn($dp) => $dp->location?->name ?? $dp->address)->filter()->unique()->join(', ');
                @endphp

                <div class="rounded-xl border {{ $cardBorder }} bg-white dark:bg-gray-900 overflow-hidden shadow-xs">
                    {{-- Tiêu đề của chuyến xe --}}
                    <div class="px-3.5 py-2.5 border-b border-gray-100 dark:border-gray-800 {{ $headerBg }} flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded text-xs font-bold {{ $isActive ? 'bg-amber-500 text-white' : ($isCompleted ? 'bg-emerald-600 text-white' : 'bg-gray-600 text-white') }}">
                                Chuyến {{ $index + 1 }}
                            </span>
                            <span class="font-bold text-sm text-gray-900 dark:text-white">
                                {{ $t->trip_code }}
                            </span>
                            @if ($t->is_empty_run)
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-purple-100 text-purple-700 dark:bg-purple-900/50 dark:text-purple-300">
                                    Chạy rỗng
                                </span>
                            @endif
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $t->isPaused() ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-300' : ($isCompleted ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-300' : ($isActive ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/60 dark:text-blue-300' : 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300')) }}">
                                @if ($isActive && ! $isCompleted)
                                    <span class="w-1.5 h-1.5 rounded-full bg-current animate-pulse"></span>
                                @endif
                                {{ $t->getStatusLabel() }}
                            </span>

                            <a 
                                href="{{ \App\Filament\Resources\Trips\TripResource::getUrl('timeline', ['record' => $t]) }}" 
                                target="_blank"
                                title="Xem hành trình chi tiết trong tab mới"
                                class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400 hover:underline px-2 py-1 rounded bg-white/90 dark:bg-gray-800 border border-gray-200 dark:border-gray-700"
                            >
                                <span>Hành trình</span>
                                <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="w-3.5 h-3.5" />
                            </a>
                        </div>
                    </div>

                    {{-- Nội dung thông tin chi tiết của chuyến --}}
                    <div class="p-3.5 space-y-3">
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 text-xs">
                            <div class="bg-gray-50 dark:bg-gray-800/50 p-2.5 rounded-lg border border-gray-100 dark:border-gray-800">
                                <span class="text-gray-500 text-[11px] block">Lái xe</span>
                                <strong class="text-gray-900 dark:text-white mt-0.5 block truncate" title="{{ $t->driver?->name }}">
                                    {{ $t->driver?->name ?? '—' }}
                                </strong>
                                @if ($t->driver?->phone)
                                    <span class="text-[10px] text-gray-500">{{ $t->driver->phone }}</span>
                                @endif
                            </div>

                            <div class="bg-gray-50 dark:bg-gray-800/50 p-2.5 rounded-lg border border-gray-100 dark:border-gray-800">
                                <span class="text-gray-500 text-[11px] block">Bắt đầu</span>
                                <strong class="text-gray-900 dark:text-white mt-0.5 block">
                                    {{ $t->started_at ? $t->started_at->format('H:i d/m') : 'Chưa bắt đầu' }}
                                </strong>
                                @if ($t->start_km)
                                    <span class="text-[10px] text-gray-500">Km: {{ number_format($t->start_km, 1) }}</span>
                                @endif
                            </div>

                            <div class="bg-gray-50 dark:bg-gray-800/50 p-2.5 rounded-lg border border-gray-100 dark:border-gray-800">
                                <span class="text-gray-500 text-[11px] block">Kết thúc</span>
                                <strong class="text-gray-900 dark:text-white mt-0.5 block">
                                    {{ $t->completed_at ? $t->completed_at->format('H:i d/m') : ($isActive ? 'Đang chạy' : '—') }}
                                </strong>
                                @if ($t->end_km)
                                    <span class="text-[10px] text-gray-500">Km: {{ number_format($t->end_km, 1) }}</span>
                                @endif
                            </div>

                            <div class="bg-gray-50 dark:bg-gray-800/50 p-2.5 rounded-lg border border-gray-100 dark:border-gray-800">
                                <span class="text-gray-500 text-[11px] block">Tổng Km / Đơn</span>
                                <strong class="text-gray-900 dark:text-white mt-0.5 block">
                                    {{ $t->total_km ? number_format($t->total_km, 1) . ' km' : '—' }}
                                </strong>
                                <span class="text-[10px] text-gray-500">{{ $t->orders->count() }} đơn hàng</span>
                            </div>
                        </div>

                        {{-- Tuyến đường vắn tắt nếu có --}}
                        @if ($pickupName || $deliveryNames)
                            <div class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300 bg-slate-50 dark:bg-slate-800/40 px-3 py-1.5 rounded-lg border border-slate-200/60 dark:border-slate-700/60">
                                <span class="text-gray-400 shrink-0 font-medium">Lộ trình:</span>
                                <span class="font-semibold text-gray-800 dark:text-gray-200 truncate">{{ $pickupName ?: '—' }}</span>
                                <x-filament::icon icon="heroicon-m-arrow-right" class="w-3.5 h-3.5 text-gray-400 shrink-0" />
                                <span class="font-semibold text-gray-800 dark:text-gray-200 truncate">{{ $deliveryNames ?: '—' }}</span>
                            </div>
                        @endif

                        {{-- Danh sách đơn hàng trong chuyến --}}
                        @if ($t->orders->isNotEmpty())
                            <div class="border-t border-gray-100 dark:border-gray-800 pt-2.5">
                                <div class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2 flex items-center justify-between">
                                    <span>Danh sách đơn hàng ({{ $t->orders->count() }} đơn)</span>
                                </div>
                                <div class="space-y-2 max-h-56 overflow-y-auto pr-1">
                                    @foreach ($t->orders as $order)
                                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-2.5 bg-gray-50/60 dark:bg-gray-800/40 text-xs">
                                            <div class="flex items-center justify-between mb-1.5">
                                                <div class="flex items-center gap-2">
                                                    <span class="font-bold text-gray-900 dark:text-white">{{ $order->order_code }}</span>
                                                    @if ($order->customer)
                                                        <span class="text-[11px] text-gray-500 dark:text-gray-400 truncate max-w-[200px]">
                                                            ({{ $order->customer->name }})
                                                        </span>
                                                    @endif
                                                </div>
                                                <span class="text-[10px] px-2 py-0.5 rounded-full font-medium {{ $order->status?->value === 'delivered' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300' }}">
                                                    {{ $order->status?->getLabel() ?? (string) $order->status }}
                                                </span>
                                            </div>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 text-[11px] text-gray-600 dark:text-gray-300">
                                                <div>
                                                    <span class="text-gray-400">Điểm đi:</span>
                                                    <span class="font-medium text-gray-800 dark:text-gray-200">
                                                        {{ $order->pickupLocation?->name ?? $order->pickup_address ?? '—' }}
                                                    </span>
                                                </div>
                                                <div>
                                                    <span class="text-gray-400">Điểm đến:</span>
                                                    <span class="font-medium text-gray-800 dark:text-gray-200">
                                                        {{ $order->deliveryPoints->map(fn($dp) => $dp->location?->name ?? $dp->address)->filter()->join(', ') ?: '—' }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @elseif ($t->is_empty_run)
                            <div class="text-xs text-purple-600 dark:text-purple-400 italic py-1">
                                Chuyến xe chạy không hàng (Chạy rỗng).
                            </div>
                        @else
                            <div class="text-xs text-gray-400 italic py-1">
                                Chưa gán đơn hàng vào chuyến này.
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
