<x-filament-panels::page>
    @php
        $metrics = $this->getSummaryMetrics();
        $paginator = $this->getPaginator();
        $customerOptions = $this->getCustomerOptions();
        $tabCounts = $this->getTabCounts();

        // Lớp dùng chung cho ô bảng (giống bảng Filament)
        $th = 'px-3 py-2.5 text-sm font-semibold text-gray-950 dark:text-white border-r border-gray-200 dark:border-white/10';
        $thEnd = 'px-3 py-2.5 text-sm font-semibold text-gray-950 dark:text-white border-r border-gray-300 dark:border-white/20';
        $td = 'px-3 py-2 border-r border-gray-200 dark:border-white/10';
        $tdEnd = 'px-3 py-2 border-r border-gray-300 dark:border-white/20';
        $stickyTh = 'sticky z-30 bg-gray-50 dark:bg-gray-800';
        $stickyTd = 'sticky z-10 bg-white dark:bg-gray-900 group-hover:bg-gray-50 dark:group-hover:bg-gray-800';
        $pageBtn = 'px-3 py-1.5 text-sm font-semibold text-gray-700 transition duration-75 outline-none hover:bg-gray-50 focus-visible:z-10 focus-visible:ring-2 focus-visible:ring-primary-600 dark:text-gray-200 dark:hover:bg-white/5 dark:focus-visible:ring-primary-500 cursor-pointer';
        $pageItem = 'border-x-[0.5px] border-gray-200 first:border-s-0 last:border-e-0 dark:border-white/10';
    @endphp

    {{-- Bộ lọc và công cụ --}}
    <div class="space-y-4">
        {{-- Nút chọn nhanh khoảng ngày (Presets) --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Khoảng thời gian:</span>
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
                    <x-filament::button
                        type="button"
                        wire:click="setDatePreset('{{ $key }}')"
                        :color="$isActive ? 'primary' : 'gray'"
                        size="xs"
                    >
                        {{ $label }}
                    </x-filament::button>
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
        <x-filament::section compact>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                {{-- Từ ngày --}}
                <div class="min-w-0">
                    <label class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">Từ ngày</label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="date"
                            wire:model.live="startDate"
                        />
                    </x-filament::input.wrapper>
                </div>

                {{-- Đến ngày --}}
                <div class="min-w-0">
                    <label class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">Đến ngày</label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="date"
                            wire:model.live="endDate"
                        />
                    </x-filament::input.wrapper>
                </div>

                {{-- Khách hàng --}}
                <div class="min-w-0">
                    <label class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">Khách hàng</label>
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
                <div class="min-w-0">
                    <label class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">Quản lý xe</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="vehicleOwner">
                            <option value="all">Tất cả hình thức</option>
                            <option value="company">Xe công ty</option>
                            <option value="rent">Xe thuê</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                {{-- Ô tìm kiếm & Reset --}}
                <div class="min-w-0">
                    <label class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">Tìm kiếm từ khóa</label>
                    <div class="flex items-center gap-2">
                        <div class="min-w-0 flex-1">
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
        </x-filament::section>

        {{-- 4 Thẻ KPI Chỉ số tổng hợp nhanh (giao diện stats-overview của Filament) --}}
        @php
            $stats = [
                [
                    'label' => 'Tổng chuyến phục vụ',
                    'icon' => 'heroicon-o-truck',
                    'value' => number_format($metrics['total_records']),
                    'title' => null,
                    'description' => 'dòng (' . number_format($metrics['total_trips']) . ' chuyến)',
                    'color' => 'gray',
                ],
                [
                    'label' => 'Tổng Km vận hành',
                    'icon' => 'heroicon-o-map-pin',
                    'value' => number_format($metrics['total_km'], 1),
                    'title' => null,
                    'description' => 'km (CH: ' . number_format($metrics['total_km_loaded'], 1) . ' - KH: ' . number_format($metrics['total_km_empty'], 1) . ')',
                    'color' => 'gray',
                ],
                [
                    'label' => 'Thời gian TB / Chuyến',
                    'icon' => 'heroicon-o-clock',
                    'value' => $this->formatDuration($metrics['avg_duration']),
                    'title' => $this->formatDurationTooltip($metrics['avg_duration']),
                    'description' => 'TB / chuyến (' . number_format($metrics['avg_duration']) . "')",
                    'color' => 'gray',
                ],
                [
                    'label' => 'Chuyến chờ hạ hàng sau 22:00',
                    'icon' => 'heroicon-o-moon',
                    'value' => number_format($metrics['count_waiting_22h']),
                    'title' => null,
                    'description' => 'chuyến phát sinh mốc 22h',
                    'color' => 'warning',
                ],
            ];
        @endphp
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($stats as $stat)
                <div class="fi-wi-stats-overview-stat">
                    <div class="fi-wi-stats-overview-stat-content">
                        <div class="fi-wi-stats-overview-stat-label-ctn">
                            <x-filament::icon :icon="$stat['icon']" class="h-5 w-5" />
                            <span class="fi-wi-stats-overview-stat-label">{{ $stat['label'] }}</span>
                        </div>
                        <div class="fi-wi-stats-overview-stat-value" @if ($stat['title']) title="{{ $stat['title'] }}" @endif>
                            {{ $stat['value'] }}
                        </div>
                        <div
                            {{ (new \Filament\Support\View\ComponentAttributeBag)->color(\Filament\Widgets\View\Components\StatsOverviewWidgetComponent\StatComponent\DescriptionComponent::class, $stat['color'])->class(['fi-wi-stats-overview-stat-description']) }}
                        >
                            <span>{{ $stat['description'] }}</span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- 3 Tabs hiển thị riêng biệt theo 3 loại hình --}}
    <div>
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
    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        {{-- Header Bảng --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 sm:px-6 dark:border-white/10">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                        BẢNG THỐNG KÊ TỔNG HỢP CÁC CHUYẾN ĐÃ PHỤC VỤ
                    </h3>
                    <x-filament::badge
                        :color="match ($this->activeTab) {
                            'HHHK' => 'info',
                            'external' => 'warning',
                            'empty' => 'gray',
                            default => 'primary',
                        }"
                    >
                        {{ $this->getActiveTabTitle() }}
                    </x-filament::badge>
                </div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
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
                <span class="text-sm text-gray-500 dark:text-gray-400">Số dòng/trang:</span>
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
        <div class="relative max-h-[680px] overflow-x-auto">
            <table class="w-full border-collapse text-left text-sm">
                <thead>
                    {{-- Dòng header phân nhóm --}}
                    <tr class="border-b border-gray-200 text-center text-xs font-semibold uppercase tracking-wider dark:border-white/10">
                        <th colspan="5" class="border-r border-gray-300 bg-primary-50 px-3 py-2 text-primary-700 dark:border-white/20 dark:bg-primary-400/10 dark:text-primary-400">
                            1. THÔNG TIN CHUYẾN & HÀNH TRÌNH
                        </th>
                        <th colspan="4" class="border-r border-gray-300 bg-gray-100 px-3 py-2 text-gray-700 dark:border-white/20 dark:bg-white/5 dark:text-gray-300">
                            2. 4 MỐC THỜI GIAN THỰC TẾ
                        </th>
                        <th colspan="5" class="border-r border-gray-300 bg-info-50 px-3 py-2 text-info-700 dark:border-white/20 dark:bg-info-400/10 dark:text-info-400">
                            3. THỜI LƯỢNG TÍNH TOÁN (GIỜ & PHÚT)
                        </th>
                        <th colspan="7" class="border-r border-gray-300 bg-gray-100 px-3 py-2 text-gray-700 dark:border-white/20 dark:bg-white/5 dark:text-gray-300">
                            4. ĐỐI TÁC, XE & TÀI XẾ
                        </th>
                        <th colspan="6" class="bg-success-50 px-3 py-2 text-success-700 dark:bg-success-400/10 dark:text-success-400">
                            5. SẢN LƯỢNG, KM & CƯỚC
                        </th>
                    </tr>

                    {{-- Dòng header chi tiết các cột --}}
                    <tr class="whitespace-nowrap border-b border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                        {{-- Sticky 1: STT --}}
                        <th class="{{ $th }} {{ $stickyTh }} left-0 w-[50px] min-w-[50px] max-w-[50px] text-center">
                            STT
                        </th>

                        {{-- Sticky 2: Mã đơn hàng hoặc Mã chuyến --}}
                        <th class="{{ $th }} {{ $stickyTh }} left-[50px] w-[140px] min-w-[140px] max-w-[140px] truncate text-center" title="{{ $this->activeTab === 'empty' ? 'Mã chuyến' : 'Mã đơn hàng' }}">
                            {{ $this->activeTab === 'empty' ? 'Mã chuyến' : 'Mã đơn hàng' }}
                        </th>

                        {{-- Sticky 3: BSX --}}
                        <th class="{{ $thEnd }} {{ $stickyTh }} left-[190px] w-[110px] min-w-[110px] max-w-[110px] text-center shadow-[3px_0_6px_-2px_rgba(0,0,0,0.15)]">
                            BSX
                        </th>

                        {{-- Cột Ngày --}}
                        <th class="{{ $th }} min-w-[90px] text-center">
                            Ngày
                        </th>

                        {{-- Cột Hành trình --}}
                        <th class="{{ $thEnd }} min-w-[130px]">
                            Hành trình
                        </th>

                        {{-- Mốc thời gian A, B, C, D --}}
                        <th class="{{ $th }} min-w-[115px] text-center">
                            TG đóng hàng (A)
                        </th>
                        <th class="{{ $th }} min-w-[115px] text-center">
                            TG xe chạy (B)
                        </th>
                        <th class="{{ $th }} min-w-[115px] text-center">
                            TG đến (C)
                        </th>
                        <th class="{{ $thEnd }} min-w-[115px] text-center">
                            TG hạ hàng (D)
                        </th>

                        {{-- Thời lượng (giờ & phút) --}}
                        <th class="{{ $th }} min-w-[85px] text-center" title="B - A (giờ & phút)">
                            TG đóng (1)
                        </th>
                        <th class="{{ $th }} min-w-[85px] text-center" title="C - B (giờ & phút)">
                            TG chạy (2)
                        </th>
                        <th class="{{ $th }} min-w-[85px] text-center" title="D - C (giờ & phút)">
                            TG hạ (3)
                        </th>
                        <th class="{{ $th }} min-w-[110px] text-center" title="Chờ hạ hàng sau 22:00 (giờ & phút)">
                            TG chờ 22:00
                        </th>
                        <th class="{{ $thEnd }} min-w-[105px] text-center" title="(1) + (2) + (3) (giờ & phút)">
                            Tổng TG (1+2+3)
                        </th>

                        {{-- Đối tác & xe --}}
                        <th class="{{ $th }} min-w-[100px]">
                            {{ $this->activeTab === 'empty' ? 'Khách hàng' : 'Khách hàng' }}
                        </th>
                        <th class="{{ $th }} min-w-[100px]">
                            {{ $this->activeTab === 'empty' ? 'Kho đến' : 'Kho đóng/trả' }}
                        </th>
                        <th class="{{ $th }} min-w-[95px] text-center">
                            Quản lý xe
                        </th>
                        <th class="{{ $th }} min-w-[90px] text-center">
                            Loại xe
                        </th>
                        <th class="{{ $th }} min-w-[100px]">
                            Ghi chú
                        </th>
                        <th class="{{ $th }} min-w-[90px] text-center">
                            Hàng quay đầu
                        </th>
                        <th class="{{ $thEnd }} min-w-[120px]">
                            Họ tên lái xe
                        </th>

                        {{-- Km & Cước --}}
                        <th class="{{ $th }} min-w-[85px] text-right">
                            Km có hàng
                        </th>
                        <th class="{{ $th }} min-w-[85px] text-right">
                            Km không hàng
                        </th>
                        <th class="{{ $th }} min-w-[110px] text-right">
                            PCS
                        </th>
                        <th class="{{ $th }} min-w-[125px] text-right">
                            GW (kg)
                        </th>
                        <th class="{{ $th }} min-w-[75px] text-center">
                            Loại hàng
                        </th>
                        <th class="min-w-[95px] px-3 py-2.5 text-right text-sm font-semibold text-gray-950 dark:text-white">
                            Trọng tải cước
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse ($paginator->items() as $index => $row)
                        @php
                            $stt = ($paginator->currentPage() - 1) * $paginator->perPage() + $index + 1;
                            $ownerBadgeColor = match($row['vehicle_owner']) {
                                'Xe công ty' => 'primary',
                                'Xe thuê' => 'info',
                                default => 'gray',
                            };
                        @endphp
                        <tr wire:key="row-{{ $row['trip_id'] }}-{{ $row['order_id'] ?? ('empty-'.$index) }}" class="group whitespace-nowrap text-gray-950 transition duration-75 hover:bg-gray-50 dark:text-white dark:hover:bg-white/5">
                            {{-- Sticky 1: STT --}}
                            <td class="{{ $td }} {{ $stickyTd }} left-0 w-[50px] min-w-[50px] max-w-[50px] text-center tabular-nums text-gray-500 dark:text-gray-400">
                                {{ $stt }}
                            </td>

                            {{-- Sticky 2: Mã đơn hoặc Mã chuyến --}}
                            <td class="{{ $td }} {{ $stickyTd }} left-[50px] w-[140px] min-w-[140px] max-w-[140px] truncate text-center font-medium" title="{{ $row['code'] }}">
                                {{ $row['code'] ?: '—' }}
                            </td>

                            {{-- Sticky 3: BSX --}}
                            <td class="{{ $tdEnd }} {{ $stickyTd }} left-[190px] w-[110px] min-w-[110px] max-w-[110px] truncate text-center font-medium tabular-nums shadow-[3px_0_6px_-2px_rgba(0,0,0,0.15)]" title="{{ $row['plate_number'] }}">
                                {{ $row['plate_number'] ?: '—' }}
                            </td>

                            {{-- Cột Ngày --}}
                            <td class="{{ $td }} text-center tabular-nums text-gray-500 dark:text-gray-400">
                                {{ $row['date'] ? (\Carbon\Carbon::parse($row['date'])->format('d/m/Y')) : '—' }}
                            </td>

                            {{-- Cột Hành trình --}}
                            <td class="{{ $tdEnd }} font-medium">
                                {{ $row['journey'] ?: '—' }}
                            </td>

                            {{-- 4 Mốc thời gian --}}
                            <td class="{{ $td }} text-center tabular-nums text-gray-700 dark:text-gray-300">
                                {{ $row['time_a'] ? (\Carbon\Carbon::parse($row['time_a'])->format('H:i d/m')) : '—' }}
                            </td>
                            <td class="{{ $td }} text-center tabular-nums text-gray-700 dark:text-gray-300">
                                {{ $row['time_b'] ? (\Carbon\Carbon::parse($row['time_b'])->format('H:i d/m')) : '—' }}
                            </td>
                            <td class="{{ $td }} text-center tabular-nums text-gray-700 dark:text-gray-300">
                                {{ $row['time_c'] ? (\Carbon\Carbon::parse($row['time_c'])->format('H:i d/m')) : '—' }}
                            </td>
                            <td class="{{ $tdEnd }} text-center tabular-nums text-gray-700 dark:text-gray-300">
                                {{ $row['time_d'] ? (\Carbon\Carbon::parse($row['time_d'])->format('H:i d/m')) : '—' }}
                            </td>

                            {{-- Thời lượng (giờ & phút) --}}
                            <td class="{{ $td }} text-center tabular-nums text-gray-700 dark:text-gray-300" title="{{ $this->formatDurationTooltip($row['time_loading']) }}">
                                {{ $this->formatDuration($row['time_loading']) }}
                            </td>
                            <td class="{{ $td }} text-center tabular-nums text-gray-700 dark:text-gray-300" title="{{ $this->formatDurationTooltip($row['time_travel']) }}">
                                {{ $this->formatDuration($row['time_travel']) }}
                            </td>
                            <td class="{{ $td }} text-center tabular-nums text-gray-700 dark:text-gray-300" title="{{ $this->formatDurationTooltip($row['time_unloading']) }}">
                                {{ $this->formatDuration($row['time_unloading']) }}
                            </td>
                            <td class="{{ $td }} text-center">
                                @if (($row['time_waiting_22h'] ?? 0) > 0)
                                    <x-filament::badge
                                        color="warning"
                                        class="tabular-nums"
                                        title="{{ $this->formatDurationTooltip($row['time_waiting_22h']) }}"
                                    >
                                        {{ $this->formatDuration($row['time_waiting_22h']) }}
                                    </x-filament::badge>
                                @else
                                    <span class="text-gray-400 dark:text-gray-500">0</span>
                                @endif
                            </td>
                            <td class="{{ $tdEnd }} text-center font-semibold tabular-nums text-primary-600 dark:text-primary-400" title="{{ $this->formatDurationTooltip($row['time_total_trip']) }}">
                                {{ $this->formatDuration($row['time_total_trip']) }}
                            </td>

                            {{-- Đối tác & Xe --}}
                            <td class="{{ $td }} font-medium">
                                {{ $row['customer'] ?: '—' }}
                            </td>
                            <td class="{{ $td }} text-gray-700 dark:text-gray-300">
                                {{ $row['warehouse'] ?: '—' }}
                            </td>
                            <td class="{{ $td }} text-center">
                                <x-filament::badge :color="$ownerBadgeColor" size="sm">
                                    {{ $row['vehicle_owner'] }}
                                </x-filament::badge>
                            </td>
                            <td class="{{ $td }} text-center text-gray-500 dark:text-gray-400">
                                {{ $row['vehicle_type'] }}
                            </td>
                            <td class="{{ $td }} max-w-[140px] truncate text-gray-500 dark:text-gray-400" title="{{ $row['note'] }}">
                                {{ $row['note'] ?: '—' }}
                            </td>
                            <td class="{{ $td }} text-center">
                                @if ($row['is_return_trip'])
                                    <x-filament::badge color="success" size="sm">
                                        Quay đầu
                                    </x-filament::badge>
                                @else
                                    <span class="text-gray-400 dark:text-gray-500">—</span>
                                @endif
                            </td>
                            <td class="{{ $tdEnd }} font-medium">
                                {{ $row['driver_name'] ?: '—' }}
                            </td>

                            {{-- Km & Cước --}}
                            <td class="{{ $td }} text-right tabular-nums">
                                {{ $row['km_loaded'] > 0 ? number_format($row['km_loaded'], 1) : '—' }}
                            </td>
                            <td class="{{ $td }} text-right tabular-nums text-gray-500 dark:text-gray-400">
                                {{ $row['km_empty'] > 0 ? number_format($row['km_empty'], 1) : '—' }}
                            </td>
                            {{-- PCS (Click to edit) --}}
                            <td
                                wire:key="cell-pcs-{{ $row['order_id'] ?? ('empty-'.$index) }}"
                                class="border-r border-gray-200 px-1.5 py-1 text-right tabular-nums dark:border-white/10"
                                @if ($row['order_id'])
                                    x-data="{
                                        editing: false,
                                        val: '{{ $row['pcs'] ? (int) $row['pcs'] : '' }}',
                                        originalVal: '{{ $row['pcs'] ? (int) $row['pcs'] : '' }}',
                                        saving: false,
                                        startEdit() {
                                            if (this.saving) return;
                                            this.editing = true;
                                            this.val = this.originalVal;
                                            $nextTick(() => {
                                                $refs.input.focus();
                                                $refs.input.select();
                                            });
                                        },
                                        save() {
                                            if (this.saving || !this.editing) return;
                                            this.editing = false;
                                            let cleanVal = (this.val === '' || isNaN(this.val)) ? null : Math.max(0, parseInt(this.val, 10));
                                            let origVal = (this.originalVal === '' || isNaN(this.originalVal)) ? null : Math.max(0, parseInt(this.originalVal, 10));
                                            if (cleanVal === origVal) {
                                                this.val = this.originalVal;
                                                return;
                                            }
                                            this.saving = true;
                                            $wire.updateOrderMetric({{ $row['order_id'] }}, 'total_packages', this.val)
                                                .then(() => {
                                                    this.originalVal = this.val;
                                                })
                                                .catch((err) => {
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
                                    }"
                                @endif
                            >
                                @if ($row['order_id'])
                                    <div
                                        x-show="!editing"
                                        @click="startEdit()"
                                        class="group/edit inline-flex w-full cursor-pointer items-center justify-end gap-1 rounded-md px-1.5 py-1 transition duration-75 hover:bg-primary-50 hover:ring-1 hover:ring-primary-600/20 dark:hover:bg-primary-400/10 dark:hover:ring-primary-400/30"
                                        title="Nhấn để sửa nhanh số kiện (PCS)"
                                    >
                                        <span x-show="!saving" class="group-hover/edit:font-semibold group-hover/edit:text-primary-600 dark:group-hover/edit:text-primary-400">
                                            {{ $row['pcs'] > 0 ? number_format($row['pcs']) : '—' }}
                                        </span>
                                        <span x-show="saving" x-cloak class="inline-flex items-center text-primary-500">
                                            <x-filament::loading-indicator class="h-4 w-4" />
                                        </span>
                                        <x-filament::icon icon="heroicon-m-pencil-square" class="h-4 w-4 shrink-0 text-primary-500 opacity-0 transition-opacity group-hover/edit:opacity-100 motion-reduce:transition-none" />
                                    </div>
                                    <div x-show="editing" x-cloak @click.away="save()" class="flex w-full items-center justify-end gap-1">
                                        <input
                                            x-ref="input"
                                            type="number"
                                            min="0"
                                            step="1"
                                            x-model="val"
                                            @click.stop
                                            @keydown.enter.prevent="save()"
                                            @keydown.escape.prevent="cancel()"
                                            class="w-16 min-w-[50px] rounded-md border-0 bg-white px-1.5 py-0.5 text-right text-sm tabular-nums text-gray-950 shadow-sm ring-2 ring-primary-600 focus:outline-none dark:bg-white/5 dark:text-white dark:ring-primary-500"
                                        />
                                        <div class="flex shrink-0 items-center gap-0.5">
                                            <x-filament::icon-button
                                                icon="heroicon-m-check"
                                                color="success"
                                                size="sm"
                                                label="Lưu (Enter)"
                                                tooltip="Lưu (Enter)"
                                                x-on:mousedown.prevent=""
                                                x-on:click.stop="save()"
                                            />
                                            <x-filament::icon-button
                                                icon="heroicon-m-x-mark"
                                                color="danger"
                                                size="sm"
                                                label="Hủy (Esc)"
                                                tooltip="Hủy (Esc)"
                                                x-on:mousedown.prevent=""
                                                x-on:click.stop="cancel()"
                                            />
                                        </div>
                                    </div>
                                @else
                                    <span class="text-gray-400 dark:text-gray-500">—</span>
                                @endif
                            </td>

                            {{-- GW (Click to edit) --}}
                            <td
                                wire:key="cell-gw-{{ $row['order_id'] ?? ('empty-'.$index) }}"
                                class="border-r border-gray-200 px-1.5 py-1 text-right tabular-nums dark:border-white/10"
                                @if ($row['order_id'])
                                    x-data="{
                                        editing: false,
                                        val: '{{ $row['gw'] ? (float) $row['gw'] : '' }}',
                                        originalVal: '{{ $row['gw'] ? (float) $row['gw'] : '' }}',
                                        saving: false,
                                        startEdit() {
                                            if (this.saving) return;
                                            this.editing = true;
                                            this.val = this.originalVal;
                                            $nextTick(() => {
                                                $refs.input.focus();
                                                $refs.input.select();
                                            });
                                        },
                                        save() {
                                            if (this.saving || !this.editing) return;
                                            this.editing = false;
                                            let cleanVal = (this.val === '' || isNaN(this.val)) ? null : Math.max(0, parseFloat(this.val));
                                            let origVal = (this.originalVal === '' || isNaN(this.originalVal)) ? null : Math.max(0, parseFloat(this.originalVal));
                                            if (cleanVal === origVal) {
                                                this.val = this.originalVal;
                                                return;
                                            }
                                            this.saving = true;
                                            $wire.updateOrderMetric({{ $row['order_id'] }}, 'total_weight', this.val)
                                                .then(() => {
                                                    this.originalVal = this.val;
                                                })
                                                .catch((err) => {
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
                                    }"
                                @endif
                            >
                                @if ($row['order_id'])
                                    <div
                                        x-show="!editing"
                                        @click="startEdit()"
                                        class="group/edit inline-flex w-full cursor-pointer items-center justify-end gap-1 rounded-md px-1.5 py-1 transition duration-75 hover:bg-primary-50 hover:ring-1 hover:ring-primary-600/20 dark:hover:bg-primary-400/10 dark:hover:ring-primary-400/30"
                                        title="Nhấn để sửa nhanh trọng lượng (GW)"
                                    >
                                        <span x-show="!saving" class="group-hover/edit:font-semibold group-hover/edit:text-primary-600 dark:group-hover/edit:text-primary-400">
                                            {{ $row['gw'] > 0 ? (fmod((float) $row['gw'], 1) != 0 ? number_format($row['gw'], 2) : number_format($row['gw'])) : '—' }}
                                        </span>
                                        <span x-show="saving" x-cloak class="inline-flex items-center text-primary-500">
                                            <x-filament::loading-indicator class="h-4 w-4" />
                                        </span>
                                        <x-filament::icon icon="heroicon-m-pencil-square" class="h-4 w-4 shrink-0 text-primary-500 opacity-0 transition-opacity group-hover/edit:opacity-100 motion-reduce:transition-none" />
                                    </div>
                                    <div x-show="editing" x-cloak @click.away="save()" class="flex w-full items-center justify-end gap-1">
                                        <input
                                            x-ref="input"
                                            type="number"
                                            min="0"
                                            step="any"
                                            x-model="val"
                                            @click.stop
                                            @keydown.enter.prevent="save()"
                                            @keydown.escape.prevent="cancel()"
                                            class="w-20 min-w-[60px] rounded-md border-0 bg-white px-1.5 py-0.5 text-right text-sm tabular-nums text-gray-950 shadow-sm ring-2 ring-primary-600 focus:outline-none dark:bg-white/5 dark:text-white dark:ring-primary-500"
                                        />
                                        <div class="flex shrink-0 items-center gap-0.5">
                                            <x-filament::icon-button
                                                icon="heroicon-m-check"
                                                color="success"
                                                size="sm"
                                                label="Lưu (Enter)"
                                                tooltip="Lưu (Enter)"
                                                x-on:mousedown.prevent=""
                                                x-on:click.stop="save()"
                                            />
                                            <x-filament::icon-button
                                                icon="heroicon-m-x-mark"
                                                color="danger"
                                                size="sm"
                                                label="Hủy (Esc)"
                                                tooltip="Hủy (Esc)"
                                                x-on:mousedown.prevent=""
                                                x-on:click.stop="cancel()"
                                            />
                                        </div>
                                    </div>
                                @else
                                    <span class="text-gray-400 dark:text-gray-500">—</span>
                                @endif
                            </td>
                            <td class="{{ $td }} text-center">
                                <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $row['cargo_type'] ?: '—' }}</span>
                            </td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums">
                                {{ $row['chargeable_weight'] > 0 ? number_format($row['chargeable_weight'], 1) : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="27">
                                <x-filament::empty-state
                                    :contained="false"
                                    icon="heroicon-o-inbox"
                                    icon-color="gray"
                                    :heading="'Không tìm thấy dữ liệu chuyến nào cho ' . $this->getActiveTabTitle() . ' phù hợp với bộ lọc'"
                                >
                                    <x-slot name="footer">
                                        <x-filament::button
                                            type="button"
                                            wire:click="resetFilters"
                                            color="gray"
                                            size="sm"
                                            icon="heroicon-m-arrow-path"
                                        >
                                            Đặt lại bộ lọc
                                        </x-filament::button>
                                    </x-slot>
                                </x-filament::empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Phân trang (Pagination footer) --}}
        @if ($paginator->hasPages())
            <nav aria-label="Phân trang" class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 px-4 py-3 sm:px-6 dark:border-white/10">
                <div class="text-sm text-gray-700 dark:text-gray-200">
                    Hiển thị từ <span class="font-semibold tabular-nums">{{ ($paginator->currentPage() - 1) * $paginator->perPage() + 1 }}</span>
                    đến <span class="font-semibold tabular-nums">{{ min($paginator->total(), $paginator->currentPage() * $paginator->perPage()) }}</span>
                    trên tổng số <span class="font-semibold tabular-nums">{{ number_format($paginator->total()) }}</span> dòng
                </div>

                <ol class="flex flex-wrap overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/20">
                    {{-- Nút Trang trước --}}
                    <li class="{{ $pageItem }}">
                        @if ($paginator->onFirstPage())
                            <span class="block cursor-not-allowed px-3 py-1.5 text-sm font-semibold text-gray-400 dark:text-gray-500">Trước</span>
                        @else
                            <button
                                type="button"
                                wire:click="gotoPage({{ $paginator->currentPage() - 1 }})"
                                class="{{ $pageBtn }}"
                            >
                                Trước
                            </button>
                        @endif
                    </li>

                    {{-- Các số trang --}}
                    @php
                        $start = max(1, $paginator->currentPage() - 2);
                        $end = min($paginator->lastPage(), $paginator->currentPage() + 2);
                    @endphp

                    @if ($start > 1)
                        <li class="{{ $pageItem }}">
                            <button type="button" wire:click="gotoPage(1)" class="{{ $pageBtn }}">1</button>
                        </li>
                        @if ($start > 2)
                            <li class="{{ $pageItem }}"><span class="block px-2 py-1.5 text-sm text-gray-400 dark:text-gray-500">...</span></li>
                        @endif
                    @endif

                    @for ($p = $start; $p <= $end; $p++)
                        <li class="{{ $pageItem }}">
                            @if ($p === $paginator->currentPage())
                                <span aria-current="page" class="block bg-gray-50 px-3 py-1.5 text-sm font-semibold text-primary-700 dark:bg-white/5 dark:text-primary-400">{{ $p }}</span>
                            @else
                                <button
                                    type="button"
                                    wire:click="gotoPage({{ $p }})"
                                    class="{{ $pageBtn }}"
                                >
                                    {{ $p }}
                                </button>
                            @endif
                        </li>
                    @endfor

                    @if ($end < $paginator->lastPage())
                        @if ($end < $paginator->lastPage() - 1)
                            <li class="{{ $pageItem }}"><span class="block px-2 py-1.5 text-sm text-gray-400 dark:text-gray-500">...</span></li>
                        @endif
                        <li class="{{ $pageItem }}">
                            <button type="button" wire:click="gotoPage({{ $paginator->lastPage() }})" class="{{ $pageBtn }}">{{ $paginator->lastPage() }}</button>
                        </li>
                    @endif

                    {{-- Nút Trang sau --}}
                    <li class="{{ $pageItem }}">
                        @if ($paginator->hasMorePages())
                            <button
                                type="button"
                                wire:click="gotoPage({{ $paginator->currentPage() + 1 }})"
                                class="{{ $pageBtn }}"
                            >
                                Sau
                            </button>
                        @else
                            <span class="block cursor-not-allowed px-3 py-1.5 text-sm font-semibold text-gray-400 dark:text-gray-500">Sau</span>
                        @endif
                    </li>
                </ol>
            </nav>
        @endif
    </div>
</x-filament-panels::page>
