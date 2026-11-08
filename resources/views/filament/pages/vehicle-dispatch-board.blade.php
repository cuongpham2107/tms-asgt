<x-filament-panels::page>
    {{-- Loading overlay khi mở modal xe/GPS/đảo lái (chặn click liên tục) --}}
    <div
        wire:loading
        wire:target="openTripModal, openGpsModal, openDriverSwapModal, submitDriverSwap"
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

    @vite('resources/js/filament/gps-modal-map.ts')

    @include('filament.pages.partials.dispatch-table-styles')

    {{-- Bảng Kiểm soát tình trạng xe do widget React vẽ; page giữ bộ lọc, modal và action --}}
    <div class="fi-js-widget-bare">
        @livewire(\App\Filament\Widgets\VehicleDispatchTable::class, [
            'defer' => true,
            'searchQuery' => $this->searchQuery,
            'sortBy' => $this->sortBy,
            'sortDirection' => $this->sortDirection,
            'activeStation' => $this->activeStation,
            'activeMovementStatus' => $this->activeMovementStatus,
            'dataVersion' => $this->dataVersion,
        ])
    </div>

    {{-- Modals khi click vào BSX hoặc Vị trí GPS --}}
    @php
        $tripVehicle = $this->getSelectedVehicleForTrip();
        $gpsVehicle = $this->getSelectedVehicleForGps();
        $timelineTrip = $this->getSelectedTripForTimeline();
    @endphp

    {{-- Modal xem danh sách chuyến xe trong ngày --}}
    <x-filament::modal id="vehicle-trip-modal" width="6xl">
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

    {{-- Modal gán lại tài xế (Đảo lái) tự tạo, không phụ thuộc Filament table action modals --}}
    @php
        $swapTrip = $this->getSelectedTripForSwap();
        $swapVehicle = $this->getSelectedVehicleForSwap();
        $availableDrivers = $this->getAvailableDriversForSwap();
        $swapHandoverInfo = $this->getSwapHandoverInfo();
    @endphp

    <x-filament::modal id="vehicle-swap-driver-modal" width="xl" icon="heroicon-o-arrow-path" icon-color="warning">
        <x-slot name="heading">
            Gán lại tài xế mới{{ $swapTrip?->trip_code ? ' — '.$swapTrip->trip_code : ($swapVehicle ? ' — '.$swapVehicle->plate_number : '') }}
        </x-slot>

        <x-slot name="description">
            Chuyến đi đang yêu cầu đảo tài xế. Chọn tài xế mới để tiếp tục xử lý chuyến.
        </x-slot>

        @if ($swapTrip)
            <div class="space-y-4 text-sm" wire:key="vehicle-swap-modal-content-{{ $swapTrip->id }}">
                {{-- Thông tin chuyến xe hiện tại --}}
                <div class="rounded-xl bg-gray-50/70 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10 space-y-2 text-xs">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-sm text-gray-900 dark:text-white">{{ $swapVehicle?->plate_number ?? $swapTrip->vehicle?->plate_number }}</span>
                            <x-filament::badge color="danger" size="xs">Đảo lái</x-filament::badge>
                        </div>
                        <span class="text-gray-500 font-mono font-medium">{{ $swapTrip->trip_code }}</span>
                    </div>
                    <div class="grid grid-cols-2 gap-2 pt-1.5 border-t border-gray-200 dark:border-white/10 text-gray-600 dark:text-gray-400">
                        <div>
                            <span>Tài xế hiện tại:</span>
                            <strong class="text-gray-900 dark:text-white block mt-0.5">{{ $swapTrip->driver?->name ?? '—' }}</strong>
                        </div>
                        <div>
                            <span>Mốc Km bàn giao:</span>
                            <strong class="text-amber-600 dark:text-amber-400 block mt-0.5">{{ $swapHandoverInfo }}</strong>
                        </div>
                    </div>
                </div>

                {{-- Cảnh báo nếu chưa có mốc bàn giao --}}
                @if ($swapHandoverInfo === 'Chưa có mốc bàn giao')
                    <div class="rounded-lg border border-amber-300 bg-amber-50 p-2.5 text-xs text-amber-800 dark:border-amber-700 dark:bg-amber-950/60 dark:text-amber-200 flex items-center gap-2">
                        <svg class="w-4 h-4 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <span>Tài xế hiện tại cần bấm <strong>"Bàn giao chuyến chưa xong"</strong> trên app để ghi nhận Km bàn giao trước.</span>
                    </div>
                @endif

                {{-- Chọn tài xế mới: Combobox tìm kiếm 1 ô gọn gàng, chuẩn giao diện Filament --}}
                <div
                    class="space-y-1.5"
                    x-data="{
                        open: false,
                        query: '',
                        selectedId: @entangle('swapNewDriverId'),
                        drivers: @js($this->getAvailableDriversListForSwap()),
                        get selectedDriver() {
                            return this.drivers.find(d => d.id == this.selectedId) || null;
                        },
                        get displayQuery() {
                            if (this.open) {
                                return this.query;
                            }
                            if (this.selectedDriver) {
                                return this.selectedDriver.name + (this.selectedDriver.plate ? ' (' + this.selectedDriver.plate + ')' : '') + (this.selectedDriver.phone ? ' · ' + this.selectedDriver.phone : '');
                            }
                            return '';
                        },
                        get filteredDrivers() {
                            if (!this.query || !this.query.trim()) {
                                return this.drivers;
                            }
                            const q = this.query.trim().toLowerCase();
                            return this.drivers.filter(d => 
                                (d.name && d.name.toLowerCase().includes(q)) ||
                                (d.phone && d.phone.toLowerCase().includes(q)) ||
                                (d.plate && d.plate.toLowerCase().includes(q)) ||
                                (d.shift && d.shift.toLowerCase().includes(q))
                            );
                        },
                        onFocus() {
                            this.open = true;
                            this.query = '';
                        },
                        selectDriver(d) {
                            this.selectedId = d.id;
                            this.open = false;
                            this.query = '';
                        },
                        clearSelection() {
                            this.selectedId = null;
                            this.query = '';
                            this.open = false;
                        }
                    }"
                    @click.outside="open = false; query = ''"
                >
                    <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300">
                        Tài xế mới <span class="text-rose-600">*</span>
                    </label>

                    <div class="relative">
                        <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                            <input
                                type="text"
                                class="fi-input block w-full border-none bg-transparent py-1.5 pe-8 ps-0 text-xs text-gray-950 placeholder-gray-400 focus:outline-none focus:ring-0 dark:text-white dark:placeholder-gray-500"
                                :value="displayQuery"
                                @focus="onFocus()"
                                @input="query = $event.target.value; open = true"
                                placeholder="Gõ tên, SĐT hoặc biển số xe để tìm..."
                            />

                            <x-slot name="suffix">
                                <div class="flex items-center gap-1">
                                    <button
                                        type="button"
                                        x-show="selectedId"
                                        @click="clearSelection()"
                                        class="text-gray-400 hover:text-rose-600 p-0.5 transition"
                                        title="Xóa lựa chọn"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        @click="open = !open; if(open) onFocus()"
                                        class="text-gray-400 hover:text-gray-600 p-0.5 transition"
                                    >
                                        <svg class="w-3.5 h-3.5 transition-transform duration-200" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </button>
                                </div>
                            </x-slot>
                        </x-filament::input.wrapper>

                        {{-- Dropdown danh sách kết quả tìm kiếm --}}
                        <div
                            x-show="open"
                            x-cloak
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="opacity-0 translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            class="absolute left-0 right-0 top-full mt-1 z-50 max-h-56 overflow-y-auto rounded-lg bg-white p-1 shadow-xl ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10 text-xs divide-y divide-gray-100 dark:divide-white/5"
                            style="display: none;"
                        >
                            <template x-for="item in filteredDrivers" :key="item.id">
                                <div
                                    @click="selectDriver(item)"
                                    class="flex items-center justify-between px-3 py-2 rounded-md cursor-pointer transition hover:bg-gray-50 dark:hover:bg-white/5"
                                    :class="{ 'bg-primary-50 text-primary-700 font-semibold dark:bg-primary-950/50 dark:text-primary-400': item.id == selectedId }"
                                >
                                    <div class="flex items-center gap-2 truncate">
                                        <span class="font-medium text-gray-900 dark:text-white" x-text="item.name"></span>
                                        <template x-if="item.plate">
                                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-700/10 dark:bg-blue-900/30 dark:text-blue-300" x-text="item.plate"></span>
                                        </template>
                                        <template x-if="item.phone">
                                            <span class="text-gray-400 font-mono text-[11px]" x-text="item.phone"></span>
                                        </template>
                                        <template x-if="item.shift">
                                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-700/10 dark:bg-amber-900/30 dark:text-amber-300" x-text="item.shift"></span>
                                        </template>
                                    </div>

                                    <template x-if="item.id == selectedId">
                                        <svg class="w-4 h-4 text-primary-600 shrink-0 ms-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </template>
                                </div>
                            </template>

                            <template x-if="filteredDrivers.length === 0">
                                <div class="px-3 py-4 text-center text-gray-400 italic">
                                    Không tìm thấy tài xế nào phù hợp
                                </div>
                            </template>
                        </div>
                    </div>

                    @error('swapNewDriverId')
                        <span class="text-xs text-rose-600 font-medium block">{{ $message }}</span>
                    @enderror
                </div>

                {{-- Lý do đảo lái với viền border chuẩn Filament --}}
                <div class="space-y-1.5">
                    <label for="swapReason" class="block text-xs font-semibold text-gray-700 dark:text-gray-300">
                        Lý do đảo lái <span class="text-rose-600">*</span>
                    </label>
                    <x-filament::input.wrapper prefix-icon="heroicon-m-clipboard-document-list">
                        <x-filament::input.select
                            id="swapReason"
                            wire:model="swapReason"
                        >
                            @foreach (\App\Enums\DriverSwapReason::cases() as $reason)
                                <option value="{{ $reason->value }}">{{ $reason->getLabel() }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            </div>
        @else
            <div class="text-sm text-gray-500 italic p-4 text-center">
                Không tìm thấy thông tin chuyến xe cần đảo lái.
            </div>
        @endif

        <x-slot name="footer">
            <div class="flex items-center justify-end gap-3 w-full">
                <x-filament::button
                    color="gray"
                    x-on:click="$dispatch('close-modal', { id: 'vehicle-swap-driver-modal' })"
                >
                    Hủy
                </x-filament::button>

                @if ($swapTrip)
                    <x-filament::button
                        color="warning"
                        wire:click="submitDriverSwap"
                        wire:loading.attr="disabled"
                    >
                        <span wire:loading.remove wire:target="submitDriverSwap">Xác nhận gán tài xế</span>
                        <span wire:loading wire:target="submitDriverSwap">Đang xử lý...</span>
                    </x-filament::button>
                @endif
            </div>
        </x-slot>
    </x-filament::modal>

    {{-- Filament Action Modals (cho ReassignDriverAction và ResolveKmReportAction) --}}
    <x-filament-actions::modals />
</x-filament-panels::page>
