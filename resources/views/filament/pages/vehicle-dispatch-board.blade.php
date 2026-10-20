<x-filament-panels::page>
    @php
        $groupedVehicles = $this->getGroupedVehicles();
    @endphp

    <div 
        wire:poll.20s 
        x-data="{ 
            collapsed: {},
            toggleGroup(code) {
                this.collapsed[code] = !this.collapsed[code];
            }
        }" 
        class="w-full space-y-3"
    >
        {{-- Bảng Kiểm Soát Tình Trạng Xe theo mẫu thực tế --}}
        <div class="overflow-x-auto rounded-lg border border-[rgb(30_58_95)]/40 dark:border-slate-700 bg-white dark:bg-gray-900 shadow-md">
            <table class="w-full text-xs border-collapse border border-[rgb(30_58_95)]/40 dark:border-slate-700 font-sans">
                <thead>
                    {{-- Dòng tiêu đề chính màu navy theo theme table --}}
                    <tr class="dark:bg-slate-950" style="background-color: rgb(30 58 95 / var(--tw-bg-opacity, 1));">
                        <th 
                            colspan="15" 
                            class="text-white py-2 px-4 text-center font-bold tracking-wider text-sm sm:text-base border-b border-white/20 uppercase shadow-xs dark:bg-slate-950"
                            style="background-color: rgb(30 58 95 / var(--tw-bg-opacity, 1));"
                        >
                            KIỂM SOÁT TÌNH TRẠNG XE 🚛
                        </th>
                    </tr>

                    {{-- Dòng tiêu đề các cột màu navy theo theme table --}}
                    <tr class="text-white text-[11px] font-bold tracking-wide uppercase dark:bg-slate-950" style="background-color: rgb(30 58 95 / var(--tw-bg-opacity, 1));">
                        <th class="border border-white/20 py-1.5 px-2 text-center whitespace-nowrap min-w-[100px]">Địa điểm</th>
                        <th class="border border-white/20 py-1.5 px-2 text-center whitespace-nowrap min-w-[125px]">BSX</th>
                        <th class="border border-white/20 py-1.5 px-1 text-center whitespace-nowrap min-w-[45px]">STT</th>
                        <th class="border border-white/20 py-1.5 px-2 text-center whitespace-nowrap min-w-[85px]">Trạng thái</th>
                        <th class="border border-white/20 py-1.5 px-2 text-center whitespace-nowrap min-w-[65px]">Điểm đi</th>
                        <th class="border border-white/20 py-1.5 px-2 text-center whitespace-nowrap min-w-[100px]">Điểm đến</th>
                        <th class="border border-white/20 py-1.5 px-2 text-center whitespace-nowrap min-w-[70px]">Xe chờ</th>
                        <th class="border border-white/20 py-1.5 px-2 text-center whitespace-nowrap min-w-[140px]">Vị trí thực tế trên GPS</th>
                        <th class="border border-white/20 py-1.5 px-1 text-center whitespace-nowrap min-w-[50px]">Tốc độ</th>
                        <th class="border border-white/20 py-1.5 px-2 text-center whitespace-nowrap min-w-[85px]">Status</th>
                        <th class="border border-white/20 py-1.5 px-1 text-center whitespace-nowrap min-w-[50px]">Chuyến</th>
                        <th class="border border-white/20 py-1.5 px-2 text-center whitespace-nowrap min-w-[70px]">KM over</th>
                        <th class="border border-white/20 py-1.5 px-2 text-center whitespace-nowrap min-w-[125px]">Tên lái xe</th>
                        <th class="border border-white/20 py-1.5 px-1 text-center whitespace-nowrap min-w-[45px]">Trực</th>
                        <th class="border border-white/20 py-1.5 px-1 text-center whitespace-nowrap min-w-[45px]">Ca</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-[rgb(30_58_95)]/30 dark:divide-slate-800">
                    @forelse ($groupedVehicles as $group)
                        @php
                            $gCode = $group['code'];
                            $gTitle = $group['title'];
                            $vehicles = $group['vehicles'];
                            $count = $vehicles->count();
                        @endphp

                        @foreach ($vehicles as $index => $vehicle)
                            @php
                                $statusLabel = \App\Filament\Pages\VehicleDispatchBoard::getTripStatusLabel($vehicle);
                                $statusColor = \App\Filament\Pages\VehicleDispatchBoard::getTripStatusBadgeClass($vehicle);

                                $movStatus = \App\Filament\Pages\VehicleDispatchBoard::getMovementStatus($vehicle);
                                $movColor = match ($movStatus) {
                                    'Đang chạy' => 'bg-[#00b050] text-white font-bold',
                                    'Ko tắt máy' => 'bg-[#facc15] text-black font-semibold',
                                    'Mất GPS' => 'bg-gray-400 text-white font-semibold',
                                    default => 'bg-white text-gray-800 dark:bg-gray-800 dark:text-gray-200 border border-gray-300 dark:border-gray-600',
                                };

                                $xeChoData = \App\Filament\Pages\VehicleDispatchBoard::getXeChoData($vehicle);
                                $xeCho = $xeChoData['text'];
                                $xeChoTooltip = $xeChoData['tooltip'];
                                $isXeChoAlert = $xeChoData['isAlert'];
                                $isXeChoWarning = $xeChoData['isWarning'];

                                $pickup = \App\Filament\Pages\VehicleDispatchBoard::getPickupPoint($vehicle);
                                $dest = \App\Filament\Pages\VehicleDispatchBoard::getDeliveryPoint($vehicle);
                                $pickupFull = \App\Filament\Pages\VehicleDispatchBoard::getPickupPointFullName($vehicle);
                                $destFull = \App\Filament\Pages\VehicleDispatchBoard::getDeliveryPointFullName($vehicle);
                                $speed = \App\Filament\Pages\VehicleDispatchBoard::getSpeed($vehicle);
                                $kmOver = \App\Filament\Pages\VehicleDispatchBoard::getKmOver($vehicle);
                                $driverName = \App\Filament\Pages\VehicleDispatchBoard::getDriverName($vehicle);
                                $station = \App\Filament\Pages\VehicleDispatchBoard::getDriverStation($vehicle);
                                $shift = \App\Filament\Pages\VehicleDispatchBoard::getShiftLabel($vehicle);
                                $gpsLoc = \App\Filament\Pages\VehicleDispatchBoard::getGpsLocation($vehicle);
                            @endphp

                            {{-- Dòng dữ liệu --}}
                            <tr 
                                x-show="!collapsed['{{ $gCode }}'] || {{ $index === 0 ? 'true' : 'false' }}"
                                class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors {{ $index % 2 === 0 ? 'bg-white dark:bg-gray-900' : 'bg-slate-50/70 dark:bg-gray-800/40' }}"
                            >
                                {{-- 1. Địa điểm: Chỉ hiện ở dòng đầu tiên của nhóm, các dòng sau để trống với border --}}
                                @if ($index === 0)
                                    <td 
                                        class="border border-[rgb(30_58_95)]/30 dark:border-slate-800 bg-slate-100 dark:bg-slate-800/80 px-2 py-1 text-left font-bold text-gray-900 dark:text-gray-100 whitespace-nowrap select-none align-middle"
                                    >
                                        <button 
                                            type="button" 
                                            @click="toggleGroup('{{ $gCode }}')"
                                            class="inline-flex items-center gap-1.5 font-bold hover:opacity-80 transition cursor-pointer"
                                            title="Bấm để đóng/mở nhóm {{ $gTitle }}"
                                        >
                                            <span 
                                                class="w-3.5 h-3.5 text-white text-[10px] inline-flex items-center justify-center rounded-xs font-mono font-bold leading-none shrink-0" 
                                                style="background-color: rgb(30 58 95 / var(--tw-bg-opacity, 1));"
                                                x-text="collapsed['{{ $gCode }}'] ? '+' : '−'"
                                            >−</span>
                                            <span class="text-xs">{{ $gTitle }}</span>
                                            <span 
                                                x-show="collapsed['{{ $gCode }}']" 
                                                class="text-[10px] text-[rgb(30_58_95)] dark:text-blue-300 font-semibold"
                                            >({{ $count }} xe)</span>
                                        </button>
                                    </td>
                                @else
                                    <td class="border border-[rgb(30_58_95)]/30 dark:border-slate-800 bg-white dark:bg-gray-900 px-2 py-1"></td>
                                @endif

                                {{-- 2. BSX --}}
                                <td class="border border-[rgb(30_58_95)]/30 dark:border-slate-800 px-2 py-1 whitespace-nowrap font-medium text-gray-900 dark:text-gray-100">
                                    <span class="cursor-pointer hover:underline text-blue-700 dark:text-blue-300 font-semibold" wire:click="openTripModal({{ $vehicle->id }})">
                                        {!! \App\Filament\Pages\VehicleDispatchBoard::renderBsx($vehicle) !!}
                                    </span>
                                </td>

                                {{-- 3. STT (CH / KH) --}}
                                <td class="border border-[rgb(30_58_95)]/30 dark:border-slate-800 px-1 py-1 text-center whitespace-nowrap">
                                    <span class="px-1 py-0.5 rounded text-[10px] font-bold {{ $vehicle->isCompany() ? 'bg-sky-100 text-sky-800 dark:bg-sky-900/60 dark:text-sky-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-300' }}">
                                        {{ $vehicle->isCompany() ? 'CH' : 'KH' }}
                                    </span>
                                </td>

                                {{-- 4. Trạng thái --}}
                                <td class="border border-[rgb(30_58_95)]/30 dark:border-slate-800 px-1.5 py-1 text-center whitespace-nowrap">
                                    @php
                                        $activeTrip = $vehicle->active_trip;
                                        $isKmPaused = $activeTrip && $activeTrip->isPaused();
                                        $isDriverSwap = $activeTrip && ! $isKmPaused && $activeTrip->status === \App\Enums\TripStatus::DriverSwap;
                                    @endphp

                                    @if ($isKmPaused)
                                        <button 
                                            type="button"
                                            wire:click="mountTableAction('resolve_km_report', '{{ $vehicle->id }}')"
                                            title="Bấm để xử lý báo sai Km"
                                            class="inline-flex items-center justify-center gap-1 px-2 py-0.5 rounded text-[11px] shadow-xs cursor-pointer hover:opacity-85 hover:ring-2 hover:ring-amber-400 font-bold transition {{ $statusColor }}"
                                        >
                                            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="w-3.5 h-3.5 shrink-0" />
                                            <span>{{ $statusLabel }}</span>
                                        </button>
                                    @elseif ($isDriverSwap)
                                        <button 
                                            type="button"
                                            wire:click="mountTableAction('reassign_driver', '{{ $vehicle->id }}')"
                                            title="Bấm để gán lại tài xế (Đảo lái)"
                                            class="inline-flex items-center justify-center gap-1 px-2 py-0.5 rounded text-[11px] shadow-xs cursor-pointer hover:opacity-85 hover:ring-2 hover:ring-rose-400 font-bold transition {{ $statusColor }}"
                                        >
                                            <x-filament::icon icon="heroicon-m-arrow-path" class="w-3.5 h-3.5 shrink-0" />
                                            <span>{{ $statusLabel }}</span>
                                        </button>
                                    @else
                                        <span class="px-2 py-0.5 rounded text-[11px] shadow-xs inline-block {{ $statusColor }}">
                                            {{ $statusLabel }}
                                        </span>
                                    @endif
                                </td>

                                {{-- 5. Điểm đi (Hiển thị code) --}}
                                <td class="border border-[rgb(30_58_95)]/30 dark:border-slate-800 px-1.5 py-1 text-center whitespace-nowrap font-medium text-gray-800 dark:text-gray-200">
                                    <span class="font-bold cursor-default" @if($pickupFull) title="{{ $pickupFull }}" @endif>{{ $pickup }}</span>
                                </td>

                                {{-- 6. Điểm đến (Hiển thị code) --}}
                                <td class="border border-[rgb(30_58_95)]/30 dark:border-slate-800 px-2 py-1 whitespace-nowrap font-medium text-gray-800 dark:text-gray-200">
                                    @if ($dest === 'Thiếu điểm')
                                        <span class="bg-[#ffff00] text-black font-bold px-1.5 py-0.5 rounded text-[11px]">Thiếu điểm</span>
                                    @else
                                        <span class="cursor-default font-medium" @if($destFull) title="{{ $destFull }}" @endif>{{ $dest }}</span>
                                    @endif
                                </td>

                                {{-- 7. Xe chờ --}}
                                <td class="border border-[rgb(30_58_95)]/30 dark:border-slate-800 px-1.5 py-1 text-center whitespace-nowrap font-medium" title="{{ $xeChoTooltip }}">
                                    @if ($isXeChoAlert)
                                        <span class="bg-[#c00000] text-white font-bold px-1.5 py-0.5 rounded text-[10px] inline-block animate-pulse">
                                            {{ $xeCho }}
                                        </span>
                                    @elseif ($isXeChoWarning)
                                        <span class="bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-300 font-bold px-1.5 py-0.5 rounded text-[10px] inline-block">
                                            {{ $xeCho }}
                                        </span>
                                    @elseif ($xeCho === 'Sẵn sàng')
                                        <span class="text-emerald-700 dark:text-emerald-400 font-semibold text-[11px]">
                                            Sẵn sàng
                                        </span>
                                    @else
                                        <span class="text-gray-800 dark:text-gray-200 font-mono text-[11px]">
                                            {{ $xeCho }}
                                        </span>
                                    @endif
                                </td>

                                {{-- 8. Vị trí thực tế trên GPS --}}
                                <td class="border border-[rgb(30_58_95)]/30 dark:border-slate-800 px-2 py-1 whitespace-nowrap text-gray-700 dark:text-gray-300">
                                    <span 
                                        class="cursor-pointer hover:underline hover:text-blue-600 dark:hover:text-blue-400 font-medium"
                                        wire:click="openGpsModal({{ $vehicle->id }})"
                                        title="{{ $vehicle->gps_address ? $vehicle->gps_address : 'Bấm để xem trên bản đồ' }}"
                                    >
                                        {{ $gpsLoc }}
                                    </span>
                                </td>

                                {{-- 9. Tốc độ --}}
                                <td class="border border-[rgb(30_58_95)]/30 dark:border-slate-800 px-1 py-1 text-center whitespace-nowrap font-bold text-gray-900 dark:text-white">
                                    <span class="{{ (float)$vehicle->gps_speed > 0 ? 'text-emerald-600 dark:text-emerald-400 font-black' : '' }}">
                                        {{ $speed }}
                                    </span>
                                </td>

                                {{-- 10. Status (GPS / Động cơ) --}}
                                <td class="border border-[rgb(30_58_95)]/30 dark:border-slate-800 px-1.5 py-1 text-center whitespace-nowrap">
                                    <span class="px-1.5 py-0.5 rounded text-[10px] inline-block {{ $movColor }}">
                                        {{ $movStatus }}
                                    </span>
                                </td>

                                {{-- 11. Chuyến --}}
                                <td class="border border-[rgb(30_58_95)]/30 dark:border-slate-800 px-1 py-1 text-center whitespace-nowrap font-bold text-gray-800 dark:text-gray-200">
                                    <span>{{ \App\Filament\Pages\VehicleDispatchBoard::getTodayTripsCount($vehicle) }}</span>
                                </td>

                                {{-- 12. KM over --}}
                                <td class="border border-[rgb(30_58_95)]/30 dark:border-slate-800 px-1.5 py-1 text-center whitespace-nowrap font-mono text-[11px] {{ $kmOver !== '—' ? 'text-rose-600 dark:text-rose-400 font-bold' : 'text-gray-400' }}">
                                    <span>{{ $kmOver }}</span>
                                </td>

                                {{-- 13. Tên lái xe --}}
                                <td class="border border-[rgb(30_58_95)]/30 dark:border-slate-800 px-2 py-1 whitespace-nowrap font-medium text-gray-800 dark:text-gray-200">
                                    <span>{{ $driverName }}</span>
                                </td>

                                {{-- 14. Trực --}}
                                <td class="border border-[rgb(30_58_95)]/30 dark:border-slate-800 px-1 py-1 text-center whitespace-nowrap font-bold text-[11px]">
                                    @php
                                        $stClass = match ($station) {
                                            'BN' => 'text-amber-700 bg-amber-100 dark:bg-amber-900/60 dark:text-amber-300 px-1 rounded',
                                            'NBA' => 'text-emerald-700 bg-emerald-100 dark:bg-emerald-900/60 dark:text-emerald-300 px-1 rounded',
                                            'TN' => 'text-sky-700 bg-sky-100 dark:bg-sky-900/60 dark:text-sky-300 px-1 rounded',
                                            default => 'text-gray-500',
                                        };
                                    @endphp
                                    <span class="{{ $stClass }}">{{ $station }}</span>
                                </td>

                                {{-- 15. Ca --}}
                                <td class="border border-[rgb(30_58_95)]/30 dark:border-slate-800 px-1 py-1 text-center whitespace-nowrap font-medium text-[11px] text-gray-700 dark:text-gray-300">
                                    <span class="font-bold">{{ $shift }}</span>
                                </td>
                            </tr>
                        @endforeach
                    @empty
                        <tr>
                            <td colspan="15" class="py-8 text-center text-gray-500 italic border border-[rgb(30_58_95)]/30 dark:border-slate-800">
                                Không tìm thấy xe nào phù hợp.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Modals khi click vào BSX hoặc Vị trí GPS --}}
    @php
        $tripVehicle = $this->getSelectedVehicleForTrip();
        $gpsVehicle = $this->getSelectedVehicleForGps();
    @endphp

    {{-- Modal xem chuyến xe --}}
    <x-filament::modal id="vehicle-trip-modal" width="2xl">
        <x-slot name="heading">
            Chuyến xe — {{ $tripVehicle?->plate_number }}
        </x-slot>

        @if ($tripVehicle && $tripVehicle->active_trip)
            @include('filament.pages.partials.vehicle-trip-modal', [
                'vehicle' => $tripVehicle,
                'trip' => $tripVehicle->active_trip,
            ])
        @else
            <div class="text-sm text-gray-500 italic p-4 text-center">
                Xe {{ $tripVehicle?->plate_number }} hiện tại chưa có chuyến xe nào đang hoạt động.
            </div>
        @endif
    </x-filament::modal>

    {{-- Modal xem GPS --}}
    <x-filament::modal id="vehicle-gps-modal" width="2xl">
        <x-slot name="heading">
            Vị trí xe {{ $gpsVehicle?->plate_number }}
        </x-slot>

        @if ($gpsVehicle)
            @include('filament.pages.partials.vehicle-gps-modal', [
                'vehicle' => $gpsVehicle,
            ])
        @endif
    </x-filament::modal>

    {{-- Filament Action Modals (cho ReassignDriverAction và ResolveKmReportAction) --}}
    <x-filament-actions::modals />
</x-filament-panels::page>
