<x-filament-panels::page>
    @php
        $groupedVehicles = $this->getGroupedVehicles();
    @endphp

    {{-- Global Loading Overlay khi mở popup (Ngăn click liên tục & hiển thị loading mượt mà) --}}
    <div 
        wire:loading
        wire:target="openTripModal, openGpsModal" 
        class="fixed inset-0 z-50 bg-gray-950/40 dark:bg-black/60 backdrop-blur-[2px] transition-opacity select-none cursor-wait"
        style="display: none;"
    >
        <div class="fixed top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 inline-flex items-center gap-3.5 px-6 py-4 rounded-2xl bg-white dark:bg-gray-900 shadow-2xl ring-1 ring-gray-950/10 dark:ring-white/10 text-primary-600 dark:text-primary-400">
            <svg class="animate-spin h-6 w-6 text-primary-600 dark:text-primary-400 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <div class="flex flex-col text-left">
                <span class="text-sm font-bold text-gray-900 dark:text-white leading-tight">Đang tải dữ liệu...</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 leading-tight">Vui lòng đợi trong giây lát</span>
            </div>
        </div>
    </div>

    <div 
        x-data="{ 
            collapsed: {},
            toggleGroup(code) {
                this.collapsed[code] = !this.collapsed[code];
            },
            refreshEvery: {{ \App\Filament\Pages\VehicleDispatchBoard::REFRESH_SECONDS }},
            secondsLeft: {{ \App\Filament\Pages\VehicleDispatchBoard::REFRESH_SECONDS }},
            refreshing: false,
            tick() {
                // Tab ẩn thì tạm dừng đếm (không gọi server vô ích), quay lại thì đếm tiếp
                if (document.hidden || this.refreshing) return;
                if (--this.secondsLeft <= 0) this.refresh();
            },
            async refresh() {
                if (this.refreshing) return;
                this.refreshing = true;
                try { await $wire.$refresh(); } finally {
                    this.refreshing = false;
                    this.secondsLeft = this.refreshEvery;
                }
            }
        }" 
        x-init="setInterval(() => tick(), 1000)"
        class="w-full space-y-3"
    >
        @include('filament.pages.partials.dispatch-table-styles')

        {{-- Tìm kiếm theo biển số / tên lái xe --}}
        <div class="flex items-center gap-2">
            <div class="w-full max-w-xs">
                <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                    <x-filament::input
                        type="search"
                        wire:model.live.debounce.400ms="searchQuery"
                        placeholder="Tìm biển số hoặc tên lái xe..."
                    />
                </x-filament::input.wrapper>
            </div>
            @if (filled($searchQuery))
                <x-filament::icon-button
                    icon="heroicon-m-x-mark"
                    wire:click="$set('searchQuery', null)"
                    label="Xoá tìm kiếm"
                    color="gray"
                    tooltip="Xoá tìm kiếm"
                />
            @endif

            {{-- Đếm ngược tới lần làm mới tiếp theo; bấm để làm mới ngay --}}
            <button
                type="button"
                x-on:click="refresh()"
                x-bind:disabled="refreshing"
                title="Bấm để làm mới ngay"
                class="ms-auto inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1.5 text-xs font-medium text-gray-600 ring-1 ring-gray-950/10 transition hover:bg-gray-50 hover:text-primary-600 disabled:cursor-wait dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-white/5"
            >
                <svg x-bind:class="refreshing && 'animate-spin'" class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H3.989a.75.75 0 0 0-.75.75v4.242a.75.75 0 0 0 1.5 0v-2.43l.31.31a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm1.23-3.723a.75.75 0 0 0 .219-.53V2.929a.75.75 0 0 0-1.5 0V5.36l-.31-.31A7 7 0 0 0 3.239 8.188a.75.75 0 1 0 1.448.389A5.5 5.5 0 0 1 13.89 6.11l.311.31h-2.432a.75.75 0 0 0 0 1.5h4.243a.75.75 0 0 0 .53-.219Z" clip-rule="evenodd" />
                </svg>
                <span x-text="refreshing ? 'Đang làm mới…' : `Làm mới sau ${secondsLeft}s`">Làm mới sau {{ \App\Filament\Pages\VehicleDispatchBoard::REFRESH_SECONDS }}s</span>
            </button>
        </div>

        {{-- Bảng Kiểm Soát Tình Trạng Xe theo mẫu thực tế --}}
        <div class="dispatch-table-container overflow-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 bg-white dark:bg-gray-900 shadow-xs max-h-[calc(100vh-9rem)]">
            <table class="dispatch-table w-full text-xs border-collapse font-sans">
                <thead class="sticky top-0 z-20 shadow-sm">
                    {{-- Dòng tiêu đề chính màu navy theo theme table --}}
                    <tr class="dark:bg-slate-950" style="background-color: rgb(30 58 95 / var(--tw-bg-opacity, 1));">
                        <th 
                            colspan="18" 
                            class="text-white py-2.5 px-4 text-center font-bold tracking-wider text-sm sm:text-base border-b border-white/10 uppercase dark:bg-slate-950"
                            style="background-color: rgb(30 58 95 / var(--tw-bg-opacity, 1));"
                        >
                            KIỂM SOÁT TÌNH TRẠNG XE 🚛
                        </th>
                    </tr>

                    {{-- Dòng tiêu đề các cột màu navy theo theme table --}}
                    <tr class="text-white text-[11px] font-bold tracking-wide uppercase dark:bg-slate-950" style="background-color: rgb(30 58 95 / var(--tw-bg-opacity, 1));">
                        <th class="border-r border-b border-white/10 py-2 px-2 text-center whitespace-nowrap min-w-[100px]">Địa điểm</th>
                        <th class="border-r border-b border-white/10 py-2 px-1 text-center whitespace-nowrap min-w-[45px]">HT</th>
                        <th 
                            wire:click="sortTable('plate_number')"
                            class="border-r border-b border-white/10 py-2 px-2 text-center whitespace-nowrap min-w-[125px] cursor-pointer hover:bg-white/10 select-none transition"
                            title="Bấm để sắp xếp theo BSX"
                        >
                            <span class="inline-flex items-center justify-center gap-1">
                                <span>BSX</span>
                                @if ($this->sortBy === 'plate_number')
                                    <span class="text-[9px] text-amber-300 font-bold">{{ $this->sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                @endif
                            </span>
                        </th>

                        <th class="border-r border-b border-white/10 py-2 px-2 text-center whitespace-nowrap min-w-[75px]">Phân loại</th>

                        <th class="border-r border-b border-white/10 py-2 px-1 text-center whitespace-nowrap min-w-[55px]">Loại đơn</th>
                        <th 
                            wire:click="sortTable('status')"
                            class="border-r border-b border-white/10 py-2 px-2 text-center whitespace-nowrap min-w-[85px] cursor-pointer hover:bg-white/10 select-none transition"
                            title="Bấm để sắp xếp theo Trạng thái"
                        >
                            <span class="inline-flex items-center justify-center gap-1">
                                <span>Trạng thái</span>
                                @if ($this->sortBy === 'status')
                                    <span class="text-[9px] text-amber-300 font-bold">{{ $this->sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                @endif
                            </span>
                        </th>
                        <th class="border-r border-b border-white/10 py-2 px-2 text-center whitespace-nowrap min-w-[65px]">Điểm đi</th>
                        <th class="border-r border-b border-white/10 py-2 px-2 text-center whitespace-nowrap min-w-[100px]">Điểm đến</th>
                        <th class="border-r border-b border-white/10 py-2 px-2 text-center whitespace-nowrap min-w-[70px]">Xe chờ</th>
                        <th class="border-r border-b border-white/10 py-2 px-2 text-center whitespace-nowrap min-w-[140px]">Vị trí thực tế trên GPS</th>
                        <th class="border-r border-b border-white/10 py-2 px-1 text-center whitespace-nowrap min-w-[50px]">Tốc độ</th>
                        <th class="border-r border-b border-white/10 py-2 px-2 text-center whitespace-nowrap min-w-[85px]">Status</th>
                        <th class="border-r border-b border-white/10 py-2 px-1 text-center whitespace-nowrap min-w-[50px]">Chuyến</th>
                        <th class="border-r border-b border-white/10 py-2 px-1 text-center whitespace-nowrap min-w-[55px]">Đơn chờ</th>
                        <th class="border-r border-b border-white/10 py-2 px-2 text-center whitespace-nowrap min-w-[70px]">KM over</th>
                        <th class="border-r border-b border-white/10 py-2 px-2 text-center whitespace-nowrap min-w-[125px]">Tên lái xe</th>
                        <th class="border-r border-b border-white/10 py-2 px-1 text-center whitespace-nowrap min-w-[45px]">Trực</th>
                        <th class="border-b border-white/10 py-2 px-1 text-center whitespace-nowrap min-w-[45px]">Ca</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($groupedVehicles as $group)
                        @php
                            $gCode = $group['code'];
                            $gTitle = $group['title'];
                            $vehicles = $group['vehicles'];
                            $count = $vehicles->count();
                        @endphp

                        @foreach ($vehicles as $index => $vehicle)
                            @php
                                $isLastInGroup = ($index === $count - 1);
                                $statusLabel = \App\Filament\Pages\VehicleDispatchBoard::getTripStatusLabel($vehicle);
                                $statusColor = \App\Filament\Pages\VehicleDispatchBoard::getTripStatusBadgeClass($vehicle);

                                $movStatusText = \App\Filament\Pages\VehicleDispatchBoard::getMovementStatusText($vehicle);
                                $movCellClass = \App\Filament\Pages\VehicleDispatchBoard::getMovementStatusCellClass($vehicle);
                                $movTooltip = \App\Filament\Pages\VehicleDispatchBoard::getMovementStatusTooltip($vehicle);

                                $xeChoData = \App\Filament\Pages\VehicleDispatchBoard::getXeChoData($vehicle);
                                $xeCho = $xeChoData['text'];
                                $xeChoTooltip = $xeChoData['tooltip'];
                                $isXeChoAlert = $xeChoData['isAlert'];
                                $isXeChoWarning = $xeChoData['isWarning'];

                                $ownerCategory = \App\Filament\Pages\VehicleDispatchBoard::getOwnerCategory($vehicle);
                                $orderType = \App\Filament\Pages\VehicleDispatchBoard::getOrderType($vehicle);
                                $currentTrip = $vehicle->active_trip;
                                $pickup = \App\Filament\Pages\VehicleDispatchBoard::getPickupPoint($vehicle);
                                $dest = \App\Filament\Pages\VehicleDispatchBoard::getDeliveryPoint($vehicle);
                                $pickupFull = \App\Filament\Pages\VehicleDispatchBoard::getPickupPointFullName($vehicle);
                                $destFull = \App\Filament\Pages\VehicleDispatchBoard::getDeliveryPointFullName($vehicle);
                                $speed = \App\Filament\Pages\VehicleDispatchBoard::getSpeed($vehicle);
                                $kmOverData = \App\Filament\Pages\VehicleDispatchBoard::getKmOverData($vehicle);
                                $kmOver = $kmOverData['text'];
                                $driverName = \App\Filament\Pages\VehicleDispatchBoard::getDriverName($vehicle);
                                $station = \App\Filament\Pages\VehicleDispatchBoard::getDriverStation($vehicle);
                                $shift = \App\Filament\Pages\VehicleDispatchBoard::getShiftLabel($vehicle);
                                $gpsLoc = \App\Filament\Pages\VehicleDispatchBoard::getGpsLocation($vehicle);
                            @endphp

                            {{-- Dòng dữ liệu --}}
                            <tr 
                                x-show="!collapsed['{{ $gCode }}'] || {{ $index === 0 ? 'true' : 'false' }}"
                                @if ($index === 0)
                                    :class="{ 'dispatch-group-end': Boolean(collapsed['{{ $gCode }}']{{ $isLastInGroup ? ' || true' : '' }}) }"
                                @endif
                                class="dispatch-row group/row cursor-default {{ $isLastInGroup ? 'dispatch-group-end' : '' }} {{ $index % 2 === 0 ? 'bg-white dark:bg-gray-900' : 'bg-gray-50/40 dark:bg-white/[0.015]' }}"
                            >
                                {{-- 1. Địa điểm: Chỉ hiện ở dòng đầu tiên của nhóm, các dòng sau để trống với border --}}
                                @if ($index === 0)
                                    <td 
                                        class="border-r border-gray-100 dark:border-white/5 bg-gray-50/80 dark:bg-white/5 px-2 py-1.5 text-left font-bold text-gray-900 dark:text-gray-100 whitespace-nowrap select-none align-middle"
                                    >
                                        <button 
                                            type="button" 
                                            @click="toggleGroup('{{ $gCode }}')"
                                            class="inline-flex items-center gap-1.5 font-bold hover:opacity-80 transition cursor-pointer"
                                            title="Bấm để đóng/mở nhóm {{ $gTitle }}"
                                        >
                                            <span 
                                                class="w-3.5 h-3.5 text-white text-[10px] inline-flex items-center justify-center rounded font-mono font-bold leading-none shrink-0" 
                                                style="background-color: rgb(30 58 95 / var(--tw-bg-opacity, 1));"
                                                x-text="collapsed['{{ $gCode }}'] ? '+' : '−'"
                                            >−</span>
                                            <span class="text-xs">{{ $gTitle }}</span>
                                            <span 
                                                x-show="collapsed['{{ $gCode }}']" 
                                                class="text-[10px] text-primary-600 dark:text-primary-400 font-semibold"
                                            >({{ $count }} xe)</span>
                                        </button>
                                    </td>
                                @else
                                    <td class="border-r border-gray-100 dark:border-white/5 px-2 py-1.5"></td>
                                @endif
                                
                                {{-- Hành trình chuyến hiện tại --}}
                                <td class="border-r border-gray-100 dark:border-white/5 px-1 py-1.5 text-center whitespace-nowrap">
                                    @if ($currentTrip)
                                        <x-filament::icon-button
                                            icon="heroicon-o-map-pin"
                                            color="primary"
                                            size="xs"
                                            class="mx-auto"
                                            label="Hành trình"
                                            tooltip="Xem hành trình chuyến hiện tại"
                                            wire:click="openTimelineModal({{ $currentTrip->id }})"
                                        />
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>

                                {{-- 2. BSX --}}
                                <td class="border-r border-gray-100 dark:border-white/5 px-2 py-1.5 whitespace-nowrap font-medium text-gray-900 dark:text-gray-100">
                                    <span class="cursor-pointer hover:underline text-primary-600 hover:text-primary-500 dark:text-primary-400 font-semibold" wire:click="openTripModal({{ $vehicle->id }})">
                                        {!! \App\Filament\Pages\VehicleDispatchBoard::renderBsx($vehicle) !!}
                                    </span>
                                </td>

                                

                                {{-- 3. Phân loại: chủ xe ASGT là xe nhà, còn lại là xe thuê --}}
                                <td class="border-r border-gray-100 dark:border-white/5 px-2 py-1.5 text-center whitespace-nowrap">
                                    <x-filament::badge size="xs" :color="$ownerCategory === 'Xe CTY' ? 'info' : 'warning'">
                                        {{ $ownerCategory }}
                                    </x-filament::badge>
                                </td>

                                {{-- 4. Loại đơn của đơn hiện tại (HHHK / Hàng ngoài) --}}
                                <td class="border-r border-gray-100 dark:border-white/5 px-1 py-1.5 text-center whitespace-nowrap">
                                    @if ($orderType)
                                        <x-filament::badge size="xs" :color="$orderType->getColor()">
                                            {{ $orderType->getLabel() }}
                                        </x-filament::badge>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>

                                {{-- 4. Trạng thái (Tô màu toàn bộ ô, không bị ghi đè màu khi hover) --}}
                                <td class="status-cell border-r border-gray-100 dark:border-white/5 px-2 py-1.5 text-center whitespace-nowrap font-bold text-[11px] select-none {{ $statusColor }}">
                                    @php
                                        $activeTrip = $vehicle->active_trip;
                                        $isKmPaused = $activeTrip && $activeTrip->isPaused();
                                        $isDriverSwap = $activeTrip && ! $isKmPaused && $activeTrip->status === \App\Enums\TripStatus::DriverSwap;
                                    @endphp

                                    @if ($isKmPaused)
                                        <button 
                                            type="button"
                                            wire:click="mountTableAction('resolve_km_report', '{{ $vehicle->id }}')"
                                            title="Bấm để xử lý báo sai Km"
                                            class="inline-flex items-center justify-center gap-1 cursor-pointer hover:opacity-85 underline decoration-dotted font-bold text-white transition"
                                        >
                                            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="w-3.5 h-3.5 shrink-0" />
                                            <span>{{ $statusLabel }}</span>
                                        </button>
                                    @elseif ($isDriverSwap)
                                        <button 
                                            type="button"
                                            wire:click="mountTableAction('reassign_driver', '{{ $vehicle->id }}')"
                                            title="Bấm để gán lại tài xế (Đảo lái)"
                                            class="inline-flex items-center justify-center gap-1 cursor-pointer hover:opacity-85 underline decoration-dotted font-bold text-white transition"
                                        >
                                            <x-filament::icon icon="heroicon-m-arrow-path" class="w-3.5 h-3.5 shrink-0" />
                                            <span>{{ $statusLabel }}</span>
                                        </button>
                                    @else
                                        <span>{{ $statusLabel }}</span>
                                    @endif
                                </td>

                                {{-- 5. Điểm đi (Hiển thị code) --}}
                                <td class="border-r border-gray-100 dark:border-white/5 px-1.5 py-1.5 text-center whitespace-nowrap font-medium text-gray-800 dark:text-gray-200">
                                    <span class="font-bold cursor-default" @if($pickupFull) title="{{ $pickupFull }}" @endif>{{ $pickup }}</span>
                                </td>

                                {{-- 6. Điểm đến (Hiển thị code) --}}
                                <td class="border-r border-gray-100 dark:border-white/5 px-2 py-1.5 whitespace-nowrap font-medium text-gray-800 dark:text-gray-200">
                                    @if ($dest === 'Thiếu điểm')
                                        <x-filament::badge color="warning" size="xs">Thiếu điểm</x-filament::badge>
                                    @else
                                        <span class="cursor-default font-medium" @if($destFull) title="{{ $destFull }}" @endif>{{ $dest }}</span>
                                    @endif
                                </td>

                                {{-- 7. Xe chờ --}}
                                <td class="border-r border-gray-100 dark:border-white/5 px-1.5 py-1.5 text-center whitespace-nowrap font-medium" title="{{ $xeChoTooltip }}">
                                    @if ($isXeChoAlert)
                                        <x-filament::badge color="danger" size="xs" class="animate-pulse">
                                            {{ $xeCho }}
                                        </x-filament::badge>
                                    @elseif ($isXeChoWarning)
                                        <x-filament::badge color="warning" size="xs">
                                            {{ $xeCho }}
                                        </x-filament::badge>
                                    @elseif ($xeCho === 'Sẵn sàng')
                                        <x-filament::badge color="success" size="xs">
                                            Sẵn sàng
                                        </x-filament::badge>
                                    @elseif ($xeCho === '—')
                                        <span class="text-gray-400">—</span>
                                    @else
                                        <x-filament::badge color="gray" size="xs">
                                            {{ $xeCho }}
                                        </x-filament::badge>
                                    @endif
                                </td>

                                {{-- 8. Vị trí thực tế trên GPS --}}
                                <td class="border-r border-gray-100 dark:border-white/5 px-2 py-1.5 whitespace-nowrap text-gray-700 dark:text-gray-300">
                                    <span 
                                        class="cursor-pointer hover:underline text-primary-600 hover:text-primary-500 dark:text-primary-400 font-medium"
                                        wire:click="openGpsModal({{ $vehicle->id }})"
                                        title="{{ $vehicle->gps_address ? $vehicle->gps_address : 'Bấm để xem trên bản đồ' }}"
                                    >
                                        {{ $gpsLoc }}
                                    </span>
                                </td>

                                {{-- 9. Tốc độ --}}
                                @php
                                    $speedAlertClass = \App\Filament\Pages\VehicleDispatchBoard::getSpeedAlertClass($vehicle);
                                @endphp
                                <td class="border-r border-gray-100 dark:border-white/5 px-1 py-1.5 text-center whitespace-nowrap font-bold {{ $speedAlertClass ?: 'text-gray-900 dark:text-white' }}">
                                    <span class="{{ ! $speedAlertClass && (float) $vehicle->gps_speed > 0 ? 'text-emerald-600 dark:text-emerald-400 font-black' : '' }}">
                                        {{ $speed }}
                                    </span>
                                </td>

                                {{-- 10. Status (GPS / Động cơ) — tô màu cả ô như cột Trạng thái --}}
                                <td class="status-cell border-r border-gray-100 dark:border-white/5 px-2 py-1.5 text-center whitespace-nowrap text-[11px] select-none {{ $movCellClass }}"
                                    @if ($movTooltip) title="{{ $movTooltip }}" @endif>
                                    {{ $movStatusText }}
                                </td>

                                {{-- 11. Chuyến --}}
                                <td class="border-r border-gray-100 dark:border-white/5 px-1 py-1.5 text-center whitespace-nowrap font-bold text-gray-800 dark:text-gray-200">
                                    <button 
                                        type="button" 
                                        wire:click="openTripModal({{ $vehicle->id }})" 
                                        title="Bấm để xem danh sách chuyến trong ngày của xe {{ $vehicle->plate_number }}"
                                        class="cursor-pointer hover:underline text-primary-600 hover:text-primary-500 dark:text-primary-400 font-bold"
                                    >
                                        {{ \App\Filament\Pages\VehicleDispatchBoard::getTodayTripsCount($vehicle) }}
                                    </button>
                                </td>

                                {{-- 12. Đơn chờ --}}
                                @php
                                    $waitingOrders = \App\Filament\Pages\VehicleDispatchBoard::getWaitingOrdersCount($vehicle);
                                @endphp
                                <td class="border-r border-gray-100 dark:border-white/5 px-1 py-1.5 text-center whitespace-nowrap font-bold">
                                    <button 
                                        type="button" 
                                        wire:click="openTripModal({{ $vehicle->id }})" 
                                        title="Bấm để xem danh sách chuyến và đơn chờ của xe {{ $vehicle->plate_number }}"
                                        class="cursor-pointer hover:underline {{ $waitingOrders > 0 ? 'text-purple-600 dark:text-purple-400 font-bold' : 'text-gray-400 font-normal' }}"
                                    >
                                        {{ $waitingOrders }}
                                    </button>
                                </td>

                                {{-- 13. KM over (>=3000 đỏ, >=2800 cam, >=2600 vàng nhạt, còn lại tô nền theo tỉ lệ / 3.000 km) --}}
                                <td 
                                    class="border-r border-gray-100 dark:border-white/5 px-1.5 py-1.5 text-center whitespace-nowrap font-mono text-[11px] {{ $kmOverData['cellClass'] }}"
                                    title="{{ $kmOverData['tooltip'] }}"
                                    @if ($kmOverData['status'] === 'normal' && $kmOverData['fillPercent'] > 0)
                                        style="background: linear-gradient(to right, rgb(59 130 246 / 0.22) {{ $kmOverData['fillPercent'] }}%, transparent {{ $kmOverData['fillPercent'] }}%);"
                                    @endif
                                >
                                    <span>{{ $kmOver }}</span>
                                </td>

                                {{-- 13. Tên lái xe --}}
                                <td class="border-r border-gray-100 dark:border-white/5 px-2 py-1.5 whitespace-nowrap font-medium text-gray-800 dark:text-gray-200">
                                    <span>{{ $driverName }}</span>
                                </td>

                                {{-- 14. Trực --}}
                                <td class="border-r border-gray-100 dark:border-white/5 px-1 py-1.5 text-center whitespace-nowrap font-bold text-[11px]">
                                    @php
                                        $stationColor = match ($station) {
                                            'BN' => 'warning',
                                            'NB', 'NBA' => 'success',
                                            'TN' => 'info',
                                            'Province' => 'primary',
                                            default => 'gray',
                                        };
                                    @endphp
                                    @if ($station && $station !== '—' && $station !== 'OTHER')
                                        <x-filament::badge size="xs" :color="$stationColor">
                                            {{ $station }}
                                        </x-filament::badge>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>

                                {{-- 15. Ca --}}
                                <td class="px-1 py-1.5 text-center whitespace-nowrap font-medium text-[11px] text-gray-700 dark:text-gray-300">
                                    <span class="font-bold">{{ $shift }}</span>
                                </td>
                            </tr>
                        @endforeach
                    @empty
                        <tr>
                            <td colspan="16" class="py-8 text-center text-gray-500 italic">
                                Không tìm thấy xe nào phù hợp.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Modals khi click vào BSX hoặc Vị trí GPS --}}
    @php
        $tripVehicle = $this->getSelectedVehicleForTrip();
        $gpsVehicle = $this->getSelectedVehicleForGps();
        $timelineTrip = $this->getSelectedTripForTimeline();
    @endphp

    {{-- Modal xem danh sách chuyến xe trong ngày --}}
    <x-filament::modal id="vehicle-trip-modal" width="4xl">
        <x-slot name="heading">
            Danh sách chuyến xe trong ca — {{ $tripVehicle?->plate_number }} ({{ \App\Services\ShiftScheduleService::getShiftDate()->format('d/m/Y') }})
        </x-slot>

        @if ($tripVehicle)
            <div wire:key="vehicle-trip-modal-content-{{ $tripVehicle->id }}">
                @include('filament.pages.partials.vehicle-trip-modal', [
                    'vehicle' => $tripVehicle,
                    'trips' => $this->getTodayTripsForSelectedVehicle(),
                ])
            </div>
        @else
            <div class="text-sm text-gray-500 italic p-4 text-center">
                Vui lòng chọn xe để xem thông tin chuyến xe.
            </div>
        @endif
    </x-filament::modal>

    {{-- Modal xem GPS --}}
    <x-filament::modal id="vehicle-gps-modal" width="2xl">
        <x-slot name="heading">
            Vị trí xe {{ $gpsVehicle?->plate_number }}
        </x-slot>

        @if ($gpsVehicle)
            <div wire:key="vehicle-gps-modal-content-{{ $gpsVehicle->id }}">
                @include('filament.pages.partials.vehicle-gps-modal', [
                    'vehicle' => $gpsVehicle,
                ])
            </div>
        @endif
    </x-filament::modal>

    {{-- Modal hành trình chuyến hiện tại --}}
    <x-filament::modal id="vehicle-timeline-modal" width="7xl">
        <x-slot name="heading">
            Hành trình — {{ $timelineTrip?->vehicle?->plate_number }}
        </x-slot>

        @if ($timelineTrip)
            <div wire:key="vehicle-timeline-modal-content-{{ $timelineTrip->id }}">
                @include('filament.resources.trips.components.trip-timeline-popup', [
                    'trip' => $timelineTrip,
                ])
            </div>
        @else
            <div class="text-sm text-gray-500 italic p-4 text-center">
                Xe chưa có chuyến đang chạy.
            </div>
        @endif
    </x-filament::modal>

    {{-- Filament Action Modals (cho ReassignDriverAction và ResolveKmReportAction) --}}
    <x-filament-actions::modals />
</x-filament-panels::page>
