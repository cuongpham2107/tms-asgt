@php
    /** @var \App\Models\Trip $trip */
    $photos = $trip->photos()
        ->with([
            'tripCheckpoint.driver',
            'tripCheckpoint.order',
            'tripCheckpoint.deliveryPoint.location',
        ])
        ->orderBy('trip_checkpoints.occurred_at', 'asc')
        ->orderBy('trip_photos.id', 'asc')
        ->get();

    $photosData = $photos->map(function ($photo, $index) {
        $cp = $photo->tripCheckpoint;
        $cpType = $cp?->checkpoint_type;
        $orderCode = $cp?->order?->order_code;
        $pointCode = $cp?->deliveryPoint?->location?->code ?? $cp?->deliveryPoint?->location?->name;

        return [
            'id' => $photo->id,
            'index' => $index,
            'url' => $photo->url,
            'is_valid' => $photo->is_valid,
            'type_key' => $cpType?->value ?? 'unknown',
            'type_label' => $cpType?->getLabel() ?? 'Mốc hành trình',
            'type_color' => $cpType?->getColor() ?? 'gray',
            'occurred_at' => $cp?->occurred_at?->format('H:i d/m/Y') ?? ($photo->created_at?->format('H:i d/m/Y') ?? '—'),
            'driver_name' => $cp?->driver?->name ?? '—',
            'order_code' => $orderCode,
            'point_code' => $pointCode,
            'km_reading' => $cp?->km_reading !== null ? number_format((float) $cp->km_reading, 1, ',', '.') . ' km' : null,
            'voice_note' => $cp?->voice_note,
        ];
    })->values();

    $typesSummary = $photosData->groupBy('type_key')->map(function ($items, $key) {
        return [
            'key' => $key,
            'label' => $items->first()['type_label'],
            'color' => $items->first()['type_color'],
            'count' => $items->count(),
        ];
    })->values();

    $badgeColorClasses = [
        'info'    => 'border-blue-300 bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300 dark:border-blue-800',
        'warning' => 'border-amber-300 bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800',
        'success' => 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800',
        'danger'  => 'border-red-300 bg-red-50 text-red-700 dark:bg-red-950/60 dark:text-red-300 dark:border-red-800',
        'primary' => 'border-purple-300 bg-purple-50 text-purple-700 dark:bg-purple-950/60 dark:text-purple-300 dark:border-purple-800',
        'gray'    => 'border-gray-300 bg-gray-50 text-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700',
    ];
@endphp

@if ($photosData->isEmpty())
    <div class="flex flex-col items-center justify-center py-12 px-4 text-center">
        <div class="flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500 mb-4">
            <svg class="h-8 w-8" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
            </svg>
        </div>
        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Chưa có ảnh nào</h3>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400 max-w-sm">
            Chuyến đi <span class="font-medium text-gray-700 dark:text-gray-300">{{ $trip->trip_code }}</span> hiện chưa có hình ảnh nào được tải lên từ tài xế qua ứng dụng mobile.
        </p>
    </div>
@else
    <div
        x-data="{
            activeFilter: 'all',
            lightboxOpen: false,
            currentIndex: 0,
            photos: {{ \Illuminate\Support\Js::from($photosData) }},
            get currentPhoto() {
                return this.photos[this.currentIndex] || {};
            },
            get filteredPhotos() {
                if (this.activeFilter === 'all') return this.photos;
                return this.photos.filter(p => p.type_key === this.activeFilter);
            },
            openLightbox(idx) {
                this.currentIndex = idx;
                this.lightboxOpen = true;
            },
            closeLightbox() {
                this.lightboxOpen = false;
            },
            next() {
                if (this.photos.length === 0) return;
                this.currentIndex = (this.currentIndex + 1) % this.photos.length;
            },
            prev() {
                if (this.photos.length === 0) return;
                this.currentIndex = (this.currentIndex - 1 + this.photos.length) % this.photos.length;
            }
        }"
        @keydown.window.escape="if (lightboxOpen) { closeLightbox(); }"
        @keydown.window.arrow-right="if (lightboxOpen) { next(); }"
        @keydown.window.arrow-left="if (lightboxOpen) { prev(); }"
        class="space-y-4"
    >
        {{-- Trip Header Summary --}}
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-gray-50/80 p-3.5 dark:border-gray-700 dark:bg-gray-800/80">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                <div>
                    <span class="text-xs text-gray-500 dark:text-gray-400">Mã chuyến:</span>
                    <span class="font-bold text-gray-900 dark:text-white">{{ $trip->trip_code }}</span>
                </div>
                <div class="h-4 w-px bg-gray-300 dark:bg-gray-600 hidden sm:block"></div>
                <div>
                    <span class="text-xs text-gray-500 dark:text-gray-400">Xe:</span>
                    <span class="font-bold text-amber-700 dark:text-amber-400">{{ $trip->vehicle?->plate_number ?? '—' }}</span>
                </div>
                <div class="h-4 w-px bg-gray-300 dark:bg-gray-600 hidden sm:block"></div>
                <div>
                    <span class="text-xs text-gray-500 dark:text-gray-400">Lái xe:</span>
                    <span class="font-semibold text-gray-900 dark:text-white">{{ $trip->driver?->name ?? '—' }}</span>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700 dark:bg-blue-950/60 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                    </svg>
                    <span>{{ $photosData->count() }} ảnh</span>
                </span>

                <a
                    href="{{ route('trips.photos.download-zip', $trip) }}"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 hover:bg-primary-500 px-3 py-1 text-xs font-semibold text-white shadow-xs transition duration-150 cursor-pointer"
                    title="Tải tất cả ảnh của chuyến đi dạng file nén .ZIP"
                >
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.5V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    <span>Tải tất cả ảnh (.zip)</span>
                </a>
            </div>
        </div>

        {{-- Filter Pills by Checkpoint Type --}}
        @if ($typesSummary->count() > 1)
            <div class="flex flex-wrap items-center gap-1.5 border-b border-gray-200 pb-2 dark:border-gray-700">
                <button
                    type="button"
                    @click="activeFilter = 'all'"
                    :class="activeFilter === 'all'
                        ? 'bg-primary-600 text-white shadow-sm'
                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'"
                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition cursor-pointer"
                >
                    <span>Tất cả</span>
                    <span class="rounded-full bg-white/20 px-1.5 py-0.2 text-[10px] font-bold">{{ $photosData->count() }}</span>
                </button>

                @foreach ($typesSummary as $type)
                    <button
                        type="button"
                        @click="activeFilter = '{{ $type['key'] }}'"
                        :class="activeFilter === '{{ $type['key'] }}'
                            ? 'bg-primary-600 text-white shadow-sm'
                            : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition cursor-pointer"
                    >
                        <span>{{ $type['label'] }}</span>
                        <span class="rounded-full bg-black/10 dark:bg-white/10 px-1.5 py-0.2 text-[10px] font-bold">{{ $type['count'] }}</span>
                    </button>
                @endforeach
            </div>
        @endif

        {{-- Gallery Grid --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
            <template x-for="item in filteredPhotos" :key="item.id">
                <div class="group flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition hover:shadow-md hover:border-primary-400 dark:border-gray-700 dark:bg-gray-900">
                    {{-- Thumbnail Container --}}
                    <div class="relative aspect-4/3 w-full overflow-hidden bg-gray-100 dark:bg-gray-800 cursor-pointer" @click="openLightbox(item.index)">
                        <template x-if="item.is_valid && item.url">
                            <img
                                :src="item.url"
                                :alt="'Ảnh ' + item.type_label"
                                loading="lazy"
                                class="h-full w-full object-cover transition duration-200 group-hover:scale-105"
                            />
                        </template>

                        <template x-if="!item.is_valid || !item.url">
                            <div class="flex h-full w-full flex-col items-center justify-center p-3 text-center">
                                <svg class="h-8 w-8 text-amber-500 mb-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                                </svg>
                                <span class="text-xs font-semibold text-amber-700 dark:text-amber-300">File lỗi / 0 KB</span>
                            </div>
                        </template>

                        {{-- Overlay on Hover --}}
                        <div class="absolute inset-0 flex items-center justify-center bg-black/30 opacity-0 transition duration-150 group-hover:opacity-100">
                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-black/70 px-2.5 py-1 text-xs font-medium text-white shadow">
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607ZM10.5 7.5v6m3-3h-6" />
                                </svg>
                                Xem lớn
                            </span>
                        </div>

                        {{-- Checkpoint Type Badge in corner --}}
                        <div class="absolute top-2 left-2">
                            <span
                                class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-bold shadow-sm border"
                                :class="{
                                    'border-blue-300 bg-blue-50/95 text-blue-700 dark:bg-blue-950/90 dark:text-blue-300': item.type_color === 'info',
                                    'border-amber-300 bg-amber-50/95 text-amber-700 dark:bg-amber-950/90 dark:text-amber-300': item.type_color === 'warning',
                                    'border-emerald-300 bg-emerald-50/95 text-emerald-700 dark:bg-emerald-950/90 dark:text-emerald-300': item.type_color === 'success',
                                    'border-red-300 bg-red-50/95 text-red-700 dark:bg-red-950/90 dark:text-red-300': item.type_color === 'danger',
                                    'border-purple-300 bg-purple-50/95 text-purple-700 dark:bg-purple-950/90 dark:text-purple-300': item.type_color === 'primary',
                                    'border-gray-300 bg-gray-50/95 text-gray-700 dark:bg-gray-800/90 dark:text-gray-300': !['info','warning','success','danger','primary'].includes(item.type_color)
                                }"
                                x-text="item.type_label"
                            ></span>
                        </div>
                    </div>

                    {{-- Card Info --}}
                    <div class="flex flex-1 flex-col justify-between p-3 text-xs space-y-2">
                        <div class="space-y-1">
                            <div class="flex items-center justify-between text-gray-500 dark:text-gray-400">
                                <span class="flex items-center gap-1 font-medium text-gray-800 dark:text-gray-200">
                                    <svg class="h-3.5 w-3.5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                    </svg>
                                    <span x-text="item.occurred_at"></span>
                                </span>

                                <template x-if="item.km_reading">
                                    <span class="font-semibold text-amber-600 dark:text-amber-400" x-text="item.km_reading"></span>
                                </template>
                            </div>

                            <div class="flex items-center gap-1 text-gray-600 dark:text-gray-300">
                                <svg class="h-3.5 w-3.5 text-gray-400 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                                </svg>
                                <span class="truncate" x-text="item.driver_name"></span>
                            </div>

                            <template x-if="item.point_code || item.order_code">
                                <div class="flex flex-wrap items-center gap-1 text-[11px] text-gray-500 dark:text-gray-400">
                                    <template x-if="item.order_code">
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300" x-text="item.order_code"></span>
                                    </template>
                                    <template x-if="item.point_code">
                                        <span class="truncate" x-text="item.point_code"></span>
                                    </template>
                                </div>
                            </template>

                            <template x-if="item.voice_note">
                                <p class="text-[11px] italic text-gray-500 line-clamp-1" x-text="'Ghi chú: ' + item.voice_note"></p>
                            </template>
                        </div>

                        {{-- Card Actions --}}
                        <div class="flex items-center justify-between border-t border-gray-100 pt-2 dark:border-gray-800">
                            <span class="text-[10px] text-gray-400" x-text="'ID: #' + item.id"></span>
                            <div class="flex items-center gap-2">
                                <a
                                    :href="item.url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-[11px] font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400 hover:underline inline-flex items-center gap-0.5"
                                    title="Mở ảnh trong tab mới"
                                >
                                    <span>Mở tab mới</span>
                                    <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                    </svg>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        {{-- Alpine Lightbox Modal --}}
        <div
            x-show="lightboxOpen"
            x-cloak
            style="display: none;"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4 backdrop-blur-xs"
            @click.self="closeLightbox()"
        >
            <div class="relative flex max-h-[95vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-gray-900 text-white shadow-2xl">
                {{-- Lightbox Header --}}
                <div class="flex items-center justify-between border-b border-gray-800 px-4 py-3">
                    <div class="flex items-center gap-3">
                        <span
                            class="inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-bold"
                            :class="{
                                'bg-blue-600 text-white': currentPhoto.type_color === 'info',
                                'bg-amber-600 text-white': currentPhoto.type_color === 'warning',
                                'bg-emerald-600 text-white': currentPhoto.type_color === 'success',
                                'bg-red-600 text-white': currentPhoto.type_color === 'danger',
                                'bg-purple-600 text-white': currentPhoto.type_color === 'primary',
                                'bg-gray-700 text-white': !['info','warning','success','danger','primary'].includes(currentPhoto.type_color)
                            }"
                            x-text="currentPhoto.type_label"
                        ></span>
                        <span class="text-sm font-medium text-gray-300" x-text="currentPhoto.occurred_at"></span>
                        <span class="text-xs text-gray-500" x-text="'(' + (currentIndex + 1) + ' / ' + photos.length + ')'"></span>
                    </div>

                    <div class="flex items-center gap-2">
                        <a
                            :href="currentPhoto.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="rounded-lg bg-gray-800 p-1.5 text-gray-300 hover:bg-gray-700 hover:text-white transition"
                            title="Mở tab mới"
                        >
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                            </svg>
                        </a>
                        <a
                            :href="currentPhoto.url"
                            download
                            class="rounded-lg bg-gray-800 p-1.5 text-gray-300 hover:bg-gray-700 hover:text-white transition"
                            title="Tải ảnh này về máy"
                        >
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.5V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                            </svg>
                        </a>
                        <a
                            href="{{ route('trips.photos.download-zip', $trip) }}"
                            class="rounded-lg bg-primary-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-primary-500 transition inline-flex items-center gap-1 cursor-pointer"
                            title="Tải tất cả ảnh của chuyến đi (.zip)"
                        >
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.5V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                            </svg>
                            <span>Tải tất cả (.zip)</span>
                        </a>
                        <button
                            type="button"
                            @click="closeLightbox()"
                            class="rounded-lg bg-gray-800 p-1.5 text-gray-300 hover:bg-gray-700 hover:text-white transition cursor-pointer"
                            title="Đóng"
                        >
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                {{-- Lightbox Image with Prev/Next Controls --}}
                <div class="relative flex flex-1 items-center justify-center bg-black/60 p-4 select-none min-h-[300px] max-h-[72vh]">
                    <template x-if="currentPhoto.is_valid && currentPhoto.url">
                        <img
                            :src="currentPhoto.url"
                            :alt="currentPhoto.type_label"
                            class="max-h-[70vh] max-w-full rounded-lg object-contain"
                        />
                    </template>

                    <template x-if="!currentPhoto.is_valid || !currentPhoto.url">
                        <div class="flex flex-col items-center justify-center p-8 text-center text-amber-400">
                            <svg class="h-12 w-12 mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                            </svg>
                            <span class="text-sm font-semibold">Tập tin ảnh không hợp lệ hoặc rỗng từ thiết bị</span>
                        </div>
                    </template>

                    {{-- Prev Button --}}
                    <button
                        type="button"
                        x-show="photos.length > 1"
                        @click="prev()"
                        class="absolute left-4 top-1/2 -translate-y-1/2 rounded-full bg-black/60 p-2 text-white hover:bg-black/90 transition cursor-pointer"
                        title="Ảnh trước (Phím mũi tên trái)"
                    >
                        <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                        </svg>
                    </button>

                    {{-- Next Button --}}
                    <button
                        type="button"
                        x-show="photos.length > 1"
                        @click="next()"
                        class="absolute right-4 top-1/2 -translate-y-1/2 rounded-full bg-black/60 p-2 text-white hover:bg-black/90 transition cursor-pointer"
                        title="Ảnh tiếp theo (Phím mũi tên phải)"
                    >
                        <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>
                    </button>
                </div>

                {{-- Lightbox Footer Details --}}
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-800 bg-gray-900/90 px-4 py-2.5 text-xs text-gray-300">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <div>
                            <span class="text-gray-500">Tài xế:</span>
                            <span class="font-semibold text-white ml-1" x-text="currentPhoto.driver_name"></span>
                        </div>
                        <template x-if="currentPhoto.km_reading">
                            <div>
                                <span class="text-gray-500">Km:</span>
                                <span class="font-semibold text-amber-400 ml-1" x-text="currentPhoto.km_reading"></span>
                            </div>
                        </template>
                        <template x-if="currentPhoto.order_code">
                            <div>
                                <span class="text-gray-500">Đơn hàng:</span>
                                <span class="font-semibold text-white ml-1" x-text="currentPhoto.order_code"></span>
                            </div>
                        </template>
                        <template x-if="currentPhoto.point_code">
                            <div>
                                <span class="text-gray-500">Điểm:</span>
                                <span class="font-semibold text-white ml-1" x-text="currentPhoto.point_code"></span>
                            </div>
                        </template>
                        <template x-if="currentPhoto.voice_note">
                            <div>
                                <span class="text-gray-500">Ghi chú:</span>
                                <span class="italic text-gray-200 ml-1" x-text="currentPhoto.voice_note"></span>
                            </div>
                        </template>
                    </div>

                    <div class="text-[11px] text-gray-400">
                        <span>Dùng phím &larr; &rarr; để chuyển ảnh, Esc để đóng</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif
