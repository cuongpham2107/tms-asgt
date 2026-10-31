@php
    $trips = isset($trips) 
        ? $trips 
        : (isset($trip) ? collect([$trip]) : collect());
@endphp

<div class="space-y-4 text-sm">
    {{-- Header thông tin xe và thống kê trong ngày --}}
    <div class="rounded-xl bg-gray-50/70 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10 flex flex-wrap items-center justify-between gap-3 text-xs">
        <div class="flex flex-wrap items-center gap-2">
            <span class="font-bold text-sm text-gray-900 dark:text-white">{{ $vehicle->plate_number }}</span>
            @if ($vehicle->load_capacity)
                <x-filament::badge color="gray" size="xs">
                    {{ round((float) $vehicle->load_capacity, 1) }}T
                </x-filament::badge>
            @endif
            <span class="text-gray-300 dark:text-gray-600">|</span>
            <span class="text-gray-600 dark:text-gray-400">
                Lái xe: <strong class="text-gray-900 dark:text-white font-semibold">{{ \App\Filament\Pages\VehicleDispatchBoard::getDriverName($vehicle) }}</strong>
            </span>
            <span class="text-gray-300 dark:text-gray-600">|</span>
            <span class="text-gray-600 dark:text-gray-400">
                Ca trực: <strong class="text-gray-900 dark:text-white font-semibold">{{ \App\Services\ShiftScheduleService::getShiftDate()->format('d/m/Y') }} (08:00 - 08:00)</strong>
            </span>
        </div>

        <div class="flex flex-wrap items-center gap-1.5">
            <x-filament::badge color="info" size="sm">
                Tổng: {{ $trips->count() }} chuyến
            </x-filament::badge>
            @php
                $runningStatuses = \App\Filament\Pages\VehicleDispatchBoard::runningTripStatuses();
                $activeCount = $trips->filter(fn($t) => in_array($t->status, $runningStatuses, true))->count();
                $pendingCount = $trips->where('status', \App\Enums\TripStatus::Pending)->count();
                $completedCount = $trips->where('status', \App\Enums\TripStatus::Completed)->count();
                $totalKmSum = $trips->sum('total_km');
            @endphp
            @if ($activeCount > 0)
                <x-filament::badge color="warning" size="sm">
                    {{ $activeCount }} đang chạy
                </x-filament::badge>
            @endif
            @if ($pendingCount > 0)
                <x-filament::badge color="purple" size="sm">
                    {{ $pendingCount }} chờ chạy
                </x-filament::badge>
            @endif
            @if ($completedCount > 0)
                <x-filament::badge color="success" size="sm">
                    {{ $completedCount }} hoàn thành
                </x-filament::badge>
            @endif
            @if ($totalKmSum > 0)
                <x-filament::badge color="gray" size="sm">
                    {{ number_format($totalKmSum, 1) }} km
                </x-filament::badge>
            @endif
        </div>
    </div>

    {{-- Danh sách chuyến xe trong ngày --}}
    @if ($trips->isEmpty())
        <div class="py-10 text-center rounded-xl bg-gray-50/50 dark:bg-white/[0.02] ring-1 ring-gray-950/5 dark:ring-white/10">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 dark:bg-white/5 text-gray-400 mb-3">
                <x-filament::icon icon="heroicon-o-truck" class="w-6 h-6" />
            </div>
            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                Không có chuyến xe nào trong ngày hôm nay
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Xe {{ $vehicle->plate_number }} chưa thực hiện chuyến xe nào vào ngày {{ \App\Services\ShiftScheduleService::getShiftDate()->format('d/m/Y') }}.
            </p>
        </div>
    @else
        <div class="space-y-4 max-h-[70vh] overflow-y-auto pe-1">
            @foreach ($trips as $index => $t)
                @php
                    $runningStatuses = \App\Filament\Pages\VehicleDispatchBoard::runningTripStatuses();
                    $isActive = in_array($t->status, $runningStatuses, true);
                    $isPending = $t->status === \App\Enums\TripStatus::Pending;
                    $isCompleted = $t->status === \App\Enums\TripStatus::Completed;
                    
                    $accentBorder = $isActive 
                        ? 'border-s-4 border-s-amber-500' 
                        : ($isPending 
                            ? 'border-s-4 border-s-purple-500' 
                            : ($isCompleted ? 'border-s-4 border-s-emerald-500' : 'border-s-4 border-s-gray-300 dark:border-s-gray-600'));
                    
                    $pickupCode = $t->startLocation?->code ?? ($t->orders->first()?->pickupLocation?->code ?? $t->orders->first()?->pickup_address);
                    $deliveryCodes = $t->endLocation?->code ?? $t->orders->flatMap->deliveryPoints->map(fn ($dp) => $dp->location?->code ?? $dp->address)->filter()->unique()->join(', ');
                    $customerCodes = $t->orders->map(fn ($order) => $order->customer?->code)->filter()->unique();
                @endphp

                <div class="rounded-xl bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10 shadow-xs overflow-hidden {{ $accentBorder }}">
                    {{-- Tiêu đề của chuyến xe --}}
                    <div class="px-4 py-2.5 bg-gray-50/60 dark:bg-white/[0.02] border-b border-gray-100 dark:border-white/5 flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <x-filament::badge :color="$isActive ? 'warning' : ($isPending ? 'purple' : ($isCompleted ? 'success' : 'gray'))" size="sm">
                                Chuyến {{ $index + 1 }}
                            </x-filament::badge>
                            <span class="font-bold text-sm text-gray-900 dark:text-white">
                                {{ $t->trip_code }}
                            </span>
                            @if ($t->is_empty_run)
                                <x-filament::badge color="purple" size="xs">
                                    Chạy rỗng
                                </x-filament::badge>
                            @endif
                            @foreach ($customerCodes as $customerCode)
                                <x-filament::badge color="info" size="xs" title="Mã khách hàng">
                                    {{ $customerCode }}
                                </x-filament::badge>
                            @endforeach
                        </div>

                        <div class="flex items-center gap-2">
                            <x-filament::badge :color="$t->status?->getColor() ?? ($isCompleted ? 'success' : ($isActive ? 'warning' : ($isPending ? 'purple' : 'gray')))" size="sm">
                                @if ($isActive && ! $isCompleted)
                                    <span class="w-1.5 h-1.5 rounded-full bg-current animate-pulse me-1"></span>
                                @endif
                                {{ $t->getStatusLabel() }}
                            </x-filament::badge>

                            <x-filament::button 
                                tag="a"
                                href="{{ \App\Filament\Resources\Trips\TripResource::getUrl('timeline', ['record' => $t]) }}" 
                                target="_blank"
                                size="xs"
                                color="gray"
                                outlined
                                icon="heroicon-m-arrow-top-right-on-square"
                                icon-position="after"
                                title="Xem hành trình chi tiết trong tab mới"
                            >
                                Hành trình
                            </x-filament::button>
                        </div>
                    </div>

                    {{-- Nội dung thông tin chi tiết của chuyến --}}
                    <div class="p-4 space-y-3.5">
                        <div class="grid grid-cols-2 gap-3 text-xs lg:grid-cols-4">
                            <div>
                                <span class="text-[11px] font-medium text-gray-500 dark:text-gray-400 block">Lái xe</span>
                                <span class="text-xs font-semibold text-gray-900 dark:text-white mt-0.5 block truncate" title="{{ $t->driver?->name }}">
                                    {{ $t->driver?->name ?? '—' }}
                                </span>
                                @if ($t->driver?->phone)
                                    <span class="text-[11px] text-gray-500 dark:text-gray-400 font-mono">{{ $t->driver->phone }}</span>
                                @endif
                            </div>

                            <div>
                                <span class="text-[11px] font-medium text-gray-500 dark:text-gray-400 block">Bắt đầu</span>
                                <span class="text-xs font-semibold text-gray-900 dark:text-white mt-0.5 block">
                                    {{ $t->started_at ? $t->started_at->format('H:i d/m') : 'Chưa bắt đầu' }}
                                </span>
                                @if ($t->start_km)
                                    <span class="text-[11px] text-gray-500 dark:text-gray-400 font-mono">Km: {{ number_format($t->start_km, 1) }}</span>
                                @endif
                            </div>

                            <div>
                                <span class="text-[11px] font-medium text-gray-500 dark:text-gray-400 block">Kết thúc</span>
                                <span class="text-xs font-semibold text-gray-900 dark:text-white mt-0.5 block">
                                    {{ $t->completed_at ? $t->completed_at->format('H:i d/m') : ($isActive ? 'Đang chạy' : ($isPending ? 'Chờ chạy' : '—')) }}
                                </span>
                                @if ($t->end_km)
                                    <span class="text-[11px] text-gray-500 dark:text-gray-400 font-mono">Km: {{ number_format($t->end_km, 1) }}</span>
                                @endif
                            </div>

                            <div>
                                <span class="text-[11px] font-medium text-gray-500 dark:text-gray-400 block">Tổng Km / Đơn</span>
                                <span class="text-xs font-semibold text-gray-900 dark:text-white mt-0.5 block">
                                    {{ $t->total_km ? number_format($t->total_km, 1) . ' km' : '—' }}
                                </span>
                                <span class="text-[11px] text-gray-500 dark:text-gray-400">{{ $t->orders->count() }} đơn hàng</span>
                            </div>
                        </div>

                        {{-- Tuyến đường vắn tắt nếu có --}}
                        @if ($pickupCode || $deliveryCodes)
                            <div class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300 bg-gray-50 dark:bg-white/5 px-3 py-2 rounded-lg">
                                <x-filament::icon icon="heroicon-m-map-pin" class="w-4 h-4 text-gray-400 shrink-0" />
                                <span class="text-gray-500 dark:text-gray-400 shrink-0 font-medium">Lộ trình:</span>
                                <span class="font-mono font-medium text-gray-900 dark:text-white truncate">{{ $pickupCode ?: '—' }}</span>
                                <x-filament::icon icon="heroicon-m-arrow-right" class="w-3.5 h-3.5 text-gray-400 shrink-0" />
                                <span class="font-mono font-medium text-gray-900 dark:text-white truncate">{{ $deliveryCodes ?: '—' }}</span>
                            </div>
                        @endif

                        {{-- Danh sách đơn hàng trong chuyến --}}
                        @if ($t->orders->isNotEmpty())
                            <div class="border-t border-gray-100 dark:border-white/5 pt-3">
                                <div class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2.5 flex items-center justify-between">
                                    <span>Danh sách đơn hàng ({{ $t->orders->count() }} đơn)</span>
                                </div>
                                <div class="space-y-2 max-h-56 overflow-y-auto pe-1">
                                    @foreach ($t->orders as $order)
                                        <div class="rounded-lg bg-gray-50/70 dark:bg-white/[0.03] p-3 text-xs space-y-1.5">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="font-bold text-gray-900 dark:text-white">{{ $order->order_code }}</span>
                                                <x-filament::badge size="xs" :color="$order->status?->getColor() ?? 'gray'">
                                                    {{ $order->status?->getLabel() ?? (string) $order->status }}
                                                </x-filament::badge>
                                                @if ($order->customer)
                                                    <span class="text-[11px] text-gray-500 dark:text-gray-400 truncate max-w-[260px]">
                                                        ({{ $order->customer->code }} · {{ $order->customer->name }})
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1 text-[11px] text-gray-600 dark:text-gray-300">
                                                <span class="inline-flex items-baseline gap-1">
                                                    <span class="text-gray-400">Điểm đi:</span>
                                                    <span class="font-mono font-medium text-gray-800 dark:text-gray-200">{{ $order->pickupLocation?->code ?? $order->pickup_address ?? '—' }}</span>
                                                </span>
                                                <span class="inline-flex items-baseline gap-1">
                                                    <span class="text-gray-400">Điểm đến:</span>
                                                    <span class="font-mono font-medium text-gray-800 dark:text-gray-200">{{ $order->deliveryPoints->map(fn($dp) => $dp->location?->code ?? $dp->address)->filter()->join(', ') ?: '—' }}</span>
                                                </span>
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

