<x-filament-panels::page>
    @php
        $metrics = $this->getSummaryMetrics();
        $paginator = $this->getPaginator();
        $customerOptions = $this->getCustomerOptions();
    @endphp

    {{-- Bộ lọc và công cụ --}}
    <div class="space-y-3">
        {{-- Nút chọn nhanh khoảng ngày (Presets) --}}
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 mr-1">Khoảng thời gian:</span>
                @php
                    $presets = [
                        'today' => 'Hôm nay',
                        'yesterday' => 'Hôm qua',
                        'last7days' => '7 ngày qua',
                        'thisMonth' => 'Tháng này',
                        'lastMonth' => 'Tháng trước',
                    ];
                @endphp
                @foreach ($presets as $key => $label)
                    @php
                        $isActive = $this->isDatePresetActive($key);
                    @endphp
                    <button
                        type="button"
                        wire:click="setDatePreset('{{ $key }}')"
                        @class([
                            'rounded-lg px-2.5 py-1 text-xs font-medium transition cursor-pointer',
                            'bg-blue-50 text-blue-700 border border-blue-200 hover:bg-blue-100 dark:bg-blue-900/40 dark:text-blue-300 dark:border-blue-700 font-semibold shadow-xs' => $isActive,
                            'bg-gray-100 text-gray-700 hover:bg-gray-200 border border-transparent dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700' => ! $isActive,
                        ])
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <div class="flex items-center gap-2">
                <x-filament::button
                    type="button"
                    wire:click="exportExcel"
                    color="success"
                    icon="heroicon-m-arrow-down-tray"
                    size="sm"
                >
                    Xuất Excel (.xlsx)
                </x-filament::button>
            </div>
        </div>

        {{-- Thanh bộ lọc chi tiết --}}
        <div class="rounded-xl border border-gray-200 bg-white p-3.5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                {{-- Từ ngày --}}
                <div>
                    <label class="block text-[11px] font-semibold text-gray-600 dark:text-gray-400 mb-1">Từ ngày</label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="date"
                            wire:model.live="startDate"
                        />
                    </x-filament::input.wrapper>
                </div>

                {{-- Đến ngày --}}
                <div>
                    <label class="block text-[11px] font-semibold text-gray-600 dark:text-gray-400 mb-1">Đến ngày</label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="date"
                            wire:model.live="endDate"
                        />
                    </x-filament::input.wrapper>
                </div>

                {{-- Loại hình phục vụ --}}
                <div>
                    <label class="block text-[11px] font-semibold text-gray-600 dark:text-gray-400 mb-1">Loại hình phục vụ</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="serviceType">
                            <option value="all">Tất cả loại hình</option>
                            <option value="HHHK">HHHK (Hàng không)</option>
                            <option value="external">Hàng ngoài</option>
                            <option value="empty">Xe không hàng</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                {{-- Khách hàng --}}
                <div>
                    <label class="block text-[11px] font-semibold text-gray-600 dark:text-gray-400 mb-1">Khách hàng</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="customerId">
                            <option value="">Tất cả khách hàng</option>
                            @foreach ($customerOptions as $id => $code)
                                <option value="{{ $id }}">{{ $code }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                {{-- Quản lý xe --}}
                <div>
                    <label class="block text-[11px] font-semibold text-gray-600 dark:text-gray-400 mb-1">Quản lý xe</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="vehicleOwner">
                            <option value="all">Tất cả hình thức</option>
                            <option value="company">Xe công ty</option>
                            <option value="rent">Xe thuê</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                {{-- Ô tìm kiếm & Reset --}}
                <div>
                    <label class="block text-[11px] font-semibold text-gray-600 dark:text-gray-400 mb-1">Tìm kiếm từ khóa</label>
                    <div class="flex items-center gap-1.5">
                        <div class="flex-1">
                            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                                <x-filament::input
                                    type="search"
                                    wire:model.live.debounce.400ms="search"
                                    placeholder="Mã đơn, BSX, lái xe..."
                                />
                            </x-filament::input.wrapper>
                        </div>
                        <x-filament::icon-button
                            icon="heroicon-m-arrow-path"
                            wire:click="resetFilters"
                            label="Đặt lại bộ lọc"
                            color="gray"
                            tooltip="Đặt lại bộ lọc"
                        />
                    </div>
                </div>
            </div>
        </div>

        {{-- 4 Thẻ KPI Chỉ số tổng hợp nhanh --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            {{-- Tổng chuyến / đơn --}}
            <div class="rounded-xl border border-blue-100 bg-gradient-to-br from-blue-50/70 to-white p-3.5 shadow-sm dark:border-blue-900/40 dark:from-blue-950/30 dark:to-gray-900">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-blue-700 dark:text-blue-300">Tổng chuyến phục vụ</span>
                    <span class="rounded-full bg-blue-100 dark:bg-blue-900/50 p-1.5 text-blue-600 dark:text-blue-300">
                        <x-heroicon-o-truck class="w-4 h-4" />
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-bold tracking-tight text-blue-900 dark:text-blue-100">{{ number_format($metrics['total_records']) }}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">dòng ({{ number_format($metrics['total_trips']) }} chuyến)</span>
                </div>
            </div>

            {{-- Km vận hành --}}
            <div class="rounded-xl border border-emerald-100 bg-gradient-to-br from-emerald-50/70 to-white p-3.5 shadow-sm dark:border-emerald-900/40 dark:from-emerald-950/30 dark:to-gray-900">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-emerald-700 dark:text-emerald-300">Tổng Km vận hành</span>
                    <span class="rounded-full bg-emerald-100 dark:bg-emerald-900/50 p-1.5 text-emerald-600 dark:text-emerald-300">
                        <x-heroicon-o-map-pin class="w-4 h-4" />
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-bold tracking-tight text-emerald-900 dark:text-emerald-100">{{ number_format($metrics['total_km'], 1) }}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">km (CH: {{ number_format($metrics['total_km_loaded'], 1) }} - KH: {{ number_format($metrics['total_km_empty'], 1) }})</span>
                </div>
            </div>

            {{-- Thời gian TB 1 chuyến --}}
            <div class="rounded-xl border border-indigo-100 bg-gradient-to-br from-indigo-50/70 to-white p-3.5 shadow-sm dark:border-indigo-900/40 dark:from-indigo-950/30 dark:to-gray-900">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-indigo-700 dark:text-indigo-300">Thời gian TB / Chuyến</span>
                    <span class="rounded-full bg-indigo-100 dark:bg-indigo-900/50 p-1.5 text-indigo-600 dark:text-indigo-300">
                        <x-heroicon-o-clock class="w-4 h-4" />
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-bold tracking-tight text-indigo-900 dark:text-indigo-100">{{ number_format($metrics['avg_duration']) }}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">phút / chuyến</span>
                </div>
            </div>

            {{-- Chuyến chờ sau 22h --}}
            <div class="rounded-xl border border-amber-100 bg-gradient-to-br from-amber-50/70 to-white p-3.5 shadow-sm dark:border-amber-900/40 dark:from-amber-950/30 dark:to-gray-900">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-amber-700 dark:text-amber-300">Chuyến chờ hạ hàng sau 22:00</span>
                    <span class="rounded-full bg-amber-100 dark:bg-amber-900/50 p-1.5 text-amber-600 dark:text-amber-300">
                        <x-heroicon-o-moon class="w-4 h-4" />
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-bold tracking-tight text-amber-800 dark:text-amber-200">{{ number_format($metrics['count_waiting_22h']) }}</span>
                    <span class="text-xs text-amber-600 dark:text-amber-400">chuyến phát sinh mốc 22h</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Bảng thống kê chi tiết 27 cột --}}
    <div class="mt-4 rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900 overflow-hidden">
        {{-- Header Bảng --}}
        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 uppercase tracking-wide">
                    BẢNG THỐNG KÊ TỔNG HỢP CÁC CHUYẾN ĐÃ PHỤC VỤ
                </h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Bao gồm đơn HHHK, Hàng ngoài đóng trả 1 điểm / nhiều điểm và các chuyến xe không hàng.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs text-gray-500 dark:text-gray-400">Số dòng/trang:</span>
                <div class="w-20">
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="perPage">
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            </div>
        </div>

        {{-- Bảng có thanh cuộn ngang và sticky columns --}}
        <div class="overflow-x-auto relative max-h-[680px]">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    {{-- Dòng header phân nhóm --}}
                    <tr class="bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-b border-gray-300 dark:border-gray-700 text-center font-semibold uppercase text-[10px] tracking-wider">
                        <th colspan="5" class="py-2 px-3 border-r border-gray-300 dark:border-gray-700 bg-blue-100/70 dark:bg-blue-950/60 text-blue-900 dark:text-blue-200">
                            1. THÔNG TIN CHUYẾN & HÀNH TRÌNH
                        </th>
                        <th colspan="4" class="py-2 px-3 border-r border-gray-300 dark:border-gray-700 bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200">
                            2. 4 MỐC THỜI GIAN THỰC TẾ
                        </th>
                        <th colspan="5" class="py-2 px-3 border-r border-gray-300 dark:border-gray-700 bg-sky-100/70 dark:bg-sky-950/60 text-sky-900 dark:text-sky-200">
                            3. THỜI LƯỢNG TÍNH TOÁN (PHÚT)
                        </th>
                        <th colspan="7" class="py-2 px-3 border-r border-gray-300 dark:border-gray-700 bg-purple-100/60 dark:bg-purple-950/50 text-purple-900 dark:text-purple-200">
                            4. ĐỐI TÁC, XE & TÀI XẾ
                        </th>
                        <th colspan="6" class="py-2 px-3 bg-emerald-100/60 dark:bg-emerald-950/50 text-emerald-900 dark:text-emerald-200">
                            5. SẢN LƯỢNG, KM & CƯỚC
                        </th>
                    </tr>

                    {{-- Dòng header chi tiết 27 cột --}}
                    <tr class="bg-gray-50 dark:bg-gray-800/90 text-gray-800 dark:text-gray-200 border-b border-gray-300 dark:border-gray-700 font-semibold text-[11px] whitespace-nowrap">
                        {{-- Sticky 1: STT --}}
                        <th class="py-2.5 px-2.5 text-center border-r border-gray-200 dark:border-gray-700 sticky left-0 z-20 bg-gray-100 dark:bg-gray-800 min-w-[45px]">
                            STT
                        </th>

                        {{-- Sticky 2: Loại hình phục vụ --}}
                        <th class="py-2.5 px-3 text-center border-r border-gray-200 dark:border-gray-700 sticky left-[45px] z-20 bg-gray-100 dark:bg-gray-800 min-w-[95px]">
                            Loại hình
                        </th>

                        {{-- Sticky 3: Mã đơn hàng --}}
                        <th class="py-2.5 px-3 text-center border-r border-gray-200 dark:border-gray-700 sticky left-[140px] z-20 bg-gray-100 dark:bg-gray-800 min-w-[105px]">
                            Mã đơn hàng
                        </th>

                        {{-- Sticky 4: BSX --}}
                        <th class="py-2.5 px-3 text-center border-r border-gray-300 dark:border-gray-700 sticky left-[245px] z-20 bg-gray-100 dark:bg-gray-800 shadow-[2px_0_4px_-1px_rgba(0,0,0,0.1)] min-w-[95px]">
                            BSX
                        </th>

                        {{-- Cột 3: Ngày --}}
                        <th class="py-2.5 px-3 text-center border-r border-gray-200 dark:border-gray-700 min-w-[90px]">
                            Ngày
                        </th>

                        {{-- Cột 5: Hành trình --}}
                        <th class="py-2.5 px-3.5 border-r border-gray-300 dark:border-gray-700 min-w-[130px]">
                            Hành trình
                        </th>

                        {{-- Mốc thời gian A, B, C, D --}}
                        <th class="py-2.5 px-3 text-center border-r border-gray-200 dark:border-gray-700 min-w-[115px]">
                            TG đóng hàng (A)
                        </th>
                        <th class="py-2.5 px-3 text-center border-r border-gray-200 dark:border-gray-700 min-w-[115px]">
                            TG xe chạy (B)
                        </th>
                        <th class="py-2.5 px-3 text-center border-r border-gray-200 dark:border-gray-700 min-w-[115px]">
                            TG đến (C)
                        </th>
                        <th class="py-2.5 px-3 text-center border-r border-gray-300 dark:border-gray-700 min-w-[115px]">
                            TG hạ hàng (D)
                        </th>

                        {{-- Thời lượng phút (10, 11, 12, 13, 14) --}}
                        <th class="py-2.5 px-2.5 text-center border-r border-gray-200 dark:border-gray-700 min-w-[80px]" title="B - A (phút)">
                            TG đóng (1)
                        </th>
                        <th class="py-2.5 px-2.5 text-center border-r border-gray-200 dark:border-gray-700 min-w-[80px]" title="C - B (phút)">
                            TG chạy (2)
                        </th>
                        <th class="py-2.5 px-2.5 text-center border-r border-gray-200 dark:border-gray-700 min-w-[80px]" title="D - C (phút)">
                            TG hạ (3)
                        </th>
                        <th class="py-2.5 px-3 text-center border-r border-gray-200 dark:border-gray-700 min-w-[130px] bg-amber-50/50 dark:bg-amber-950/20 text-amber-800 dark:text-amber-300" title="Chờ hạ hàng sau 22:00">
                            TG chờ 22:00
                        </th>
                        <th class="py-2.5 px-3 text-center border-r border-gray-300 dark:border-gray-700 min-w-[95px] font-bold text-blue-700 dark:text-blue-300" title="(1) + (2) + (3)">
                            Tổng TG (1+2+3)
                        </th>

                        {{-- Đối tác & xe --}}
                        <th class="py-2.5 px-3 border-r border-gray-200 dark:border-gray-700 min-w-[100px]">
                            Khách hàng
                        </th>
                        <th class="py-2.5 px-3 border-r border-gray-200 dark:border-gray-700 min-w-[100px]">
                            Kho đóng/trả
                        </th>
                        <th class="py-2.5 px-3 text-center border-r border-gray-200 dark:border-gray-700 min-w-[95px]">
                            Quản lý xe
                        </th>
                        <th class="py-2.5 px-3 text-center border-r border-gray-200 dark:border-gray-700 min-w-[90px]">
                            Loại xe
                        </th>
                        <th class="py-2.5 px-3 border-r border-gray-200 dark:border-gray-700 min-w-[100px]">
                            Ghi chú
                        </th>
                        <th class="py-2.5 px-2.5 text-center border-r border-gray-200 dark:border-gray-700 min-w-[90px]">
                            Hàng quay đầu
                        </th>
                        <th class="py-2.5 px-3 border-r border-gray-300 dark:border-gray-700 min-w-[120px]">
                            Họ tên lái xe
                        </th>

                        {{-- Km & Cước --}}
                        <th class="py-2.5 px-3 text-right border-r border-gray-200 dark:border-gray-700 min-w-[85px]">
                            Km có hàng
                        </th>
                        <th class="py-2.5 px-3 text-right border-r border-gray-200 dark:border-gray-700 min-w-[85px]">
                            Km không hàng
                        </th>
                        <th class="py-2.5 px-2.5 text-right border-r border-gray-200 dark:border-gray-700 min-w-[65px]">
                            PCS
                        </th>
                        <th class="py-2.5 px-3 text-right border-r border-gray-200 dark:border-gray-700 min-w-[75px]">
                            GW (kg)
                        </th>
                        <th class="py-2.5 px-3 text-center border-r border-gray-200 dark:border-gray-700 min-w-[75px]">
                            Loại hàng
                        </th>
                        <th class="py-2.5 px-3 text-right min-w-[95px]">
                            Trọng tải cước
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    @forelse ($paginator->items() as $index => $row)
                        @php
                            $stt = ($paginator->currentPage() - 1) * $paginator->perPage() + $index + 1;
                            $serviceBadgeClass = match($row['service_type']) {
                                'HHHK' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/60 dark:text-blue-200',
                                'Hàng ngoài' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200',
                                default => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
                            };
                            $ownerBadgeClass = match($row['vehicle_owner']) {
                                'Xe công ty' => 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
                                'Xe thuê' => 'bg-purple-50 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
                                default => 'bg-gray-50 text-gray-600',
                            };
                        @endphp
                        <tr class="hover:bg-gray-50/80 dark:hover:bg-gray-800/60 transition whitespace-nowrap">
                            {{-- Sticky 1: STT --}}
                            <td class="py-2 px-2.5 text-center border-r border-gray-200 dark:border-gray-700 sticky left-0 z-10 bg-white dark:bg-gray-900 text-gray-500 font-mono">
                                {{ $stt }}
                            </td>

                            {{-- Sticky 2: Loại hình --}}
                            <td class="py-2 px-3 text-center border-r border-gray-200 dark:border-gray-700 sticky left-[45px] z-10 bg-white dark:bg-gray-900">
                                <span class="inline-block px-2 py-0.5 rounded text-[10px] font-semibold {{ $serviceBadgeClass }}">
                                    {{ $row['service_type'] }}
                                </span>
                            </td>

                            {{-- Sticky 3: Mã đơn --}}
                            <td class="py-2 px-3 text-center border-r border-gray-200 dark:border-gray-700 sticky left-[140px] z-10 bg-white dark:bg-gray-900 font-semibold text-gray-900 dark:text-gray-100">
                                {{ $row['code'] ?: '—' }}
                            </td>

                            {{-- Sticky 4: BSX --}}
                            <td class="py-2 px-3 text-center border-r border-gray-300 dark:border-gray-700 sticky left-[245px] z-10 bg-white dark:bg-gray-900 shadow-[2px_0_4px_-1px_rgba(0,0,0,0.1)] font-mono font-medium text-gray-800 dark:text-gray-200">
                                {{ $row['plate_number'] ?: '—' }}
                            </td>

                            {{-- Cột 3: Ngày --}}
                            <td class="py-2 px-3 text-center border-r border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400">
                                {{ $row['date'] ? (\Carbon\Carbon::parse($row['date'])->format('d/m/Y')) : '—' }}
                            </td>

                            {{-- Cột 5: Hành trình --}}
                            <td class="py-2 px-3.5 border-r border-gray-300 dark:border-gray-700 font-medium text-gray-800 dark:text-gray-200">
                                {{ $row['journey'] ?: '—' }}
                            </td>

                            {{-- 4 Mốc thời gian --}}
                            <td class="py-2 px-3 text-center border-r border-gray-200 dark:border-gray-700 font-mono text-gray-700 dark:text-gray-300">
                                {{ $row['time_a'] ? (\Carbon\Carbon::parse($row['time_a'])->format('H:i d/m')) : '—' }}
                            </td>
                            <td class="py-2 px-3 text-center border-r border-gray-200 dark:border-gray-700 font-mono text-gray-700 dark:text-gray-300">
                                {{ $row['time_b'] ? (\Carbon\Carbon::parse($row['time_b'])->format('H:i d/m')) : '—' }}
                            </td>
                            <td class="py-2 px-3 text-center border-r border-gray-200 dark:border-gray-700 font-mono text-gray-700 dark:text-gray-300">
                                {{ $row['time_c'] ? (\Carbon\Carbon::parse($row['time_c'])->format('H:i d/m')) : '—' }}
                            </td>
                            <td class="py-2 px-3 text-center border-r border-gray-300 dark:border-gray-700 font-mono text-gray-700 dark:text-gray-300">
                                {{ $row['time_d'] ? (\Carbon\Carbon::parse($row['time_d'])->format('H:i d/m')) : '—' }}
                            </td>

                            {{-- Thời lượng phút --}}
                            <td class="py-2 px-2.5 text-center border-r border-gray-200 dark:border-gray-700 font-medium text-gray-700 dark:text-gray-300">
                                {{ $row['time_loading'] !== null ? $row['time_loading'] . "'" : '—' }}
                            </td>
                            <td class="py-2 px-2.5 text-center border-r border-gray-200 dark:border-gray-700 font-medium text-gray-700 dark:text-gray-300">
                                {{ $row['time_travel'] !== null ? $row['time_travel'] . "'" : '—' }}
                            </td>
                            <td class="py-2 px-2.5 text-center border-r border-gray-200 dark:border-gray-700 font-medium text-gray-700 dark:text-gray-300">
                                {{ $row['time_unloading'] !== null ? $row['time_unloading'] . "'" : '—' }}
                            </td>
                            <td class="py-2 px-3 text-center border-r border-gray-200 dark:border-gray-700">
                                @if (($row['time_waiting_22h'] ?? 0) > 0)
                                    <span class="inline-block px-2 py-0.5 rounded bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200 font-bold">
                                        {{ $row['time_waiting_22h'] }}'
                                    </span>
                                @else
                                    <span class="text-gray-400">0</span>
                                @endif
                            </td>
                            <td class="py-2 px-3 text-center border-r border-gray-300 dark:border-gray-700 font-bold text-blue-600 dark:text-blue-400">
                                {{ $row['time_total_trip'] !== null ? $row['time_total_trip'] . "'" : '—' }}
                            </td>

                            {{-- Đối tác & Xe --}}
                            <td class="py-2 px-3 border-r border-gray-200 dark:border-gray-700 font-semibold text-gray-800 dark:text-gray-200">
                                {{ $row['customer'] ?: '—' }}
                            </td>
                            <td class="py-2 px-3 border-r border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300">
                                {{ $row['warehouse'] ?: '—' }}
                            </td>
                            <td class="py-2 px-3 text-center border-r border-gray-200 dark:border-gray-700">
                                <span class="inline-block px-2 py-0.5 rounded text-[10px] font-medium {{ $ownerBadgeClass }}">
                                    {{ $row['vehicle_owner'] }}
                                </span>
                            </td>
                            <td class="py-2 px-3 text-center border-r border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400">
                                {{ $row['vehicle_type'] }}
                            </td>
                            <td class="py-2 px-3 border-r border-gray-200 dark:border-gray-700 text-gray-500 max-w-[140px] truncate" title="{{ $row['note'] }}">
                                {{ $row['note'] ?: '—' }}
                            </td>
                            <td class="py-2 px-2.5 text-center border-r border-gray-200 dark:border-gray-700">
                                @if ($row['is_return_trip'])
                                    <span class="inline-block px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 font-medium text-[10px]">
                                        Quay đầu
                                    </span>
                                @else
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                @endif
                            </td>
                            <td class="py-2 px-3 border-r border-gray-300 dark:border-gray-700 font-medium text-gray-800 dark:text-gray-200">
                                {{ $row['driver_name'] ?: '—' }}
                            </td>

                            {{-- Km & Cước --}}
                            <td class="py-2 px-3 text-right border-r border-gray-200 dark:border-gray-700 font-mono">
                                {{ $row['km_loaded'] > 0 ? number_format($row['km_loaded'], 1) : '—' }}
                            </td>
                            <td class="py-2 px-3 text-right border-r border-gray-200 dark:border-gray-700 font-mono text-gray-500">
                                {{ $row['km_empty'] > 0 ? number_format($row['km_empty'], 1) : '—' }}
                            </td>
                            <td class="py-2 px-2.5 text-right border-r border-gray-200 dark:border-gray-700 font-mono">
                                {{ $row['pcs'] > 0 ? number_format($row['pcs']) : '—' }}
                            </td>
                            <td class="py-2 px-3 text-right border-r border-gray-200 dark:border-gray-700 font-mono">
                                {{ $row['gw'] > 0 ? number_format($row['gw']) : '—' }}
                            </td>
                            <td class="py-2 px-3 text-center border-r border-gray-200 dark:border-gray-700">
                                <span class="font-mono text-[11px] text-gray-600 dark:text-gray-400">{{ $row['cargo_type'] ?: '—' }}</span>
                            </td>
                            <td class="py-2 px-3 text-right font-mono font-semibold text-gray-900 dark:text-gray-100">
                                {{ $row['chargeable_weight'] > 0 ? number_format($row['chargeable_weight'], 1) : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="27" class="py-12 text-center text-gray-500 dark:text-gray-400">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-heroicon-o-inbox class="w-8 h-8 text-gray-400" />
                                    <p class="text-sm font-medium">Không tìm thấy dữ liệu chuyến nào phù hợp với bộ lọc</p>
                                    <button
                                        type="button"
                                        wire:click="resetFilters"
                                        class="mt-1 text-xs text-blue-600 hover:underline cursor-pointer"
                                    >
                                        Đặt lại bộ lọc
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Phân trang (Pagination footer) --}}
        @if ($paginator->hasPages())
            <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-800 flex flex-wrap items-center justify-between gap-2 bg-gray-50/60 dark:bg-gray-800/40">
                <div class="text-xs text-gray-600 dark:text-gray-400">
                    Hiển thị từ <span class="font-semibold text-gray-900 dark:text-gray-100">{{ ($paginator->currentPage() - 1) * $paginator->perPage() + 1 }}</span>
                    đến <span class="font-semibold text-gray-900 dark:text-gray-100">{{ min($paginator->total(), $paginator->currentPage() * $paginator->perPage()) }}</span>
                    trên tổng số <span class="font-semibold text-gray-900 dark:text-gray-100">{{ number_format($paginator->total()) }}</span> dòng
                </div>

                <div class="flex items-center gap-1">
                    {{-- Nút Trang trước --}}
                    @if ($paginator->onFirstPage())
                        <span class="px-2.5 py-1 text-xs rounded border border-gray-200 dark:border-gray-700 text-gray-400 bg-gray-100 dark:bg-gray-800 cursor-not-allowed">Trước</span>
                    @else
                        <button
                            type="button"
                            wire:click="gotoPage({{ $paginator->currentPage() - 1 }})"
                            class="px-2.5 py-1 text-xs rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 cursor-pointer"
                        >
                            Trước
                        </button>
                    @endif

                    {{-- Các số trang --}}
                    @php
                        $start = max(1, $paginator->currentPage() - 2);
                        $end = min($paginator->lastPage(), $paginator->currentPage() + 2);
                    @endphp

                    @if ($start > 1)
                        <button type="button" wire:click="gotoPage(1)" class="px-2.5 py-1 text-xs rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 hover:bg-gray-50 cursor-pointer">1</button>
                        @if ($start > 2) <span class="px-1 text-gray-400">...</span> @endif
                    @endif

                    @for ($p = $start; $p <= $end; $p++)
                        @if ($p === $paginator->currentPage())
                            <span class="px-2.5 py-1 text-xs rounded bg-blue-600 text-white font-semibold shadow-sm">{{ $p }}</span>
                        @else
                            <button
                                type="button"
                                wire:click="gotoPage({{ $p }})"
                                class="px-2.5 py-1 text-xs rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 cursor-pointer"
                            >
                                {{ $p }}
                            </button>
                        @endif
                    @endfor

                    @if ($end < $paginator->lastPage())
                        @if ($end < $paginator->lastPage() - 1) <span class="px-1 text-gray-400">...</span> @endif
                        <button type="button" wire:click="gotoPage({{ $paginator->lastPage() }})" class="px-2.5 py-1 text-xs rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 hover:bg-gray-50 cursor-pointer">{{ $paginator->lastPage() }}</button>
                    @endif

                    {{-- Nút Trang sau --}}
                    @if ($paginator->hasMorePages())
                        <button
                            type="button"
                            wire:click="gotoPage({{ $paginator->currentPage() + 1 }})"
                            class="px-2.5 py-1 text-xs rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 cursor-pointer"
                        >
                            Sau
                        </button>
                    @else
                        <span class="px-2.5 py-1 text-xs rounded border border-gray-200 dark:border-gray-700 text-gray-400 bg-gray-100 dark:bg-gray-800 cursor-not-allowed">Sau</span>
                    @endif
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
