<x-filament-panels::page>
    @include('filament.pages.partials.dispatch-table-styles')

    {{-- Thân trang (chọn kỳ, tìm, tab, bảng) do widget React vẽ; page chỉ truyền kỳ ban đầu từ URL --}}
    <div class="fi-js-widget-bare">
        @livewire(\App\Filament\Widgets\DriverMonthlyKmTable::class, [
            'period' => $period,
            'date' => $date,
        ])
    </div>

    <div wire:loading wire:target="openTripTimeline" class="fixed inset-x-0 top-0 z-50 h-0.5 animate-pulse bg-primary-500"></div>

    {{-- Hành trình chuyến: bấm một dòng chuyến trong bảng để xem checkpoint, ảnh, vị trí --}}
    @php $timelineTrip = $this->timelineTrip(); @endphp
    <x-filament::modal id="driver-trip-timeline" width="7xl">
        <x-slot name="heading">
            Hành trình — {{ $timelineTrip?->trip_code }} · {{ $timelineTrip?->vehicle?->plate_number }} · {{ $timelineTrip?->driver?->name }}
        </x-slot>

        @if ($timelineTrip)
            <div wire:key="driver-trip-timeline-{{ $timelineTrip->id }}">
                @include('filament.resources.trips.components.trip-timeline-popup', ['trip' => $timelineTrip])
            </div>
        @endif
    </x-filament::modal>
</x-filament-panels::page>
