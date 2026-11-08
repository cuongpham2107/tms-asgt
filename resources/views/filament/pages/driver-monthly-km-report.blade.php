<x-filament-panels::page>
    @include('filament.pages.partials.dispatch-table-styles')

    {{-- Thân trang (chọn kỳ, tìm, tab, bảng) do widget React vẽ; page chỉ truyền kỳ ban đầu từ URL --}}
    <div class="fi-js-widget-bare">
        @livewire(\App\Filament\Widgets\DriverMonthlyKmTable::class, [
            'period' => $period,
            'date' => $date,
        ])
    </div>
</x-filament-panels::page>
