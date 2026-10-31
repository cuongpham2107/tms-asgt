<x-filament-panels::page>
    {{-- Loading overlay khi mở modal xe/GPS (chặn click liên tục) --}}
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

    @include('filament.pages.partials.dispatch-table-styles')

    {{-- Bảng Kiểm soát tình trạng xe do widget React vẽ; page giữ bộ lọc, modal và action --}}
    <div class="fi-js-widget-bare">
        @livewire(\App\Filament\Widgets\VehicleDispatchTable::class, [
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

    {{-- Filament Action Modals (cho ReassignDriverAction và ResolveKmReportAction) --}}
    <x-filament-actions::modals />
</x-filament-panels::page>
