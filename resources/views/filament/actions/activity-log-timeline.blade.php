@php
    use App\Services\ActivityLog\ActivityLogFormatter;

    $activities = \Spatie\Activitylog\Models\Activity::query()
        ->where('subject_type', $record->getMorphClass())
        ->where('subject_id', $record->getKey())
        ->with('causer')
        ->latest()
        ->get();

    $colorClasses = [
        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-400 dark:ring-emerald-500/30',
        'warning' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-950/40 dark:text-amber-400 dark:ring-amber-500/30',
        'danger'  => 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-950/40 dark:text-rose-400 dark:ring-rose-500/30',
        'info'    => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-950/40 dark:text-sky-400 dark:ring-sky-500/30',
        'primary' => 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-950/40 dark:text-blue-400 dark:ring-blue-500/30',
        'gray'    => 'bg-zinc-50 text-zinc-700 ring-zinc-600/20 dark:bg-zinc-900/60 dark:text-zinc-400 dark:ring-zinc-700/30',
    ];
@endphp

<div class="space-y-4 py-2 px-1" x-data="{ allOpen: true }">
    @if ($activities->isEmpty())
        <div class="flex flex-col items-center justify-center gap-3 py-16 text-center">
            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-400">
                <x-filament::icon icon="heroicon-o-clock" class="h-6 w-6" />
            </div>
            <div class="space-y-1">
                <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Chưa có lịch sử hoạt động</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Các thao tác tạo mới hoặc cập nhật sẽ được lưu tại đây.</p>
            </div>
        </div>
    @else
        {{-- Top Toolbar --}}
        <div class="flex items-center justify-between pb-1 border-b border-zinc-100 dark:border-zinc-800/80">
            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">
                Tổng cộng: <strong class="text-zinc-900 dark:text-zinc-100">{{ $activities->count() }}</strong> hoạt động
            </span>
            <button
                type="button"
                class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-lg text-zinc-600 hover:text-zinc-900 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:text-zinc-100 dark:hover:bg-zinc-800/60 transition-colors"
                @click="allOpen = !allOpen; $dispatch('toggle-all-activities', { state: allOpen })"
            >
                <x-filament::icon icon="heroicon-m-arrows-pointing-out" class="h-3.5 w-3.5" x-show="!allOpen" />
                <x-filament::icon icon="heroicon-m-arrows-pointing-in" class="h-3.5 w-3.5" x-show="allOpen" />
                <span x-text="allOpen ? 'Thu gọn tất cả' : 'Mở rộng tất cả'"></span>
            </button>
        </div>

        {{-- Timeline List --}}
        <div class="relative pl-6 space-y-4 before:absolute before:left-3 before:top-3 before:bottom-3 before:w-px before:bg-zinc-200 dark:before:bg-zinc-800">
            @foreach ($activities as $activity)
                @php
                    $event = $activity->event ?? 'updated';
                    $icon = $timelineIcons[$event] ?? match($event) {
                        'created' => 'heroicon-m-check-badge',
                        'updated' => 'heroicon-m-pencil-square',
                        'deleted' => 'heroicon-m-trash',
                        default => 'heroicon-m-information-circle',
                    };

                    $color = $timelineIconColors[$event] ?? match($event) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        default => 'info',
                    };

                    $badgeClass = $colorClasses[$color] ?? $colorClasses['info'];

                    $changes = $activity->attribute_changes;
                    if (is_array($changes) && (isset($changes['attributes']) || isset($changes['old']))) {
                        $attributes = $changes['attributes'] ?? [];
                        $old = $changes['old'] ?? [];
                    } elseif ($changes instanceof \Illuminate\Support\Collection) {
                        $attributes = $changes->get('attributes', []);
                        $old = $changes->get('old', []);
                    } else {
                        $properties = $activity->properties;
                        if (is_array($properties) && (isset($properties['attributes']) || isset($properties['old']))) {
                            $attributes = $properties['attributes'] ?? [];
                            $old = $properties['old'] ?? [];
                        } elseif ($properties instanceof \Illuminate\Support\Collection) {
                            $attributes = $properties->get('attributes', []);
                            $old = $properties->get('old', []);
                        } else {
                            $attributes = is_array($changes) ? $changes : ($changes?->toArray() ?? []);
                            $old = [];
                        }
                    }

                    $changeCount = count($attributes);
                @endphp

                <div
                    class="relative group"
                    x-data="{ open: true }"
                    @toggle-all-activities.window="open = $event.detail.state"
                >
                    {{-- Dot / Icon on timeline line --}}
                    <div class="absolute -left-6 top-1.5 flex h-6 w-6 -translate-x-1/2 items-center justify-center rounded-full bg-white dark:bg-zinc-950 ring-4 ring-white dark:ring-zinc-950">
                        <div class="flex h-5 w-5 items-center justify-center rounded-full ring-1 {{ $badgeClass }}">
                            <x-filament::icon :icon="$icon" class="h-3 w-3" />
                        </div>
                    </div>

                    {{-- Activity Card --}}
                    <div class="rounded-xl border border-zinc-200/80 bg-white shadow-xs dark:border-zinc-800/80 dark:bg-zinc-900/60 overflow-hidden transition-all">
                        {{-- Card Header / Accordion Trigger --}}
                        <div
                            class="flex flex-wrap items-center justify-between gap-2 p-3.5 cursor-pointer hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40 transition-colors select-none"
                            @click="open = !open"
                        >
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-semibold uppercase tracking-wider ring-1 {{ $badgeClass }}">
                                    <x-filament::icon :icon="$icon" class="h-3.5 w-3.5" />
                                    {{ match($event) {
                                        'created' => 'Tạo mới',
                                        'updated' => 'Cập nhật',
                                        'deleted' => 'Xóa',
                                        default => ucfirst($event)
                                    } }}
                                </span>
                                <span class="text-xs font-medium text-zinc-900 dark:text-zinc-100 flex items-center gap-1">
                                    <x-filament::icon icon="heroicon-m-user" class="h-3.5 w-3.5 text-zinc-400" />
                                    {{ $activity->causer?->name ?? 'Hệ thống' }}
                                </span>
                                @if ($changeCount > 0)
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                        {{ $changeCount }} thay đổi
                                    </span>
                                @endif
                            </div>

                            <div class="flex items-center gap-2 text-xs text-zinc-400 dark:text-zinc-500">
                                <div class="flex items-center gap-1">
                                    <x-filament::icon icon="heroicon-m-calendar" class="h-3.5 w-3.5" />
                                    <span>{{ $activity->created_at->format('H:i:s d/m/Y') }}</span>
                                    <span>•</span>
                                    <span>{{ $activity->created_at->diffForHumans() }}</span>
                                </div>
                                <button
                                    type="button"
                                    class="p-1 rounded-md text-zinc-400 hover:text-zinc-600 hover:bg-zinc-200/50 dark:hover:text-zinc-200 dark:hover:bg-zinc-700/50 transition-transform duration-200"
                                    :class="open ? 'rotate-180' : ''"
                                    aria-label="Thu gọn/Mở rộng"
                                >
                                    <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>

                        {{-- Collapsible Content --}}
                        <div x-show="open" x-collapse class="border-t border-zinc-100 dark:border-zinc-800/80 p-3.5 pt-2">
                            @if (!empty($attributes))
                                <div class="overflow-hidden rounded-lg border border-zinc-100 dark:border-zinc-800/80">
                                    <table class="w-full text-left text-xs divide-y divide-zinc-100 dark:divide-zinc-800/80">
                                        <thead class="bg-zinc-50/70 dark:bg-zinc-800/40">
                                            <tr class="text-zinc-500 dark:text-zinc-400">
                                                <th class="px-3 py-2 font-medium w-1/3">Trường dữ liệu</th>
                                                @if (!empty($old))
                                                    <th class="px-3 py-2 font-medium w-1/3">Trước đó</th>
                                                    <th class="px-3 py-2 font-medium w-1/3">Thay đổi thành</th>
                                                @else
                                                    <th class="px-3 py-2 font-medium" colspan="2">Giá trị thiết lập</th>
                                                @endif
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/50 bg-white dark:bg-zinc-900/40">
                                            @foreach ($attributes as $key => $newVal)
                                                @php
                                                    $oldVal = $old[$key] ?? null;
                                                    $label = ActivityLogFormatter::getFieldLabel($key);
                                                    $formattedOld = ActivityLogFormatter::formatValue($key, $oldVal, $activity->subject_type);
                                                    $formattedNew = ActivityLogFormatter::formatValue($key, $newVal, $activity->subject_type);
                                                @endphp
                                                <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30 transition-colors">
                                                    <td class="px-3 py-2 font-medium text-zinc-700 dark:text-zinc-300">
                                                        {{ $label }}
                                                    </td>
                                                    @if (!empty($old))
                                                        <td class="px-3 py-2">
                                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400 line-through">
                                                                {{ $formattedOld }}
                                                            </span>
                                                        </td>
                                                        <td class="px-3 py-2">
                                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400 font-medium">
                                                                {{ $formattedNew }}
                                                            </span>
                                                        </td>
                                                    @else
                                                        <td class="px-3 py-2 text-zinc-900 dark:text-zinc-100 font-medium" colspan="2">
                                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200">
                                                                {{ $formattedNew }}
                                                            </span>
                                                        </td>
                                                    @endif
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @elseif ($activity->description)
                                <p class="text-xs text-zinc-600 dark:text-zinc-300 py-1">{{ $activity->description }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
