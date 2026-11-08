<x-filament-panels::page>
    @include('filament.pages.partials.dispatch-table-styles')

    {{-- Thân trang (chọn kỳ, tìm, tab, bảng) do widget React vẽ; page chỉ truyền kỳ ban đầu từ URL --}}
    <div class="fi-js-widget-bare">
        @livewire(\App\Filament\Widgets\DriverMonthlyKmTable::class, [
            'defer' => true,
            'period' => $period,
            'date' => $date,
        ])
    </div>

    {{-- Hành trình chuyến: bấm một dòng chuyến trong bảng để xem checkpoint, ảnh, vị trí --}}
    @php $timelineTrip = $this->timelineTrip(); @endphp
    <x-filament::modal id="driver-trip-timeline" width="7xl">
        <x-slot name="heading">
            <span wire:loading wire:target="openTripTimeline">Đang tải hành trình…</span>
            <span wire:loading.remove wire:target="openTripTimeline">
                Hành trình — {{ $timelineTrip?->trip_code }} · {{ $timelineTrip?->vehicle?->plate_number }} · {{ $timelineTrip?->driver?->name }}
            </span>
        </x-slot>

        {{-- Popup mở ngay khi bấm (React), skeleton hiện đến khi server trả nội dung --}}
        <div wire:loading.flex wire:target="openTripTimeline" class="flex-col gap-4" role="status" aria-label="Đang tải hành trình">
            @foreach (range(1, 4) as $index)
                <div class="flex animate-pulse gap-3 motion-reduce:animate-none">
                    <div class="h-8 w-8 shrink-0 rounded-full bg-gray-200 dark:bg-white/10"></div>
                    <div class="flex-1 space-y-2 py-1">
                        <div class="h-3 rounded bg-gray-200 dark:bg-white/10" style="width: {{ [55, 70, 45, 62][$index - 1] }}%"></div>
                        <div class="h-2.5 rounded bg-gray-200 dark:bg-white/10" style="width: {{ [35, 48, 30, 40][$index - 1] }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($timelineTrip)
            <div wire:loading.remove wire:target="openTripTimeline" wire:key="driver-trip-timeline-{{ $timelineTrip->id }}">
                @include('filament.resources.trips.components.trip-timeline-popup', ['trip' => $timelineTrip])
            </div>
        @endif
    </x-filament::modal>
</x-filament-panels::page>
