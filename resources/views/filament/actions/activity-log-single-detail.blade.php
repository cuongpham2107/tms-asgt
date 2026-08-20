@php
    use App\Services\ActivityLog\ActivityLogFormatter;

    $colorClasses = [
        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-400 dark:ring-emerald-500/30',
        'warning' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-950/40 dark:text-amber-400 dark:ring-amber-500/30',
        'danger'  => 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-950/40 dark:text-rose-400 dark:ring-rose-500/30',
        'info'    => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-950/40 dark:text-sky-400 dark:ring-sky-500/30',
        'primary' => 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-950/40 dark:text-blue-400 dark:ring-blue-500/30',
        'gray'    => 'bg-zinc-50 text-zinc-700 ring-zinc-600/20 dark:bg-zinc-900/60 dark:text-zinc-400 dark:ring-zinc-700/30',
    ];

    $event = $activity->event ?? 'updated';
    $icon = match($event) {
        'created' => 'heroicon-m-check-badge',
        'updated' => 'heroicon-m-pencil-square',
        'deleted' => 'heroicon-m-trash',
        default => 'heroicon-m-information-circle',
    };

    $color = match($event) {
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
@endphp

<div class="space-y-6 py-2 px-1">
    <div class="rounded-xl border border-zinc-200/80 bg-zinc-50/70 p-4 dark:border-zinc-800 dark:bg-zinc-900/60">
        <div class="grid grid-cols-2 gap-4 text-xs">
            <div>
                <span class="text-zinc-500 dark:text-zinc-400">Sự kiện</span>
                <p class="mt-1">
                    <span class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 font-semibold uppercase ring-1 {{ $badgeClass }}">
                        <x-filament::icon :icon="$icon" class="h-3.5 w-3.5" />
                        {{ match($event) {
                            'created' => 'Tạo mới',
                            'updated' => 'Cập nhật',
                            'deleted' => 'Xóa',
                            default => ucfirst($event)
                        } }}
                    </span>
                </p>
            </div>
            <div>
                <span class="text-zinc-500 dark:text-zinc-400">Người thực hiện</span>
                <p class="mt-1 font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-1.5">
                    <x-filament::icon icon="heroicon-m-user" class="h-3.5 w-3.5 text-zinc-400" />
                    {{ $activity->causer?->name ?? 'Hệ thống' }}
                </p>
            </div>
            <div>
                <span class="text-zinc-500 dark:text-zinc-400">Đối tượng</span>
                <p class="mt-1 font-medium text-zinc-900 dark:text-zinc-100">
                    {{ class_basename($activity->subject_type ?? '') }} #{{ $activity->subject_id }}
                </p>
            </div>
            <div>
                <span class="text-zinc-500 dark:text-zinc-400">Thời gian</span>
                <p class="mt-1 font-medium text-zinc-900 dark:text-zinc-100 flex items-center gap-1.5">
                    <x-filament::icon icon="heroicon-m-calendar" class="h-3.5 w-3.5 text-zinc-400" />
                    {{ $activity->created_at->format('H:i:s d/m/Y') }} ({{ $activity->created_at->diffForHumans() }})
                </p>
            </div>
        </div>
    </div>

    @if (!empty($attributes))
        <div class="rounded-xl border border-zinc-200/80 bg-white p-4 shadow-xs dark:border-zinc-800/80 dark:bg-zinc-900/60 space-y-3">
            <h4 class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                Chi tiết biến động dữ liệu
            </h4>
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
                                <td class="px-3 py-2 font-medium text-zinc-700 dark:text-zinc-300">{{ $label }}</td>
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
        </div>
    @endif
</div>
