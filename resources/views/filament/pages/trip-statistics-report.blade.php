<x-filament-panels::page>
    @php
        $metrics = $this->getSummaryMetrics();
        $paginator = $this->getPaginator();
        $customerOptions = $this->getCustomerOptions();
        $tabCounts = $this->getTabCounts();
    @endphp

    {{-- 4 Thẻ KPI Chỉ số tổng hợp nhanh --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        {{-- Tổng chuyến / đơn --}}
        <div class="rounded-xl bg-white p-4 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Tổng chuyến phục vụ</span>
                <span class="rounded-lg bg-primary-50 dark:bg-primary-500/10 p-2 text-primary-600 dark:text-primary-400">
                    <x-filament::icon icon="heroicon-o-truck" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">{{ number_format($metrics['total_records']) }}</span>
                <span class="text-xs text-gray-500 dark:text-gray-400">dòng ({{ number_format($metrics['total_trips']) }} chuyến)</span>
            </div>
        </div>

        {{-- Km vận hành --}}
        <div class="rounded-xl bg-white p-4 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Tổng Km vận hành</span>
                <span class="rounded-lg bg-emerald-50 dark:bg-emerald-500/10 p-2 text-emerald-600 dark:text-emerald-400">
                    <x-filament::icon icon="heroicon-o-map-pin" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">{{ number_format($metrics['total_km'], 1) }}</span>
                <span class="text-xs text-gray-500 dark:text-gray-400">km (CH: {{ number_format($metrics['total_km_loaded'], 1) }} - KH: {{ number_format($metrics['total_km_empty'], 1) }})</span>
            </div>
        </div>

        {{-- Thời gian TB 1 chuyến --}}
        <div class="rounded-xl bg-white p-4 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Thời gian TB / Chuyến</span>
                <span class="rounded-lg bg-sky-50 dark:bg-sky-500/10 p-2 text-sky-600 dark:text-sky-400">
                    <x-filament::icon icon="heroicon-o-clock" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white" title="{{ $this->formatDurationTooltip($metrics['avg_duration']) }}">
                    {{ $this->formatDuration($metrics['avg_duration']) }}
                </span>
                <span class="text-xs text-gray-500 dark:text-gray-400">TB / chuyến ({{ number_format($metrics['avg_duration']) }}')</span>
            </div>
        </div>

        {{-- Chuyến chờ sau 22h --}}
        <div class="rounded-xl bg-white p-4 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Chuyến chờ hạ hàng sau 22:00</span>
                <span class="rounded-lg bg-amber-50 dark:bg-amber-500/10 p-2 text-amber-600 dark:text-amber-400">
                    <x-filament::icon icon="heroicon-o-moon" class="w-4 h-4" />
                </span>
            </div>
            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">{{ number_format($metrics['count_waiting_22h']) }}</span>
                <span class="text-xs text-amber-600 dark:text-amber-400 font-medium">chuyến phát sinh mốc 22h</span>
            </div>
        </div>
    </div>

    {{-- Bộ lọc và công cụ --}}
    <div class="space-y-3">
        {{-- Nút chọn nhanh khoảng ngày (Presets) --}}
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 me-1">Khoảng thời gian:</span>
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
                            'bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-400 ring-1 ring-primary-500/20 font-semibold shadow-xs' => $isActive,
                            'bg-gray-100 text-gray-600 hover:bg-gray-200/70 dark:bg-white/5 dark:text-gray-400 dark:hover:bg-white/10' => ! $isActive,
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
        <div class="rounded-xl bg-white p-4 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
                {{-- Từ ngày --}}
                <div>
                    <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1">Từ ngày</label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="date"
                            wire:model.live="startDate"
                        />
                    </x-filament::input.wrapper>
                </div>

                {{-- Đến ngày --}}
                <div>
                    <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1">Đến ngày</label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="date"
                            wire:model.live="endDate"
                        />
                    </x-filament::input.wrapper>
                </div>

                {{-- Khách hàng --}}
                <div>
                    <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1">Khách hàng</label>
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
                    <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1">Quản lý xe</label>
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
                    <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1">Tìm kiếm từ khóa</label>
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
    </div>

    {{-- 3 Tabs hiển thị riêng biệt theo 3 loại hình --}}
    <div class="mt-4 transition-opacity duration-150" wire:loading.class="opacity-70 pointer-events-none" wire:target="setActiveTab">
        <x-filament::tabs>
            <x-filament::tabs.item
                :active="$this->activeTab === 'HHHK'"
                wire:click="setActiveTab('HHHK')"
                icon="heroicon-m-paper-airplane"
                :badge="$tabCounts['HHHK']"
                badge-color="info"
            >
                HHHK (Hàng không)
            </x-filament::tabs.item>

            <x-filament::tabs.item
                :active="$this->activeTab === 'external'"
                wire:click="setActiveTab('external')"
                icon="heroicon-m-truck"
                :badge="$tabCounts['external']"
                badge-color="warning"
            >
                Hàng ngoài
            </x-filament::tabs.item>

            <x-filament::tabs.item
                :active="$this->activeTab === 'empty'"
                wire:click="setActiveTab('empty')"
                icon="heroicon-m-arrow-path"
                :badge="$tabCounts['empty']"
                badge-color="gray"
            >
                Xe không hàng
            </x-filament::tabs.item>
        </x-filament::tabs>
    </div>

    {{-- Bảng thống kê chi tiết theo loại hình đã chọn --}}
    <div class="mt-2 rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 bg-white shadow-xs dark:bg-gray-900 overflow-hidden relative">
        {{-- Loading indicator khi chuyển tab hoặc lọc --}}
        <div
            wire:loading.flex
            wire:target="setActiveTab, setDatePreset, startDate, endDate, customerId, vehicleOwner, search, resetFilters, gotoPage, perPage"
            class="absolute inset-0 bg-white/60 dark:bg-gray-900/60 backdrop-blur-[1px] z-40 items-center justify-center transition-opacity"
        >
            <div class="flex items-center gap-2.5 px-4 py-2 rounded-xl bg-white dark:bg-gray-800 shadow-lg ring-1 ring-gray-950/10 dark:ring-white/10 text-xs font-semibold text-gray-700 dark:text-gray-200">
                <svg class="animate-spin h-4 w-4 text-primary-600 dark:text-primary-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>Đang tải dữ liệu...</span>
            </div>
        </div>

        {{-- Header Bảng --}}
        <div class="px-4 py-3 border-b border-gray-100 dark:border-white/5 flex flex-wrap items-center justify-between gap-2">
            <div>
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 uppercase tracking-wide">
                        BẢNG THỐNG KÊ TỔNG HỢP CÁC CHUYẾN ĐÃ PHỤC VỤ
                    </h3>
                    <x-filament::badge :color="match($this->activeTab) { 'HHHK' => 'info', 'external' => 'warning', 'empty' => 'gray', default => 'primary' }">
                        {{ $this->getActiveTabTitle() }}
                    </x-filament::badge>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                    @if ($this->activeTab === 'HHHK')
                        Bảng thống kê các đơn và chuyến vận chuyển hàng không (HHHK) đi và đến sân bay.
                    @elseif ($this->activeTab === 'external')
                        Bảng thống kê các đơn và chuyến vận chuyển hàng ngoài (nhà máy, KCN) đóng trả 1 điểm / nhiều điểm.
                    @elseif ($this->activeTab === 'empty')
                        Bảng thống kê các chuyến điều xe rỗng, chuyển bãi hoặc quay đầu không tải.
                    @else
                        Bao gồm đơn HHHK, Hàng ngoài đóng trả 1 điểm / nhiều điểm và các chuyến xe không hàng.
                    @endif
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
            <table class="w-full text-left border-collapse text-xs font-sans">
                <thead>
                    {{-- Dòng header phân nhóm --}}
                    <tr class="bg-gray-50/80 dark:bg-white/5 text-gray-700 dark:text-gray-300 border-b border-gray-100 dark:border-white/5 text-center font-semibold uppercase text-[10px] tracking-wider">
                        <th colspan="5" class="py-2 px-3 border-r border-gray-200/50 dark:border-white/5 text-primary-700 dark:text-primary-300">
                            1. THÔNG TIN CHUYẾN & HÀNH TRÌNH
                        </th>
                        <th colspan="4" class="py-2 px-3 border-r border-gray-200/50 dark:border-white/5 text-slate-700 dark:text-slate-300">
                            2. 4 MỐC THỜI GIAN THỰC TẾ
                        </th>
                        <th colspan="5" class="py-2 px-3 border-r border-gray-200/50 dark:border-white/5 text-sky-700 dark:text-sky-300">
                            3. THỜI LƯỢNG TÍNH TOÁN (GIỜ & PHÚT)
                        </th>
                        <th colspan="7" class="py-2 px-3 border-r border-gray-200/50 dark:border-white/5 text-purple-700 dark:text-purple-300">
                            4. ĐỐI TÁC, XE & TÀI XẾ
                        </th>
                        <th colspan="6" class="py-2 px-3 text-emerald-700 dark:text-emerald-300">
                            5. SẢN LƯỢNG, KM & CƯỚC
                        </th>
                    </tr>

                    {{-- Dòng header chi tiết các cột --}}
                    <tr class="bg-gray-50/50 dark:bg-white/[0.02] text-gray-700 dark:text-gray-300 border-b border-gray-200/70 dark:border-white/10 font-semibold text-[11px] whitespace-nowrap">
                        {{-- Sticky 1: STT --}}
                        <th class="py-2.5 px-2.5 text-center border-r border-gray-100 dark:border-white/5 sticky left-0 z-30 bg-gray-50 dark:bg-gray-800 w-[50px] min-w-[50px] max-w-[50px]">
                            STT
                        </th>

                        {{-- Sticky 2: Mã đơn hàng hoặc Mã chuyến --}}
                        <th class="py-2.5 px-3 text-center border-r border-gray-100 dark:border-white/5 sticky left-[50px] z-30 bg-gray-50 dark:bg-gray-800 w-[140px] min-w-[140px] max-w-[140px] truncate" title="{{ $this->activeTab === 'empty' ? 'Mã chuyến' : 'Mã đơn hàng' }}">
                            {{ $this->activeTab === 'empty' ? 'Mã chuyến' : 'Mã đơn hàng' }}
                        </th>

                        {{-- Sticky 3: BSX --}}
                        <th class="py-2.5 px-3 text-center border-r border-gray-200 dark:border-white/10 sticky left-[190px] z-30 bg-gray-50 dark:bg-gray-800 shadow-[2px_0_4px_-1px_rgba(0,0,0,0.06)] w-[110px] min-w-[110px] max-w-[110px]">
                            BSX
                        </th>

                        {{-- Cột Ngày --}}
                        <th class="py-2.5 px-3 text-center border-r border-gray-100 dark:border-white/5 min-w-[90px]">
                            Ngày
                        </th>

                        {{-- Cột Hành trình --}}
                        <th class="py-2.5 px-3.5 border-r border-gray-200/50 dark:border-white/5 min-w-[130px]">
                            Hành trình
                        </th>

                        {{-- Mốc thời gian A, B, C, D --}}
                        <th class="py-2.5 px-3 text-center border-r border-gray-100 dark:border-white/5 min-w-[115px]">
                            TG đóng hàng (A)
                        </th>
                        <th class="py-2.5 px-3 text-center border-r border-gray-100 dark:border-white/5 min-w-[115px]">
                            TG xe chạy (B)
                        </th>
                        <th class="py-2.5 px-3 text-center border-r border-gray-100 dark:border-white/5 min-w-[115px]">
                            TG đến (C)
                        </th>
                        <th class="py-2.5 px-3 text-center border-r border-gray-200/50 dark:border-white/5 min-w-[115px]">
                            TG hạ hàng (D)
                        </th>

                        {{-- Thời lượng (giờ & phút) --}}
                        <th class="py-2.5 px-2.5 text-center border-r border-gray-100 dark:border-white/5 min-w-[85px]" title="B - A (giờ & phút)">
                            TG đóng (1)
                        </th>
                        <th class="py-2.5 px-2.5 text-center border-r border-gray-100 dark:border-white/5 min-w-[85px]" title="C - B (giờ & phút)">
                            TG chạy (2)
                        </th>
                        <th class="py-2.5 px-2.5 text-center border-r border-gray-100 dark:border-white/5 min-w-[85px]" title="D - C (giờ & phút)">
                            TG hạ (3)
                        </th>
                        <th class="py-2.5 px-3 text-center border-r border-gray-100 dark:border-white/5 min-w-[110px] text-amber-700 dark:text-amber-400" title="Chờ hạ hàng sau 22:00 (giờ & phút)">
                            TG chờ 22:00
                        </th>
                        <th class="py-2.5 px-3 text-center border-r border-gray-200/50 dark:border-white/5 min-w-[105px] font-bold text-primary-600 dark:text-primary-400" title="(1) + (2) + (3) (giờ & phút)">
                            Tổng TG (1+2+3)
                        </th>

                        {{-- Đối tác & xe --}}
                        <th class="py-2.5 px-3 border-r border-gray-100 dark:border-white/5 min-w-[100px]">
                            Khách hàng
                        </th>
                        <th class="py-2.5 px-3 border-r border-gray-100 dark:border-white/5 min-w-[100px]">
                            {{ $this->activeTab === 'empty' ? 'Kho đến' : 'Kho đóng/trả' }}
                        </th>
                        <th class="py-2.5 px-3 text-center border-r border-gray-100 dark:border-white/5 min-w-[95px]">
                            Quản lý xe
                        </th>
                        <th class="py-2.5 px-3 text-center border-r border-gray-100 dark:border-white/5 min-w-[90px]">
                            Loại xe
                        </th>
                        <th class="py-2.5 px-3 border-r border-gray-100 dark:border-white/5 min-w-[100px]">
                            Ghi chú
                        </th>
                        <th class="py-2.5 px-2.5 text-center border-r border-gray-100 dark:border-white/5 min-w-[90px]">
                            Hàng quay đầu
                        </th>
                        <th class="py-2.5 px-3 border-r border-gray-200/50 dark:border-white/5 min-w-[120px]">
                            Họ tên lái xe
                        </th>

                        {{-- Km & Cước --}}
                        <th class="py-2.5 px-3 text-right border-r border-gray-100 dark:border-white/5 min-w-[85px]">
                            Km có hàng
                        </th>
                        <th class="py-2.5 px-3 text-right border-r border-gray-100 dark:border-white/5 min-w-[85px]">
                            Km không hàng
                        </th>
                        <th class="py-2.5 px-2.5 text-right border-r border-gray-100 dark:border-white/5 min-w-[110px]">
                            PCS
                        </th>
                        <th class="py-2.5 px-3 text-right border-r border-gray-100 dark:border-white/5 min-w-[125px]">
                            GW (kg)
                        </th>
                        <th class="py-2.5 px-3 text-center border-r border-gray-100 dark:border-white/5 min-w-[75px]">
                            Loại hàng
                        </th>
                        <th class="py-2.5 px-3 text-right min-w-[95px]">
                            Trọng tải cước
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($paginator->items() as $index => $row)
                        @php
                            $stt = ($paginator->currentPage() - 1) * $paginator->perPage() + $index + 1;
                        @endphp
                        <tr wire:key="row-{{ $row['trip_id'] }}-{{ $row['order_id'] ?? ('empty-'.$index) }}" class="hover:bg-gray-50/70 dark:hover:bg-white/5 transition-colors whitespace-nowrap group">
                            {{-- Sticky 1: STT --}}
                            <td class="py-2 px-2.5 text-center border-r border-gray-100 dark:border-white/5 sticky left-0 z-10 bg-white dark:bg-gray-900 group-hover:bg-gray-50 dark:group-hover:bg-gray-800/90 text-gray-400 font-mono w-[50px] min-w-[50px] max-w-[50px]">
                                {{ $stt }}
                            </td>

                            {{-- Sticky 2: Mã đơn hoặc Mã chuyến --}}
                            <td class="py-2 px-3 text-center border-r border-gray-100 dark:border-white/5 sticky left-[50px] z-10 bg-white dark:bg-gray-900 group-hover:bg-gray-50 dark:group-hover:bg-gray-800/90 font-semibold text-gray-900 dark:text-gray-100 w-[140px] min-w-[140px] max-w-[140px] truncate" title="{{ $row['code'] }}">
                                {{ $row['code'] ?: '—' }}
                            </td>

                            {{-- Sticky 3: BSX --}}
                            <td class="py-2 px-3 text-center border-r border-gray-200 dark:border-white/10 sticky left-[190px] z-10 bg-white dark:bg-gray-900 group-hover:bg-gray-50 dark:group-hover:bg-gray-800/90 shadow-[2px_0_4px_-1px_rgba(0,0,0,0.06)] font-mono font-medium text-gray-800 dark:text-gray-200 w-[110px] min-w-[110px] max-w-[110px]">
                                {{ $row['plate_number'] ?: '—' }}
                            </td>

                            {{-- Cột Ngày --}}
                            <td class="py-2 px-3 text-center border-r border-gray-100 dark:border-white/5 text-gray-600 dark:text-gray-400">
                                {{ $row['date'] ? (\Carbon\Carbon::parse($row['date'])->format('d/m/Y')) : '—' }}
                            </td>

                            {{-- Cột Hành trình --}}
                            <td class="py-2 px-3.5 border-r border-gray-200/50 dark:border-white/5 font-medium text-gray-800 dark:text-gray-200">
                                {{ $row['journey'] ?: '—' }}
                            </td>

                            {{-- 4 Mốc thời gian --}}
                            <td class="py-2 px-3 text-center border-r border-gray-100 dark:border-white/5 font-mono text-gray-700 dark:text-gray-300">
                                {{ $row['time_a'] ? (\Carbon\Carbon::parse($row['time_a'])->format('H:i d/m')) : '—' }}
                            </td>
                            <td class="py-2 px-3 text-center border-r border-gray-100 dark:border-white/5 font-mono text-gray-700 dark:text-gray-300">
                                {{ $row['time_b'] ? (\Carbon\Carbon::parse($row['time_b'])->format('H:i d/m')) : '—' }}
                            </td>
                            <td class="py-2 px-3 text-center border-r border-gray-100 dark:border-white/5 font-mono text-gray-700 dark:text-gray-300">
                                {{ $row['time_c'] ? (\Carbon\Carbon::parse($row['time_c'])->format('H:i d/m')) : '—' }}
                            </td>
                            <td class="py-2 px-3 text-center border-r border-gray-200/50 dark:border-white/5 font-mono text-gray-700 dark:text-gray-300">
                                {{ $row['time_d'] ? (\Carbon\Carbon::parse($row['time_d'])->format('H:i d/m')) : '—' }}
                            </td>

                            {{-- Thời lượng (giờ & phút) --}}
                            <td class="py-2 px-2.5 text-center border-r border-gray-100 dark:border-white/5 font-medium text-gray-700 dark:text-gray-300" title="{{ $this->formatDurationTooltip($row['time_loading']) }}">
                                {{ $this->formatDuration($row['time_loading']) }}
                            </td>
                            <td class="py-2 px-2.5 text-center border-r border-gray-100 dark:border-white/5 font-medium text-gray-700 dark:text-gray-300" title="{{ $this->formatDurationTooltip($row['time_travel']) }}">
                                {{ $this->formatDuration($row['time_travel']) }}
                            </td>
                            <td class="py-2 px-2.5 text-center border-r border-gray-100 dark:border-white/5 font-medium text-gray-700 dark:text-gray-300" title="{{ $this->formatDurationTooltip($row['time_unloading']) }}">
                                {{ $this->formatDuration($row['time_unloading']) }}
                            </td>
                            <td class="py-2 px-3 text-center border-r border-gray-100 dark:border-white/5">
                                @if (($row['time_waiting_22h'] ?? 0) > 0)
                                    <x-filament::badge color="warning" size="xs" :tooltip="$this->formatDurationTooltip($row['time_waiting_22h'])">
                                        {{ $this->formatDuration($row['time_waiting_22h']) }}
                                    </x-filament::badge>
                                @else
                                    <span class="text-gray-400">0</span>
                                @endif
                            </td>
                            <td class="py-2 px-3 text-center border-r border-gray-200/50 dark:border-white/5 font-bold text-primary-600 dark:text-primary-400" title="{{ $this->formatDurationTooltip($row['time_total_trip']) }}">
                                {{ $this->formatDuration($row['time_total_trip']) }}
                            </td>

                            {{-- Đối tác & Xe --}}
                            <td class="py-2 px-3 border-r border-gray-100 dark:border-white/5 font-semibold text-gray-800 dark:text-gray-200">
                                {{ $row['customer'] ?: '—' }}
                            </td>
                            <td class="py-2 px-3 border-r border-gray-100 dark:border-white/5 text-gray-700 dark:text-gray-300">
                                {{ $row['warehouse'] ?: '—' }}
                            </td>
                            <td class="py-2 px-3 text-center border-r border-gray-100 dark:border-white/5">
                                <x-filament::badge size="xs" :color="$row['vehicle_owner'] === 'Xe công ty' ? 'info' : ($row['vehicle_owner'] === 'Xe thuê' ? 'warning' : 'gray')">
                                    {{ $row['vehicle_owner'] }}
                                </x-filament::badge>
                            </td>
                            <td class="py-2 px-3 text-center border-r border-gray-100 dark:border-white/5 text-gray-600 dark:text-gray-400">
                                {{ $row['vehicle_type'] }}
                            </td>
                            <td class="py-2 px-3 border-r border-gray-100 dark:border-white/5 text-gray-500 max-w-[140px] truncate" title="{{ $row['note'] }}">
                                {{ $row['note'] ?: '—' }}
                            </td>
                            <td class="py-2 px-2.5 text-center border-r border-gray-100 dark:border-white/5">
                                @if ($row['is_return_trip'])
                                    <x-filament::badge size="xs" color="success">
                                        Quay đầu
                                    </x-filament::badge>
                                @else
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                @endif
                            </td>
                            <td class="py-2 px-3 border-r border-gray-200/50 dark:border-white/5 font-medium text-gray-800 dark:text-gray-200">
                                {{ $row['driver_name'] ?: '—' }}
                            </td>

                            {{-- Km & Cước --}}
                            <td class="py-2 px-3 text-right border-r border-gray-100 dark:border-white/5 font-mono">
                                {{ $row['km_loaded'] > 0 ? number_format($row['km_loaded'], 1) : '—' }}
                            </td>
                            <td class="py-2 px-3 text-right border-r border-gray-100 dark:border-white/5 font-mono text-gray-500">
                                {{ $row['km_empty'] > 0 ? number_format($row['km_empty'], 1) : '—' }}
                            </td>

                            {{-- PCS (Click to edit) --}}
                            <td
                                wire:key="cell-pcs-{{ $row['order_id'] ?? ('empty-'.$index) }}"
                                class="py-1 px-1.5 text-right border-r border-gray-100 dark:border-white/5 font-mono text-xs"
                                @if ($row['order_id'])
                                    x-data="inlineMetricEditor({{ $row['order_id'] }}, 'total_packages', '{{ $row['pcs'] ? (int) $row['pcs'] : '' }}')"
                                @endif
                            >
                                @if ($row['order_id'])
                                    <div
                                        x-show="!editing"
                                        @click="startEdit()"
                                        class="group/edit inline-flex items-center justify-end gap-1 w-full cursor-pointer py-1 px-1 rounded-md hover:bg-primary-50/80 dark:hover:bg-primary-950/40 hover:ring-1 hover:ring-primary-300 dark:hover:ring-primary-700 transition"
                                        title="Nhấn để sửa nhanh số kiện (PCS)"
                                    >
                                        <span x-show="!saving" class="group-hover/edit:text-primary-600 dark:group-hover/edit:text-primary-400 group-hover/edit:font-semibold">
                                            {{ $row['pcs'] > 0 ? number_format($row['pcs']) : '—' }}
                                        </span>
                                        <span x-show="saving" x-cloak class="inline-flex items-center text-primary-500">
                                            <svg class="animate-spin h-3 w-3 text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                        </span>
                                        <x-heroicon-m-pencil-square class="w-3 h-3 text-gray-400 dark:text-gray-500 opacity-0 group-hover/edit:opacity-100 transition-opacity text-primary-500 shrink-0" />
                                    </div>
                                    <div x-show="editing" x-cloak @click.away="save()" class="flex items-center justify-end gap-1 w-full">
                                        <input
                                            x-ref="input"
                                            type="number"
                                            min="0"
                                            step="1"
                                            x-model="val"
                                            @click.stop
                                            @keydown.enter.prevent="save()"
                                            @keydown.escape.prevent="cancel()"
                                            class="w-16 min-w-[50px] text-right font-mono text-xs py-0.5 px-1.5 rounded-md ring-1 ring-primary-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 shadow-xs focus:outline-none focus:ring-2 focus:ring-primary-600"
                                        />
                                        <div class="flex items-center gap-0.5 shrink-0">
                                            <button
                                                type="button"
                                                @mousedown.prevent
                                                @click.stop="save()"
                                                class="p-1 rounded-md text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-500/10 transition"
                                                title="Lưu (Enter)"
                                            >
                                                <x-heroicon-m-check class="w-3.5 h-3.5 stroke-[2.5]" />
                                            </button>
                                            <button
                                                type="button"
                                                @mousedown.prevent
                                                @click.stop="cancel()"
                                                class="p-1 rounded-md text-gray-400 hover:bg-gray-100 dark:text-gray-500 dark:hover:bg-white/5 transition"
                                                title="Hủy (Esc)"
                                            >
                                                <x-heroicon-m-x-mark class="w-3.5 h-3.5 stroke-[2.5]" />
                                            </button>
                                        </div>
                                    </div>
                                @else
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                @endif
                            </td>

                            {{-- GW (Click to edit) --}}
                            <td
                                wire:key="cell-gw-{{ $row['order_id'] ?? ('empty-'.$index) }}"
                                class="py-1 px-1.5 text-right border-r border-gray-100 dark:border-white/5 font-mono text-xs"
                                @if ($row['order_id'])
                                    x-data="inlineMetricEditor({{ $row['order_id'] }}, 'total_weight', '{{ $row['gw'] ? (float) $row['gw'] : '' }}')"
                                @endif
                            >
                                @if ($row['order_id'])
                                    <div
                                        x-show="!editing"
                                        @click="startEdit()"
                                        class="group/edit inline-flex items-center justify-end gap-1 w-full cursor-pointer py-1 px-1 rounded-md hover:bg-primary-50/80 dark:hover:bg-primary-950/40 hover:ring-1 hover:ring-primary-300 dark:hover:ring-primary-700 transition"
                                        title="Nhấn để sửa nhanh trọng lượng (GW)"
                                    >
                                        <span x-show="!saving" class="group-hover/edit:text-primary-600 dark:group-hover/edit:text-primary-400 group-hover/edit:font-semibold">
                                            {{ $row['gw'] > 0 ? (fmod((float) $row['gw'], 1) != 0 ? number_format($row['gw'], 2) : number_format($row['gw'])) : '—' }}
                                        </span>
                                        <span x-show="saving" x-cloak class="inline-flex items-center text-primary-500">
                                            <svg class="animate-spin h-3 w-3 text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                        </span>
                                        <x-heroicon-m-pencil-square class="w-3 h-3 text-gray-400 dark:text-gray-500 opacity-0 group-hover/edit:opacity-100 transition-opacity text-primary-500 shrink-0" />
                                    </div>
                                    <div x-show="editing" x-cloak @click.away="save()" class="flex items-center justify-end gap-1 w-full">
                                        <input
                                            x-ref="input"
                                            type="number"
                                            min="0"
                                            step="any"
                                            x-model="val"
                                            @click.stop
                                            @keydown.enter.prevent="save()"
                                            @keydown.escape.prevent="cancel()"
                                            class="w-20 min-w-[60px] text-right font-mono text-xs py-0.5 px-1.5 rounded-md ring-1 ring-primary-500 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 shadow-xs focus:outline-none focus:ring-2 focus:ring-primary-600"
                                        />
                                        <div class="flex items-center gap-0.5 shrink-0">
                                            <button
                                                type="button"
                                                @mousedown.prevent
                                                @click.stop="save()"
                                                class="p-1 rounded-md text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-500/10 transition"
                                                title="Lưu (Enter)"
                                            >
                                                <x-heroicon-m-check class="w-3.5 h-3.5 stroke-[2.5]" />
                                            </button>
                                            <button
                                                type="button"
                                                @mousedown.prevent
                                                @click.stop="cancel()"
                                                class="p-1 rounded-md text-gray-400 hover:bg-gray-100 dark:text-gray-500 dark:hover:bg-white/5 transition"
                                                title="Hủy (Esc)"
                                            >
                                                <x-heroicon-m-x-mark class="w-3.5 h-3.5 stroke-[2.5]" />
                                            </button>
                                        </div>
                                    </div>
                                @else
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                @endif
                            </td>
                            <td class="py-2 px-3 text-center border-r border-gray-100 dark:border-white/5">
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
                                    <x-filament::icon icon="heroicon-o-inbox" class="w-8 h-8 text-gray-400" />
                                    <p class="text-sm font-medium">Không tìm thấy dữ liệu chuyến nào cho {{ $this->getActiveTabTitle() }} phù hợp với bộ lọc</p>
                                    <button
                                        type="button"
                                        wire:click="resetFilters"
                                        class="mt-1 text-xs text-primary-600 dark:text-primary-400 hover:underline cursor-pointer"
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
            <div class="px-4 py-3 border-t border-gray-100 dark:border-white/5 flex flex-wrap items-center justify-between gap-2 bg-gray-50/50 dark:bg-white/[0.02]">
                <div class="text-xs text-gray-600 dark:text-gray-400">
                    Hiển thị từ <span class="font-semibold text-gray-900 dark:text-gray-100">{{ ($paginator->currentPage() - 1) * $paginator->perPage() + 1 }}</span>
                    đến <span class="font-semibold text-gray-900 dark:text-gray-100">{{ min($paginator->total(), $paginator->currentPage() * $paginator->perPage()) }}</span>
                    trên tổng số <span class="font-semibold text-gray-900 dark:text-gray-100">{{ number_format($paginator->total()) }}</span> dòng
                </div>

                <div class="flex items-center gap-1">
                    {{-- Nút Trang trước --}}
                    @if ($paginator->onFirstPage())
                        <span class="px-2.5 py-1 text-xs rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10 text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-white/5 cursor-not-allowed">Trước</span>
                    @else
                        <button
                            type="button"
                            wire:click="gotoPage({{ $paginator->currentPage() - 1 }})"
                            class="px-2.5 py-1 text-xs rounded-lg ring-1 ring-gray-950/10 dark:ring-white/10 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 cursor-pointer transition"
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
                        <button type="button" wire:click="gotoPage(1)" class="px-2.5 py-1 text-xs rounded-lg ring-1 ring-gray-950/10 dark:ring-white/10 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 cursor-pointer transition">1</button>
                        @if ($start > 2) <span class="px-1 text-gray-400">...</span> @endif
                    @endif

                    @for ($p = $start; $p <= $end; $p++)
                        @if ($p === $paginator->currentPage())
                            <span class="px-2.5 py-1 text-xs rounded-lg bg-primary-600 text-white font-semibold shadow-xs">{{ $p }}</span>
                        @else
                            <button
                                type="button"
                                wire:click="gotoPage({{ $p }})"
                                class="px-2.5 py-1 text-xs rounded-lg ring-1 ring-gray-950/10 dark:ring-white/10 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 cursor-pointer transition"
                            >
                                {{ $p }}
                            </button>
                        @endif
                    @endfor

                    @if ($end < $paginator->lastPage())
                        @if ($end < $paginator->lastPage() - 1) <span class="px-1 text-gray-400">...</span> @endif
                        <button type="button" wire:click="gotoPage({{ $paginator->lastPage() }})" class="px-2.5 py-1 text-xs rounded-lg ring-1 ring-gray-950/10 dark:ring-white/10 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 cursor-pointer transition">{{ $paginator->lastPage() }}</button>
                    @endif

                    {{-- Nút Trang sau --}}
                    @if ($paginator->hasMorePages())
                        <button
                            type="button"
                            wire:click="gotoPage({{ $paginator->currentPage() + 1 }})"
                            class="px-2.5 py-1 text-xs rounded-lg ring-1 ring-gray-950/10 dark:ring-white/10 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 cursor-pointer transition"
                        >
                            Sau
                        </button>
                    @else
                        <span class="px-2.5 py-1 text-xs rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10 text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-white/5 cursor-not-allowed">Sau</span>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            registerMetricEditor();
        });
        if (window.Alpine) {
            registerMetricEditor();
        }
        function registerMetricEditor() {
            if (!window.Alpine) return;
            try {
                window.Alpine.data('inlineMetricEditor', (orderId, field, initialVal) => ({
                    editing: false,
                    val: initialVal,
                    originalVal: initialVal,
                    saving: false,
                    startEdit() {
                        if (this.saving) return;
                        this.editing = true;
                        this.val = this.originalVal;
                        this.$nextTick(() => {
                            this.$refs.input?.focus();
                            this.$refs.input?.select();
                        });
                    },
                    save() {
                        if (this.saving || !this.editing) return;
                        this.editing = false;
                        let cleanVal = (this.val === '' || isNaN(this.val)) ? null : (field === 'total_packages' ? Math.max(0, parseInt(this.val, 10)) : Math.max(0, parseFloat(this.val)));
                        let origVal = (this.originalVal === '' || isNaN(this.originalVal)) ? null : (field === 'total_packages' ? Math.max(0, parseInt(this.originalVal, 10)) : Math.max(0, parseFloat(this.originalVal)));
                        if (cleanVal === origVal) {
                            this.val = this.originalVal;
                            return;
                        }
                        this.saving = true;
                        this.$wire.updateOrderMetric(orderId, field, this.val)
                            .then(() => {
                                this.originalVal = this.val;
                            })
                            .catch(() => {
                                this.val = this.originalVal;
                            })
                            .finally(() => {
                                this.saving = false;
                            });
                    },
                    cancel() {
                        this.val = this.originalVal;
                        this.editing = false;
                    }
                }));
            } catch (e) {
                // Ignore if already registered
            }
        }
    </script>
</x-filament-panels::page>
