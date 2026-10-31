                        @php
                            $isTrip = $variant === 'trip';
                            $isChild = $variant === 'order';
                            $charge = $row['order_id'] ? $charges->get($row['order_id']) : null;
                            $chargeTone = match (true) {
                                $charge?->isLocked() === true => 'locked',
                                $charge !== null && $charge->base_price === null => 'missing',
                                default => null,
                            };
                            $stickyBg = match ($chargeTone) {
                                'locked' => 'bg-emerald-50 dark:bg-emerald-950',
                                'missing' => 'bg-amber-50 dark:bg-amber-950',
                                default => $isTrip ? 'bg-slate-100 dark:bg-gray-800' : 'bg-white dark:bg-gray-900',
                            };
                        @endphp
                        <tr wire:key="row-{{ $variant }}-{{ $row['trip_id'] }}-{{ $row['order_id'] ?? 'trip' }}" @class([
                            'dispatch-row whitespace-nowrap group',
                            'bg-slate-100 dark:bg-gray-800 font-semibold' => $isTrip,
                            'bg-emerald-50 dark:bg-emerald-950' => $chargeTone === 'locked',
                            'bg-amber-50 dark:bg-amber-950' => $chargeTone === 'missing',
                        ])>
                            {{-- Sticky 1: STT --}}
                            <td class="py-2 px-2.5 text-center border-r border-gray-100 dark:border-white/5 sticky left-0 z-10 {{ $stickyBg }} group-hover:bg-gray-50 dark:group-hover:bg-gray-800/90 text-gray-400 font-mono w-[50px] min-w-[50px] max-w-[50px]">
                                {{ $stt ?? '' }}
                            </td>

                            {{-- Sticky 2: Mã đơn hoặc Mã chuyến --}}
                            <td class="py-2 px-3 text-center border-r border-gray-100 dark:border-white/5 sticky left-[50px] z-10 {{ $stickyBg }} group-hover:bg-gray-50 dark:group-hover:bg-gray-800/90 font-semibold text-gray-900 dark:text-gray-100 w-[140px] min-w-[140px] max-w-[140px] truncate" title="{{ $row['code'] }}">
                                @if ($isChild)
                                    <span class="text-gray-400">└</span> <span class="font-medium text-gray-700 dark:text-gray-300">{{ $row['code'] }}</span>
                                @else
                                    {{ $row['code'] ?: '—' }}
                                    @if ($isTrip)
                                        <span class="ml-1 text-[10px] font-medium text-gray-500">({{ count($orders) }} đơn)</span>
                                    @endif
                                @endif
                            </td>

                            {{-- Sticky 3: BSX --}}
                            <td class="py-2 px-3 text-center border-r border-gray-200 dark:border-white/10 sticky left-[190px] z-10 {{ $stickyBg }} group-hover:bg-gray-50 dark:group-hover:bg-gray-800/90 shadow-[2px_0_4px_-1px_rgba(0,0,0,0.06)] font-mono font-medium text-gray-800 dark:text-gray-200 w-[110px] min-w-[110px] max-w-[110px]">
                                {{ $isChild ? '' : ($row['plate_number'] ?: '—') }}
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
                                @unless ($isChild)
                                    <x-filament::badge size="xs" :color="$row['vehicle_owner'] === 'Xe công ty' ? 'info' : ($row['vehicle_owner'] === 'Xe thuê' ? 'warning' : 'gray')">
                                        {{ $row['vehicle_owner'] }}
                                    </x-filament::badge>
                                @endunless
                            </td>
                            <td class="py-2 px-3 text-center border-r border-gray-100 dark:border-white/5 text-gray-600 dark:text-gray-400">
                                {{ $isChild ? '' : $row['vehicle_type'] }}
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
                                {{ $isChild ? '' : ($row['driver_name'] ?: '—') }}
                            </td>

                            {{-- Km & Cước --}}
                            <td class="py-2 px-3 text-right border-r border-gray-100 dark:border-white/5 font-mono">
                                {{ $row['km_loaded'] > 0 ? number_format($row['km_loaded'], 1) : '—' }}
                            </td>
                            <td class="py-2 px-3 text-right border-r border-gray-100 dark:border-white/5 font-mono text-gray-500">
                                {{ $isChild ? '' : ($row['km_empty'] > 0 ? number_format($row['km_empty'], 1) : '—') }}
                            </td>

                            {{-- PCS (Click to edit) --}}
                            <td
                                wire:key="cell-pcs-{{ $row['order_id'] ?? ('trip-'.$row['trip_id']) }}"
                                class="py-1 px-1.5 text-right border-r border-gray-100 dark:border-white/5 font-mono text-xs"
                                @if ($row['order_id'])
                                    x-data="inlineMetricEditor({{ $row['order_id'] }}, 'total_packages', '{{ $row['pcs'] ? (int) $row['pcs'] : '' }}')"
                                @endif
                            >
                                @if ($row['order_id'])
                                    <div
                                        x-show="!editing"
                                        @click="startEdit()"
                                        class="group/edit inline-flex items-center justify-end gap-1 w-full cursor-pointer px-1 rounded-md hover:bg-primary-50/80 dark:hover:bg-primary-950/40 hover:ring-1 hover:ring-primary-300 dark:hover:ring-primary-700 transition"
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
                                    <span class="px-1">{{ $row['pcs'] > 0 ? number_format($row['pcs']) : '—' }}</span>
                                @endif
                            </td>

                            {{-- GW (Click to edit) --}}
                            <td
                                wire:key="cell-gw-{{ $row['order_id'] ?? ('trip-'.$row['trip_id']) }}"
                                class="py-1 px-1.5 text-right border-r border-gray-100 dark:border-white/5 font-mono text-xs"
                                @if ($row['order_id'])
                                    x-data="inlineMetricEditor({{ $row['order_id'] }}, 'total_weight', '{{ $row['gw'] ? (float) $row['gw'] : '' }}')"
                                @endif
                            >
                                @if ($row['order_id'])
                                    <div
                                        x-show="!editing"
                                        @click="startEdit()"
                                        class="group/edit inline-flex items-center justify-end gap-1 w-full cursor-pointer px-1 rounded-md hover:bg-primary-50/80 dark:hover:bg-primary-950/40 hover:ring-1 hover:ring-primary-300 dark:hover:ring-primary-700 transition"
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
                                    <span class="px-1">{{ $row['gw'] > 0 ? number_format($row['gw'], 2) : '—' }}</span>
                                @endif
                            </td>
                            <td class="py-2 px-3 text-center border-r border-gray-100 dark:border-white/5">
                                <span class="font-mono text-[11px] text-gray-600 dark:text-gray-400">{{ $row['cargo_type'] ?: '—' }}</span>
                            </td>
                            @if ($showChargeableWeight)
                            <td class="py-2 px-3 text-right font-mono font-semibold text-gray-900 dark:text-gray-100">
                                {{ $row['chargeable_weight'] > 0 ? number_format($row['chargeable_weight'], 1) : '—' }}
                            </td>
                            @endif

                            @if ($showCharges)
                                @php
                                    $money = fn ($value): string => $value === null ? '—' : number_format((float) $value);
                                @endphp
                                <td class="py-2 px-3 border-r border-gray-100 dark:border-white/5 font-mono text-[11px] text-gray-600 dark:text-gray-400 max-w-[200px] truncate" title="{{ $charge?->price_code }}">
                                    {{ $charge?->price_code ?: '—' }}
                                </td>
                                <td class="py-2 px-3 border-r border-gray-100 dark:border-white/5 text-gray-700 dark:text-gray-300">
                                    {{ $charge ? ($charge->rate_type ?: 'Chuẩn') : '—' }}
                                </td>
                                <td class="py-2 px-3 text-right border-r border-gray-100 dark:border-white/5 font-mono">
                                    @if ($charge && $charge->base_price === null)
                                        <span class="text-amber-700 dark:text-amber-400 font-sans font-medium">Chưa có giá</span>
                                    @else
                                        {{ $money($charge?->base_price) }}
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-right border-r border-gray-100 dark:border-white/5 font-mono">
                                    {{ $charge ? rtrim(rtrim(number_format((float) $charge->fuel_adjustment_percent, 2), '0'), '.').'%' : '—' }}
                                </td>
                                <td class="py-2 px-3 text-right border-r border-gray-100 dark:border-white/5 font-mono font-medium text-gray-900 dark:text-gray-100">
                                    {{ $money($charge?->adjusted_price) }}
                                </td>
                                <td class="py-2 px-3 text-right border-r border-gray-100 dark:border-white/5 font-mono" title="{{ $charge ? 'Chờ '.$charge->waiting_minutes.' phút (đóng + hạ hàng)' : '' }}">
                                    {{ $charge && (float) $charge->waiting_fee > 0 ? $money($charge->waiting_fee) : '—' }}
                                    @if ($charge && $charge->waiting_minutes > 0)
                                        <span class="ml-1 text-[10px] text-gray-400">{{ $this->formatDuration($charge->waiting_minutes) }}</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-right border-r border-gray-100 dark:border-white/5 font-mono" title="{{ $charge?->fees->count() ? $charge->fees->count().' khoản phí' : '' }}">
                                    {{ $charge && (float) $charge->other_fees_total > 0 ? $money($charge->other_fees_total) : '—' }}
                                </td>
                                <td class="py-2 px-3 text-right border-r border-gray-100 dark:border-white/5 font-mono" title="{{ $charge ? 'VAT '.(float) $charge->vat_percent.'%' : '' }}">
                                    {{ $charge && (float) $charge->vat_amount > 0 ? $money($charge->vat_amount) : '—' }}
                                </td>
                                <td class="py-2 px-3 text-right border-r border-gray-100 dark:border-white/5 font-mono font-bold text-sky-700 dark:text-sky-300">
                                    {{ $charge ? $money($charge->total) : ($isTrip ? $money($tripTotal) : '—') }}
                                </td>
                                <td class="py-1 px-2 text-center">
                                    @if ($row['order_id'])
                                        <div class="inline-flex items-center gap-0.5">
                                            <button
                                                type="button"
                                                wire:click="mountAction('editCharge', { orderId: {{ $row['order_id'] }} })"
                                                class="p-1 rounded-md text-primary-600 hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-primary-500/10 transition"
                                                title="{{ $chargeTone === 'locked' ? 'Xem cước' : 'Sửa cước' }}"
                                            >
                                                <x-heroicon-m-pencil-square class="w-4 h-4" />
                                            </button>
                                            @if ($chargeTone === 'locked')
                                                <button
                                                    type="button"
                                                    wire:click="unlockCharge({{ $row['order_id'] }})"
                                                    wire:confirm="Mở khoá dòng cước đã xuất bảng kê để sửa lại?"
                                                    class="p-1 rounded-md text-emerald-700 hover:bg-emerald-100 dark:text-emerald-400 dark:hover:bg-emerald-500/10 transition"
                                                    title="Mở khoá"
                                                >
                                                    <x-heroicon-m-lock-closed class="w-4 h-4" />
                                                </button>
                                            @else
                                                <button
                                                    type="button"
                                                    wire:click="recalculateCharge({{ $row['order_id'] }})"
                                                    @if ($charge) wire:confirm="Tra lại đơn giá và giờ chờ theo dữ liệu hiện tại? Đơn giá gốc và giờ chờ đã sửa tay sẽ bị thay." @endif
                                                    wire:loading.attr="disabled"
                                                    wire:target="recalculateCharge({{ $row['order_id'] }})"
                                                    class="p-1 rounded-md text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5 transition"
                                                    title="{{ $charge ? 'Tính lại cước' : 'Tính cước' }}"
                                                >
                                                    <x-heroicon-m-arrow-path class="w-4 h-4" wire:loading.class="animate-spin" wire:target="recalculateCharge({{ $row['order_id'] }})" />
                                                </button>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            @endif
                        </tr>
