@php
    $gpsCustomerCodes = $vehicle->active_trip?->orders
        ->map(fn ($order) => $order->customer?->code)
        ->filter()
        ->unique()
        ?? collect();
@endphp

<div class="space-y-4 text-sm">
    <div class="grid grid-cols-2 gap-3 bg-gray-50/70 dark:bg-white/5 p-3.5 rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400 font-medium">Tọa độ GPS</div>
            <div class="font-bold text-gray-900 dark:text-white mt-0.5 font-mono text-xs">{{ $vehicle->gps_lat }}, {{ $vehicle->gps_lng }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400 font-medium">Tốc độ hiện tại</div>
            <div class="font-bold text-emerald-600 dark:text-emerald-400 mt-0.5">
                {{ $vehicle->gps_speed !== null ? round((float) $vehicle->gps_speed) . ' km/h' : '0 km/h' }}
            </div>
        </div>
        <div class="col-span-2">
            <div class="text-xs text-gray-500 dark:text-gray-400 font-medium">Mã khách hàng</div>
            <div class="mt-0.5 flex flex-wrap items-center gap-1.5">
                @forelse ($gpsCustomerCodes as $customerCode)
                    <x-filament::badge color="info" size="sm">{{ $customerCode }}</x-filament::badge>
                @empty
                    <span class="text-xs text-gray-400">—</span>
                @endforelse
            </div>
        </div>
        <div class="col-span-2">
            <div class="text-xs text-gray-500 dark:text-gray-400 font-medium">Địa chỉ thực tế</div>
            <div class="font-medium text-gray-800 dark:text-gray-200 mt-0.5">{{ $vehicle->gps_address ?? 'Chưa xác định' }}</div>
        </div>
        <div class="col-span-2 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
            <span>Cập nhật lúc: <strong class="text-gray-700 dark:text-gray-300">{{ $vehicle->last_gps_update ? $vehicle->last_gps_update->format('H:i:s d/m/Y') : '—' }}</strong></span>
            <span>{{ $vehicle->last_gps_update?->diffForHumans() }}</span>
        </div>
    </div>

    @if ($vehicle->gps_lat && $vehicle->gps_lng)
        <div class="rounded-xl overflow-hidden ring-1 ring-gray-950/5 dark:ring-white/10 shadow-xs" style="height: 320px;">
            <iframe 
                width="100%" 
                height="100%" 
                frameborder="0" 
                scrolling="no" 
                marginheight="0" 
                marginwidth="0" 
                src="https://www.openstreetmap.org/export/embed.html?bbox={{ (float)$vehicle->gps_lng - 0.008 }}%2C{{ (float)$vehicle->gps_lat - 0.006 }}%2C{{ (float)$vehicle->gps_lng + 0.008 }}%2C{{ (float)$vehicle->gps_lat + 0.006 }}&amp;layer=mapnik&amp;marker={{ $vehicle->gps_lat }}%2C{{ $vehicle->gps_lng }}">
            </iframe>
        </div>
        <div class="flex items-center justify-between pt-1">
            <span class="text-xs text-gray-500 dark:text-gray-400">Dữ liệu định vị từ thiết bị giám sát hành trình</span>
            <a 
                href="https://www.google.com/maps?q={{ $vehicle->gps_lat }},{{ $vehicle->gps_lng }}" 
                target="_blank" 
                rel="noopener noreferrer" 
                class="text-xs text-primary-600 hover:text-primary-500 dark:text-primary-400 font-semibold inline-flex items-center gap-1"
            >
                Mở trên Google Maps &rarr;
            </a>
        </div>
    @else
        <div class="text-xs text-gray-500 dark:text-gray-400 italic p-6 text-center rounded-xl bg-gray-50/50 dark:bg-white/[0.02] ring-1 ring-gray-950/5 dark:ring-white/10">
            Xe chưa có tọa độ GPS để hiển thị trên bản đồ.
        </div>
    @endif
</div>

