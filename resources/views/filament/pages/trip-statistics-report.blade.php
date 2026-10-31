<x-filament-panels::page>
    @php
        $metrics = $this->getSummaryMetrics();
        $paginator = $this->getPaginator();
        $customerOptions = $this->getCustomerOptions();
        $tabCounts = $this->getTabCounts();
        $showCharges = $this->activeTab === 'external';
        $charges = $showCharges ? $this->getChargesForRows($paginator->items()) : collect();
    @endphp

    <div class="w-full space-y-3">
        @include('filament.pages.partials.dispatch-table-styles')

        {{-- Thanh lọc: tìm kiếm, khách hàng, quản lý xe --}}
        <div class="flex flex-wrap items-center gap-2">
            <div class="w-full max-w-xs">
                <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                    <x-filament::input
                        type="search"
                        wire:model.live.debounce.400ms="search"
                        placeholder="Mã đơn, BSX, lái xe..."
                    />
                </x-filament::input.wrapper>
            </div>
            <div class="w-48">
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="customerId">
                        <option value="">Tất cả khách hàng</option>
                        @foreach ($customerOptions as $id => $code)
                            <option value="{{ $id }}">{{ $code }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
            <div class="w-40">
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="vehicleOwner">
                        <option value="all">Tất cả xe</option>
                        <option value="company">Xe công ty</option>
                        <option value="rent">Xe thuê</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
            <x-filament::icon-button
                icon="heroicon-m-arrow-path"
                wire:click="resetFilters"
                label="Đặt lại bộ lọc"
                color="gray"
                tooltip="Đặt lại bộ lọc"
            />

            {{-- Dải số liệu tổng hợp --}}
            <div class="ms-auto flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span><span class="font-semibold text-gray-900 dark:text-white">{{ number_format($metrics['total_trips']) }}</span> chuyến · {{ number_format($metrics['total_records']) }} đơn</span>
                <span title="Có hàng {{ number_format($metrics['total_km_loaded'], 1) }} · không hàng {{ number_format($metrics['total_km_empty'], 1) }}"><span class="font-semibold text-gray-900 dark:text-white">{{ number_format($metrics['total_km'], 1) }}</span> km</span>
                <span title="{{ $this->formatDurationTooltip($metrics['avg_duration']) }}">TB <span class="font-semibold text-gray-900 dark:text-white">{{ $this->formatDuration($metrics['avg_duration']) }}</span> / chuyến</span>
                @if ($metrics['count_waiting_22h'] > 0)
                    <span class="text-amber-600 dark:text-amber-400"><span class="font-semibold">{{ number_format($metrics['count_waiting_22h']) }}</span> chờ sau 22h</span>
                @endif
                @if ($showCharges)
                    <span>Cước <span class="font-semibold text-sky-700 dark:text-sky-300">{{ number_format($metrics['total_payment']) }} đ</span></span>
                @endif
            </div>
        </div>

        {{-- 3 tab theo loại hình --}}
        <div class="transition-opacity duration-150" wire:loading.class="opacity-70 pointer-events-none" wire:target="setActiveTab">
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

        @if ($showCharges)
            @php
                $chargeStatusCounts = $this->getChargeStatusCounts();
                $chargeStatusFilters = [
                    'locked' => ['label' => 'Đã xuất bảng kê (khoá)', 'swatch' => 'bg-emerald-100 ring-emerald-300 dark:bg-emerald-900 dark:ring-emerald-700', 'active' => 'bg-emerald-50 ring-emerald-400 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'],
                    'missing' => ['label' => 'Chưa có giá', 'swatch' => 'bg-amber-100 ring-amber-300 dark:bg-amber-900 dark:ring-amber-700', 'active' => 'bg-amber-50 ring-amber-400 text-amber-800 dark:bg-amber-950 dark:text-amber-300'],
                ];
            @endphp
            <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <span>Lọc:</span>
                @foreach ($chargeStatusFilters as $status => $filter)
                    <button
                        type="button"
                        wire:click="toggleChargeStatus('{{ $status }}')"
                        aria-pressed="{{ $chargeStatus === $status ? 'true' : 'false' }}"
                        title="{{ $chargeStatus === $status ? 'Bỏ lọc' : 'Chỉ hiện các đơn '.mb_strtolower($filter['label']) }}"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 ring-1 transition cursor-pointer',
                            $filter['active'].' font-semibold' => $chargeStatus === $status,
                            'ring-gray-950/10 hover:bg-gray-50 dark:ring-white/10 dark:hover:bg-white/5' => $chargeStatus !== $status,
                        ])
                    >
                        <span class="w-3 h-3 rounded-sm ring-1 {{ $filter['swatch'] }}"></span>
                        {{ $filter['label'] }}
                        <span class="font-mono text-[11px] opacity-70">{{ number_format($chargeStatusCounts[$status]) }}</span>
                        @if ($chargeStatus === $status)
                            <x-heroicon-m-x-mark class="w-3 h-3" />
                        @endif
                    </button>
                @endforeach
            </div>
        @endif

    {{-- Bảng thống kê chi tiết theo loại hình đã chọn --}}
    <div class="rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 bg-white shadow-xs dark:bg-gray-900 overflow-hidden relative">
        {{-- Loading indicator khi chuyển tab hoặc lọc --}}
        <div
            wire:loading.flex
            wire:target="setActiveTab, setDatePreset, startDate, endDate, customerId, vehicleOwner, search, resetFilters, gotoPage, perPage, mountAction"
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

        {{-- Bảng có thanh cuộn ngang và sticky columns --}}
        <div class="dispatch-table-container overflow-auto relative">
            <table class="dispatch-table w-full text-left border-collapse text-xs font-sans">
                <thead>
                    {{-- Dòng header phân nhóm --}}
                    <tr class="text-white text-center font-bold uppercase text-[10px] tracking-wider">
                        <th colspan="5" class="border-b border-r border-white/25 py-2 px-3">
                            1. THÔNG TIN CHUYẾN & HÀNH TRÌNH
                        </th>
                        <th colspan="4" class="border-b border-r border-white/25 py-2 px-3">
                            2. 4 MỐC THỜI GIAN THỰC TẾ
                        </th>
                        <th colspan="5" class="border-b border-r border-white/25 py-2 px-3">
                            3. THỜI LƯỢNG TÍNH TOÁN (GIỜ & PHÚT)
                        </th>
                        <th colspan="7" class="border-b border-r border-white/25 py-2 px-3">
                            4. ĐỐI TÁC, XE & TÀI XẾ
                        </th>
                        <th colspan="6" class="border-b border-r border-white/25 py-2 px-3">
                            5. SẢN LƯỢNG, KM & CƯỚC
                        </th>
                        @if ($showCharges)
                        <th colspan="10" class="border-b border-r border-white/25 py-2 px-3">
                            6. CƯỚC VẬN CHUYỂN
                        </th>
                        @endif
                    </tr>

                    {{-- Dòng header chi tiết các cột --}}
                    <tr class="text-white font-bold uppercase text-[11px] tracking-wide whitespace-nowrap">
                        {{-- Sticky 1: STT --}}
                        <th class="border-b border-r border-white/25 py-2.5 px-2.5 text-center sticky left-0 z-30 w-[50px] min-w-[50px] max-w-[50px]">
                            STT
                        </th>

                        {{-- Sticky 2: Mã đơn hàng hoặc Mã chuyến --}}
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-center sticky left-[50px] z-30 w-[140px] min-w-[140px] max-w-[140px] truncate" title="{{ $this->activeTab === 'empty' ? 'Mã chuyến' : 'Mã đơn hàng' }}">
                            {{ $this->activeTab === 'empty' ? 'Mã chuyến' : 'Mã đơn hàng' }}
                        </th>

                        {{-- Sticky 3: BSX --}}
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-center sticky left-[190px] z-30 shadow-[2px_0_4px_-1px_rgba(0,0,0,0.06)] w-[110px] min-w-[110px] max-w-[110px]">
                            BSX
                        </th>

                        {{-- Cột Ngày --}}
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-center min-w-[90px]">
                            Ngày
                        </th>

                        {{-- Cột Hành trình --}}
                        <th class="border-b border-r border-white/25 py-2.5 px-3.5 min-w-[130px]">
                            Hành trình
                        </th>

                        {{-- Mốc thời gian A, B, C, D --}}
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-center min-w-[115px]">
                            TG đóng hàng (A)
                        </th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-center min-w-[115px]">
                            TG xe chạy (B)
                        </th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-center min-w-[115px]">
                            TG đến (C)
                        </th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-center min-w-[115px]">
                            TG hạ hàng (D)
                        </th>

                        {{-- Thời lượng (giờ & phút) --}}
                        <th class="border-b border-r border-white/25 py-2.5 px-2.5 text-center min-w-[85px]" title="B - A (giờ & phút)">
                            TG đóng (1)
                        </th>
                        <th class="border-b border-r border-white/25 py-2.5 px-2.5 text-center min-w-[85px]" title="C - B (giờ & phút)">
                            TG chạy (2)
                        </th>
                        <th class="border-b border-r border-white/25 py-2.5 px-2.5 text-center min-w-[85px]" title="D - C (giờ & phút)">
                            TG hạ (3)
                        </th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-center min-w-[110px] text-amber-300" title="Chờ hạ hàng sau 22:00 (giờ & phút)">
                            TG chờ 22:00
                        </th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-center min-w-[105px] font-bold text-sky-300" title="(1) + (2) + (3) (giờ & phút)">
                            Tổng TG (1+2+3)
                        </th>

                        {{-- Đối tác & xe --}}
                        <th class="border-b border-r border-white/25 py-2.5 px-3 min-w-[100px]">
                            Khách hàng
                        </th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 min-w-[100px]">
                            {{ $this->activeTab === 'empty' ? 'Kho đến' : 'Kho đóng/trả' }}
                        </th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-center min-w-[95px]">
                            Quản lý xe
                        </th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-center min-w-[90px]">
                            Loại xe
                        </th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 min-w-[100px]">
                            Ghi chú
                        </th>
                        <th class="border-b border-r border-white/25 py-2.5 px-2.5 text-center min-w-[90px]">
                            Hàng quay đầu
                        </th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 min-w-[120px]">
                            Họ tên lái xe
                        </th>

                        {{-- Km & Cước --}}
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-right min-w-[85px]">
                            Km có hàng
                        </th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-right min-w-[85px]">
                            Km không hàng
                        </th>
                        <th class="border-b border-r border-white/25 py-2.5 px-2.5 text-right min-w-[110px]">
                            PCS
                        </th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-right min-w-[125px]">
                            GW (kg)
                        </th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-center min-w-[75px]">
                            Loại hàng
                        </th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-right min-w-[95px]">
                            Trọng tải cước
                        </th>

                        @if ($showCharges)
                        <th class="border-b border-r border-white/25 py-2.5 px-3 min-w-[160px]">Mã giá</th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 min-w-[110px]">Loại hình cước</th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-right min-w-[110px]">Đơn giá gốc</th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-right min-w-[70px]">% dầu</th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-right min-w-[120px]">Đơn giá sau ĐC</th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-right min-w-[110px]" title="Chi phí giờ chờ (thời gian chờ đóng + hạ hàng)">Giờ chờ</th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-right min-w-[100px]">Phí khác</th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-right min-w-[90px]">VAT</th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-right min-w-[120px] font-bold text-sky-300">Thanh toán</th>
                        <th class="border-b border-r border-white/25 py-2.5 px-3 text-center min-w-[120px]">Thao tác</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($paginator->items() as $index => $tripRow)
                        @php
                            $stt = ($paginator->currentPage() - 1) * $paginator->perPage() + $index + 1;
                            $orders = $tripRow['orders'];
                        @endphp
                        @if (count($orders) > 1)
                            @include('filament.pages.partials.trip-statistics-row', [
                                'row' => $tripRow,
                                'variant' => 'trip',
                                'tripTotal' => collect($orders)->sum(fn (array $order): float => (float) $charges->get($order['order_id'])?->total),
                            ])
                            @foreach ($orders as $orderRow)
                                @include('filament.pages.partials.trip-statistics-row', ['row' => $orderRow, 'variant' => 'order', 'stt' => null])
                            @endforeach
                        @else
                            @include('filament.pages.partials.trip-statistics-row', ['row' => $orders[0], 'variant' => 'single'])
                        @endif
                    @empty
                        <tr>
                            <td colspan="{{ $showCharges ? 37 : 27 }}" class="py-12 text-center text-gray-500 dark:text-gray-400">
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

        {{-- Chân bảng: số dòng/trang + phân trang --}}
        @if ($paginator->total() > 0)
            <div class="px-4 py-3 border-t border-gray-100 dark:border-white/5">
                <x-filament::pagination
                    :paginator="$paginator"
                    :page-options="[25, 50, 100]"
                    current-page-option-property="perPage"
                />
            </div>
        @endif
    </div>
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
