{{-- Tab bảng kê chỉ xem: bộ lọc, đánh giá lái xe (xếp hạng + thẻ chi tiết) và dòng thời gian từng chuyến. Không có cước/phí. --}}
@php
    $options = $this->statementFilterOptions;
    $ranking = $this->driverRanking;
    $fleet = $this->fleetPerformance;
    $selected = $this->driverId === null ? null : $ranking->firstWhere('driver_id', $this->driverId);
    $minutes = fn (?int $value): string => $this::formatMinutes($value);
    $longWait = \App\Services\DriverMonthlyKmReportService::LONG_WAIT_MINUTES / 60;
    $maxWait = max(1, (int) $ranking->max('avg_wait'));
    $hasFilters = $serviceType !== '' || $customer !== '' || $warehouse !== '' || $driverId !== null || filled($search);
    $rankHeaders = [
        'trip_count' => ['Chuyến', 'Số chuyến hoàn thành'],
        'km_total' => ['Km', 'Tổng km chạy'],
        'avg_travel' => ['Chạy TB', 'Thời gian chạy trung bình B→C mỗi đơn'],
        'avg_wait' => ['Chờ TB', 'Thời gian chờ trung bình mỗi đơn = đóng (A→B) + hạ (C→D)'],
        'wait_percent' => ['% chờ', 'Tỉ lệ thời gian chờ trên tổng thời gian chạy + chờ'],
        'avg_speed' => ['Km/h', 'Tốc độ trung bình khi chạy (km / thời gian chạy)'],
        'long_wait_count' => ['Chờ > '.$longWait.'h', 'Số đơn chờ lâu hơn '.$longWait.' giờ'],
    ];
    // Chờ TB lệch so với trung bình đội xe: cao hơn 25% là chậm, thấp hơn 20% là nhanh
    $waitTone = fn (?int $value): string => match (true) {
        $value === null || $fleet['avg_wait'] === null => 'text-gray-500 dark:text-gray-400',
        $value > $fleet['avg_wait'] * 1.25 => 'text-rose-600 dark:text-rose-400',
        $value < $fleet['avg_wait'] * 0.8 => 'text-emerald-600 dark:text-emerald-400',
        default => 'text-gray-800 dark:text-gray-200',
    };
    $selectClass = 'block w-full rounded-lg border-none bg-white py-1.5 pe-8 ps-3 text-xs text-gray-950 shadow-xs ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20';
@endphp

{{-- Bộ lọc --}}
<div class="flex flex-wrap items-center gap-2 rounded-xl bg-white p-2 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <div class="inline-flex rounded-lg bg-gray-100 p-0.5 dark:bg-white/5">
        @foreach (['' => 'Tất cả', ...array_combine($options['serviceTypes'], $options['serviceTypes'])] as $value => $label)
            <button
                type="button"
                wire:click="$set('serviceType', @js($value))"
                @class([
                    'rounded-md px-3 py-1 text-xs font-semibold transition',
                    'bg-white text-primary-700 shadow-sm dark:bg-gray-800 dark:text-primary-400' => $serviceType === $value,
                    'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' => $serviceType !== $value,
                ])
            >{{ $label }}</button>
        @endforeach
    </div>

    <select class="{{ $selectClass }} !w-48" wire:change="$set('driverId', $event.target.value ? Number($event.target.value) : null)" aria-label="Lái xe">
        <option value="">Tất cả lái xe</option>
        @foreach ($options['drivers'] as $id => $name)
            <option value="{{ $id }}" @selected($driverId === $id)>{{ $name }}</option>
        @endforeach
    </select>

    <select class="{{ $selectClass }} !w-40" wire:model.live="customer" aria-label="Khách hàng">
        <option value="">Tất cả khách hàng</option>
        @foreach ($options['customers'] as $code)
            <option value="{{ $code }}">{{ $code }}</option>
        @endforeach
    </select>

    <select class="{{ $selectClass }} !w-36" wire:model.live="warehouse" aria-label="Kho">
        <option value="">Tất cả kho</option>
        @foreach ($options['warehouses'] as $code)
            <option value="{{ $code }}">{{ $code }}</option>
        @endforeach
    </select>

    @if ($hasFilters)
        <x-filament::link tag="button" wire:click="resetStatementFilters" icon="heroicon-m-x-mark" size="sm" color="gray">Bỏ lọc</x-filament::link>
    @endif
</div>

<div class="grid grid-cols-1 items-start gap-3 xl:grid-cols-5">
    {{-- Xếp hạng lái xe --}}
    <div class="dispatch-table-container max-h-[460px] overflow-auto rounded-xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 xl:col-span-3">
        <table class="dispatch-table w-full border-collapse font-sans text-xs">
            <thead class="sticky top-0 z-20 shadow-sm">
                <tr class="text-[11px] font-bold uppercase tracking-wide text-white dark:bg-slate-950" style="{{ $navy }}">
                    <th class="border-b border-r border-white/10 px-2 py-2 text-center">#</th>
                    <th class="border-b border-r border-white/10 px-2 py-2 text-left">Đánh giá lái xe · {{ $ranking->count() }}</th>
                    @foreach ($rankHeaders as $column => [$label, $hint])
                        <th class="whitespace-nowrap border-b border-r border-white/10 px-2 py-2 text-center last:border-r-0" title="{{ $hint }}">
                            <button type="button" wire:click="sortRanking('{{ $column }}')" class="inline-flex items-center gap-0.5 uppercase hover:text-white/80">
                                {{ $label }}
                                @if ($rankSort === $column)
                                    <x-filament::icon :icon="$rankDesc ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-up'" class="h-3 w-3" />
                                @endif
                            </button>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($ranking as $index => $row)
                    @php $isSelected = $driverId === $row['driver_id']; @endphp
                    <tr
                        wire:key="rank-{{ $row['driver_id'] }}"
                        wire:click="selectDriver({{ $row['driver_id'] }})"
                        @class([
                            'dispatch-row cursor-pointer',
                            'bg-primary-50 dark:bg-primary-500/15' => $isSelected,
                            'bg-white dark:bg-gray-900' => ! $isSelected,
                        ])
                    >
                        <td class="border-r border-gray-100 px-2 py-1.5 text-center font-mono text-[11px] text-gray-400 dark:border-white/5">{{ $index + 1 }}</td>
                        <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 font-semibold text-primary-600 dark:border-white/5 dark:text-primary-400">{{ $row['driver_name'] }}</td>
                        <td class="border-r border-gray-100 px-2 py-1.5 text-center font-bold dark:border-white/5">{{ $row['trip_count'] }}</td>
                        <td class="border-r border-gray-100 px-2 py-1.5 text-right font-mono text-[11px] dark:border-white/5">{{ number_format($row['km_total'], 1) }}</td>
                        <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-center font-mono text-[11px] dark:border-white/5">{{ $minutes($row['avg_travel']) }}</td>
                        <td
                            class="km-over-cell whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-center font-mono text-[11px] font-semibold dark:border-white/5 {{ $waitTone($row['avg_wait']) }}"
                            @if ($row['avg_wait'])
                                style="background: linear-gradient(to right, rgb(245 158 11 / 0.22) {{ round($row['avg_wait'] / $maxWait * 100) }}%, transparent {{ round($row['avg_wait'] / $maxWait * 100) }}%);"
                            @endif
                        >{{ $minutes($row['avg_wait']) }}</td>
                        <td class="border-r border-gray-100 px-2 py-1.5 text-center font-mono text-[11px] dark:border-white/5">{{ $row['wait_percent'] }}%</td>
                        <td class="border-r border-gray-100 px-2 py-1.5 text-center font-mono text-[11px] dark:border-white/5">{{ $row['avg_speed'] === null ? '—' : number_format($row['avg_speed'], 1) }}</td>
                        <td @class([
                            'px-2 py-1.5 text-center font-mono text-[11px]',
                            'font-bold text-rose-600 dark:text-rose-400' => $row['long_wait_count'] > 0,
                            'text-gray-400' => $row['long_wait_count'] === 0,
                        ])>{{ $row['long_wait_count'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-3 py-8 text-center text-gray-500">Không có lái xe phù hợp.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Thẻ chi tiết: lái xe đang chọn so với trung bình đội xe --}}
    @include('filament.pages.partials.driver-monthly-km-statement-card', ['selected' => $selected, 'fleet' => $fleet, 'navy' => $navy, 'longWait' => $longWait])
</div>

@include('filament.pages.partials.driver-monthly-km-statement-trips', ['navy' => $navy])
