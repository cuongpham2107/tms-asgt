@php
    use App\Services\ActivityLog\ActivityLogFormatter;

    $activities = \Spatie\Activitylog\Models\Activity::query()
        ->where('subject_type', $record->getMorphClass())
        ->where('subject_id', $record->getKey())
        ->with('causer')
        ->latest()
        ->get();

    $semanticColors = ['primary', 'gray', 'success', 'warning', 'danger', 'info'];
@endphp

<div class="space-y-4" x-data="{ allOpen: true }">
    @if ($activities->isEmpty())
        <div class="flex flex-col items-center justify-center gap-3 py-12 text-center">
            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-500/20">
                <x-filament::icon icon="heroicon-o-clock" class="h-6 w-6 text-gray-500 dark:text-gray-400" />
            </div>
            <div class="space-y-1">
                <p class="text-base font-semibold text-gray-950 dark:text-white">Chưa có lịch sử hoạt động</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">Các thao tác tạo mới hoặc cập nhật sẽ được lưu tại đây.</p>
            </div>
        </div>
    @else
        {{-- Top Toolbar --}}
        <div class="flex items-center justify-between gap-3 border-b border-gray-200 pb-3 dark:border-white/10">
            <span class="text-sm text-gray-500 dark:text-gray-400">
                Tổng cộng: <strong class="font-semibold tabular-nums text-gray-950 dark:text-white">{{ $activities->count() }}</strong> hoạt động
            </span>
            <button
                type="button"
                class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 dark:text-gray-200 dark:hover:bg-white/5"
                @click="allOpen = !allOpen; $dispatch('toggle-all-activities', { state: allOpen })"
            >
                <x-filament::icon icon="heroicon-m-arrows-pointing-out" class="h-4 w-4 text-gray-400 dark:text-gray-500" x-show="!allOpen" />
                <x-filament::icon icon="heroicon-m-arrows-pointing-in" class="h-4 w-4 text-gray-400 dark:text-gray-500" x-show="allOpen" />
                <span x-text="allOpen ? 'Thu gọn tất cả' : 'Mở rộng tất cả'"></span>
            </button>
        </div>

        {{-- Timeline List --}}
        <ol>
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

                    $color = in_array($color, $semanticColors, true) ? $color : 'info';

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

                <li
                    class="flex gap-x-3"
                    x-data="{ open: true }"
                    @toggle-all-activities.window="open = $event.detail.state"
                >
                    {{-- Dot / Icon on timeline line --}}
                    <div @class([
                        'relative flex flex-col items-center',
                        'after:absolute after:top-8 after:bottom-0 after:w-px after:bg-gray-200 dark:after:bg-white/10' => ! $loop->last,
                    ])>
                        <div class="fi-color-{{ $color }} relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-(--color-50) text-(--color-600) ring-1 ring-(--color-600)/20 dark:bg-(--color-400)/10 dark:text-(--color-400) dark:ring-(--color-400)/30">
                            <x-filament::icon :icon="$icon" class="h-4 w-4" />
                        </div>
                    </div>

                    {{-- Activity Card --}}
                    <div @class(['min-w-0 grow overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10', 'mb-4' => ! $loop->last])>
                        {{-- Card Header / Accordion Trigger --}}
                        <div
                            class="flex cursor-pointer select-none flex-wrap items-center justify-between gap-2 px-4 py-3 transition hover:bg-gray-50 dark:hover:bg-white/5"
                            @click="open = !open"
                        >
                            <div class="flex min-w-0 flex-wrap items-center gap-2">
                                <x-filament::badge :color="$color" :icon="$icon">
                                    {{ match($event) {
                                        'created' => 'Tạo mới',
                                        'updated' => 'Cập nhật',
                                        'deleted' => 'Xóa',
                                        default => ucfirst($event)
                                    } }}
                                </x-filament::badge>
                                <span class="flex items-center gap-1 text-sm font-semibold text-gray-950 dark:text-white">
                                    <x-filament::icon icon="heroicon-m-user" class="h-4 w-4 text-gray-400 dark:text-gray-500" />
                                    {{ $activity->causer?->name ?? 'Hệ thống' }}
                                </span>
                                @if ($changeCount > 0)
                                    <x-filament::badge color="gray" size="sm">
                                        <span class="tabular-nums">{{ $changeCount }}</span> thay đổi
                                    </x-filament::badge>
                                @endif
                            </div>

                            <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                <div class="flex items-center gap-1">
                                    <x-filament::icon icon="heroicon-m-calendar" class="h-4 w-4 text-gray-400 dark:text-gray-500" />
                                    <span class="tabular-nums">{{ $activity->created_at->format('H:i:s d/m/Y') }}</span>
                                    <span aria-hidden="true">•</span>
                                    <span>{{ $activity->created_at->diffForHumans() }}</span>
                                </div>
                                <button
                                    type="button"
                                    class="cursor-pointer rounded-md p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 motion-reduce:transition-none dark:text-gray-500 dark:hover:bg-white/5 dark:hover:text-gray-400"
                                    :class="open ? 'rotate-180' : ''"
                                    aria-label="Thu gọn/Mở rộng"
                                >
                                    <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>

                        {{-- Collapsible Content --}}
                        <div x-show="open" x-collapse class="border-t border-gray-200 p-4 dark:border-white/10">
                            @if (!empty($attributes))
                                <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                                    <table class="w-full divide-y divide-gray-200 text-start text-sm dark:divide-white/10">
                                        <thead class="bg-gray-50 dark:bg-white/5">
                                            <tr class="text-xs text-gray-500 dark:text-gray-400">
                                                <th class="w-1/3 px-3 py-2 text-start font-medium">Trường dữ liệu</th>
                                                @if (!empty($old))
                                                    <th class="w-1/3 px-3 py-2 text-start font-medium">Trước đó</th>
                                                    <th class="w-1/3 px-3 py-2 text-start font-medium">Thay đổi thành</th>
                                                @else
                                                    <th class="px-3 py-2 text-start font-medium" colspan="2">Giá trị thiết lập</th>
                                                @endif
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                            @foreach ($attributes as $key => $newVal)
                                                @php
                                                    $oldVal = $old[$key] ?? null;
                                                    $label = ActivityLogFormatter::getFieldLabel($key);
                                                    $formattedOld = ActivityLogFormatter::formatValue($key, $oldVal, $activity->subject_type);
                                                    $formattedNew = ActivityLogFormatter::formatValue($key, $newVal, $activity->subject_type);
                                                @endphp
                                                <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/5">
                                                    <td class="px-3 py-2 font-medium text-gray-950 dark:text-white">
                                                        {{ $label }}
                                                    </td>
                                                    @if (!empty($old))
                                                        <td class="px-3 py-2">
                                                            <span class="inline-flex items-center rounded-md bg-danger-50 px-1.5 py-0.5 text-danger-700 line-through dark:bg-danger-400/10 dark:text-danger-400">
                                                                {{ $formattedOld }}
                                                            </span>
                                                        </td>
                                                        <td class="px-3 py-2">
                                                            <span class="inline-flex items-center rounded-md bg-success-50 px-1.5 py-0.5 font-medium text-success-700 dark:bg-success-400/10 dark:text-success-400">
                                                                {{ $formattedNew }}
                                                            </span>
                                                        </td>
                                                    @else
                                                        <td class="px-3 py-2" colspan="2">
                                                            <span class="inline-flex items-center rounded-md bg-gray-100 px-1.5 py-0.5 font-medium text-gray-950 dark:bg-white/10 dark:text-white">
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
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $activity->description }}</p>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>
